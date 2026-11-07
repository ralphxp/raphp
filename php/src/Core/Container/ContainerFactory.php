<?php

declare(strict_types=1);

namespace Raphp\Core\Container;

use function DI\autowire;

use DI\ContainerBuilder;

use function DI\factory;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Raphp\Core\Clock\SystemClock;
use Raphp\Core\Config\Config;
use Raphp\Core\Logging\LoggerFactory;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Filesystem\Filesystem;

/**
 * The composition root: builds the PSR-11 container (PHP-DI 7, D24).
 *
 * It is the only place that wires services. Everything else receives its dependencies
 * through its constructor (O9). There are no helpers and no static access to the container.
 *
 * Services must be built without suspending the current Fiber. PHP-DI's circular-dependency
 * guard is kept per container, not per Fiber (verified with 7.1.1), so if a constructor or
 * factory suspends while a service is being built, a second Fiber asking for the same service
 * fails with a misleading "Circular dependency" error. Do asynchronous initialisation in a
 * separate step after the container is built (FRAMEWORK 4.9, risk R24).
 */
final class ContainerFactory
{
    /**
     * @param array<string, mixed> $overrides service definitions, in PHP-DI syntax and keyed by
     *                                        service id, that replace the defaults (used by tests
     *                                        and by later phases to swap an implementation)
     */
    public function create(Config $config, array $overrides = []): ContainerInterface
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);
        $builder->addDefinitions([
            Config::class => $config,
            ClockInterface::class => autowire(SystemClock::class),
            Filesystem::class => autowire(CooperativeFilesystem::class),
            LoggerInterface::class => factory(
                static fn(Config $config, ClockInterface $clock): LoggerInterface => (new LoggerFactory())->create(
                    $config->logLevel,
                    clock: $clock,
                ),
            ),
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }
}
