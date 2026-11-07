<?php

declare(strict_types=1);

namespace Raphp\Tests\Core;

use function Amp\delay;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use Raphp\Core\Kernel;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\Recorder;
use RuntimeException;
use Stringable;

/**
 * Kernel behaviour that can be checked inside this process. The signal, exit-code and ordering
 * tests that need a real process are in {@see KernelTest}.
 */
final class KernelInProcessTest extends AsyncTestCase
{
    private function kernel(): Kernel
    {
        return new Kernel(new Logger('test', [new TestHandler()]), 2.0, static function (int $code): never {
            throw new RuntimeException("force exit {$code} must not happen in these tests");
        });
    }

    public function testAKernelCanRunTwiceInARowAndEachRunReturnsItsOwnCode(): void
    {
        $kernel = $this->kernel();

        self::assertSame(3, $kernel->run(static function (Kernel $kernel): void {
            $kernel->stop(3);
        }));
        self::assertSame(0, $kernel->run(static fn(Kernel $kernel): null => null));
        self::assertSame(7, $kernel->run(static function (Kernel $kernel): void {
            $kernel->stop(7);
        }));
    }

    public function testAMainThatEndsLateAfterItsRunWasStoppedCannotStopTheNextRun(): void
    {
        $kernel = $this->kernel();

        // The first main asks for the stop itself and then keeps running for a while.
        $kernel->run(static function (Kernel $kernel): void {
            $kernel->stop(3);
            delay(0.15);
        });

        // The second run is meant to last 0.4 s and end with code 5. The first main ends at about 0.15 s,
        // while this run is going, and must not stop it.
        $exit = $kernel->run(static function (Kernel $kernel): void {
            delay(0.4);
            $kernel->stop(5);
        });

        self::assertSame(5, $exit);
    }

    public function testAMainThatThrowsLateAfterItsRunWasStoppedCannotStopTheNextRun(): void
    {
        $kernel = $this->kernel();

        $kernel->run(static function (Kernel $kernel): void {
            $kernel->stop(3);
            delay(0.15);

            throw new RuntimeException('late failure of an old main');
        });

        $exit = $kernel->run(static function (Kernel $kernel): void {
            delay(0.4);
            $kernel->stop(5);
        });

        self::assertSame(5, $exit);
    }

    public function testShutdownHooksRunOnceAndAreNotRunAgainByALaterRun(): void
    {
        $kernel = $this->kernel();
        $ran = new Recorder();
        $kernel->onShutdown(static function () use ($ran): void {
            $ran->add();
        }, 'once');

        $kernel->run(static fn(Kernel $kernel): null => null);
        $kernel->run(static fn(Kernel $kernel): null => null);

        self::assertSame(1, $ran->count());
    }

    public function testAFailureOfTheWholeShutdownPhaseStillEndsTheRunWithAnErrorCode(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'raphp-log');
        self::assertIsString($logFile);
        $previous = ini_set('error_log', $logFile);
        $kernel = new Kernel(new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    throw new RuntimeException('the log is broken');
                }
            }
        }, 2.0);
        $kernel->onShutdown(static function (): never {
            throw new RuntimeException('a hook that fails, which the broken logger cannot report');
        }, 'failing hook');

        try {
            // If the run hung, the watchdog of the base class would stop the whole test run.
            $exit = $kernel->run(static fn(Kernel $kernel): null => null);

            self::assertSame(Kernel::EXIT_ERROR, $exit);
            self::assertStringContainsString('running the shutdown hooks failed', (string) file_get_contents($logFile));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            unlink($logFile);
        }
    }

    public function testRunningWhileAlreadyRunningIsRefused(): void
    {
        $kernel = $this->kernel();
        $refused = new Recorder();

        $kernel->run(static function (Kernel $kernel) use ($refused): void {
            try {
                $kernel->run(static fn(Kernel $inner): null => null);
            } catch (\LogicException) {
                $refused->add();
            }
        });

        self::assertSame(1, $refused->count());
    }
}
