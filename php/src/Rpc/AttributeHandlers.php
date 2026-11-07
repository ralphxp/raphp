<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Raphp\Rpc\Internal\MethodInvoker;
use ReflectionMethod;

/**
 * Turns the methods of a {@see MethodCatalog} into handlers of a {@see HandlerRegistry}, with the
 * services that own them taken from a PSR-11 container.
 *
 * Every service is fetched here, once, at boot, and not when the first call arrives: building a
 * service inside a request's Fiber could suspend, and PHP-DI's loop guard does not know about
 * Fibers (finding F26, risk R24).
 */
final readonly class AttributeHandlers
{
    public function __construct(private ContainerInterface $container) {}

    /**
     * A new registry holding every method of the catalog.
     *
     * @throws LogicException when a service or a method cannot be set up
     */
    public function build(MethodCatalog $catalog): HandlerRegistry
    {
        $registry = new HandlerRegistry();
        $this->register($registry, $catalog);

        return $registry;
    }

    /**
     * Adds every method of the catalog to the registry.
     *
     * @throws LogicException when a service or a method cannot be set up, or a name is already registered
     */
    public function register(HandlerRegistry $registry, MethodCatalog $catalog): void
    {
        /** @var array<string, object> $services */
        $services = [];

        foreach ($catalog->all() as $descriptor) {
            $services[$descriptor->class] ??= $this->service($descriptor->class);
            $invoker = MethodInvoker::create($services[$descriptor->class], new ReflectionMethod($descriptor->class, $descriptor->method));
            $registry->register($descriptor->name, $invoker->__invoke(...));
        }
    }

    /**
     * @throws LogicException
     */
    private function service(string $class): object
    {
        try {
            $service = $this->container->get($class);
        } catch (ContainerExceptionInterface $exception) {
            throw new LogicException("the container cannot provide {$class}, which has RPC methods: {$exception->getMessage()}", 0, $exception);
        }

        if (!is_object($service)) {
            throw new LogicException("the container returned something that is not an object for {$class}");
        }

        return $service;
    }
}
