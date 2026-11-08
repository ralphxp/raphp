<?php

declare(strict_types=1);

namespace Raphp\Tests\Ui\Ffi;

use FFI;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\ShimEventRecorder;
use Raphp\Tests\Support\ShimFixture;
use Raphp\Ui\Ffi\Internal\Native;
use Raphp\Ui\Ffi\Library;
use Raphp\Ui\Ffi\Shim;
use Raphp\Ui\Ffi\ShimClosedException;
use Raphp\Ui\Ffi\ShimEvent;
use Raphp\Ui\Ffi\ShimException;
use Raphp\Ui\Ffi\ShimPoisonedException;
use Revolt\EventLoop;

/**
 * The PHP side of the shim, driving the real library (headless) through the real event loop (plan P3.5).
 */
final class ShimTest extends AsyncTestCase
{
    private TestHandler $logs;

    private Library $library;

    /** @var list<Shim> */
    private array $shims = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = new TestHandler();
        $this->library = ShimFixture::library(new Logger('test', [$this->logs]));
        $this->shims = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->shims as $shim) {
            $shim->close();
        }

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function start(array $configuration = []): Shim
    {
        $shim = $this->library->start($configuration);
        $this->shims[] = $shim;

        return $shim;
    }

    public function testABatchIsAppliedAndItsEventsReachTheListenerInOrderThroughTheLoop(): void
    {
        $shim = $this->start();
        $recorder = new ShimEventRecorder($shim);

        $batch = $shim->send([
            ['op' => 'window.create', 'id' => 1, 'title' => 'Demo'],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => 'Hi']],
            ['op' => 'widget.set', 'id' => 99, 'props' => ['text' => 'nobody']],
        ]);
        $events = $recorder->until(ShimEventRecorder::batchDone($batch));

        self::assertSame(1, $batch);
        self::assertSame(['error', 'batch.done'], array_map(static fn(ShimEvent $event): string => $event->type, $events));
        self::assertSame('unknown_widget', $events[0]->fields['code']);
        self::assertSame(2, $events[0]->fields['index']);
        self::assertEquals(['type' => 'batch.done', 'batch' => 1, 'applied' => 2, 'failed' => 1], $events[1]->fields, 'the key order of the shim\'s JSON does not matter');
    }

    public function testBatchIdsCountUpAndOnlyAcceptedBatchesUseOne(): void
    {
        $shim = $this->start();

        self::assertSame(1, $shim->send([]));
        try {
            $shim->send([['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => "\xff"]]]);
            self::fail('a batch that cannot be encoded must not be sent');
        } catch (ShimException $exception) {
            self::assertStringContainsString('cannot be encoded', $exception->getMessage());
        }

        self::assertSame(2, $shim->send([]), 'the refused batch did not take an id');
        self::assertSame(3, $shim->send([]));
    }

    public function testEveryListenerGetsEveryEventAndOneThatThrowsIsLoggedAndHarmless(): void
    {
        $shim = $this->start();
        $shim->listen(static function (): never {
            throw new \RuntimeException('listener bug');
        });
        $second = [];
        $shim->listen(static function (ShimEvent $event) use (&$second): void {
            $second[] = $event->type;
        });
        $recorder = new ShimEventRecorder($shim);

        $first = $shim->send([['op' => 'widget.set', 'id' => 5, 'props' => []]]);
        $recorder->until(ShimEventRecorder::batchDone($first));

        self::assertSame(['error', 'batch.done'], $second);
        self::assertTrue($this->logs->hasErrorThatContains('a shim event listener failed'));
        self::assertSame([], $this->loopErrors, 'nothing leaked into the loop');
    }

    public function testCloseStopsWatchingAndFreesTheDescriptorAndLaterCallsAreClosedErrors(): void
    {
        $before = ShimFixture::descriptors();
        $shim = $this->start();
        $shim->listen(static function (): void {});
        $watchers = count(EventLoop::getIdentifiers());

        $shim->close();
        $shim->close(); // twice is fine

        self::assertTrue($shim->isClosed());
        self::assertLessThan($watchers, count(EventLoop::getIdentifiers()), 'the watcher is gone');
        self::assertSame([], $shim->drain());
        $this->expectException(ShimClosedException::class);
        try {
            $shim->send([]);
        } finally {
            self::assertCount(count($before), ShimFixture::descriptors(), 'no descriptor was left open');
        }
    }

    public function testAShimThatIsForgottenIsStoppedAndItsWatcherRemoved(): void
    {
        $before = ShimFixture::descriptors();
        $watchers = count(EventLoop::getIdentifiers());

        $shim = $this->library->start();
        $shim->listen(static function (): void {});
        self::assertGreaterThan($watchers, count(EventLoop::getIdentifiers()));
        unset($shim);

        self::assertSame($watchers, count(EventLoop::getIdentifiers()), 'the loop did not keep it alive');
        self::assertCount(count($before), ShimFixture::descriptors());
    }

    public function testAppQuitMakesLaterSendsAClosedErrorWithTheShimsReason(): void
    {
        $shim = $this->start();
        $recorder = new ShimEventRecorder($shim);
        $batch = $shim->send([['op' => 'app.quit']]);
        $recorder->until(ShimEventRecorder::batchDone($batch));

        $deadline = hrtime(true) + 5_000_000_000;
        while (hrtime(true) < $deadline) {
            try {
                $shim->send([]);
                usleep(5_000);
            } catch (ShimClosedException $exception) {
                self::assertStringContainsString('stopped taking commands', $exception->getMessage());
                self::assertSame(Native::STATUS_CLOSED, $exception->status);

                return;
            }
        }

        self::fail('the shim never stopped taking commands');
    }

    public function testAFailedShimDeliversItsLastEventAndThenRefusesSendsAsPoisoned(): void
    {
        $handle = $this->startRaw();
        [$native, $cdata, $shim] = $handle;
        $recorder = new ShimEventRecorder($shim);

        $native->send($cdata, '#poison the window system failed');
        $events = $recorder->until(ShimEventRecorder::ofType('shim.failed'));

        self::assertSame('the window system failed', $events[0]->fields['reason']);
        try {
            $shim->send([]);
            self::fail('a poisoned shim must refuse commands');
        } catch (ShimPoisonedException $exception) {
            self::assertStringContainsString('the window system failed', $exception->getMessage());
        }
    }

    public function testAnEventTooLargeForTheUsualBufferArrivesWholeAndTheNextOneBehindIt(): void
    {
        [$native, $cdata, $shim] = $this->startRaw();
        $recorder = new ShimEventRecorder($shim);

        $native->send($cdata, '#emit-large 200000');
        $native->send($cdata, '#emit {"type":"after"}');
        $events = $recorder->until(ShimEventRecorder::ofType('after'));

        self::assertSame(['large', 'after'], array_map(static fn(ShimEvent $event): string => $event->type, $events));
        self::assertGreaterThan(200_000, strlen(json_encode($events[0]->fields, JSON_THROW_ON_ERROR)));
    }

    public function testASmallEventsInTheSamePollAreAllDelivered(): void
    {
        [$native, $cdata, $shim] = $this->startRaw();
        for ($number = 1; $number <= 50; $number++) {
            $native->send($cdata, '#emit {"type":"n","n":' . $number . '}');
        }

        $deadline = hrtime(true) + 5_000_000_000;
        $numbers = [];
        while (count($numbers) < 50 && hrtime(true) < $deadline) {
            foreach ($shim->drain() as $event) {
                $numbers[] = $event->fields['n'];
            }

            usleep(2_000);
        }

        self::assertSame(range(1, 50), $numbers, 'in order, none lost');
    }

    public function testTheHeadlessClockFramesAndDiagnosticsAreReachableFromPhp(): void
    {
        $shim = $this->start(['refresh_hz' => 100, 'scale' => 2]);
        $shim->send([
            ['op' => 'window.create', 'id' => 1, 'width' => 40, 'height' => 30],
            ['op' => 'widget.create', 'id' => 1, 'type' => 'label', 'props' => ['text' => 'a']],
            ['op' => 'window.set_root', 'id' => 1, 'widget' => 1],
        ]);
        $shim->advance();

        $frame = $shim->frame(1);

        self::assertSame([80, 60], [$frame->width, $frame->height]);
        self::assertSame(80 * 60 * 4, strlen($frame->pixels));
        self::assertSame("\xff\xff\xff\xff", substr($frame->pixels, 0, 4));
        $diagnostics = $shim->diagnostics();
        self::assertSame(1, $diagnostics['batches']);
        self::assertSame(['1' => ['redraws_requested' => 1, 'frames_drawn' => 1]], $diagnostics['windows']);
        try {
            $shim->frame(9);
            self::fail('there is no window 9');
        } catch (ShimException $exception) {
            self::assertSame(Native::STATUS_INVALID_ARGUMENT, $exception->status);
        }
    }

    public function testABadConfigurationIsAStartErrorWithTheShimsReason(): void
    {
        $this->assertStartFails(['backend' => 'window'], 'not built yet');
    }

    public function testAnUnknownSettingIsAStartErrorToo(): void
    {
        $this->assertStartFails(['refesh_hz' => 60], 'refesh_hz');
    }

    public function testTheTestsRunWithoutAnyDeprecationOrWarning(): void
    {
        $previous = error_reporting(E_ALL);
        $problems = [];
        set_error_handler(static function (int $level, string $message) use (&$problems): bool {
            $problems[] = $message;

            return true;
        });

        try {
            $shim = $this->start();
            $recorder = new ShimEventRecorder($shim);
            $recorder->until(ShimEventRecorder::batchDone($shim->send([['op' => 'window.create', 'id' => 1]])));
            $shim->advance();
            $shim->frame(1);
            $shim->diagnostics();
            $shim->close();
        } finally {
            restore_error_handler();
            error_reporting($previous);
        }

        self::assertSame([], $problems);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function assertStartFails(array $configuration, string $reason): void
    {
        try {
            $this->library->start($configuration);
            self::fail('the shim should not have started');
        } catch (ShimException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    /**
     * A shim started through the library's internals, so that the tests can use the commands only the test build has.
     *
     * @return array{Native, \FFI\CData, Shim}
     */
    private function startRaw(): array
    {
        $header = file_get_contents(ShimFixture::HEADER);
        self::assertNotFalse($header);
        $native = new Native(FFI::cdef($header, ShimFixture::LIBRARY));
        $cdata = $native->init('');
        self::assertNotNull($cdata);
        $shim = new Shim($native, $cdata, new Logger('test', [$this->logs]));
        $this->shims[] = $shim;

        return [$native, $cdata, $shim];
    }
}
