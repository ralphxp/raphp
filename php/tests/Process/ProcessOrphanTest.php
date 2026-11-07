<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use function Amp\delay;

use Closure;
use PHPUnit\Framework\Attributes\Group;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\ChildProcess;
use Raphp\Tests\Support\WorkerFixture;

/**
 * What happens to workers when the master dies without any cleanup (plan P2.9, checks 2 and 3).
 *
 * The master is a real process (the master fixture) that starts real workers through the real
 * spawner, and is then killed with SIGKILL. The two safeguards are told apart:
 * - end of input alone: workers started with `--no-pdeathsig` still end, because the channel closes;
 * - the kernel's parent-death signal alone: workers started with `--ignore-eof` do not care about the
 *   channel, so only the signal can end them.
 */
#[Group('slow')]
final class ProcessOrphanTest extends AsyncTestCase
{
    /** @var list<int> */
    private array $started = [];

    protected function tearDown(): void
    {
        foreach ($this->started as $pid) {
            if (WorkerFixture::isAlive($pid)) {
                posix_kill($pid, SIGKILL);
            }
        }

        parent::tearDown();
    }

    /**
     * Starts the master fixture, waits for it to be ready, kills it with SIGKILL and returns the
     * pids of its workers and how long the master took to die.
     *
     * @param list<string> $arguments
     *
     * @return list<int>
     */
    private function killMaster(array $arguments): array
    {
        $result = (new ChildProcess())->run(WorkerFixture::script('master.php'), $arguments, [SIGKILL], 30.0);

        self::assertFalse($result->timedOut, 'the master fixture did not become ready: ' . $result->stderr);
        $pids = [];
        foreach ($result->stdoutLines() as $line) {
            if (str_starts_with($line, 'PIDS ')) {
                $pids = array_map(intval(...), explode(' ', substr($line, 5)));
            }
        }

        self::assertNotSame([], $pids, 'the master fixture reported no workers: ' . $result->stdout . $result->stderr);
        array_push($this->started, ...$pids);

        return $pids;
    }

    /**
     * @param Closure(): bool $condition
     */
    private function within(float $seconds, Closure $condition): float
    {
        $started = hrtime(true);
        $deadline = $started + (int) ($seconds * 1e9);
        while (!$condition() && hrtime(true) < $deadline) {
            delay(0.005);
        }

        return (hrtime(true) - $started) / 1e9;
    }

    /**
     * @param list<int> $pids
     */
    private function allGone(array $pids): bool
    {
        foreach ($pids as $pid) {
            if (WorkerFixture::isAlive($pid)) {
                return false;
            }
        }

        return true;
    }

    public function testWhenTheMasterIsKilledEveryWorkerSeesEndOfInputAndEndsEvenWithoutTheDeathSignal(): void
    {
        $pids = $this->killMaster(['--workers=2', '--no-pdeathsig']);

        $seconds = $this->within(2.0, fn(): bool => $this->allGone($pids));

        self::assertTrue($this->allGone($pids), 'both workers must end by end of input alone (nobody else holds a copy of the master\'s end)');
        self::assertLessThan(1.0, $seconds);
    }

    public function testWithTheDeathSignalEnabledAWorkerThatIgnoresEndOfInputIsStillEndedByTheKernel(): void
    {
        $pids = $this->killMaster(['--workers=1', '--ignore-eof']);

        $seconds = $this->within(2.0, fn(): bool => $this->allGone($pids));

        self::assertTrue($this->allGone($pids), 'the kernel must signal the worker when the master dies');
        self::assertLessThan(1.0, $seconds, 'the spike measured 5 to 9 ms; one second is a generous bound for a loaded CI machine');
    }

    public function testTheKernelsSignalIsSigtermSoAWorkerCanStillCleanUp(): void
    {
        $marker = sys_get_temp_dir() . '/raphp-marker-' . bin2hex(random_bytes(4));

        try {
            $pids = $this->killMaster(['--workers=1', '--ignore-eof', "--marker={$marker}"]);
            $this->within(2.0, fn(): bool => $this->allGone($pids));

            self::assertSame('sigterm', @file_get_contents($marker), 'the worker was sent SIGTERM (which it can catch), not SIGKILL');
        } finally {
            @unlink($marker);
        }
    }

    public function testControlWithoutTheDeathSignalAndWithoutEndOfInputTheWorkerSurvivesTheMaster(): void
    {
        $pids = $this->killMaster(['--workers=1', '--no-pdeathsig', '--ignore-eof']);

        $this->within(1.5, fn(): bool => $this->allGone($pids));

        self::assertFalse($this->allGone($pids), 'the control: with both safeguards off the worker lives on, so the tests above really test them');
    }

    public function testTwoWorkersAreBothEndedByTheDeathSignalWhenTheyIgnoreEndOfInput(): void
    {
        $pids = $this->killMaster(['--workers=2', '--ignore-eof']);

        $this->within(2.0, fn(): bool => $this->allGone($pids));

        self::assertTrue($this->allGone($pids));
        self::assertCount(2, $pids);
    }
}
