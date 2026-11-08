<?php

declare(strict_types=1);

namespace Raphp\Tests\Support;

use PHPUnit\Framework\Assert;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Raphp\Ui\Ffi\Library;
use Raphp\Ui\Ffi\Loader;

/**
 * Where the tests find the shim: the debug build that `make shim` makes with the `test-hooks` feature, and the
 * committed header.
 */
final class ShimFixture
{
    public const string HEADER = __DIR__ . '/../../../shim/include/raphp.h';

    public const string LIBRARY = __DIR__ . '/../../../shim/target/debug/libraphp.so';

    /**
     * Loads the library, or skips the test when it has not been built.
     */
    public static function library(?LoggerInterface $logger = null): Library
    {
        if (!is_file(self::LIBRARY)) {
            Assert::markTestSkipped('the test build of the shim does not exist: run make shim');
        }

        return (new Loader($logger ?? new NullLogger()))->load(self::LIBRARY, self::HEADER);
    }

    /**
     * The open descriptors of this process: number to target, from /proc/self/fd.
     *
     * @return array<string, string>
     */
    public static function descriptors(): array
    {
        $found = [];
        $entries = scandir('/proc/self/fd');
        foreach ($entries === false ? [] : $entries as $fd) {
            $target = $fd === '.' || $fd === '..' ? false : @readlink("/proc/self/fd/{$fd}");
            if ($target !== false) {
                $found[$fd] = $target;
            }
        }

        return $found;
    }
}
