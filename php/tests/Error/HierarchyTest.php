<?php

declare(strict_types=1);

namespace Raphp\Tests\Error;

use PHPUnit\Framework\TestCase;
use Raphp\Core\Config\ConfigLoader;
use Raphp\Core\Config\InvalidConfigException;
use Raphp\Error\RaphpException;

/**
 * That a module exception is a RaphpException, and not an IPC error, is enforced by the type
 * system: PHPStan rejects any assertion about it as always true or always false.
 */
final class HierarchyTest extends TestCase
{
    public function testAFailureFromARealModuleIsCaughtAsARaphpException(): void
    {
        $missing = sys_get_temp_dir() . '/raphp-hierarchy-' . bin2hex(random_bytes(6)) . '/missing.json';

        try {
            (new ConfigLoader())->load($missing);
            self::fail('Expected an exception.');
        } catch (RaphpException $exception) {
            self::assertInstanceOf(InvalidConfigException::class, $exception);
        }
    }
}
