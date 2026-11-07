<?php

declare(strict_types=1);

namespace Raphp\Tests\Supervisor;

use function Amp\async;
use function Amp\delay;

use Amp\TimeoutCancellation;
use Closure;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Process\ChannelFactory;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SelfCommand;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Supervisor\RecyclePolicy;
use Raphp\Supervisor\RestartPolicy;
use Raphp\Supervisor\Routing;
use Raphp\Supervisor\Supervisor;
use Raphp\Supervisor\WorkerDefinition;
use Raphp\Supervisor\WorkerEventType;
use Raphp\Supervisor\WorkerState;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\RecordingDispatcher;
use Raphp\Tests\Support\WorkerFixture;
use Throwable;

/**
 * Supervised workers: real processes, real crashes, real signals (plan P2.10).
 */
#[Group('slow')]
final class SupervisorTest extends AsyncTestCase
{
    private RecordingDispatcher $events;

    private Supervisor $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new RecordingDispatcher();
        $this->supervisor = $this->newSupervisor($this->events);
    }

    protected function tearDown(): void
    {
        try {
            $this->supervisor->stop(0.5);
        } catch (Throwable) {
            // the test may have stopped it already
        }

        parent::tearDown();
    }

    private function newSupervisor(RecordingDispatcher $events): Supervisor
    {
        return new Supervisor(new ProcessSpawner(new ChannelFactory(), new NullLogger()), new NullLogger(), $events);
    }

    /**
     * @param list<string> $extraArguments
     */
    private function define(
        string $name = 'main',
        int $pool = 1,
        array $extraArguments = [],
        ?RestartPolicy $restart = null,
        ?RecyclePolicy $recycle = null,
        Routing $routing = Routing::RoundRobin,
        float $readyTimeout = 5.0,
        float $handshakeTimeout = 10.0,
        float $shutdownGrace = 5.0,
        float $terminateGrace = 2.0,
        ?RpcPeerOptions $rpc = null,
    ): WorkerDefinition {
        $definition = new WorkerDefinition(
            $name,
            (new SelfCommand())->resolve(WorkerFixture::script('worker.php')),
            pool: $pool,
            routing: $routing,
            restart: $restart ?? new RestartPolicy(0.02, 0.2, 5, 60.0),
            recycle: $recycle ?? new RecyclePolicy(),
            rpc: $rpc ?? new RpcPeerOptions(),
            extraArguments: $extraArguments,
            handshakeTimeout: $handshakeTimeout,
            readyTimeout: $readyTimeout,
            shutdownGrace: $shutdownGrace,
            terminateGrace: $terminateGrace,
        );
        $this->supervisor->register($definition);

        return $definition;
    }

    private function startAndWait(string $name = 'main'): void
    {
        $this->supervisor->start();
        $this->supervisor->awaitReady($name, new TimeoutCancellation(15.0));
    }

    /**
     * @param Closure(): mixed $action
     */
    private function thrownBy(Closure $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Expected an exception.');
    }

    /**
     * The pid a worker reports for itself when called.
     */
    private function answeredBy(string $worker = 'main'): int
    {
        $pid = $this->supervisor->call($worker, 'pid', timeout: 10.0);
        self::assertIsInt($pid);

        return $pid;
    }

    private function pid(string $worker = 'main', string $member = 'main'): int
    {
        return $this->supervisor->pids($worker)[$member] ?? 0;
    }

    // ------------------------------------------------------------ start

    public function testAWorkerStartsBecomesReadyAndAnswersCalls(): void
    {
        $this->define();
        $this->startAndWait();

        self::assertSame(['main' => ['main' => WorkerState::Ready]], $this->supervisor->states());
        self::assertSame(['a' => 1], $this->supervisor->call('main', 'echo', ['a' => 1], timeout: 5.0));
        self::assertSame($this->pid(), $this->supervisor->call('main', 'pid', timeout: 5.0));
        self::assertSame([WorkerEventType::Started, WorkerEventType::Ready], $this->events->types());
    }

    public function testEveryMemberOfAPoolIsStartedAndNamedByItsNumber(): void
    {
        $this->define(pool: 3);
        $this->startAndWait();

        self::assertSame(['main-1', 'main-2', 'main-3'], array_keys($this->supervisor->pids('main')));
        self::assertCount(3, array_unique($this->supervisor->pids('main')));
    }

    // ------------------------------------------------------------ crash and restart

    public function testKillingAWorkerFailsItsCallsInFlightWithWorkerCrashedAndItIsRestarted(): void
    {
        $this->define();
        $this->startAndWait();
        $first = $this->pid();
        $slow = async(fn(): mixed => $this->supervisor->call('main', 'sleep', ['seconds' => 20], timeout: 30.0));
        delay(0.1);

        $dies = $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
        $slowError = $this->thrownBy(fn(): mixed => $slow->await());

        self::assertInstanceOf(WorkerCrashedException::class, $dies);
        self::assertInstanceOf(WorkerCrashedException::class, $slowError, 'every call in flight on the dead worker fails');
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Ready) === 2), 'the worker came back');
        self::assertNotSame($first, $this->pid(), 'as a new process');
        self::assertSame('slept', $this->supervisor->call('main', 'sleep', ['seconds' => 0], timeout: 5.0));
        self::assertSame(
            [WorkerEventType::Started, WorkerEventType::Ready, WorkerEventType::Crashed, WorkerEventType::Restarting, WorkerEventType::Started, WorkerEventType::Ready],
            $this->events->types(),
        );
        self::assertStringContainsString('killed by signal 9', $this->events->of(WorkerEventType::Crashed)[0]->detail);
    }

    public function testTheWaitBeforeEachRestartDoublesUpToTheLongest(): void
    {
        $this->define(extraArguments: ['--fail-start'], restart: new RestartPolicy(0.05, 0.4, 100, 60.0));
        $this->supervisor->start();

        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Restarting) >= 6, 10.0));

        $delays = array_map(static fn($event): ?float => $event->delay, array_slice($this->events->of(WorkerEventType::Restarting), 0, 6));
        self::assertSame([0.05, 0.1, 0.2, 0.4, 0.4, 0.4], $delays);
    }

    public function testTooManyCrashesInTheWindowMakeTheWorkerDegradedAndItIsNotRestartedAgain(): void
    {
        $this->define(extraArguments: ['--fail-start'], restart: new RestartPolicy(0.01, 0.05, 3, 30.0));
        $this->supervisor->start();

        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Degraded) === 1, 10.0));
        $started = $this->events->count(WorkerEventType::Started);
        delay(0.4);

        self::assertSame($started, $this->events->count(WorkerEventType::Started), 'no start after degraded');
        self::assertSame(4, $this->events->count(WorkerEventType::Crashed), 'three restarts allowed, the fourth crash is too many');
        self::assertSame(3, $this->events->count(WorkerEventType::Restarting));
        self::assertSame(['main' => ['main' => WorkerState::Degraded]], $this->supervisor->states());
        self::assertStringContainsString('4 crashes within 30', $this->events->of(WorkerEventType::Degraded)[0]->detail);
    }

    public function testACallToADegradedWorkerFailsAtOnceWithoutWaiting(): void
    {
        $this->define(extraArguments: ['--fail-start'], restart: new RestartPolicy(0.01, 0.05, 1, 30.0), readyTimeout: 5.0);
        $this->supervisor->start();
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Degraded) === 1, 10.0));
        $started = hrtime(true);

        $error = $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'echo'));

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
        self::assertStringContainsString('degraded', $error->getMessage());
        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9, 'no waiting for a restart that is not coming');
        self::assertInstanceOf(WorkerUnavailableException::class, $this->thrownBy(fn() => $this->supervisor->awaitReady('main')));
    }

    public function testCrashesOlderThanTheWindowDoNotCount(): void
    {
        $this->define(restart: new RestartPolicy(0.01, 0.05, 1, 0.5));
        $this->startAndWait();

        for ($round = 1; $round <= 3; $round++) {
            $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
            self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Ready) === $round + 1, 10.0));
            delay(0.7); // the crash is now older than the window
        }

        self::assertSame(0, $this->events->count(WorkerEventType::Degraded), 'one crash in each half second never exceeds one restart per window');
    }

    // ------------------------------------------------------------ calls while a worker is not ready

    public function testAWorkerThatNeverSaysHelloIsKilledNotLeftRunning(): void
    {
        $this->define(extraArguments: ['--hang-start'], handshakeTimeout: 0.4, restart: new RestartPolicy(5.0, 5.0, 5, 60.0));
        $this->supervisor->start();

        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Crashed) === 1, 10.0));

        $crashed = $this->events->of(WorkerEventType::Crashed)[0];
        self::assertStringContainsString('did not complete the handshake', $crashed->detail);
        $pid = $this->events->of(WorkerEventType::Started)[0]->pid;
        self::assertNotNull($pid);
        self::assertTrue($this->eventually(fn(): bool => !WorkerFixture::isAlive($pid), 3.0), 'the process that never said hello is gone');
    }

    public function testADeadWorkerIsNotListedAsRunningWhileItWaitsToBeRestarted(): void
    {
        $this->define(restart: new RestartPolicy(3.0, 3.0, 5, 60.0));
        $this->startAndWait();
        $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Restarting) === 1));

        self::assertSame([], $this->supervisor->pids('main'));
        self::assertSame(['main' => ['main' => WorkerState::Crashed]], $this->supervisor->states());
    }

    public function testACallDuringARestartWaitsForTheNewWorkerAndSucceeds(): void
    {
        $this->define(restart: new RestartPolicy(0.4, 0.4, 5, 60.0));
        $this->startAndWait();
        $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
        $started = hrtime(true);

        $result = $this->supervisor->call('main', 'echo', ['after' => 'restart'], timeout: 5.0);

        self::assertSame(['after' => 'restart'], $result);
        self::assertGreaterThan(0.3, (hrtime(true) - $started) / 1e9, 'it waited for the restart delay');
    }

    public function testACallThatWaitsLongerThanTheReadyTimeoutFailsWithWorkerUnavailable(): void
    {
        $this->define(restart: new RestartPolicy(3.0, 3.0, 5, 60.0), readyTimeout: 0.3);
        $this->startAndWait();
        $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
        $started = hrtime(true);

        $error = $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'echo'));
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
        self::assertStringContainsString('within 0.3 seconds', $error->getMessage());
        self::assertGreaterThanOrEqual(0.25, $elapsed);
        self::assertLessThan(1.5, $elapsed);
    }

    // ------------------------------------------------------------ hung workers

    public function testAWorkerThatStopsAnsweringPingsIsKilledAndRestarted(): void
    {
        $this->define(rpc: new RpcPeerOptions(pingInterval: 0.1, missedPings: 3));
        $this->startAndWait();
        $first = $this->pid();
        $this->supervisor->call('main', 'busy', timeout: 5.0);

        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Crashed) === 1, 10.0), 'the silent worker was declared dead');
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Ready) === 2, 10.0));

        self::assertStringContainsString('the connection was lost', $this->events->of(WorkerEventType::Crashed)[0]->detail);
        self::assertFalse(WorkerFixture::isAlive($first), 'the hung process is gone, not left running');
        self::assertNotSame($first, $this->pid());
    }

    // ------------------------------------------------------------ pools

    public function testRoundRobinGivesEachMemberItsTurn(): void
    {
        $this->define(pool: 3);
        $this->startAndWait();

        $pids = [];
        for ($call = 0; $call < 9; $call++) {
            $pids[] = $this->answeredBy();
        }

        $counts = array_count_values($pids);
        self::assertCount(3, $counts);
        self::assertSame([3, 3, 3], array_values($counts));
        self::assertSame(array_slice($pids, 0, 3), array_slice($pids, 3, 3), 'in the same order each round');
    }

    public function testLeastBusyAvoidsTheMemberThatIsBusy(): void
    {
        $this->define(pool: 2, routing: Routing::LeastBusy);
        $this->startAndWait();
        $busy = async(fn(): mixed => $this->supervisor->call('main', 'sleep-pid', ['seconds' => 1.0], timeout: 10.0));
        delay(0.2);

        $pids = [];
        for ($call = 0; $call < 4; $call++) {
            $pids[] = $this->answeredBy();
        }

        self::assertCount(1, array_unique($pids), 'all quick calls went to the one member that was free');
        self::assertCount(2, $this->supervisor->pids('main'));
        $busyPid = $busy->await();
        self::assertIsInt($busyPid);
        self::assertNotSame($busyPid, $pids[0], 'and it was not the member that was busy');
    }

    public function testRoundRobinSkipsAMemberThatIsNotReady(): void
    {
        $this->define(pool: 2, restart: new RestartPolicy(5.0, 5.0, 5, 60.0));
        $this->startAndWait();
        $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0)); // goes to one of the two
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Crashed) === 1));

        $pids = [];
        for ($call = 0; $call < 4; $call++) {
            $pids[] = $this->answeredBy();
        }

        self::assertCount(1, array_unique($pids), 'every call went to the surviving member');
    }

    // ------------------------------------------------------------ recycling

    public function testAWorkerIsReplacedAfterTheConfiguredNumberOfCallsWithoutAnyCallFailing(): void
    {
        $this->define(recycle: new RecyclePolicy(maxCalls: 3));
        $this->startAndWait();
        $first = $this->pid();

        $pids = [];
        for ($call = 0; $call < 8; $call++) {
            $pids[] = $this->answeredBy();
        }

        self::assertGreaterThanOrEqual(2, $this->events->count(WorkerEventType::Recycled));
        self::assertCount(3, array_unique($pids), '8 calls with 3 per worker use three processes');
        self::assertSame($first, $pids[0]);
        self::assertSame(0, $this->events->count(WorkerEventType::Crashed), 'recycling is not a crash');
        self::assertStringContainsString('3 calls served', $this->events->of(WorkerEventType::Recycled)[0]->detail);
    }

    public function testRecyclingLetsTheCallsInFlightFinishFirst(): void
    {
        $this->define(recycle: new RecyclePolicy(maxCalls: 2));
        $this->startAndWait();
        $long = async(function (): float {
            $result = $this->supervisor->call('main', 'sleep', ['seconds' => 0.8], timeout: 10.0);
            self::assertSame('slept', $result, 'the call that was running was not cut off');

            return hrtime(true) / 1e9;
        });
        delay(0.1);

        $this->answeredBy();
        $this->answeredBy(); // the second finished call: the limit is reached
        $finishedAt = $long->await();

        self::assertIsFloat($finishedAt);
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Recycled) === 1));
        $index = array_search(WorkerEventType::Recycled, $this->events->types(), true);
        self::assertIsInt($index);
        self::assertGreaterThanOrEqual($finishedAt - 0.05, $this->events->times[$index], 'the replacement happened after the long call finished');
    }

    public function testAWorkerThatGrowsTooLargeIsReplaced(): void
    {
        $this->define(recycle: new RecyclePolicy(maxMemoryBytes: 70_000_000));
        $this->startAndWait();
        $first = $this->pid();

        $this->supervisor->call('main', 'bloat', ['bytes' => 100_000_000], timeout: 20.0);

        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Recycled) === 1, 10.0));
        self::assertStringContainsString('bytes of memory', $this->events->of(WorkerEventType::Recycled)[0]->detail);
        self::assertTrue($this->eventually(fn(): bool => $this->pid() !== $first && ($this->supervisor->states()['main']['main'] ?? null) === WorkerState::Ready, 10.0));
    }

    // ------------------------------------------------------------ stopping

    public function testACooperativeWorkerIsStoppedWithByeAndNothingStrongerAndEverythingIsGone(): void
    {
        $this->define(pool: 2);
        $this->startAndWait();
        $pids = $this->supervisor->pids('main');

        $this->supervisor->stop(5.0);

        self::assertSame(2, $this->events->count(WorkerEventType::Draining));
        self::assertSame(2, $this->events->count(WorkerEventType::Exited));
        self::assertSame(0, $this->events->count(WorkerEventType::Terminating));
        self::assertSame(0, $this->events->count(WorkerEventType::Killing));
        foreach ($pids as $pid) {
            self::assertFalse(WorkerFixture::isAlive($pid));
        }

        self::assertSame(['main' => ['main-1' => WorkerState::Exited, 'main-2' => WorkerState::Exited]], $this->supervisor->states());
        self::assertSame([], $this->supervisor->pids('main'), 'no member is running any more');
    }

    public function testAStubbornWorkerIsAskedThenTerminatedThenKilledInThatOrder(): void
    {
        $marker = sys_get_temp_dir() . '/raphp-term-' . bin2hex(random_bytes(4));
        $this->define(extraArguments: ['--ignore-eof'], terminateGrace: 0.3);
        $this->startAndWait();
        $pid = $this->pid();
        $this->supervisor->call('main', 'record-term', ['path' => $marker], timeout: 5.0);
        $started = hrtime(true);

        $this->supervisor->stop(0.3);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(
            [WorkerEventType::Draining, WorkerEventType::Terminating, WorkerEventType::Killing, WorkerEventType::Exited],
            array_slice($this->events->types(), 2),
        );
        self::assertGreaterThanOrEqual(0.55, $elapsed, 'it was given the grace after bye and the grace after SIGTERM');
        self::assertFalse(WorkerFixture::isAlive($pid));
        self::assertSame('sigterm', @file_get_contents($marker), 'the worker really received SIGTERM before it was killed');
        @unlink($marker);
    }

    public function testStoppingLetsACallInFlightFinish(): void
    {
        $this->define();
        $this->startAndWait();
        $call = async(fn(): mixed => $this->supervisor->call('main', 'sleep', ['seconds' => 0.5], timeout: 10.0));
        delay(0.1);

        $this->supervisor->stop(5.0);

        self::assertSame('slept', $call->await(), 'the call that was running was finished, then the worker was told bye');
        self::assertSame(1, $this->events->count(WorkerEventType::Exited));
    }

    public function testAfterStopCallsFailAndNoWorkerRestarts(): void
    {
        $this->define();
        $this->startAndWait();
        $this->supervisor->stop(5.0);
        $started = hrtime(true);

        $error = $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'echo'));

        self::assertInstanceOf(WorkerUnavailableException::class, $error);
        self::assertStringContainsString('shutting down', $error->getMessage());
        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9, 'it must not wait for a worker that will never come back');
        self::assertSame(1, $this->events->count(WorkerEventType::Started));
    }

    public function testAWorkerInTheMiddleOfARestartWaitDoesNotRestartWhenStopped(): void
    {
        $this->define(restart: new RestartPolicy(5.0, 5.0, 5, 60.0));
        $this->startAndWait();
        $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'die', timeout: 10.0));
        self::assertTrue($this->eventually(fn(): bool => $this->events->count(WorkerEventType::Restarting) === 1));
        $started = hrtime(true);

        $this->supervisor->stop(1.0);

        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9, 'it did not wait out the five second restart delay');
        self::assertSame(1, $this->events->count(WorkerEventType::Started), 'and did not start again');
    }

    // ------------------------------------------------------------ misuse and events

    public function testWorkersMustBeDeclaredBeforeStartAndNamesAreUnique(): void
    {
        $this->define();

        try {
            $this->define();
            self::fail('Expected LogicException for a second worker of the same name.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }

        $this->supervisor->start();

        try {
            $this->define('late');
            self::fail('Expected LogicException for a worker declared after start.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $this->supervisor->start();
    }

    public function testACallToAWorkerThatWasNeverDeclaredIsAProgrammingError(): void
    {
        $this->define();
        $this->startAndWait();

        $this->expectException(InvalidArgumentException::class);

        $this->supervisor->call('nobody', 'echo');
    }

    public function testAListenerThatThrowsDoesNotDisturbTheSupervision(): void
    {
        $events = new RecordingDispatcher(failing: true);
        $this->supervisor = $this->newSupervisor($events);
        $this->define();
        $this->startAndWait();

        self::assertSame(['a' => 1], $this->supervisor->call('main', 'echo', ['a' => 1], timeout: 5.0));
        self::assertSame([WorkerEventType::Started, WorkerEventType::Ready], $events->types(), 'the events were still delivered');
    }

    public function testACallTimeoutIsTheCallersOwnAndLeavesTheWorkerRunning(): void
    {
        $this->define();
        $this->startAndWait();

        $error = $this->thrownBy(fn(): mixed => $this->supervisor->call('main', 'sleep', ['seconds' => 5], timeout: 0.2));

        self::assertInstanceOf(RequestTimeoutException::class, $error);
        self::assertSame('main', array_key_first($this->supervisor->pids('main')));
        self::assertSame(0, $this->events->count(WorkerEventType::Crashed));
    }
}
