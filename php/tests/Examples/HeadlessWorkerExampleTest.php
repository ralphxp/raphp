<?php

declare(strict_types=1);

namespace Raphp\Tests\Examples;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Raphp\Tests\Support\ChildProcess;

/**
 * Runs `examples/headless-worker` as a user would, so it cannot rot unnoticed: a real master, a
 * real worker, real signals.
 */
#[Group('slow')]
final class HeadlessWorkerExampleTest extends TestCase
{
    private const string MAIN = __DIR__ . '/../../../examples/headless-worker/main.php';

    public function testAJobRunsToTheEndWithProgress(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['job', '--steps=3']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('[###] 3/3', $result->stdout);
        self::assertMatchesRegularExpression('/job finished in [0-9.]+ s, done by worker pid [1-9][0-9]*/', $result->stdout);
        self::assertStringContainsString('reports: exited', $result->stdout, 'the worker was stopped in order at the end');
    }

    public function testCtrlCCancelsTheJobAndEndsTheWorkerInOrder(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['job', '--steps=60'], [SIGINT], 20.0, '] 3/60');

        self::assertFalse($result->timedOut);
        self::assertSame(130, $result->exitCode, $result->stderr);
        self::assertMatchesRegularExpression('/job cancelled after [0-9.]+ s/', $result->stdout);
        self::assertStringNotContainsString('60/60', $result->stdout);
        self::assertStringContainsString('reports: exited', $result->stdout);
        self::assertStringContainsString('report.build was cancelled during step', $result->stderr, 'the worker itself saw the cancellation');
        self::assertStringNotContainsString('crashed', $result->stdout, 'a Ctrl-C is not a crash');
    }

    public function testACrashedWorkerComesBack(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['crash']);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('the call failed as it should: E_WORKER_CRASHED', $result->stdout);
        self::assertStringContainsString('reports: crashed', $result->stdout);
        self::assertStringContainsString('reports: restarting', $result->stdout);
        self::assertMatchesRegularExpression('/recovered: a new worker \(pid [1-9][0-9]*\) answers/', $result->stdout);
    }

    public function testABadCommandLineIsRefused(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['--steps=0']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('usage:', $result->stderr);
    }
}
