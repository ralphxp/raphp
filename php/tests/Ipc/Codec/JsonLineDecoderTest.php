<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Codec;

use PHPUnit\Framework\TestCase;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RpcException;
use Raphp\Ipc\Codec\Decoder;
use Raphp\Ipc\Codec\JsonLineCodec;

final class JsonLineDecoderTest extends TestCase
{
    private const string PING = '{"kind":"ping"}';

    private function decoder(int $maxLineBytes = JsonLineCodec::DEFAULT_MAX_LINE_BYTES, int $maxDepth = 64): Decoder
    {
        return (new JsonLineCodec($maxLineBytes, $maxDepth))->createDecoder();
    }

    /**
     * @return list<array<array-key, mixed>|RpcException>
     */
    private function drain(Decoder $decoder): array
    {
        $items = [];
        while (($item = $decoder->next()) !== null) {
            $items[] = $item;
        }

        return $items;
    }

    private function goldenStream(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../../docs/protocol/golden/messages.ndjson');
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function goldenMessages(): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../../../../docs/protocol/golden/messages.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertTrue(array_is_list($decoded));

        /** @var list<array<array-key, mixed>> $decoded */
        return $decoded;
    }

    public function testOneCompleteLineGivesOneMessageThenNull(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(self::PING . "\n");

        self::assertSame(['kind' => 'ping'], $decoder->next());
        self::assertNull($decoder->next());
    }

    public function testSeveralMessagesInOneChunkComeOutInOrder(): void
    {
        $decoder = $this->decoder();
        $decoder->feed("{\"kind\":\"ping\"}\n{\"kind\":\"pong\"}\n{\"kind\":\"bye\",\"reason\":\"x\"}\n");

        self::assertSame([['kind' => 'ping'], ['kind' => 'pong'], ['kind' => 'bye', 'reason' => 'x']], $this->drain($decoder));
    }

    public function testNothingIsReturnedUntilTheNewlineArrives(): void
    {
        $decoder = $this->decoder();
        $decoder->feed('{"kind":"ping"}');

        self::assertNull($decoder->next());

        $decoder->feed("\n");

        self::assertSame(['kind' => 'ping'], $decoder->next());
    }

    public function testTheResultDoesNotDependOnWhereTheInputIsCutIntoTwoChunks(): void
    {
        $stream = $this->goldenStream();
        $expected = $this->goldenMessages();

        for ($cut = 0; $cut <= strlen($stream); $cut++) {
            $decoder = $this->decoder();
            $items = [];
            foreach ([substr($stream, 0, $cut), substr($stream, $cut)] as $chunk) {
                $decoder->feed($chunk);
                array_push($items, ...$this->drain($decoder));
            }

            self::assertSame($expected, $items, "cut at byte {$cut}");
            self::assertNull($decoder->finish(), "cut at byte {$cut}");
        }
    }

    public function testTheResultDoesNotDependOnFeedingOneByteAtATime(): void
    {
        $stream = $this->goldenStream();
        $decoder = $this->decoder();
        $items = [];

        for ($index = 0; $index < strlen($stream); $index++) {
            $decoder->feed($stream[$index]);
            array_push($items, ...$this->drain($decoder));
        }

        self::assertSame($this->goldenMessages(), $items);
        self::assertNull($decoder->finish());
    }

    public function testTheResultDoesNotDependOnRandomChunking(): void
    {
        $stream = $this->goldenStream();
        $expected = $this->goldenMessages();

        for ($seed = 1; $seed <= 40; $seed++) {
            mt_srand($seed);
            $decoder = $this->decoder();
            $items = [];
            for ($position = 0; $position < strlen($stream);) {
                $size = mt_rand(1, 97);
                $decoder->feed(substr($stream, $position, $size));
                $position += $size;
                array_push($items, ...$this->drain($decoder));
            }

            self::assertSame($expected, $items, "seed {$seed}");
        }
    }

