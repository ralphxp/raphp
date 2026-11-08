<?php

/**
 * Worker fixture for the process tests. It is started by the real spawner, with the standard
 * command line (--role=worker --name=<name> --parent-pid=<pid>) and the channel on descriptor 3.
 *
 * Test-only flags: --fail-start (exit at once), --hang-start (start, never say hello), --marker=<file> (leave that file behind when SIGTERM arrives), --no-pdeathsig (do not ask the kernel for a signal when the master dies) and
 * --ignore-eof (stay alive when the channel closes, so only a signal can end the worker).
 *
 * It answers these methods: spin, die, sleep, bloat, argv, env, cwd, stdin, raw, pid, ppid, echo, fds, say, flood, exit, busy, handler-term,
 * ignore-term, fork-holder. A timer of 40 seconds ends it whatever happens, so a failed test
 * never leaves a stray process behind.
 */

declare(strict_types=1);

use Amp\Cancellation;

use function Amp\delay;

use Psr\Log\NullLogger;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Worker\WorkerArguments;
use Raphp\Worker\WorkerRuntime;
use Revolt\EventLoop;

require __DIR__ . '/../../../vendor/autoload.php';

/**
 * Memory a worker was asked to keep, so the memory tests can make it grow.
 */
final class Hoard
{
    /** @var list<string> */
    public static array $items = [];
}

function currentPid(): int
{
    $pid = getmypid();

    return $pid === false ? 1 : $pid;
}

pcntl_alarm(40);

try {
    $arguments = WorkerArguments::parse($argv ?? []);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(2);
}

$options = $arguments->options;
$name = $arguments->name;

if (isset($options['fail-start'])) {
    exit(5); // a worker that cannot start, for the restart tests
}

if (isset($options['hang-start'])) {
    sleep(30); // a worker that starts but never says hello (the 40 second alarm ends it anyway)
}

if (isset($options['marker'])) {
    // Proof of which signal arrived: a SIGTERM handler that leaves a file behind. SIGKILL cannot be caught.
    EventLoop::onSignal(SIGTERM, static function () use ($options): never {
        file_put_contents($options['marker'], 'sigterm');

        exit(0);
    });
}

