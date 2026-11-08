<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use function Amp\async;

use Amp\CancelledException;
use Amp\DeferredCancellation;

use function Amp\delay;
use function Amp\Future\await;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Raphp\Process\CommandException;
use Raphp\Process\CommandOutputLimitException;
use Raphp\Process\CommandTimeoutException;
use Raphp\Process\OutputStream;
use Raphp\Process\ProcessResult;
use Raphp\Process\ProcessRunner;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\ShimFixture;
use RuntimeException;
use Throwable;

/**
 * The runner with real processes: output, input, environment, stopping a whole group, limits.
 */
#[Group('slow')]
final class ProcessRunnerTest extends AsyncTestCase
{
    private string $dir = '';

    /** @var list<int> processes a test started on purpose and must not leave behind */
    private array $strays = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/raphp-runner-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        foreach (self::names($this->dir) as $name) {
            if ($name !== '.' && $name !== '..') {
                unlink("{$this->dir}/{$name}");
            }
        }

        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private static function names(string $directory): array
    {
        $names = scandir($directory);

        return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
    }

    private function runner(): ProcessRunner
    {
        return new ProcessRunner(terminateGrace: 0.4, outputGrace: 0.3);
    }

    /**
     * Waits until the file holds a pid and returns it (the command wrote it, so it is running).
     *
     * @return list<int>
     */
    private function pidsIn(string $file, int $count): array
    {
        $found = $this->eventually(function () use ($file, $count): bool {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);

            return $lines !== false && count($lines) >= $count;
        }, 5.0);
        self::assertTrue($found, "the command did not write {$count} pids to {$file}");

        $lines = file($file, FILE_IGNORE_NEW_LINES);

