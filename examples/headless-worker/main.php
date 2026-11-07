<?php

/**
 * Headless example: a command-line master that calls a worker process.
 *
 *   php examples/headless-worker/main.php demo            the scripted tour (default)
 *   php examples/headless-worker/main.php job [--steps=N] one job; press Ctrl-C to cancel it
 *   php examples/headless-worker/main.php crash            only the crash and the recovery
 *
 * The output for people goes to stdout; the logs (and what the worker writes to stderr) go to stderr.
 */

declare(strict_types=1);

use Raphp\Core\Kernel;
use Raphp\Core\Logging\LoggerFactory;
use Raphp\Core\Logging\LogLevel;
use Raphp\Examples\HeadlessWorker\Application;
use Raphp\Examples\HeadlessWorker\ConsoleEvents;
use Raphp\Process\ChannelFactory;
use Raphp\Process\ProcessSpawner;
use Raphp\Process\SelfCommand;
use Raphp\Supervisor\RestartPolicy;
use Raphp\Supervisor\Supervisor;
use Raphp\Supervisor\WorkerDefinition;

require __DIR__ . '/../../vendor/autoload.php';

$arguments = array_slice($argv ?? [], 1);
$command = 'demo';
$steps = 10;
foreach ($arguments as $argument) {
    if (preg_match('/\A--steps=([1-9][0-9]{0,3})\z/', $argument, $match) === 1) {
        $steps = (int) $match[1];
    } elseif (in_array($argument, ['demo', 'job', 'crash'], true)) {
        $command = $argument;
    } else {
        fwrite(STDERR, "usage: main.php [demo|job|crash] [--steps=N]\n");

        exit(2);
    }
}

$logger = (new LoggerFactory())->create(LogLevel::Info, channel: 'master');
$supervisor = new Supervisor(new ProcessSpawner(new ChannelFactory(), $logger), $logger, new ConsoleEvents(STDOUT));
$supervisor->register(new WorkerDefinition(
    Application::WORKER,
    (new SelfCommand())->resolve(__DIR__ . '/worker.php'),
    restart: new RestartPolicy(initialDelay: 0.2),
));

$kernel = new Kernel($logger);
// Ending: no new calls, calls in flight finish, `bye`, then the worker ends (FRAMEWORK 5.5).
$kernel->onShutdown(static fn() => $supervisor->stop(), 'workers');

exit($kernel->run(static function (Kernel $kernel) use ($supervisor, $command, $steps): void {
    $supervisor->start();
    $supervisor->awaitReady(Application::WORKER);

    $application = new Application($supervisor, $kernel, STDOUT);
    $ok = match ($command) {
        'job' => $application->job($steps),
        'crash' => $application->crash(),
        default => $application->demo($steps),
    };

    if (!$ok && !$kernel->isStopping()) {
        $kernel->stop(Kernel::EXIT_ERROR);
    }
}));