$handlers = new HandlerRegistry();
$handlers->register('argv', static fn(): array => array_slice($argv ?? [], 1));
$handlers->register('env', static function (RpcRequest $request): ?string {
    $variable = $request->params['name'] ?? '';
    $value = is_string($variable) ? getenv($variable) : false;

    return $value === false ? null : $value;
});
$handlers->register('cwd', static fn(): string|false => getcwd());
$handlers->register('stdin', static fn(): string => (string) stream_get_contents(STDIN));
$handlers->register('raw', static function (RpcRequest $request): null {
    $out = $request->params['out'] ?? '';
    $err = $request->params['err'] ?? '';
    fwrite(STDOUT, is_string($out) ? $out : '');
    fwrite(STDERR, is_string($err) ? $err : '');

    return null;
});
$handlers->register('die', static function (): null {
    posix_kill(posix_getpid(), SIGKILL);

    return null;
});
$handlers->register('sleep', static function (RpcRequest $request, ?Cancellation $cancellation): string {
    $seconds = $request->params['seconds'] ?? 0;
    delay(is_int($seconds) || is_float($seconds) ? (float) $seconds : 0.0, true, $cancellation);

    return 'slept';
});
$handlers->register('sleep-pid', static function (RpcRequest $request, ?Cancellation $cancellation): int {
    $seconds = $request->params['seconds'] ?? 0;
    delay(is_int($seconds) || is_float($seconds) ? (float) $seconds : 0.0, true, $cancellation);

    return posix_getpid();
});
$handlers->register('record-term', static function (RpcRequest $request): string {
    $path = $request->params['path'] ?? '';
    // Through the loop, like the real code: a plain pcntl_signal() handler is delayed while the loop is idle under ext-uv (F55).
    EventLoop::onSignal(SIGTERM, static function () use ($path): void {
        file_put_contents(is_string($path) ? $path : '/dev/null', 'sigterm'); // noted, and ignored: the worker keeps running
    });
    delay(0.02); // the watcher is armed on the next tick; answer after it

    return 'ok';
});
$handlers->register('bloat', static function (RpcRequest $request): int {
    $bytes = $request->params['bytes'] ?? 0;
    Hoard::$items[] = str_repeat('x', is_int($bytes) ? $bytes : 0);

    return posix_getpid();
});
$handlers->register('pid', static fn(): int => currentPid());
$handlers->register('ppid', static fn(): int => posix_getppid());
$handlers->register('echo', static fn(RpcRequest $request): array => $request->params);
$handlers->register('fds', static function (): array {
    $fds = [];
    $entries = scandir('/proc/self/fd');
    foreach ($entries === false ? [] : $entries as $fd) {
        $target = $fd === '.' || $fd === '..' ? false : @readlink("/proc/self/fd/{$fd}");
        if ($target !== false) {
            $fds[$fd] = $target;
        }
    }

    return $fds;
});
$handlers->register('say', static function (RpcRequest $request): null {
    $out = $request->params['out'] ?? '';
    $err = $request->params['err'] ?? '';
    fwrite(STDOUT, (is_string($out) ? $out : '') . "\n");
    fwrite(STDERR, (is_string($err) ? $err : '') . "\n");

    return null;
});
$handlers->register('flood', static function (RpcRequest $request): int {
    $bytes = $request->params['bytes'] ?? 0;
    $bytes = is_int($bytes) ? $bytes : 0;
    $line = str_repeat('x', 99) . "\n";
    for ($written = 0; $written < $bytes; $written += 100) {
        fwrite(STDOUT, $line);
    }

    return $bytes;
});
$handlers->register('exit', static function (RpcRequest $request): null {
    $code = $request->params['code'] ?? 0;
    $code = is_int($code) ? $code : 0;
    EventLoop::delay(0.01, static function () use ($code): never {
        exit($code);
    });

    return null;
});
$handlers->register('busy', static function (): string {
    EventLoop::onSignal(SIGTERM, static function (): never {
        exit(0);
    });
    EventLoop::delay(0.05, static function (): void {
        for ($spin = 0; $spin >= 0; $spin = ($spin + 1) % PHP_INT_MAX) {
            // a worker whose loop is busy and never gets back to it
        }
    });

    return 'busy';
});
$handlers->register('spin', static function (): string {
    // Busy, with no signal handler installed at all: the default SIGTERM action must still end it.
    EventLoop::delay(0.05, static function (): void {
        for ($spin = 0; $spin >= 0; $spin = ($spin + 1) % PHP_INT_MAX) {
            // never returns to the loop
        }
    });

    return 'spinning';
});
$handlers->register('handler-term', static function (): string {
    EventLoop::onSignal(SIGTERM, static function (): never {
        exit(0);
    });
    delay(0.02); // Revolt arms a signal watcher on the next tick: answer only once the handler can run, or a quick SIGTERM kills the worker instead

    return 'ok';
});
$handlers->register('ignore-term', static function (): string {
    pcntl_signal(SIGTERM, SIG_IGN);

    return 'ok';
});
$handlers->register('fork-holder', static function (): int {
    $child = pcntl_fork();
    if ($child === 0) {
        sleep(3); // holds the inherited output pipes open for a while, then ends
        exit(0);
    }

    return $child;
});

$handshakeTimeout = (float) ($options['handshake-timeout'] ?? 10.0);
$exitCode = (new WorkerRuntime(new NullLogger(), new RpcPeerOptions(handshakeTimeout: $handshakeTimeout)))->run($arguments, $handlers);

if (isset($options['ignore-eof'])) {
    delay(3600.0); // only a signal (or the 40 second alarm) ends the worker
}

exit($exitCode);
