<?php

/**
 * Master fixture for the orphan tests. Run as: php master.php --workers=<n> [--no-pdeathsig] [--ignore-eof]
 *
 * It starts the workers with the real spawner, waits until each has said hello (so each has
 * armed its safeguards), prints "PIDS <pid> <pid> ..." and then "READY", and idles until the test
 * kills it. A timer of 40 seconds ends it whatever happens.
 */

declare(strict_types=1);

use function Amp\delay;

use Psr\Log\NullLogger;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\ChannelFactory;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SelfCommand;
use Raphp\Process\WorkerSpec;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;

require __DIR__ . '/../../../vendor/autoload.php';

function currentPid(): int
{
    $pid = getmypid();

    return $pid === false ? 1 : $pid;
}

pcntl_alarm(40);

$count = 1;
$extra = [];
$armed = true;
foreach (array_slice($argv ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--workers=')) {
        $count = (int) substr($argument, 10);
    } elseif ($argument === '--no-pdeathsig') {
        $armed = false;
    } elseif ($argument === '--ignore-eof' || str_starts_with($argument, '--marker=')) {
        $extra[] = $argument;
    }
}

$spawner = new ProcessSpawner(new ChannelFactory(), new NullLogger());
$command = (new SelfCommand())->resolve(__DIR__ . '/worker.php');
$handles = [];
$peers = [];
$pids = [];

for ($number = 1; $number <= $count; $number++) {
    $handle = $spawner->spawn(new WorkerSpec("w{$number}", $command, armDeathSignal: $armed, extraArguments: $extra));
    $peer = new RpcPeer($handle->transport, new JsonLineCodec(), new EnvelopeReader(), new NullLogger(), new HandlerRegistry(), options: new RpcPeerOptions(), hello: new Hello(Protocol::VERSION, 'master', 'master', currentPid()));
    $peer->start();
    $peer->awaitHandshake();
    $handles[] = $handle;
    $peers[] = $peer;
    $pids[] = $handle->pid;
}

fwrite(STDOUT, 'PIDS ' . implode(' ', $pids) . "\n");
fwrite(STDOUT, "READY\n");

delay(3600.0); // the 40 second alarm ends the process long before
