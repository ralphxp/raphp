<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Container;

use DateTimeImmutable;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Raphp\Core\Clock\SystemClock;
use Raphp\Core\Config\Config;
use Raphp\Core\Container\ContainerFactory;
use Raphp\Tests\Support\ConfigFixture;
use Raphp\Tests\Support\FrozenClock;
use Raphp\Tests\Support\NeedsLoggerAndClock;

final class ContainerFactoryTest extends TestCase
{
    public function testItBuildsAPsr11Container(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertTrue($container->has(Config::class));
        self::assertFalse($container->has('does.not.exist'));
    }

    public function testAnUnknownServiceThrowsAPsr11NotFoundException(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        $this->expectException(NotFoundExceptionInterface::class);

        $container->get('does.not.exist');
    }

    public function testTheConfigIsAvailableAsTheSameInstance(): void
    {
        $config = ConfigFixture::config();
        $container = (new ContainerFactory())->create($config);

        self::assertSame($config, $container->get(Config::class));
    }

    public function testTheLoggerHonoursTheConfiguredLevel(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config('warning'));

        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(Logger::class, $logger);
        $handlers = $logger->getHandlers();
        self::assertCount(1, $handlers);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]);
        self::assertSame(Level::Warning, $handlers[0]->getLevel());
    }

    public function testTheClockIsTheSystemClock(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        self::assertInstanceOf(SystemClock::class, $container->get(ClockInterface::class));
    }

    public function testServicesAreSharedInstances(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        self::assertSame($container->get(LoggerInterface::class), $container->get(LoggerInterface::class));
        self::assertSame($container->get(ClockInterface::class), $container->get(ClockInterface::class));
    }

    public function testAutowiringInjectsTheRegisteredServices(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        $service = $container->get(NeedsLoggerAndClock::class);

        self::assertInstanceOf(NeedsLoggerAndClock::class, $service);
        self::assertSame($container->get(LoggerInterface::class), $service->logger);
        self::assertSame($container->get(ClockInterface::class), $service->clock);
    }

    public function testOverridesReplaceTheDefaultsAndReachAutowiredServices(): void
    {
        $frozen = new FrozenClock(new DateTimeImmutable('2026-11-06T12:00:00+00:00'));
        $container = (new ContainerFactory())->create(ConfigFixture::config(), [ClockInterface::class => $frozen]);

        self::assertSame($frozen, $container->get(ClockInterface::class));
        $service = $container->get(NeedsLoggerAndClock::class);
        self::assertInstanceOf(NeedsLoggerAndClock::class, $service);
        self::assertSame($frozen, $service->clock);
    }

    public function testTwoContainersDoNotShareState(): void
    {
        $factory = new ContainerFactory();
        $first = $factory->create(ConfigFixture::config('debug'));
        $second = $factory->create(ConfigFixture::config('error'));

        self::assertNotSame($first->get(LoggerInterface::class), $second->get(LoggerInterface::class));
        self::assertNotSame($first->get(Config::class), $second->get(Config::class));
    }
}
