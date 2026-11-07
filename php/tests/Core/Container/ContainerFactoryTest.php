<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Container;

use DateTimeImmutable;
use DI\DependencyException;

use function DI\factory;

use Fiber;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Raphp\Core\Clock\SystemClock;
use Raphp\Core\Config\Config;
use Raphp\Core\Container\ContainerFactory;
use Raphp\Core\Logging\LogLevel;
use Raphp\Filesystem\CooperativeFilesystem;
use Raphp\Filesystem\Filesystem;
use Raphp\Tests\Support\ConfigFixture;
use Raphp\Tests\Support\FrozenClock;
use Raphp\Tests\Support\NeedsLoggerAndClock;

final class ContainerFactoryTest extends TestCase
{
    private function logger(mixed $logger): Logger
    {
        self::assertInstanceOf(Logger::class, $logger);

        return $logger;
    }

    private function levelOf(Logger $logger): Level
    {
        $handlers = $logger->getHandlers();
        self::assertCount(1, $handlers);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]);

        return $handlers[0]->getLevel();
    }

    public function testItKnowsItsOwnServicesAndNothingElse(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        self::assertTrue($container->has(Config::class));
        self::assertTrue($container->has(LoggerInterface::class));
        self::assertTrue($container->has(ClockInterface::class));
        self::assertTrue($container->has(Filesystem::class));
        self::assertFalse($container->has('does.not.exist'));
    }

    public function testTheFilesystemServiceIsTheCooperativeOne(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config());

        self::assertInstanceOf(CooperativeFilesystem::class, $container->get(Filesystem::class));
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
        $container = (new ContainerFactory())->create(ConfigFixture::config(LogLevel::Warning));

        $logger = $this->logger($container->get(LoggerInterface::class));

        self::assertSame(Level::Warning, $this->levelOf($logger));
    }

    public function testTheLoggerTakesItsTimeFromTheContainersClock(): void
    {
        $frozen = new FrozenClock(new DateTimeImmutable('2001-01-01T00:00:00+00:00'));
        $container = (new ContainerFactory())->create(ConfigFixture::config(), [ClockInterface::class => $frozen]);
        $logger = $this->logger($container->get(LoggerInterface::class));
        $capture = new TestHandler();
        $logger->setHandlers([$capture]);

        $logger->info('stamped');

        $records = $capture->getRecords();
        self::assertCount(1, $records);
        self::assertSame('2001-01-01T00:00:00+00:00', $records[0]->datetime->format('c'));
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
        $first = $factory->create(ConfigFixture::config(LogLevel::Debug));
        $second = $factory->create(ConfigFixture::config(LogLevel::Error));

        $firstLogger = $this->logger($first->get(LoggerInterface::class));
        $secondLogger = $this->logger($second->get(LoggerInterface::class));
        self::assertNotSame($firstLogger, $secondLogger);
        self::assertSame(Level::Debug, $this->levelOf($firstLogger));
        self::assertSame(Level::Error, $this->levelOf($secondLogger));
    }

    /**
     * Characterises a PHP-DI 7.1.1 limit (risk R24): its circular-dependency guard is kept per
     * container, not per Fiber. If this ever stops throwing, the rule that services must be
     * built without suspending can be reconsidered.
     */
    public function testConstructionMustNotSuspendBecausePhpDiIsNotFiberAware(): void
    {
        $container = (new ContainerFactory())->create(ConfigFixture::config(), [
            'slow' => factory(static function (): string {
                Fiber::suspend();

                return 'built';
            }),
        ]);
        $first = new Fiber(static fn(): mixed => $container->get('slow'));
        $second = new Fiber(static fn(): mixed => $container->get('slow'));

        $first->start();

        $this->expectException(DependencyException::class);
        $this->expectExceptionMessageMatches('/Circular dependency detected/');

        $second->start();
    }
}