    public function testAHugeChunkOfManySmallMessagesIsReadInLinearTime(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(str_repeat(self::PING . "\n", 60_000));
        $started = hrtime(true);

        $count = count($this->drain($decoder));

        self::assertSame(60_000, $count);
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9, 'reading must not slow down as the buffer grows');
    }

    public function testMessagesKeepFlowingAcrossManyFeedsSoTheBufferIsCompacted(): void
    {
        $decoder = $this->decoder();
        $count = 0;

        for ($round = 0; $round < 300; $round++) {
            $decoder->feed(str_repeat(self::PING . "\n", 400));
            $count += count($this->drain($decoder));
        }

        self::assertSame(120_000, $count);
        self::assertNull($decoder->finish());
    }

    public function testAnEmptyObjectIsAMessageForTheEnvelopeLayerToJudge(): void
    {
        $decoder = $this->decoder();
        $decoder->feed("{}\n");

        self::assertSame([], $decoder->next());
    }

    public function testAnObjectWhoseKeysAreDigitsIsStillAnObjectEvenThoughPhpMakesItAList(): void
    {
        $decoder = $this->decoder();
        $decoder->feed("{\"0\":\"a\",\"1\":\"b\"}\n");

        self::assertSame([0 => 'a', 1 => 'b'], $decoder->next());
    }

    public function testAPartialLinePendingWhenTheBufferIsCompactedStillCompletes(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(str_repeat(self::PING . "\n", 5_000) . '{"kind":"po');

        self::assertCount(5_000, $this->drain($decoder));

        $decoder->feed("ng\"}\n");

        self::assertSame(['kind' => 'pong'], $decoder->next());
        self::assertNull($decoder->next());
        self::assertNull($decoder->finish());
    }

    public function testAKeyThatAppearsTwiceKeepsItsLastValueAsPhpDoes(): void
    {
        $decoder = $this->decoder();
        $decoder->feed("{\"a\":1,\"b\":2,\"a\":3}\n");

        self::assertSame(['a' => 3, 'b' => 2], $decoder->next(), 'FRAMEWORK 6.3 documents this: the last value wins');
    }

    public function testACarriageReturnBeforeTheNewlineIsTolerated(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(self::PING . "\r\n");

        self::assertSame(['kind' => 'ping'], $decoder->next());
    }

    // ---------------------------------------------------------------- bad lines

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badLines(): iterable
    {
        yield 'not JSON' => ['{nope}', 'not valid JSON'];
        yield 'a truncated object' => ['{"kind":"ping"', 'not valid JSON'];
        yield 'trailing garbage' => ['{"kind":"ping"} x', 'not valid JSON'];
        yield 'two objects on one line' => ['{"a":1}{"b":2}', 'not valid JSON'];
        yield 'invalid UTF-8' => ["{\"kind\":\"x\",\"a\":\"\xff\"}", 'invalid UTF-8'];
        yield 'an overlong UTF-8 sequence' => ["{\"a\":\"\xc0\xaf\"}", 'invalid UTF-8'];
        yield 'a raw control character in a string' => ["{\"a\":\"x\x01y\"}", 'not valid JSON'];
        yield 'a raw NUL in a string' => ["{\"a\":\"x\0y\"}", 'not valid JSON'];
        yield 'an unpaired surrogate escape' => ['{"a":"\ud800"}', 'not valid JSON'];
        yield 'a list' => ['[1,2]', 'must be a JSON object'];
        yield 'an empty list' => ['[]', 'must be a JSON object'];
        yield 'a string' => ['"text"', 'must be a JSON object'];
        yield 'a number' => ['42', 'must be a JSON object'];
        yield 'null' => ['null', 'must be a JSON object'];
        yield 'true' => ['true', 'must be a JSON object'];
        yield 'an object with leading space' => [' {"kind":"ping"}', 'must start with "{"'];
        yield 'a blank line' => ['', 'an empty line'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLines')]
    public function testABadLineIsReportedAsAProtocolErrorAndTheNextLineStillWorks(string $line, string $expectedMessage): void
    {
        $decoder = $this->decoder();
        $decoder->feed($line . "\n" . self::PING . "\n");

        $first = $decoder->next();
        self::assertInstanceOf(ProtocolException::class, $first);
        self::assertStringContainsString($expectedMessage, $first->getMessage());
        self::assertSame(['kind' => 'ping'], $decoder->next());
        self::assertFalse($decoder->isDead(), 'a bad line must not stop the decoder');
    }

    public function testNestingBeyondTheDepthLimitIsAProtocolError(): void
    {
        $decoder = $this->decoder(maxDepth: 5);
        $decoder->feed(str_repeat('{"a":', 6) . '1' . str_repeat('}', 6) . "\n" . str_repeat('{"a":', 5) . '1' . str_repeat('}', 5) . "\n");

        self::assertInstanceOf(ProtocolException::class, $decoder->next(), 'six levels are one too many');
        self::assertIsArray($decoder->next(), 'five levels are allowed');
    }

    public function testGoodMessagesAroundABadLineAreNeverLost(): void
    {
        $decoder = $this->decoder();
        $decoder->feed("{\"kind\":\"res\"}\n{broken\n{\"kind\":\"bye\"}\n");

        $items = $this->drain($decoder);

        self::assertCount(3, $items);
        self::assertSame(['kind' => 'res'], $items[0]);
        self::assertInstanceOf(ProtocolException::class, $items[1]);
        self::assertSame(['kind' => 'bye'], $items[2]);
    }

    // ---------------------------------------------------------------- oversize

    public function testALineOfExactlyTheLimitIsAcceptedAndTheNewlineIsNotCounted(): void
    {
        $decoder = $this->decoder(strlen(self::PING));
        $decoder->feed(self::PING . "\n");

        self::assertSame(['kind' => 'ping'], $decoder->next());
        self::assertFalse($decoder->isDead());
    }

    public function testACompleteLineOneByteOverTheLimitIsFatal(): void
    {
        $decoder = $this->decoder(strlen(self::PING) - 1);
        $decoder->feed(self::PING . "\n" . self::PING . "\n");

        $item = $decoder->next();

        self::assertInstanceOf(PayloadTooLargeException::class, $item);
        self::assertSame(['limit' => strlen(self::PING) - 1], $item->data);
        self::assertTrue($decoder->isDead());
    }

    public function testAnUnfinishedLineIsNotYetTooLargeAtExactlyTheLimit(): void
    {
        $decoder = $this->decoder(16);
        $decoder->feed(str_repeat('x', 16));

        self::assertNull($decoder->next());
        self::assertFalse($decoder->isDead());
    }

    public function testAnUnfinishedLineBecomesTooLargeAsSoonAsItPassesTheLimit(): void
    {
        $decoder = $this->decoder(16);
        $decoder->feed(str_repeat('x', 17));

        self::assertInstanceOf(PayloadTooLargeException::class, $decoder->next());
        self::assertTrue($decoder->isDead());
    }

    public function testAPeerThatNeverSendsANewlineCannotMakeTheDecoderBufferWithoutEnd(): void
    {
        $limit = 1024 * 1024;
        $chunk = str_repeat('x', 64 * 1024);
        $decoder = $this->decoder($limit);
        $fed = 0;
        $reportedAt = null;

        for ($index = 0; $index < 100; $index++) {
            $decoder->feed($chunk);
            $fed += strlen($chunk);
            if ($decoder->next() instanceof PayloadTooLargeException) {
                $reportedAt = $fed;

                break;
            }
        }

        self::assertNotNull($reportedAt, 'the decoder must give up');
        self::assertLessThanOrEqual($limit + strlen($chunk), $reportedAt, 'it must give up within one chunk of the limit');
    }

    public function testTheOversizeProblemIsReportedOnceAndTheDecoderThenDiscardsEverything(): void
    {
        $decoder = $this->decoder(16);
        $decoder->feed(str_repeat('x', 40));

        self::assertInstanceOf(PayloadTooLargeException::class, $decoder->next());
        self::assertNull($decoder->next());

        $decoder->feed(self::PING . "\n");

        self::assertNull($decoder->next(), 'a dead decoder ignores further input');
        self::assertNull($decoder->finish());
        self::assertTrue($decoder->isDead());
    }

    public function testADeadDecoderDoesNotKeepBufferingWhatItIsFed(): void
    {
        $decoder = $this->decoder(16);
        $decoder->feed(str_repeat('x', 40));
        self::assertInstanceOf(PayloadTooLargeException::class, $decoder->next());
        $chunk = str_repeat('y', 1024 * 1024);
        $before = memory_get_usage();

        for ($index = 0; $index < 64; $index++) {
            $decoder->feed($chunk);
        }

        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $before, 'a dead decoder must drop its input');
    }

    public function testMessagesBeforeAnOversizeLineAreStillDelivered(): void
    {
        $decoder = $this->decoder(16);
        $decoder->feed(self::PING . "\n{\"kind\":\"ping\",\"padding\":\"xxxxxxxx\"}\n" . self::PING . "\n");

        $items = $this->drain($decoder);

        self::assertCount(2, $items);
        self::assertSame(['kind' => 'ping'], $items[0]);
        self::assertInstanceOf(PayloadTooLargeException::class, $items[1]);
    }

    // ---------------------------------------------------------------- end of input

    public function testInputThatEndsCleanlyReportsNothing(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(self::PING . "\n");
        $this->drain($decoder);

        self::assertNull($decoder->finish());
    }

    public function testInputThatEndsInTheMiddleOfAMessageIsAProtocolError(): void
    {
        $decoder = $this->decoder();
        $decoder->feed(self::PING . "\n{\"kind\":\"po");
        $this->drain($decoder);

        $problem = $decoder->finish();

        self::assertInstanceOf(ProtocolException::class, $problem);
        self::assertStringContainsString('closed in the middle of a message', $problem->getMessage());
    }

    public function testNothingAtAllIsACleanEnd(): void
    {
        self::assertNull($this->decoder()->finish());
    }

    public function testFeedingNothingChangesNothing(): void
    {
        $decoder = $this->decoder();
        $decoder->feed('');

        self::assertNull($decoder->next());
        self::assertNull($decoder->finish());
    }
}
