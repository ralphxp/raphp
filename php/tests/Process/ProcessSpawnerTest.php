<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use function Amp\async;

use Amp\CancelledException;

use function Amp\delay;

use Amp\TimeoutCancellation;
use Closure;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Raphp\Error\WorkerCrashedException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\ChannelFactory;
use Raphp\Process\OrphanGuard;
use Raphp\Process\OutputCapture;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SelfCommand;
use Raphp\Process\SpawnException;
use Raphp\Process\WorkerHandle;
use Raphp\Process\WorkerSpec;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\WorkerFixture;
use Throwable;

/**
 * Real worker processes, started by the real spawner (plan P2.9).
 */
#[Group('slow')]
final class ProcessSpawnerTest extends AsyncTestCase
{
    private TestHandler $logs;

    private ProcessSpawner $spawner;

    /** @var list<WorkerHandle> */
    private array $handles = [];

    /** @var list<RpcPeer> */
    private array $peers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = new TestHandler();
        $this->spawner = new ProcessSpawner(new ChannelFactory(), new Logger('test', [$this->logs]));
        $this->handles = [];
        $this->peers = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->peers as $peer) {
            $peer->close();
        }

        foreach ($this->handles as $handle) {
            $handle->kill();

            try {
                $handle->waitForExit(new TimeoutCancellation(3.0));
            } catch (CancelledException) {
                // nothing more can be done
            }

            $handle->close();
        }

        parent::tearDown();
    }

    /**
     * Starts a worker and waits for its hello, so it has finished starting up.
     *
     * @return array{WorkerHandle, RpcPeer}
     */
    private function start(?WorkerSpec $spec = null, ?ProcessSpawner $spawner = null): array
    {
        $handle = ($spawner ?? $this->spawner)->spawn($spec ?? WorkerFixture::spec());
        $this->handles[] = $handle;
        $peer = new RpcPeer($handle->transport, new JsonLineCodec(), new EnvelopeReader(), new NullLogger(), new HandlerRegistry(), options: new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'test', posix_getpid()));
        $this->peers[] = $peer;
        $peer->start();
        $peer->awaitHandshake(new TimeoutCancellation(10.0));

        return [$handle, $peer];
    }

    /**
     * @param Closure(): bool $condition
     */
    private function waitUntil(Closure $condition, float $seconds = 3.0): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        while (!$condition()) {
            if (hrtime(true) > $deadline) {
                return false;
            }

            delay(0.01);
        }

        return true;
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

    // ------------------------------------------------------------ hello, call, output

    public function testAWorkerSaysHelloAndAnswersCalls(): void
    {
        [$handle, $peer] = $this->start();

        $hello = $peer->remote();
        self::assertNotNull($hello);
        self::assertSame('worker', $hello->role);
        self::assertSame('w1', $hello->name);
        self::assertSame($handle->pid, $hello->pid);
        self::assertSame(['a' => 1], $peer->call('echo', ['a' => 1], timeout: 5.0));
        self::assertSame($handle->pid, $peer->call('pid', timeout: 5.0));
    }

    public function testTheWorkerGetsTheStandardCommandLineAndTheExtraArguments(): void
    {
        [, $peer] = $this->start(WorkerFixture::spec('alpha', extraArguments: ['--flag=x']));

        $argv = $peer->call('argv', timeout: 5.0);

        self::assertIsArray($argv);
        self::assertContains('--role=worker', $argv);
        self::assertContains('--name=alpha', $argv);
        self::assertContains('--parent-pid=' . posix_getpid(), $argv);
        self::assertContains('--flag=x', $argv);
        self::assertNotContains('--no-pdeathsig', $argv);
    }

    public function testTurningTheDeathSignalOffIsPassedOnToTheWorker(): void
    {
        [, $peer] = $this->start(WorkerFixture::spec(armDeathSignal: false));

        self::assertContains('--no-pdeathsig', (array) $peer->call('argv', timeout: 5.0));
    }

    public function testWhatAWorkerWritesToStdoutAndStderrIsCapturedAndLogged(): void
    {
        [$handle, $peer] = $this->start();

        $peer->call('say', ['out' => 'hello from stdout', 'err' => 'hello from stderr'], timeout: 5.0);

        self::assertTrue($this->waitUntil(fn(): bool => str_contains($handle->output('stdout'), 'hello from stdout') && str_contains($handle->output('stderr'), 'hello from stderr')));
        $lines = array_filter($this->logs->getRecords(), static fn($record): bool => $record->message === 'worker output');
        $byStream = [];
        foreach ($lines as $record) {
            $stream = $record->context['stream'] ?? null;
            $line = $record->context['line'] ?? null;
            $worker = $record->context['worker'] ?? null;
            if (is_string($stream) && is_string($line) && is_string($worker)) {
                $byStream[$stream] = [$record->level->getName(), $line, $worker];
            }
        }

        self::assertSame(['INFO', 'hello from stdout', 'w1'], $byStream['stdout'] ?? null);
        self::assertSame(['WARNING', 'hello from stderr', 'w1'], $byStream['stderr'] ?? null);
    }

    public function testTheWorkerGetsTheMastersEnvironmentPlusWhatTheSpecAdds(): void
    {
        [, $peer] = $this->start(WorkerFixture::spec(environment: ['RAPHP_TEST_VALUE' => '42']));

        self::assertSame('42', $peer->call('env', ['name' => 'RAPHP_TEST_VALUE'], timeout: 5.0));
        self::assertSame(getenv('PATH'), $peer->call('env', ['name' => 'PATH'], timeout: 5.0), 'the rest of the environment is inherited');
    }

    public function testTheWorkerStartsInTheDirectoryTheSpecNames(): void
    {
        [, $peer] = $this->start(WorkerFixture::spec(cwd: '/tmp'));

        self::assertSame('/tmp', $peer->call('cwd', timeout: 5.0));
    }

    public function testTheWorkersStdinIsEmptySoReadingItNeverBlocks(): void
    {
        [, $peer] = $this->start();

        self::assertSame('', $peer->call('stdin', timeout: 5.0));
    }

    public function testStartingAWorkerIsLoggedWithItsNameAndPid(): void
    {
        [$handle] = $this->start();

        $started = array_values(array_filter($this->logs->getRecords(), static fn($record): bool => $record->message === 'worker started'));

        self::assertCount(1, $started);
        self::assertSame(['worker' => 'w1', 'pid' => $handle->pid], $started[0]->context);
    }

    public function testALastLineWithoutANewlineIsStillLoggedWhenTheOutputEnds(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('raw', ['out' => "first\nno newline at the end"], timeout: 5.0);
        $peer->call('exit', ['code' => 0], timeout: 5.0);

        $handle->waitForExit(new TimeoutCancellation(5.0));

        $lines = array_map(static fn($record): mixed => $record->context['line'] ?? null, array_filter($this->logs->getRecords(), static fn($record): bool => $record->message === 'worker output'));
        self::assertSame(['first', 'no newline at the end'], array_values($lines));
    }

    public function testCarriageReturnsAreStrippedAndBlankLinesAreNotLogged(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('raw', ['out' => "one\r\n\r\n\ntwo\r\n"], timeout: 5.0);
        $peer->call('exit', ['code' => 0], timeout: 5.0);

        $handle->waitForExit(new TimeoutCancellation(5.0));

        $lines = array_map(static fn($record): mixed => $record->context['line'] ?? null, array_filter($this->logs->getRecords(), static fn($record): bool => $record->message === 'worker output'));
        self::assertSame(['one', 'two'], array_values($lines));
    }

    public function testALineThatNeverEndsIsLoggedInPiecesNotBufferedWithoutEnd(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('raw', ['out' => str_repeat('y', 30_000)], timeout: 5.0);
        $peer->call('exit', ['code' => 0], timeout: 5.0);

        $handle->waitForExit(new TimeoutCancellation(5.0));

        $lengths = array_map(static fn($record): int => is_string($record->context['line'] ?? null) ? strlen($record->context['line']) : 0, array_filter($this->logs->getRecords(), static fn($record): bool => $record->message === 'worker output'));
        self::assertGreaterThanOrEqual(2, count($lengths), 'a 30 KB line must come out in pieces');
        foreach ($lengths as $length) {
            self::assertLessThanOrEqual(OutputCapture::MAX_LINE_BYTES, $length);
        }

        self::assertSame(30_000, strlen($handle->output()), 'and all of it is kept in the recent output');
    }

    public function testAWorkerThatWritesMoreThanAPipeHoldsNeverStallsBecauseTheMasterAlwaysReads(): void
    {
        [$handle, $peer] = $this->start(spawner: new ProcessSpawner(new ChannelFactory(), new NullLogger()));

        $before = memory_get_usage();
        $written = $peer->call('flood', ['bytes' => 5_000_000], timeout: 20.0);

        self::assertSame(5_000_000, $written, 'the worker finished writing 5 MB, 75 times what a pipe holds');
        self::assertTrue($this->waitUntil(fn(): bool => strlen($handle->output()) >= OutputCapture::KEEP_BYTES));
        self::assertLessThanOrEqual(OutputCapture::KEEP_BYTES, strlen($handle->output()), 'only the most recent output is kept');
        self::assertLessThan(2_000_000, memory_get_usage() - $before, 'and 5 MB of output must not stay in memory');
        self::assertStringEndsWith(str_repeat('x', 99) . "\n", $handle->output());
    }

    // ------------------------------------------------------------ acceptance 1 and 4: descriptors and parent

    public function testTheWorkerHoldsNoSocketThatTheMasterHoldsSoItNeverInheritsTheMastersEnd(): void
    {
        [$handle] = $this->start();

        $workerSockets = WorkerFixture::socketInodes($handle->pid);
        $masterSockets = WorkerFixture::socketInodes(posix_getpid());

        self::assertNotSame([], $workerSockets, 'the worker has its own end of the channel');
        self::assertNotSame([], $masterSockets, 'and the master has its end');
        self::assertSame([], array_values(array_intersect($workerSockets, $masterSockets)), 'but no socket is held by both');
    }

    public function testTheWorkersOwnViewOfItsDescriptorsHoldsOneChannelAndNoOtherWorkersEnd(): void
    {
        [, $first] = $this->start(WorkerFixture::spec('first'));
        [$secondHandle] = $this->start(WorkerFixture::spec('second'));

        $firstPid = $first->call('pid', timeout: 5.0);
        self::assertIsInt($firstPid);
        $firstSockets = WorkerFixture::socketInodes($firstPid);
        $secondSockets = WorkerFixture::socketInodes($secondHandle->pid);

        self::assertSame([], array_values(array_intersect($firstSockets, $secondSockets)), 'two workers share no socket');
    }

    public function testTheWorkersParentIsTheMasterAndNotAShell(): void
    {
        [$handle] = $this->start();

        self::assertSame(posix_getpid(), WorkerFixture::parentPidOf($handle->pid));
    }

    // ------------------------------------------------------------ acceptance 2 (first half): no cross-inheritance

    public function testClosingOneWorkersEndGivesItEndOfInputWhileTheOtherKeepsRunning(): void
    {
        [$a, $peerA] = $this->start(WorkerFixture::spec('a', armDeathSignal: false));
        [$b, $peerB] = $this->start(WorkerFixture::spec('b', armDeathSignal: false));

        $peerA->close();
        $statusA = $a->waitForExit(new TimeoutCancellation(5.0));

        self::assertTrue($statusA->isSuccess(), 'A saw end of input and ended cleanly: ' . $statusA->describe());
        self::assertTrue($b->isRunning(), 'B is not affected by A closing');
        self::assertSame($b->pid, $peerB->call('pid', timeout: 5.0));
    }

    // ------------------------------------------------------------ acceptance 5: SIGTERM, the guard, busy workers

    public function testSigtermEndsAWorker(): void
    {
        [$handle] = $this->start();

        $handle->terminate();
        $status = $handle->waitForExit(new TimeoutCancellation(3.0));

        self::assertSame(SIGTERM, $status->signal);
    }

    public function testAWorkerStartedWithAParentPidThatIsNotItsParentExitsAtOnce(): void
    {
        $handle = $this->spawner->spawn(WorkerFixture::spec(parentPid: 1));
        $this->handles[] = $handle;

        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertSame(OrphanGuard::EXIT_PARENT_MISMATCH, $status->code);
    }

    public function testAnIdleWorkerWithASigtermHandlerEndsWhenTheHandlerRuns(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('handler-term', timeout: 5.0);

        $handle->terminate();
        $status = $handle->waitForExit(new TimeoutCancellation(3.0));

        self::assertSame(0, $status->code, 'the handler ran on the idle loop and ended the worker');
    }

    public function testABusyWorkerWithASigtermHandlerDoesNotEndOnSigtermAndNeedsSigkill(): void
    {
        // Recorded behaviour (FRAMEWORK 5.6, rule 3): a signal handler of the event loop only runs when the
        // loop gets a turn. A worker stuck in a busy loop never gets there, so SIGTERM does nothing.
        [$handle, $peer] = $this->start();
        self::assertSame('busy', $peer->call('busy', timeout: 5.0));
        delay(0.3);

        $handle->terminate();
        delay(0.7);

        self::assertTrue($handle->isRunning(), 'SIGTERM did not end a worker whose loop is busy');
        $handle->kill();
        self::assertSame(SIGKILL, $handle->waitForExit(new TimeoutCancellation(3.0))->signal);
    }

    // ------------------------------------------------------------ stop, exit status, reaping

    public function testStopEndsACooperativeWorkerWithSigterm(): void
    {
        [$handle] = $this->start();

        $status = $handle->stop(5.0);

        self::assertSame(SIGTERM, $status->signal);
    }

    public function testStopKillsAWorkerThatIgnoresSigtermAfterTheGraceTime(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('ignore-term', timeout: 5.0);
        $started = hrtime(true);

        $status = $handle->stop(0.3);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(SIGKILL, $status->signal);
        self::assertGreaterThanOrEqual(0.25, $elapsed, 'it waited the grace time first');
        self::assertLessThan(3.0, $elapsed);
        self::assertTrue($this->logs->hasWarningThatContains('did not end after SIGTERM'));
    }

    public function testAnExitCodeIsCollected(): void
    {
        [$handle, $peer] = $this->start();
        $peer->call('exit', ['code' => 7], timeout: 5.0);

        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertSame(7, $status->code);
        self::assertNull($status->signal);
        self::assertFalse($status->isSuccess());
        self::assertSame('exited with code 7', $status->describe());
    }

    public function testASignalThatKilledTheWorkerIsCollected(): void
    {
        [$handle] = $this->start();

        $handle->kill();
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertSame(SIGKILL, $status->signal);
        self::assertNull($status->code);
        self::assertSame('killed by signal 9', $status->describe());
    }

    public function testWhenTheMasterClosesTheChannelTheWorkerSeesEndOfInputAndEndsCleanly(): void
    {
        [$handle, $peer] = $this->start();

        $peer->close();
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertTrue($status->isSuccess());
    }

    public function testWhenTheWorkerDiesTheMasterSeesEndOfInputAtOnceAndCallsWaitingForItFail(): void
    {
        // If the master kept a copy of the worker's end, it would never see the worker go, and this call would hang.
        [$handle, $peer] = $this->start();
        $peer->call('busy', timeout: 5.0);
        delay(0.3); // by now the worker's loop is stuck and will answer nothing more
        $waiting = async(fn(): mixed => $peer->call('echo', ['x' => 1], timeout: 20.0));
        delay(0.2);
        $started = hrtime(true);

        $handle->kill();
        $error = $this->thrownBy(fn(): mixed => $waiting->await());

        self::assertInstanceOf(WorkerCrashedException::class, $error);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertTrue($peer->isClosed());
    }

    public function testClosingTheHandleClosesTheChannelAndTheWorkerEndsCleanly(): void
    {
        [$handle] = $this->start();

        $handle->close();
        $status = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertTrue($status->isSuccess(), 'the worker saw end of input: ' . $status->describe());
    }

    public function testWaitForExitNoticesAnEndPromptlyEvenAfterWaitingALongTime(): void
    {
        [$handle] = $this->start();
        $killedAt = null;
        $killer = async(static function () use ($handle, &$killedAt): void {
            delay(1.5);
            $killedAt = hrtime(true);
            $handle->kill();
        });

        $handle->waitForExit(new TimeoutCancellation(10.0));
        $noticedAt = hrtime(true);
        $killer->await();

        self::assertNotNull($killedAt);
        self::assertLessThan(0.15, ($noticedAt - $killedAt) / 1e9, 'polling must not slow down without limit while a worker runs');
    }

    public function testAnEndedWorkerIsReapedSoNoZombieIsLeftBehind(): void
    {
        [$handle] = $this->start();
        $pid = $handle->pid;

        $handle->kill();
        $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertFileDoesNotExist("/proc/{$pid}/stat", 'the process must be gone, not a zombie');
    }

    public function testTheStatusIsKeptAndSignalsToAnEndedWorkerReturnFalse(): void
    {
        [$handle] = $this->start();
        $handle->kill();
        $first = $handle->waitForExit(new TimeoutCancellation(5.0));

        self::assertFalse($handle->isRunning());
        self::assertFalse($handle->kill());
        self::assertFalse($handle->terminate());
        self::assertSame($first, $handle->exitStatus());
        self::assertSame($first, $handle->waitForExit());
    }

    public function testWaitingForExitCanBeCancelledAndLeavesTheWorkerRunning(): void
    {
        [$handle] = $this->start();

        $error = $this->thrownBy(fn() => $handle->waitForExit(new TimeoutCancellation(0.1)));

        self::assertInstanceOf(CancelledException::class, $error);
        self::assertTrue($handle->isRunning());
        self::assertNull($handle->exitStatus());
    }

    public function testAProcessThatKeepsTheOutputPipeOpenDoesNotHoldUpWaitForExitForLong(): void
    {
        [$handle, $peer] = $this->start();
        $grandchild = $peer->call('fork-holder', timeout: 5.0);
        self::assertIsInt($grandchild);
        $peer->call('exit', ['code' => 0], timeout: 5.0);
        $started = hrtime(true);

        $status = $handle->waitForExit(new TimeoutCancellation(10.0));
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertTrue($status->isSuccess());
        self::assertGreaterThan(0.8, $elapsed, 'the output is given a second to finish');
        self::assertLessThan(2.5, $elapsed, 'but no longer');
        self::assertTrue($this->logs->hasWarningThatContains('another process still holds the output'));
        posix_kill($grandchild, SIGKILL);
    }

    // ------------------------------------------------------------ spawn failures

    public function testAnExecutableThatDoesNotExistIsASpawnErrorWithTheReasonFromTheSystem(): void
    {
        $error = $this->thrownBy(fn() => $this->spawner->spawn(new WorkerSpec('ghost', ['/nonexistent/raphp-no-such-program'])));

        self::assertInstanceOf(SpawnException::class, $error);
        self::assertStringContainsString('"ghost"', $error->getMessage());
        self::assertStringContainsString('No such file or directory', $error->getMessage());
    }

    public function testADirectoryThatDoesNotExistIsASpawnError(): void
    {
        $spec = new WorkerSpec('lost', (new SelfCommand())->resolve(WorkerFixture::script('worker.php')), cwd: '/nonexistent/raphp-no-such-directory');

        $error = $this->thrownBy(fn() => $this->spawner->spawn($spec));

        self::assertInstanceOf(SpawnException::class, $error);
        self::assertStringContainsString('lost', $error->getMessage());
    }

    public function testAFailedSpawnLeavesNoDescriptorBehind(): void
    {
        $before = count((array) scandir('/proc/self/fd'));
        $spec = new WorkerSpec('lost', (new SelfCommand())->resolve(WorkerFixture::script('worker.php')), cwd: '/nonexistent/raphp-no-such-directory');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->thrownBy(fn() => $this->spawner->spawn($spec));
        }

        self::assertLessThanOrEqual($before, count((array) scandir('/proc/self/fd')), 'five failed spawns must not leak descriptors');
    }
}
