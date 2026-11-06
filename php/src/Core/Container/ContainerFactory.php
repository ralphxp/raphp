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

/**
 * The composition root: builds the PSR-11 container (PHP-DI 7, D24).
 *
 * It is the only place that wires services. Everything else receives its dependencies
 * through its constructor (O9). There are no helpers and no static access to the container.
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
            LoggerInterface::class => factory(
                static fn(Config $config): LoggerInterface => (new LoggerFactory())->create($config->logLevel),
            ),
            ClockInterface::class => autowire(SystemClock::class),
        ]);
        $builder->addDefinitions($overrides);

        return $builder->build();
    }
}
