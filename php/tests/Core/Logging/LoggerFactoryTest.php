<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Logging;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Core\Logging\LoggerFactory;
use Raphp\Core\Logging\LogLevel;
use Raphp\Tests\Support\FrozenClock;

final class LoggerFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{LogLevel}>
     */
    public static function levels(): iterable
    {
        foreach (LogLevel::cases() as $level) {
            yield $level->value => [$level];
        }
    }

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

    private function frozen(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable('2001-01-01T00:00:00+00:00'));
    }

    #[DataProvider('levels')]
    public function testARecordIsWrittenExactlyWhenItIsAsSevereAsTheConfiguredLevel(LogLevel $configured): void
    {
        $order = LogLevel::cases();
        $stream = $this->memoryStream();
        $logger = (new LoggerFactory())->create($configured, $stream);

        foreach ($order as $record) {
            $logger->log($record->value, "record-{$record->value}");
        }

        $output = $this->contents($stream);
        $threshold = array_search($configured, $order, true);
        self::assertIsInt($threshold);
        foreach ($order as $position => $record) {
            if ($position <= $threshold) {
                self::assertStringContainsString("record-{$record->value}", $output, "{$record->value} should be written at {$configured->value}");
            } else {
                self::assertStringNotContainsString("record-{$record->value}", $output, "{$record->value} should be dropped at {$configured->value}");
            }
        }
    }

    public function testALineHasAnExactShape(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create(LogLevel::Info, $stream, clock: $this->frozen())->info('hello');

        self::assertSame("[2001-01-01T00:00:00.000+00:00] raphp.INFO: hello\n", $this->contents($stream));
    }

    public function testContextIsWrittenAsJsonOnTheSameLine(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create(LogLevel::Debug, $stream, clock: $this->frozen())->error('worker died', ['name' => 'main', 'pid' => 42]);

        self::assertSame("[2001-01-01T00:00:00.000+00:00] raphp.ERROR: worker died {\"name\":\"main\",\"pid\":42}\n", $this->contents($stream));
    }

    public function testEachRecordIsExactlyOneLine(): void
    {
        $stream = $this->memoryStream();
        $logger = (new LoggerFactory())->create(LogLevel::Debug, $stream, clock: $this->frozen());

        $logger->info("first\nsecond");
        $logger->info('third');

        self::assertSame(
            "[2001-01-01T00:00:00.000+00:00] raphp.INFO: first second\n[2001-01-01T00:00:00.000+00:00] raphp.INFO: third\n",
            $this->contents($stream),
        );
    }

    public function testTheChannelCanBeNamed(): void
    {
        $stream = $this->memoryStream();
        (new LoggerFactory())->create(LogLevel::Info, $stream, 'worker.main', $this->frozen())->info('ready');

        self::assertSame("[2001-01-01T00:00:00.000+00:00] worker.main.INFO: ready\n", $this->contents($stream));
    }

    public function testTimestampsAreUtcWhateverTheProcessTimezoneIs(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $stream = $this->memoryStream();
            (new LoggerFactory())->create(LogLevel::Info, $stream)->info('now');

            self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00\] raphp\.INFO: now\n$/', $this->contents($stream));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testTheGivenClockSuppliesTheTime(): void
    {
        $stream = $this->memoryStream();
        $clock = new FrozenClock(new DateTimeImmutable('2030-05-06T07:08:09.123456+02:00'));

        (new LoggerFactory())->create(LogLevel::Info, $stream, clock: $clock)->info('later');

        self::assertSame("[2030-05-06T05:08:09.123+00:00] raphp.INFO: later\n", $this->contents($stream));
    }
}
