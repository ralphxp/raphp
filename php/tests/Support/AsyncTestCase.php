<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use function Amp\delay;

use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Throwable;

/**
 * A test case for code that runs in the event loop.
 *
 * - Errors that reach the loop's error handler are collected in {@see self::$loopErrors}, so a test
 *   can assert there were none.
 * - A watchdog stops the whole run if one test waits longer than {@see self::WATCHDOG_SECONDS}, so
 *   a deadlock shows up as a message instead of a hang.
 */
abstract class AsyncTestCase extends TestCase
{
    private const int WATCHDOG_SECONDS = 30;

    /** @var list<Throwable> */
    protected array $loopErrors = [];

    private string $watchdog = '';

    protected function setUp(): void
    {
        $this->loopErrors = [];
        EventLoop::setErrorHandler(function (Throwable $error): void {
            $this->loopErrors[] = $error;
        });
        $name = $this->name();
        $this->watchdog = EventLoop::delay(self::WATCHDOG_SECONDS, static function () use ($name): never {
            fwrite(STDERR, "\nThe test {$name} waited longer than " . self::WATCHDOG_SECONDS . " seconds and was stopped: it is probably deadlocked.\n");

            exit(3);
        });
    }

    protected function tearDown(): void
    {
        EventLoop::cancel($this->watchdog);
        EventLoop::setErrorHandler(null);
    }

    /**
     * Lets queued callbacks and destructors run.
     */
    protected function settle(float $seconds = 0.02): void
    {
        delay($seconds);
    }
}
