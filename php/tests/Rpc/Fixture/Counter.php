<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

/**
 * A dependency of {@see FileService}, so a test can see the container autowire it and count how
 * many times a service was built.
 */
final class Counter
{
    public static int $built = 0;

    public function __construct()
    {
        ++self::$built;
    }
}
