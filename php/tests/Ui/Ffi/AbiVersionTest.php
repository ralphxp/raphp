<?php

declare(strict_types=1);

namespace Raphp\Tests\Ui\Ffi;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Ui\Ffi\AbiVersion;

final class AbiVersionTest extends TestCase
{
    public function testItIsMajorThenMinorInOneNumber(): void
    {
        $version = AbiVersion::fromInt(3 << 16 | 7);

        self::assertSame(3, $version->major);
        self::assertSame(7, $version->minor);
        self::assertSame(3 << 16 | 7, $version->toInt());
        self::assertSame('3.7', (string) $version);
    }

    /**
     * @return iterable<string, array{int, int, int, int, bool}>
     */
    public static function compatibility(): iterable
    {
        yield 'unstable 0.x: the same version' => [0, 4, 0, 4, true];
        yield 'unstable 0.x: a newer minor is not accepted' => [0, 4, 0, 5, false];
        yield 'unstable 0.x: an older minor is not accepted' => [0, 4, 0, 3, false];
        yield 'another major' => [1, 0, 2, 0, false];
        yield 'another major, lower' => [2, 0, 1, 9, false];
        yield 'stable: the same version' => [1, 2, 1, 2, true];
        yield 'stable: a newer minor adds functions only' => [1, 2, 1, 5, true];
        yield 'stable: an older minor lacks functions' => [1, 2, 1, 1, false];
    }

    #[DataProvider('compatibility')]
    public function testWhichLibrariesCodeThatNeedsAVersionCanUse(int $needMajor, int $needMinor, int $foundMajor, int $foundMinor, bool $expected): void
    {
        $need = new AbiVersion($needMajor, $needMinor);

        self::assertSame($expected, $need->isSatisfiedBy(new AbiVersion($foundMajor, $foundMinor)));
    }

    public function testTheHighestPartsRoundTripAndTooLargeOnesAreRefused(): void
    {
        self::assertSame(0xFFFF_FFFF, (new AbiVersion(0xFFFF, 0xFFFF))->toInt());
        self::assertEquals(new AbiVersion(0xFFFF, 0xFFFF), AbiVersion::fromInt(0xFFFF_FFFF));
        $this->expectException(InvalidArgumentException::class);

        new AbiVersion(0, 0x10000);
    }

    public function testANegativePartIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AbiVersion(-1, 0);
    }
}
