<?php

declare(strict_types=1);

namespace Raphp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Raphp\Core\Kernel;
use Raphp\Tests\Support\ChildProcess;
use Raphp\Tests\Support\ChildResult;

/**
 * Integration tests: every case runs the kernel in a real child process, because exit codes and
 * signals cannot be observed from inside the process that is exiting.
 */
final class KernelTest extends TestCase
{
    /**
     * @param list<int> $signals
     */
    private function scenario(string $name, array $signals = []): ChildResult
    {
        $result = (new ChildProcess())->run(__DIR__ . '/../Fixtures/kernel.php', [$name], $signals);
        self::assertFalse($result->timedOut, "the '{$name}' scenario hung. stdout: {$result->stdout} stderr: {$result->stderr}");

        return $result;
    }

    public function testWhenMainReturnsTheProcessShutsDownCleanlyAndHooksRunLastRegisteredFirst(): void
    {
        $result = $this->scenario('ok');

        self::assertSame(Kernel::EXIT_OK, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertSame('', $result->stderr);
    }

    public function testAnExceptionThrownByMainIsLoggedHooksRunAndTheExitCodeIsNonZero(): void
    {
        $result = $this->scenario('throw');

        self::assertSame(Kernel::EXIT_ERROR, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('uncaught exception in the application', $result->stderr);
        self::assertStringContainsString('main boom', $result->stderr);
    }

    public function testAnExceptionThrownInAnEventLoopCallbackIsLoggedAndEndsTheProcessWithAnError(): void
    {
        $result = $this->scenario('timer');

        self::assertSame(Kernel::EXIT_ERROR, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('uncaught exception in an event-loop callback', $result->stderr);
        self::assertStringContainsString('timer boom', $result->stderr);
    }

    public function testSigintShutsDownInOrderAndExitsWith130(): void
    {
        $result = $this->scenario('wait', [SIGINT]);

        self::assertSame(Kernel::EXIT_SIGINT, $result->exitCode);
        self::assertSame(130, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('received SIGINT, shutting down', $result->stderr);
    }

    public function testSigtermShutsDownInOrderAndExitsWith143(): void
    {
        $result = $this->scenario('wait', [SIGTERM]);

        self::assertSame(Kernel::EXIT_SIGTERM, $result->exitCode);
        self::assertSame(143, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('received SIGTERM, shutting down', $result->stderr);
    }

    public function testStopWithACodeEndsTheProcessWithThatCode(): void
    {
        $result = $this->scenario('stop7');

        self::assertSame(7, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
    }

    public function testTheFirstReasonToStopDecidesTheExitCode(): void
    {
        $result = $this->scenario('stop-then-throw');

        self::assertSame(7, $result->exitCode);
        self::assertStringContainsString('too late, the first reason wins', $result->stderr);
    }

    public function testAFailingHookIsLoggedTheOthersStillRunAndTheExitCodeBecomesNonZero(): void
    {
        $result = $this->scenario('hook-throws');

        self::assertSame(Kernel::EXIT_ERROR, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('shutdown hook failed', $result->stderr);
        self::assertStringContainsString('hook boom', $result->stderr);
    }

    public function testAHookThatDoesNotFinishInTimeIsAbandonedAfterTheOthersHaveRun(): void
    {
        $result = $this->scenario('hook-timeout-last');

        self::assertSame(Kernel::EXIT_ERROR, $result->exitCode);
        self::assertSame(['READY', 'hook:c', 'hook:b', 'hook:a'], $result->stdoutLines());
        self::assertStringContainsString('shutdown hook did not finish in time and was abandoned', $result->stderr);
        self::assertLessThan(2.5, $result->seconds, 'the 3 second hook must not be waited for');
    }

    public function testAHookThatUsesUpTheBudgetMakesTheLaterHooksBeSkippedAndLogged(): void
    {
        $result = $this->scenario('hook-timeout-first');

        self::assertSame(Kernel::EXIT_ERROR, $result->exitCode);
        self::assertSame(['READY'], $result->stdoutLines());
        self::assertStringContainsString('shutdown hook did not finish in time and was abandoned', $result->stderr);
        self::assertStringContainsString('shutdown hook skipped, the time budget is used up', $result->stderr);
    }

    public function testASecondSignalWhileShuttingDownEndsTheProcessAtOnce(): void
    {
        $result = $this->scenario('double-signal', [SIGINT, SIGINT]);

        self::assertSame(Kernel::EXIT_SIGINT, $result->exitCode);
        self::assertLessThan(2.0, $result->seconds, 'a second signal must not wait for the slow hook');
        self::assertStringContainsString('received SIGINT, shutting down', $result->stderr);
        self::assertStringContainsString('received SIGINT again while shutting down, ending now', $result->stderr);
    }
}
