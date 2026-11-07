<?php

declare(strict_types=1);

namespace Raphp\Process;

use Amp\ByteStream\ReadableResourceStream;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Raphp\Ipc\Transport\StreamTransport;

/**
 * Starts worker processes (FRAMEWORK 5.1). This is the only code allowed to call `proc_open`.
 *
 * - **An array command, never a string** (finding F2): the worker is then the master's own child,
 *   not a child of `/bin/sh`, so the kernel's parent-death signal is tied to the right process.
 *   `amphp/process` is not used because it wraps every command in a shell script and takes
 *   descriptor 3 for itself (F18, D18).
 * - **The channel is descriptor 3**, from {@see ChannelFactory}; the master closes its copy of the
 *   worker's end right after the process starts, so end of input reaches the worker when the
 *   master goes away.
 * - **Descriptors 0 to 2:** stdin is `/dev/null`; stdout and stderr are pipes that are read
 *   from the start to the end of the process ({@see OutputCapture}).
 * - **The command line** gets `--role=worker`, `--name=<name>` and `--parent-pid=<this pid>`, which
 *   the worker passes to {@see OrphanGuard}.
 */
final readonly class ProcessSpawner
{
    public function __construct(
        private ChannelFactory $channels,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws SpawnException when the channel cannot be made or the process cannot be started
     */
    public function spawn(WorkerSpec $spec): WorkerHandle
    {
        $parentPid = $spec->parentPid ?? getmypid();
        if ($parentPid === false) {
            throw new SpawnException('this process cannot tell its own pid, so a worker cannot be told who its parent is');
        }

        $channel = $this->channels->create();

        $arguments = [
            ...$spec->command,
            '--role=worker',
            "--name={$spec->name}",
            "--parent-pid={$parentPid}",
        ];
        if (!$spec->armDeathSignal) {
            $arguments[] = '--no-pdeathsig';
        }

        array_push($arguments, ...$spec->extraArguments);

        $pipes = [];
        error_clear_last();
        $process = @proc_open(
            $arguments,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => $channel->child],
            $pipes,
            $spec->cwd,
            [...getenv(), ...$spec->environment],
        );

        // The master must not keep the worker's end: only then does the worker see end of input
        // when the master goes away.
        fclose($channel->child);

        if ($process === false) {
            fclose($channel->parent);
            $reason = error_get_last()['message'] ?? 'unknown error';

            throw new SpawnException("could not start the worker \"{$spec->name}\": {$reason}");
        }

        $status = proc_get_status($process);
        $stdout = new OutputCapture(new ReadableResourceStream($pipes[1]), $spec->name, 'stdout', LogLevel::INFO, $this->logger);
        $stderr = new OutputCapture(new ReadableResourceStream($pipes[2]), $spec->name, 'stderr', LogLevel::WARNING, $this->logger);

        $this->logger->info('worker started', ['worker' => $spec->name, 'pid' => $status['pid']]);

        return new WorkerHandle($spec->name, $status['pid'], $process, StreamTransport::fromResource($channel->parent), $stdout, $stderr, $this->logger);
    }
}
