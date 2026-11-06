<?php

declare(strict_types=1);

namespace Raphp\Tests\Core\Logging;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel as PsrLogLevel;
use Raphp\Core\Logging\LogLevel;
use ReflectionClass;

final class LogLevelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, LogLevel}>
     */
    public static function spellings(): iterable
    {
        foreach (LogLevel::cases() as $level) {
            yield $level->value => [$level->value, $level];
            yield strtoupper($level->value) => [strtoupper($level->value), $level];
            yield ucfirst($level->value) => [ucfirst($level->value), $level];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notLevels(): iterable
    {
        yield 'unknown' => ['loud'];
        yield 'empty' => [''];
        yield 'leading space' => [' info'];
        yield 'trailing newline' => ["info\n"];
        yield 'a PHP constant name' => ['LOG_INFO'];
    }

    public function testTheCasesAreExactlyThePsr3Levels(): void
    {
        $psr = array_values((new ReflectionClass(PsrLogLevel::class))->getConstants());
        $ours = array_map(static fn(LogLevel $level): string => $level->value, LogLevel::cases());
        sort($psr);
        sort($ours);

        self::assertSame($psr, $ours);
    }

    public function testTheCasesRunFromMostToLeastSevere(): void
    {
        self::assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            array_map(static fn(LogLevel $level): string => $level->value, LogLevel::cases()),
        );
    }

    #[DataProvider('spellings')]
    public function testANameIsFoundIgnoringCase(string $name, LogLevel $expected): void
    {
        self::assertSame($expected, LogLevel::fromName($name));
    }

    #[DataProvider('notLevels')]
    public function testAnythingElseIsNotALevel(string $name): void
    {
        self::assertNull(LogLevel::fromName($name));
    }
}
