<?php

/**
 * Worker fixture for the process tests. It is started by the real spawner, with the standard
 * command line (--role=worker --name=<name> --parent-pid=<pid>) and the channel on descriptor 3.
 *
 * Test-only flags: --marker=<file> (leave that file behind when SIGTERM arrives), --no-pdeathsig (do not ask the kernel for a signal when the master dies) and
 * --ignore-eof (stay alive when the channel closes, so only a signal can end the worker).
 *
 * It answers these methods: argv, env, cwd, stdin, raw, pid, ppid, echo, fds, say, flood, exit, busy, handler-term,
 * ignore-term, fork-holder. A timer of 40 seconds ends it whatever happens, so a failed test
 * never leaves a stray process behind.
 */

declare(strict_types=1);

use function Amp\delay;

use Psr\Log\NullLogger;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\InheritedChannel;
use Raphp\Process\OrphanGuard;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Rpc\RpcRequest;
use Raphp\Runtime\Libc\ParentDeathSignal;
use Revolt\EventLoop;

require __DIR__ . '/../../../vendor/autoload.php';

function currentPid(): int
{
    $pid = getmypid();

    return $pid === false ? 1 : $pid;
}

pcntl_alarm(40);

$options = [];
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $parts = explode('=', substr($argument, 2), 2);
        $options[$parts[0]] = $parts[1] ?? '1';
    }
}

$name = $options['name'] ?? 'worker';
$guard = new OrphanGuard(isset($options['no-pdeathsig']) ? null : new ParentDeathSignal());
if (!$guard->protect((int) ($options['parent-pid'] ?? 0))) {
    exit(OrphanGuard::EXIT_PARENT_MISMATCH);
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
$handlers->register('handler-term', static function (): string {
    EventLoop::onSignal(SIGTERM, static function (): never {
        exit(0);
    });

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

$peer = new RpcPeer(
    (new InheritedChannel())->open(),
    new JsonLineCodec(),
    new EnvelopeReader(),
    new NullLogger(),
    $handlers,
    options: new RpcPeerOptions(),
    hello: new Hello(Protocol::VERSION, 'worker', $name, currentPid()),
);
$peer->start();
$peer->awaitClosed();

if (isset($options['ignore-eof'])) {
    delay(3600.0); // only a signal (or the 40 second alarm) ends the worker
}

exit(0);
