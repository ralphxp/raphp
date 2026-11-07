<?php

declare(strict_types=1);

namespace Raphp\Tests\Error;

use PHPUnit\Framework\TestCase;
use Raphp\Error\Text;

final class TextTest extends TestCase
{
    public function testTextThatFitsIsReturnedUnchanged(): void
    {
        self::assertSame('short', Text::truncate('short', 10));
        self::assertSame('exactly10!', Text::truncate('exactly10!', 10));
        self::assertSame('', Text::truncate('', 10));
    }

    public function testLongerTextIsCutAndMarkedAndTheMarkCountsInTheLimit(): void
    {
        $cut = Text::truncate('0123456789ABCDEF', 10);

        self::assertSame('0123456...', $cut);
        self::assertSame(10, strlen($cut));
    }

    public function testACutNeverSplitsAMultiByteCharacter(): void
    {
        foreach (["\u{e9}", "\u{20ac}", "\u{1f600}"] as $character) {
            for ($padding = 0; $padding < 6; $padding++) {
                $text = str_repeat('a', $padding) . str_repeat($character, 10);
                $cut = Text::truncate($text, 12);

                self::assertSame(1, preg_match('//u', $cut), 'the result must be valid UTF-8');
                self::assertLessThanOrEqual(12, strlen($cut));
            }
        }
    }

    public function testATinyLimitStillGivesValidOutputWithinTheLimit(): void
    {
        $cut = Text::truncate('abcdefgh', 4);

        self::assertSame('a...', $cut);
        self::assertSame('...', Text::truncate('abcdefgh', 3));
    }
}
