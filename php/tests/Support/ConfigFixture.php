<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use Raphp\Core\Config\Config;

/**
 * Builds a valid {@see Config} for tests that do not care about the manifest.
 */
final class ConfigFixture
{
    public static function config(string $logLevel = 'info'): Config
    {
        return new Config(
            name: 'acme/app',
            entry: 'src/main.php',
            basePath: '/tmp/acme-app',
            logLevel: $logLevel,
        );
    }
}
