<?php

declare(strict_types=1);

namespace Raphp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/**
 * Guards the assumptions every later step relies on: the PHP floor, the required
 * extensions, and that tests run with all errors visible (finding F11).
 */
final class EnvironmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredExtensions(): iterable
    {
        foreach (['ffi', 'json', 'pcntl', 'posix', 'sockets'] as $extension) {
            yield $extension => [$extension];
        }
    }

    public function testPhpVersionMeetsTheFloor(): void
    {
        self::assertGreaterThanOrEqual(80500, PHP_VERSION_ID, 'Raphp needs PHP 8.5 or newer (D23)');
    }

    #[DataProvider('requiredExtensions')]
    public function testRequiredExtensionIsLoaded(string $extension): void
    {
        self::assertTrue(extension_loaded($extension), "ext-{$extension} is required (FRAMEWORK 9.2)");
    }

    /**
     * PHPUnit 13 narrows error_reporting() inside a test on purpose and tracks the original
     * level itself, so the value cannot be asserted. This proves the behaviour instead: a
     * deprecation raised in a test must reach PHPUnit (F11, F22).
     */
    #[IgnoreDeprecations]
    public function testDeprecationsReachPhpunit(): void
    {
        $this->expectUserDeprecationMessage('probe');

        trigger_error('probe', E_USER_DEPRECATED);
    }
}