        return array_map(intval(...), $lines === false ? [] : $lines);
    }

    private function alive(int $pid): bool
    {
        if (!posix_kill($pid, 0)) {
            return false;
        }

        $status = @file_get_contents("/proc/{$pid}/stat");

        return $status !== false && !str_contains($status, ') Z '); // a zombie is gone for our purposes
    }

    // ------------------------------------------------------------ output and exit code

    public function testItCapturesBothStreamsAndTheExitCode(): void
    {
        $result = $this->runner()->run(['sh', '-c', 'echo out; echo err >&2; exit 3']);

        self::assertSame(3, $result->exitCode);
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertFalse($result->succeeded());
        self::assertTrue($result->outputComplete);
        self::assertFalse($result->stopped);
        self::assertGreaterThan(0.0, $result->seconds);
    }

    public function testASuccessfulCommandSucceeds(): void
    {
        $result = $this->runner()->run(['true']);

        self::assertTrue($result->succeeded());
        self::assertSame('', $result->stdout);
    }

    public function testACommandEndedBySignalReportsTheShellConvention(): void
    {
        $result = $this->runner()->run(['sh', '-c', 'kill -9 $$']);

        self::assertSame(137, $result->exitCode);
    }

    public function testInputIsWrittenToStandardInputAndThenClosed(): void
    {
        $input = random_bytes(200_000) . "end\n";

        $result = $this->runner()->run(['cat'], input: $input);

        self::assertSame($input, $result->stdout);
    }

    public function testWithoutInputStandardInputIsClosedAtOnce(): void
    {
        $result = $this->runner()->run(['cat'], timeout: 5.0);

        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->stdout);
    }

    public function testInputToACommandThatDoesNotReadItIsNotAnError(): void
    {
        $result = $this->runner()->run(['true'], input: str_repeat('x', 1_000_000));

        self::assertSame(0, $result->exitCode);
    }

    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $tricky = 'a b; $HOME "q" \'s\' `x` * ~ | > & (';

        $result = $this->runner()->run(['printf', '%s', $tricky]);

        self::assertSame($tricky, $result->stdout);
    }

    public function testBothStreamsAreReadAtOnceSoNeitherCanBlockTheOther(): void
    {
        $result = $this->runner()->run(['sh', '-c', 'head -c 400000 /dev/zero >&2; head -c 400000 /dev/zero'], timeout: 20.0);

        self::assertSame(400_000, strlen($result->stdout));
        self::assertSame(400_000, strlen($result->stderr));
    }

    // ------------------------------------------------------------ working directory, environment, descriptors

    public function testItRunsInTheGivenDirectory(): void
    {
        $result = $this->runner()->run(['pwd'], workingDirectory: $this->dir);

        self::assertSame(realpath($this->dir) . "\n", $result->stdout);
    }

    public function testAMissingWorkingDirectoryIsReported(): void
    {
        $this->expectException(CommandException::class);
        $this->expectExceptionMessageMatches('/working directory .* does not exist/');

        $this->runner()->run(['true'], workingDirectory: "{$this->dir}/nowhere");
    }

    public function testTheEnvironmentIsInheritedAndExtendedAndOverridden(): void
    {
        putenv('RAPHP_RUNNER_INHERITED=from-parent');
        putenv('RAPHP_RUNNER_OVERRIDDEN=old');

        $result = $this->runner()->run(['sh', '-c', 'echo "$RAPHP_RUNNER_INHERITED $RAPHP_RUNNER_ADDED $RAPHP_RUNNER_OVERRIDDEN"'], environment: ['RAPHP_RUNNER_ADDED' => 'added', 'RAPHP_RUNNER_OVERRIDDEN' => 'new']);

        putenv('RAPHP_RUNNER_INHERITED');
        putenv('RAPHP_RUNNER_OVERRIDDEN');

        self::assertSame("from-parent added new\n", $result->stdout);
    }

    public function testACommandIsFoundThroughAPathGivenInTheEnvironment(): void
    {
        file_put_contents("{$this->dir}/mytool", "#!/bin/sh\necho mytool ran\n");
        chmod("{$this->dir}/mytool", 0o755);

        $result = $this->runner()->run(['mytool'], environment: ['PATH' => $this->dir . ':' . (getenv('PATH') === false ? '/usr/bin' : getenv('PATH'))]);

        self::assertSame("mytool ran\n", $result->stdout);
    }

    public function testAFileThatIsNotExecutableIsSkippedWhenSearchingThePath(): void
    {
        mkdir("{$this->dir}/first");
        mkdir("{$this->dir}/second");
        file_put_contents("{$this->dir}/first/tool", "#!/bin/sh\necho wrong\n");
        chmod("{$this->dir}/first/tool", 0o644);
        file_put_contents("{$this->dir}/second/tool", "#!/bin/sh\necho right\n");
        chmod("{$this->dir}/second/tool", 0o755);

        $result = $this->runner()->run(['tool'], environment: ['PATH' => "{$this->dir}/first:{$this->dir}/second:" . (getenv('PATH') === false ? '/usr/bin' : getenv('PATH'))]);

        self::assertSame("right\n", $result->stdout);

        try {
            $this->runner()->run(['tool'], environment: ['PATH' => "{$this->dir}/first"]);
            self::fail('a non-executable file alone must not count as the program');
        } catch (CommandException $exception) {
            self::assertStringContainsString('no such executable', $exception->getMessage());
        }

        unlink("{$this->dir}/first/tool");
        unlink("{$this->dir}/second/tool");
        rmdir("{$this->dir}/first");
        rmdir("{$this->dir}/second");
    }

    public function testACommandCannotUseTheMastersSockets(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);

        // Every descriptor the command has, and what it points to: amphp/process points all but 0, 1 and 2 at /dev/null.
        $result = $this->runner()->run(['sh', '-c', 'for f in /proc/$$/fd/*; do readlink "$f"; done']);
        fclose($server);
        fclose($pair[0]);
        fclose($pair[1]);

        $targets = explode("\n", trim($result->stdout));
        self::assertNotContains('socket', array_map(static fn(string $target): string => str_starts_with($target, 'socket:') ? 'socket' : 'other', $targets), 'the command holds a socket: ' . $result->stdout);
        self::assertStringNotContainsString('socket:', $result->stdout);
    }

    // ------------------------------------------------------------ streaming

    public function testOutputReachesTheCallbackAsItArrivesAndNotOnlyAtTheEnd(): void
    {
        $seen = [];
        $firstAt = null;
        $started = hrtime(true);

        $result = $this->runner()->run(
            ['sh', '-c', 'echo first; sleep 0.4; echo second >&2'],
            onOutput: static function (OutputStream $stream, string $chunk) use (&$seen, &$firstAt, $started): void {
                $seen[] = [$stream, $chunk];
                $firstAt ??= (hrtime(true) - $started) / 1e9;
            },
        );

        self::assertSame([[OutputStream::Stdout, "first\n"], [OutputStream::Stderr, "second\n"]], $seen);
        self::assertLessThan(0.3, $firstAt, 'the first line arrived while the command was still running');
        self::assertSame("first\n", $result->stdout, 'output is captured as well');
    }

    public function testOutputCanBeStreamedWithoutBeingKept(): void
    {
        $bytes = 0;

        $result = $this->runner()->run(['head', '-c', '300000', '/dev/zero'], captureOutput: false, onOutput: static function (OutputStream $stream, string $chunk) use (&$bytes): void {
            $bytes += strlen($chunk);
        });

        self::assertSame(300_000, $bytes);
        self::assertSame('', $result->stdout);
    }

    public function testACallbackThatThrowsStopsTheCommandAndItsExceptionIsRethrown(): void
    {
        file_put_contents("{$this->dir}/pids", '');

        try {
            $this->runner()->run(['sh', '-c', 'echo $$ >> ' . $this->dir . '/pids; echo go; sleep 30'], onOutput: static function (): never {
                throw new RuntimeException('the callback gave up');
            });
            self::fail('expected the callback exception');
        } catch (RuntimeException $exception) {
            self::assertSame('the callback gave up', $exception->getMessage());
        }

        [$pid] = $this->pidsIn("{$this->dir}/pids", 1);
        self::assertFalse($this->alive($pid), 'the command was stopped');
    }

    // ------------------------------------------------------------ limits, timeout, cancellation

    public function testTooMuchOutputStopsTheCommand(): void
    {
        $runner = new ProcessRunner(maxOutputBytes: 100_000, terminateGrace: 0.4, outputGrace: 0.3);
        $started = hrtime(true);

        try {
            $runner->run(['yes']);
            self::fail('expected the output limit');
        } catch (CommandOutputLimitException $exception) {
            self::assertStringContainsString('stdout', $exception->getMessage());
        }

        self::assertLessThan(5.0, (hrtime(true) - $started) / 1e9);
        self::assertSame(0, $runner->runningCount());
    }

    public function testTheLimitAppliesToEachStreamOnItsOwn(): void
    {
        $runner = new ProcessRunner(maxOutputBytes: 1000, terminateGrace: 0.4, outputGrace: 0.3);

        $result = $runner->run(['sh', '-c', 'head -c 1000 /dev/zero; head -c 1000 /dev/zero >&2']);

        self::assertSame(1000, strlen($result->stdout), 'exactly the limit is allowed');
        self::assertSame(1000, strlen($result->stderr));
    }

    public function testTheLimitDoesNotApplyWhenOutputIsNotCaptured(): void
    {
        $runner = new ProcessRunner(maxOutputBytes: 1000, terminateGrace: 0.4, outputGrace: 0.3);

        $result = $runner->run(['head', '-c', '50000', '/dev/zero'], captureOutput: false);

        self::assertSame(0, $result->exitCode);
    }

    public function testATimeoutStopsTheCommandAndEverythingItStarted(): void
    {
        file_put_contents("{$this->dir}/pids", '');
        $script = 'echo $$ >> ' . $this->dir . '/pids; sleep 40 & echo $! >> ' . $this->dir . '/pids; sleep 41 & echo $! >> ' . $this->dir . '/pids; wait';

        $started = hrtime(true);
        try {
            $this->runner()->run(['sh', '-c', $script], timeout: 0.4);
            self::fail('expected a timeout');
        } catch (CommandTimeoutException $exception) {
            self::assertStringContainsString('did not finish within 0.4 seconds', $exception->getMessage());
        }

        self::assertLessThan(4.0, (hrtime(true) - $started) / 1e9);
        $pids = $this->pidsIn("{$this->dir}/pids", 3);
        $this->strays = $pids;
        foreach ($pids as $pid) {
            self::assertTrue($this->eventually(fn(): bool => !$this->alive($pid), 3.0), "process {$pid} is still running");
        }
    }

    public function testCancellationStopsTheCommandWithSigtermFirstAndThrows(): void
    {
        file_put_contents("{$this->dir}/pids", '');
        $script = 'trap "echo got-term > ' . $this->dir . '/marker; exit 0" TERM; echo $$ >> ' . $this->dir . '/pids; sleep 40 & wait';
        $cancel = new DeferredCancellation();
        $call = async(fn() => $this->runner()->run(['sh', '-c', $script], $cancel->getCancellation()));

        [$pid] = $this->pidsIn("{$this->dir}/pids", 1);
        $this->strays[] = $pid;
        $cancel->cancel();

        try {
            $call->await();
            self::fail('expected the cancellation');
        } catch (CancelledException) {
            self::assertSame("got-term\n", file_get_contents("{$this->dir}/marker"), 'SIGTERM came first, so the command could clean up');
        }

        self::assertFalse($this->alive($pid));
    }

    public function testACommandThatIgnoresSigtermIsKilledAfterTheGrace(): void
    {
        file_put_contents("{$this->dir}/pids", '');
        $script = 'trap "" TERM; echo $$ >> ' . $this->dir . '/pids; while true; do sleep 1; done';

        $started = hrtime(true);
        try {
            $this->runner()->run(['sh', '-c', $script], timeout: 0.3);
            self::fail('expected a timeout');
        } catch (CommandTimeoutException) {
            $elapsed = (hrtime(true) - $started) / 1e9;
        }

        [$pid] = $this->pidsIn("{$this->dir}/pids", 1);
        $this->strays[] = $pid;
        self::assertGreaterThanOrEqual(0.6, $elapsed, 'the grace of 0.4 s was given before SIGKILL');
        self::assertLessThan(4.0, $elapsed);
        self::assertTrue($this->eventually(fn(): bool => !$this->alive($pid), 3.0));
    }

    public function testAnAlreadyCancelledRunNeverStartsTheCommand(): void
    {
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        $this->expectException(CancelledException::class);

        try {
            $this->runner()->run(['touch', "{$this->dir}/should-not-exist"], $cancel->getCancellation());
        } finally {
            self::assertFileDoesNotExist("{$this->dir}/should-not-exist");
        }
    }

    // ------------------------------------------------------------ things that outlive the command

    public function testABackgroundProcessHoldingThePipesDoesNotHangTheRun(): void
    {
        $started = hrtime(true);

        $result = $this->runner()->run(['sh', '-c', 'sleep 40 & echo $!; echo done']);

        $pid = (int) explode("\n", $result->stdout)[0];
        $this->strays[] = $pid;
        self::assertFalse($result->outputComplete, 'the output may be missing its tail, and the result says so');
        self::assertSame("{$pid}\ndone\n", $result->stdout);
        self::assertSame(0, $result->exitCode);
        self::assertLessThan(3.0, (hrtime(true) - $started) / 1e9);
        self::assertTrue($this->alive($pid), 'a command that starts a background process is allowed to');
    }

    // ------------------------------------------------------------ stopAll

    public function testStopAllEndsEveryRunningCommandAndTheirGroups(): void
    {
        file_put_contents("{$this->dir}/pids", '');
        $runner = $this->runner();
        $script = 'echo $$ >> ' . $this->dir . '/pids; sleep 40 & echo $! >> ' . $this->dir . '/pids; wait';
        $calls = [async(fn() => $runner->run(['sh', '-c', $script])), async(fn() => $runner->run(['sh', '-c', $script]))];

        $pids = $this->pidsIn("{$this->dir}/pids", 4);
        $this->strays = $pids;
        self::assertSame(2, $runner->runningCount());

        $runner->stopAll();
        $results = await($calls);

        foreach ($results as $result) {
            self::assertInstanceOf(ProcessResult::class, $result);
            self::assertTrue($result->stopped);
            self::assertFalse($result->succeeded());
            self::assertSame(143, $result->exitCode);
        }

        self::assertSame(0, $runner->runningCount());
        foreach ($pids as $pid) {
            self::assertTrue($this->eventually(fn(): bool => !$this->alive($pid), 3.0), "process {$pid} is still running");
        }
    }

    public function testStopAllWithNothingRunningDoesNothing(): void
    {
        $runner = $this->runner();

        $runner->stopAll();

        self::assertSame(0, $runner->runningCount());
    }

    public function testManyCommandsRunAtOnce(): void
    {
        $runner = $this->runner();
        $calls = [];
        for ($i = 0; $i < 20; $i++) {
            $calls[] = async(fn() => $runner->run(['sh', '-c', "sleep 0.1; echo {$i}"]));
        }

        $results = await($calls);

        foreach ($results as $i => $result) {
            self::assertInstanceOf(ProcessResult::class, $result);
            self::assertSame("{$i}\n", $result->stdout);
        }

        self::assertSame(0, $runner->runningCount());
    }

    // ------------------------------------------------------------ refusing what cannot run

    public function testAMissingProgramIsReportedByNameBeforeAnythingStarts(): void
    {
        try {
            $this->runner()->run(['definitely-not-a-program-xyz', '--flag']);
            self::fail('expected a CommandException');
        } catch (CommandException $exception) {
            self::assertStringContainsString('definitely-not-a-program-xyz', $exception->getMessage());
            self::assertStringContainsString('no such executable', $exception->getMessage());
        }
    }

    public function testAPathThatIsNotExecutableIsReported(): void
    {
        file_put_contents("{$this->dir}/plain", 'x');
        chmod("{$this->dir}/plain", 0o644);

        $this->expectException(CommandException::class);

        $this->runner()->run(["{$this->dir}/plain"]);
    }

    public function testARelativeProgramPathIsResolvedAgainstTheWorkingDirectory(): void
    {
        file_put_contents("{$this->dir}/local.sh", "#!/bin/sh\necho local\n");
        chmod("{$this->dir}/local.sh", 0o755);

        $result = $this->runner()->run(['./local.sh'], workingDirectory: $this->dir);

        self::assertSame("local\n", $result->stdout);
    }

    public function testUnusableArgumentsAreRefused(): void
    {
        $bad = [
            [[], null],
            [[''], null],
            [['echo', "a\0b"], null],
            [['a' => 'echo'], null],
            [['true'], 0.0],
            [['true'], -1.0],
        ];
        $run = new \ReflectionMethod(ProcessRunner::class, 'run'); // called by reflection: the wrong types are given on purpose
        foreach ($bad as [$command, $timeout]) {
            try {
                $run->invokeArgs($this->runner(), ['command' => $command, 'timeout' => $timeout]);
                self::fail('expected an InvalidArgumentException for ' . json_encode($command));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            } catch (Throwable $other) {
                self::fail('wrong exception: ' . $other::class . ' ' . $other->getMessage());
            }
        }
    }

    public function testUnusableSettingsAreRefused(): void
    {
        foreach ([[0, 1.0, 1.0], [1, 0.0, 1.0], [1, 1.0, 0.0]] as [$limit, $term, $grace]) {
            try {
                new ProcessRunner($limit, $term, $grace);
                self::fail('expected an InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        new ProcessRunner(1, 0.01, 0.01);
    }

    public function testTheRunnerIsUsableAgainAfterAFailure(): void
    {
        $runner = $this->runner();
        try {
            $runner->run(['sleep', '30'], timeout: 0.2);
        } catch (CommandTimeoutException) {
            // expected
        }

        self::assertSame("fine\n", $runner->run(['echo', 'fine'])->stdout);
    }

    public function testASlowCommandDoesNotBlockTheEventLoop(): void
    {
        $ticks = 0;
        $timer = \Revolt\EventLoop::repeat(0.01, static function () use (&$ticks): void {
            ++$ticks;
        });

        $this->runner()->run(['sleep', '0.5']);
        \Revolt\EventLoop::cancel($timer);
        delay(0);

        self::assertGreaterThan(30, $ticks);
    }

    public function testACommandInheritsNoneOfTheMastersDescriptors(): void
    {
        // amphp/process replaces every descriptor the master has open, up to the highest, with /dev/null in the command (checked for R17), so the runner needs no sweep of its own.
        $file = tempnam(sys_get_temp_dir(), 'raphp-inherit');
        self::assertNotFalse($file);
        $before = array_keys(ShimFixture::descriptors());
        $opened = fopen($file, 'r');
        self::assertNotFalse($opened);
        $number = array_values(array_diff(array_keys(ShimFixture::descriptors()), $before))[0] ?? null;
        self::assertNotNull($number);
        $duplicate = fopen("php://fd/{$number}", 'r'); // inheritable, like the duplicate that watches the shim (R17)
        self::assertNotFalse($duplicate);

        try {
            $result = $this->runner()->run(['sh', '-c', 'for f in /proc/$$/fd/*; do readlink "$f"; done']);
        } finally {
            fclose($duplicate);
            fclose($opened);
            unlink($file);
        }

        self::assertSame(0, $result->exitCode);
        self::assertStringNotContainsString($file, $result->stdout, 'the master\'s open file did not reach the command');
        self::assertStringNotContainsString('anon_inode', $result->stdout, 'nor an eventfd or epoll of the master');
        self::assertStringNotContainsString('memfd', $result->stdout, 'nor PHP\'s opcache lock');
    }
}
