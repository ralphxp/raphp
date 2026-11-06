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
     * @return iterable<string, array{int, int}>
     */
    public static function nonPositiveLimits(): iterable
    {
        yield 'zero line size' => [0, 64];
        yield 'negative line size' => [-1, 64];
        yield 'zero depth' => [1024, 0];
        yield 'negative depth' => [1024, -5];
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
    public function testNonPositiveLimitsAreRefused(int $maxLineBytes, int $maxDepth): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonLineCodec($maxLineBytes, $maxDepth);
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
