<?php

declare(strict_types=1);

namespace Raphp\Tests\Async;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Amp\TimeoutCancellation;
use ArrayObject;
use Error;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Raphp\Async\BackgroundTasks;
use Revolt\EventLoop;
use RuntimeException;
use Throwable;
use TypeError;

final class BackgroundTasksTest extends TestCase
{
    private TestHandler $logs;

    private BackgroundTasks $tasks;

    /** @var list<Throwable> errors that reached the event loop's error handler */
    private array $loopErrors = [];

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
        $this->tasks = new BackgroundTasks(new Logger('test', [$this->logs]));
        $this->loopErrors = [];
        EventLoop::setErrorHandler(function (Throwable $error): void {
            $this->loopErrors[] = $error;
        });
    }

    protected function tearDown(): void
    {
        EventLoop::setErrorHandler(null);
    }

    private function settle(): void
    {
        // Lets queued callbacks and destructors run, so an unhandled future would show up.
        delay(0.02);
    }

    public function testAFailingTaskIsLoggedWithItsNameAndTheLoopKeepsRunning(): void
    {
        $this->tasks->run(static function (): never {
            delay(0.01);

            throw new RuntimeException('it broke');
        }, 'flaky');
        $ran = false;
        $this->tasks->run(static function () use (&$ran): void {
            delay(0.05);
            $ran = true;
        }, 'healthy');

        $this->tasks->awaitAll();
        $this->settle();

        self::assertTrue($ran, 'a task that was started after the failing one must still run');
        self::assertTrue($this->logs->hasErrorThatContains('background task failed'));
        $record = $this->logs->getRecords()[0];
        self::assertSame('flaky', $record->context['task']);
        $exception = $record->context['exception'];
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame('it broke', $exception->getMessage());
        self::assertSame([], $this->loopErrors, 'no unhandled future may reach the event loop');
    }

    public function testATaskThatFailsBeforeEverSuspendingIsLoggedAndDoesNotThrowOutOfRun(): void
    {
        $this->tasks->run(static function (): never {
            throw new RuntimeException('instant failure');
        }, 'instant');
        $this->settle();

        self::assertTrue($this->logs->hasErrorThatContains('background task failed'));
        self::assertSame(0, $this->tasks->count());
        self::assertSame([], $this->loopErrors);
    }

    public function testAnErrorThatIsNotAnExceptionIsLoggedToo(): void
    {
        $this->tasks->run(static function (): never {
            throw new TypeError('wrong type');
        }, 'typed');
        $this->tasks->awaitAll();
        $this->settle();

        $record = $this->logs->getRecords()[0];
        self::assertSame(Level::Error, $record->level);
        self::assertInstanceOf(Error::class, $record->context['exception']);
        self::assertSame([], $this->loopErrors);
    }

    public function testASuccessfulTaskLogsNothing(): void
    {
        $this->tasks->run(static function (): void {
            delay(0.01);
        });
        $this->tasks->awaitAll();
        $this->settle();

        self::assertSame([], $this->logs->getRecords());
    }

    public function testTasksRunConcurrently(): void
    {
        $started = hrtime(true);
        for ($index = 0; $index < 5; $index++) {
            $this->tasks->run(static function (): void {
                delay(0.1);
            });
        }

        $this->tasks->awaitAll();

        $elapsed = (hrtime(true) - $started) / 1e9;
        self::assertLessThan(0.3, $elapsed, 'five 0.1 s tasks should overlap, not add up to 0.5 s');
    }

    public function testCountFollowsTheRunningTasks(): void
    {
        self::assertSame(0, $this->tasks->count());

        $this->tasks->run(static function (): void {
            delay(0.05);
        });
        $this->tasks->run(static function (): void {
            delay(0.05);
        });
        self::assertSame(2, $this->tasks->count());

        $this->tasks->awaitAll();
        self::assertSame(0, $this->tasks->count());
    }

    public function testATaskIsQueuedNotRunAtOnceAndLeavesTheListWhenItFinishes(): void
    {
        $events = new ArrayObject();
        $this->tasks->run(static function () use ($events): void {
            $events->append('ran');
        });

        self::assertCount(0, $events, 'run() must return before the task starts');
        self::assertSame(1, $this->tasks->count());

        $this->tasks->awaitAll();

        self::assertCount(1, $events);
        self::assertSame(0, $this->tasks->count());
    }

    public function testAwaitAllAlsoWaitsForTasksStartedWhileItWaits(): void
    {
        $order = [];
        $this->tasks->run(function () use (&$order): void {
            delay(0.03);
            $this->tasks->run(static function () use (&$order): void {
                delay(0.05);
                $order[] = 'second';
            });
            $order[] = 'first';
        });

        $this->tasks->awaitAll();

        self::assertSame(['first', 'second'], $order);
    }

    public function testAwaitAllReturnsAtOnceWhenNothingIsRunning(): void
    {
        $started = hrtime(true);

        $this->tasks->awaitAll();

        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9, 'it must not wait for anything');
    }

    public function testAwaitAllCanBeCancelled(): void
    {
        $this->tasks->run(static function (): void {
            delay(1.0);
        });

        $this->expectException(CancelledException::class);

        try {
            $this->tasks->awaitAll(new TimeoutCancellation(0.05));
        } finally {
            $this->tasks->cancelAll();
        }
    }

    public function testCancelAllStopsTasksThatHonourTheirCancellationWithoutLoggingAnError(): void
    {
        $finished = 0;
        for ($index = 0; $index < 3; $index++) {
            $this->tasks->run(static function (Cancellation $cancellation) use (&$finished): void {
                delay(5.0, cancellation: $cancellation);
                $finished++;
            }, "waiter-{$index}");
        }

        $this->tasks->cancelAll();
        $this->tasks->awaitAll();
        $this->settle();

        self::assertSame(0, $finished);
        self::assertSame(0, $this->tasks->count());
        self::assertFalse($this->logs->hasErrorRecords(), 'a cancelled task is not a failure');
        self::assertTrue($this->logs->hasDebugThatContains('background task cancelled'));
    }

    public function testTasksStartedAfterCancelAllAreNotAlreadyCancelled(): void
    {
        $this->tasks->cancelAll();
        $result = null;

        $this->tasks->run(static function (Cancellation $cancellation) use (&$result): void {
            delay(0.01);
            $result = $cancellation->isRequested() ? 'cancelled' : 'running';
        });
        $this->tasks->awaitAll();

        self::assertSame('running', $result);
    }
}
