<?php

/**
 * The worker's entry script. The master starts it (never by hand): the standard command line
 * (`--role=worker --name=<n> --parent-pid=<pid>`) and the channel on descriptor 3 come from the
 * supervisor.
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Raphp\Core\Logging\LoggerFactory;
use Raphp\Core\Logging\LogLevel;
use Raphp\Examples\HeadlessWorker\ReportService;
use Raphp\Rpc\AttributeHandlers;
use Raphp\Rpc\MethodCatalog;
use Raphp\Worker\WorkerArguments;
use Raphp\Worker\WorkerRuntime;

require __DIR__ . '/../../vendor/autoload.php';

try {
    $arguments = WorkerArguments::parse($argv ?? []);
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(2);
}

// What the worker writes to stderr, the master logs as a warning of that worker (FRAMEWORK 5.1).
$logger = (new LoggerFactory())->create(LogLevel::Info, channel: $arguments->name);

$container = (new ContainerBuilder())
    ->useAttributes(false)
    ->addDefinitions([LoggerInterface::class => $logger])
    ->build();
$handlers = (new AttributeHandlers($container))->build(MethodCatalog::fromClasses([ReportService::class]));

exit((new WorkerRuntime($logger))->run($arguments, $handlers));
