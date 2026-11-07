<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

use InvalidArgumentException;
use Raphp\Process\WorkerSpec;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcHandler;
use Raphp\Rpc\RpcPeerOptions;

/**
 * One logical worker: a name, how to start it, and how it is supervised (FRAMEWORK 5.2). A pool of
 * N is N separate processes behind the one name; with more than one, the members are named
 * `<name>-1`, `<name>-2` and so on.
 */
final readonly class WorkerDefinition
{
    public const int MAX_POOL = 256;

    /**
     * @param list<string> $command the program and its fixed arguments (see {@see \Raphp\Process\SelfCommand})
     * @param int $pool how many processes
     * @param RpcPeerOptions $rpc limits of each connection; the heartbeat is on by default (2 s, three misses)
     * @param RpcHandler $handler answers the calls a worker makes to the master
     * @param array<string, string> $environment added to the environment of each process
     * @param list<string> $extraArguments appended to each process's command line
     * @param float $handshakeTimeout seconds a new process has to say hello
     * @param float $readyTimeout seconds a call waits for a ready member before it fails with `E_WORKER_UNAVAILABLE`
     * @param float $shutdownGrace seconds a worker has to end after `bye` (when no other time is given to stop) before SIGTERM
     * @param float $terminateGrace seconds a worker has to end after SIGTERM before SIGKILL
     *
     * @throws InvalidArgumentException when a value is not usable
     */
    public function __construct(
        public string $name,
        public array $command,
        public int $pool = 1,
        public Routing $routing = Routing::RoundRobin,
        public RestartPolicy $restart = new RestartPolicy(),
        public RecyclePolicy $recycle = new RecyclePolicy(),
        public RpcPeerOptions $rpc = new RpcPeerOptions(pingInterval: 2.0),
        public RpcHandler $handler = new HandlerRegistry(),
        public array $environment = [],
        public ?string $cwd = null,
        public array $extraArguments = [],
        public bool $armDeathSignal = true,
        public float $handshakeTimeout = 10.0,
        public float $readyTimeout = 5.0,
        public float $shutdownGrace = 5.0,
        public float $terminateGrace = 2.0,
    ) {
        if (preg_match(WorkerSpec::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('a worker name starts with a letter and holds only letters, digits, "_" and "-" (at most 64)');
        }

        if ($command === [] || $command[0] === '') {
            throw new InvalidArgumentException('the command needs at least an executable');
        }

        if ($pool < 1 || $pool > self::MAX_POOL) {
            throw new InvalidArgumentException('a pool has from 1 to ' . self::MAX_POOL . ' members');
        }

        if ($handshakeTimeout <= 0.0 || $readyTimeout <= 0.0 || $shutdownGrace <= 0.0 || $terminateGrace <= 0.0) {
            throw new InvalidArgumentException('every timeout must be above 0');
        }
    }

    /**
     * The name of one member of the pool, counting from 1.
     */
    public function memberName(int $index): string
    {
        return $this->pool === 1 ? $this->name : "{$this->name}-{$index}";
    }
}
