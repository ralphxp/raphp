<?php

/**
 * Child-process fixture for KernelTest. Run as: php kernel.php <scenario>
 *
 * It prints READY when the application starts, and one "hook:<name>" line per shutdown hook,
 * so the test can see the order. Logs go to stderr.
 */

declare(strict_types=1);

use function Amp\delay;

use Raphp\Core\Kernel;
use Raphp\Core\Logging\LoggerFactory;
use Raphp\Core\Logging\LogLevel;
use Revolt\EventLoop;

require __DIR__ . '/../../../vendor/autoload.php';

$scenario = $argv[1] ?? '';
$logger = (new LoggerFactory())->create(LogLevel::Debug);
$kernel = new Kernel($logger, shutdownTimeout: 0.5);

if ($scenario === 'hook-timeout-last') {
    // Registered first, so it runs last: the other hooks finish, this one is abandoned.
    $kernel->onShutdown(static function (): void {
        delay(3.0);
    }, 'slow');
}

foreach (['a', 'b', 'c'] as $name) {
    $kernel->onShutdown(static function () use ($name): void {
        echo "hook:{$name}\n";
    }, $name);
}

if ($scenario === 'hook-throws') {
    $kernel->onShutdown(static function (): never {
        throw new RuntimeException('hook boom');
    }, 'bad');
}

if ($scenario === 'hook-timeout-first' || $scenario === 'double-signal') {
    // Registered last, so it runs first and uses up the budget (or is still running).
    $kernel->onShutdown(static function (): void {
        delay(3.0);
    }, 'slow');
}

exit($kernel->run(static function (Kernel $kernel) use ($scenario): void {
    echo "READY\n";

    switch ($scenario) {
        case 'throw':
            throw new RuntimeException('main boom');

        case 'timer':
            EventLoop::delay(0.05, static function (): never {
                throw new RuntimeException('timer boom');
            });
            $kernel->awaitStop();

            return;

        case 'stop7':
            $kernel->stop(7);

            return;

        case 'stop-then-throw':
            $kernel->stop(7);

            throw new RuntimeException('too late, the first reason wins');

        case 'wait':
        case 'double-signal':
        case 'hook-timeout-first':
        case 'hook-timeout-last':
        case 'hook-throws':
            if ($scenario === 'wait' || $scenario === 'double-signal') {
                $kernel->awaitStop();

                return;
            }

            $kernel->stop(0);

            return;

        default:
            return;
    }
}));
