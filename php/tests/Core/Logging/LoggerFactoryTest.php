<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Logging;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raphp\Core\Logging\LoggerFactory;

final class LoggerFactoryTest extends TestCase
{
    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    public function testRecordsBelowTheLevelAreDropped(): void
    {
        $stream = $this->memoryStream();
        $logger = (new LoggerFactory())->create('warning', $stream);

        $logger->info('hidden');
        $logger->warning('shown');

        $output = $this->contents($stream);
        self::assertStringContainsString('raphp.WARNING: shown', $output);
        self::assertStringNotContainsString('hidden', $output);
    }

    public function testContextIsWrittenAsJsonOnTheSameLine(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create('debug', $stream)->error('worker died', ['name' => 'main', 'pid' => 42]);

        self::assertStringContainsString('raphp.ERROR: worker died {"name":"main","pid":42}', $this->contents($stream));
    }

    public function testEachRecordIsOneLine(): void
    {
        $stream = $this->memoryStream();
        $logger = (new LoggerFactory())->create('debug', $stream);

        $logger->info("first\nsecond");
        $logger->info('third');

        self::assertCount(2, explode("\n", rtrim($this->contents($stream), "\n")));
    }

    public function testTheChannelCanBeNamed(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create('info', $stream, 'worker.main')->info('ready');

        self::assertStringContainsString('worker.main.INFO: ready', $this->contents($stream));
    }

    public function testLevelNamesAreCaseInsensitive(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create('DEBUG', $stream)->debug('visible');

        self::assertStringContainsString('visible', $this->contents($stream));
    }

    public function testAnUnknownLevelIsRejected(): void
    {
        try {
            (new LoggerFactory())->create('loud');
            self::fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("'loud' is not a PSR-3 log level", $exception->getMessage());
        }
    }
}
