<?php

declare(strict_types=1);

namespace Raphp\Worker;

use Psr\Log\LoggerInterface;
use Raphp\Error\RpcException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Protocol;
use Raphp\Process\InheritedChannel;
use Raphp\Process\OrphanGuard;
use Raphp\Process\SpawnException;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcPeer;
use Raphp\Rpc\RpcPeerOptions;
use Raphp\Runtime\Libc\LibcException;
use Raphp\Runtime\Libc\ParentDeathSignal;

/**
 * What a worker process does from start to end (FRAMEWORK 3.4, 5.1, 5.6). Call {@see self::run()} from
 * the worker's entry script and exit with what it returns.
 *
 * 1. **Protect itself from outliving the master:** ask the kernel for a signal when the master dies,
 *    and check that the master is still the parent. Without ext-ffi or libc it refuses to run.
 * 2. **Leave Ctrl-C to the master.** A terminal sends SIGINT to the whole process group, workers
 *    included; a worker that died of it would look like a crash and be restarted. The master ends
 *    its workers in order instead (`bye`).
 * 3. **Install no SIGTERM handler.** The default action ends the process even when its event loop is
 *    busy; a handler of the loop would not run then (finding F43), and nobody would be left to send
 *    SIGKILL after the master's death.
 * 4. **Open the channel** (descriptor 3), say `hello`, wait for the master's (the peer's handshake timeout applies: 5 s unless the options say otherwise), and serve calls.
 * 5. **End when the master does:** at end of input or on `bye`, with code 0.
 *
 * Exit codes: 0 an orderly end; 1 a failure (no hello from the master, a protocol error); 3 no
 * channel on descriptor 3; 4 the parent is not the master that started it; 5 the parent-death signal
 * could not be armed.
 */
final readonly class WorkerRuntime
{
    public const int EXIT_OK = 0;

    public const int EXIT_FAILURE = 1;

    public const int EXIT_NO_CHANNEL = 3;

    public const int EXIT_PARENT_MISMATCH = OrphanGuard::EXIT_PARENT_MISMATCH;

    public const int EXIT_NO_GUARD = 5;

    public function __construct(
        private LoggerInterface $logger,
        private RpcPeerOptions $options = new RpcPeerOptions(),
        private ParentDeathSignal $deathSignal = new ParentDeathSignal(),
        private InheritedChannel $channel = new InheritedChannel(),
        private Codec $codec = new JsonLineCodec(),
        private EnvelopeReader $reader = new EnvelopeReader(),
    ) {}

    /**
     * Runs the worker until its master ends the connection, and returns the exit code.
     *
     * @param RpcHandler $handler answers the calls of the master, usually a {@see \Raphp\Rpc\HandlerRegistry}
     */
    public function run(WorkerArguments $arguments, RpcHandler $handler): int
    {
        try {
            $guarded = (new OrphanGuard($arguments->armDeathSignal ? $this->deathSignal : null))->protect($arguments->parentPid);
        } catch (LibcException $exception) {
            $this->logger->error('the worker cannot protect itself from outliving its master', ['worker' => $arguments->name, 'problem' => $exception->getMessage()]);

            return self::EXIT_NO_GUARD;
        }

        if (!$guarded) {
            $this->logger->error('the worker was started by a master that is already gone', ['worker' => $arguments->name, 'expected_parent' => $arguments->parentPid]);

            return self::EXIT_PARENT_MISMATCH;
        }

        pcntl_signal(SIGINT, SIG_IGN);

        try {
            $transport = $this->channel->open();
        } catch (SpawnException $exception) {
            $this->logger->error('the worker has no channel to its master', ['worker' => $arguments->name, 'problem' => $exception->getMessage()]);

            return self::EXIT_NO_CHANNEL;
        }

        $peer = new RpcPeer($transport, $this->codec, $this->reader, $this->logger, $handler, $this->options, hello: new Hello(Protocol::VERSION, 'worker', $arguments->name, posix_getpid()));
        $peer->start();

        try {
            $peer->awaitHandshake();
        } catch (RpcException $exception) {
            $this->logger->error('the master did not complete the handshake', ['worker' => $arguments->name, 'problem' => $exception->getMessage()]);
            $peer->close();

            return self::EXIT_FAILURE;
        }

        $peer->awaitClosed();

        return $this->exitCodeFor($peer->closedBecause(), $arguments->name);
    }

    /**
     * An orderly end (the master closed the channel or said `bye`) is success; anything else is not.
     */
    private function exitCodeFor(?RpcException $reason, string $worker): int
    {
        if ($reason === null || $reason instanceof WorkerCrashedException || $reason instanceof WorkerUnavailableException) {
            $this->logger->info('the worker ends: its master closed the connection', ['worker' => $worker]);

            return self::EXIT_OK;
        }

        $this->logger->error('the worker ends because of an error on the connection', ['worker' => $worker, 'problem' => $reason->getMessage()]);

        return self::EXIT_FAILURE;
    }
}
