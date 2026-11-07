<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Codec;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Ipc\Codec\JsonLineCodec;

final class JsonLineCodecTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}|array{int, int, int}>
     */
    public static function nonPositiveLimits(): iterable
    {
        yield 'zero line size' => [0, 64];
        yield 'negative line size' => [-1, 64];
        yield 'zero depth' => [1024, 0];
        yield 'negative depth' => [1024, -5];
        yield 'zero containers' => [1024, 64, 0];
        yield 'negative containers' => [1024, 64, -1];
    }

    public function testAMessageBecomesOneCompactLineEndingInANewline(): void
    {
        $line = (new JsonLineCodec())->encode(['id' => 1, 'kind' => 'req', 'params' => ['a' => [1, 2], 'b' => null]]);

        self::assertSame('{"id":1,"kind":"req","params":{"a":[1,2],"b":null}}' . "\n", $line);
    }

    public function testSlashesStayReadableAndNonAsciiIsEscapedSoTheLineIsPlainAscii(): void
    {
        $line = (new JsonLineCodec())->encode(['path' => '/tmp/x', 'text' => "caf\u{e9} \u{20ac} \u{1f600}"]);

        self::assertSame('{"path":"/tmp/x","text":"caf\\u00e9 \\u20ac \\ud83d\\ude00"}' . "\n", $line);
        self::assertSame(1, preg_match('/^[\x20-\x7e]*\n\z/', $line));
    }

    public function testNewlinesAndOtherControlCharactersInsideStringsAreEscapedSoALineNeverBreaks(): void
    {
        $line = (new JsonLineCodec())->encode(['text' => "one\ntwo\rthree\tfour\0five\x1b \u{2028} \u{2029}"]);

        self::assertSame(1, substr_count($line, "\n"), 'only the terminating newline may be raw');
        self::assertStringEndsWith("\n", $line);
        self::assertSame(1, preg_match('/^[\x20-\x7e]*\n\z/', $line));
    }

    public function testAnEmptyMessageIsAnObjectNotAList(): void
    {
        self::assertSame("{}\n", (new JsonLineCodec())->encode([]));
    }

    public function testAListIsNotAMessage(): void
    {
        try {
            (new JsonLineCodec())->encode([1, 2, 3]);
            self::fail('Expected ProtocolException.');
        } catch (ProtocolException $exception) {
            self::assertStringContainsString('a message must be a JSON object, not a list', $exception->getMessage());
        }
    }

    public function testInvalidUtf8CannotBeEncodedAndTheCauseIsKept(): void
    {
        try {
            (new JsonLineCodec())->encode(['text' => "bad \xff byte"]);
            self::fail('Expected ProtocolException.');
        } catch (ProtocolException $exception) {
            self::assertStringContainsString('cannot be encoded as JSON', $exception->getMessage());
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    public function testNotANumberCannotBeEncoded(): void
    {
        $this->expectException(ProtocolException::class);

        (new JsonLineCodec())->encode(['value' => NAN]);
    }

    public function testTooDeepANestingCannotBeEncoded(): void
    {
        $nested = [];
        for ($level = 0; $level < 600; $level++) {
            $nested = ['x' => $nested];
        }

        $this->expectException(ProtocolException::class);

        (new JsonLineCodec())->encode($nested);
    }

    public function testALineOfExactlyTheLimitIsAcceptedAndOneByteMoreIsRefused(): void
    {
        $message = ['a' => 'xxxx'];
        $length = strlen('{"a":"xxxx"}');

        self::assertSame('{"a":"xxxx"}' . "\n", (new JsonLineCodec($length))->encode($message));

        try {
            (new JsonLineCodec($length - 1))->encode($message);
            self::fail('Expected PayloadTooLargeException.');
        } catch (PayloadTooLargeException $exception) {
            self::assertSame(['limit' => $length - 1], $exception->data);
            self::assertStringContainsString('larger than the limit of ' . ($length - 1) . ' bytes', $exception->getMessage());
        }
    }

    public function testTheLimitDoesNotCountTheNewline(): void
    {
        $length = strlen('{"kind":"ping"}');

        self::assertSame("{\"kind\":\"ping\"}\n", (new JsonLineCodec($length))->encode(['kind' => 'ping']));
    }

    #[DataProvider('nonPositiveLimits')]
    public function testNonPositiveLimitsAreRefused(int $maxLineBytes, int $maxDepth, int $maxContainers = 10): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonLineCodec($maxLineBytes, $maxDepth, $maxContainers);
    }

    /**
     * A message nested `$levels` deep, counting the message itself as level 1.
     *
     * @return array<string, mixed>
     */
    private function nested(int $levels): array
    {
        $message = ['leaf' => 1];
        for ($level = 1; $level < $levels; $level++) {
            $message = ['x' => $message];
        }

        return $message;
    }

    public function testTheEncoderAndTheDecoderAgreeExactlyOnTheDepthLimit(): void
    {
        $codec = new JsonLineCodec(maxDepth: 5);
        $unlimited = new JsonLineCodec(maxDepth: 500);

        for ($levels = 1; $levels <= 8; $levels++) {
            $encodes = true;
            try {
                $codec->encode($this->nested($levels));
            } catch (ProtocolException) {
                $encodes = false;
            }

            $decoder = $codec->createDecoder();
            $decoder->feed($unlimited->encode($this->nested($levels)));
            $decodes = is_array($decoder->next());

            self::assertSame($levels <= 5, $encodes, "encoding {$levels} levels");
            self::assertSame($encodes, $decodes, "the decoder must accept exactly what the encoder accepts ({$levels} levels)");
        }
    }

    public function testTheDefaultDepthLimitIsSixtyFourLevelsForEncodingAndDecoding(): void
    {
        $codec = new JsonLineCodec();
        $unlimited = new JsonLineCodec(maxDepth: 500);

        $codec->encode($this->nested(64));
        $decoder = $codec->createDecoder();
        $decoder->feed($unlimited->encode($this->nested(64)));
        self::assertIsArray($decoder->next());

        try {
            $codec->encode($this->nested(65));
            self::fail('Expected ProtocolException.');
        } catch (ProtocolException) {
            self::addToAssertionCount(1);
        }

        $decoder->feed($unlimited->encode($this->nested(65)));
        self::assertInstanceOf(ProtocolException::class, $decoder->next());
    }

    public function testAFloatThatLooksLikeAnIntegerStaysAFloat(): void
    {
        $codec = new JsonLineCodec();
        $line = $codec->encode(['a' => 10.0, 'b' => -0.0, 'c' => 10, 'd' => 0.5]);

        self::assertSame('{"a":10.0,"b":-0.0,"c":10,"d":0.5}' . "\n", $line);

        $decoder = $codec->createDecoder();
        $decoder->feed($line);
        $decoded = $decoder->next();

        self::assertIsArray($decoded);
        self::assertSame(10.0, $decoded['a']);
        self::assertSame(10, $decoded['c']);
    }

    public function testTheContainerLimitIsExactAndAppliesToEncodingAndDecoding(): void
    {
        $codec = new JsonLineCodec(maxContainers: 5);
        $fits = ['a' => [[], [], []]];
        $tooMany = ['a' => [[], [], [], []]];

        self::assertSame('{"a":[[],[],[]]}' . "\n", $codec->encode($fits));

        try {
            $codec->encode($tooMany);
            self::fail('Expected PayloadTooLargeException.');
        } catch (PayloadTooLargeException $exception) {
            self::assertSame(['limit' => 5], $exception->data);
        }

        $decoder = $codec->createDecoder();
        $decoder->feed('{"a":[[],[],[]]}' . "\n" . '{"a":[[],[],[],[]]}' . "\n" . '{"ok":1}' . "\n");

        $accepted = $decoder->next();
        $refused = $decoder->next();
        $afterwards = $decoder->next();

        self::assertSame($fits, $accepted);
        self::assertInstanceOf(ProtocolException::class, $refused);
        self::assertStringContainsString('more than 5 arrays and objects', $refused->getMessage());
        self::assertSame(['ok' => 1], $afterwards, 'a refused line must not stop the decoder');
        self::assertFalse($decoder->isDead());
    }

    public function testALineOfTinyArraysIsRefusedBeforeItIsDecodedSoItCostsNoMemory(): void
    {
        $line = '{"a":[' . str_repeat('[1],', 400_000) . '[1]]}' . "\n";
        $decoder = (new JsonLineCodec())->createDecoder();
        $decoder->feed($line);
        $before = memory_get_usage();

        $item = $decoder->next();

        self::assertInstanceOf(ProtocolException::class, $item);
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'refusing the line must not decode it');
    }

    public function testTheDefaultContainerLimitIsTwoHundredThousand(): void
    {
        self::assertSame(200_000, JsonLineCodec::DEFAULT_MAX_CONTAINERS);
    }

    public function testEachDecoderHoldsItsOwnPartialInput(): void
    {
        $codec = new JsonLineCodec();
        $first = $codec->createDecoder();
        $second = $codec->createDecoder();

        $first->feed('{"kind":"pi');
        $second->feed("{\"kind\":\"pong\"}\n");

        self::assertNull($first->next());
        self::assertSame(['kind' => 'pong'], $second->next());
        self::assertNotSame($first, $second);
    }

    public function testTheDefaultsAreEightMebibytesAndSixtyFourLevels(): void
    {
        self::assertSame(8 * 1024 * 1024, JsonLineCodec::DEFAULT_MAX_LINE_BYTES);
        self::assertSame(64, JsonLineCodec::DEFAULT_MAX_DEPTH);
    }
}
