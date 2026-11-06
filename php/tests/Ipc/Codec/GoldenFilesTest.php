<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Codec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\RpcException;
use Raphp\Ipc\Codec\Decoder;
use Raphp\Ipc\Codec\JsonLineCodec;

/**
 * Pins the wire format to the files in docs/protocol/golden, which the Rust shim will read too.
 */
final class GoldenFilesTest extends TestCase
{
    private const string DIRECTORY = __DIR__ . '/../../../../docs/protocol/golden';

    /**
     * @return iterable<string, array{string, int, list<mixed>, string|null}>
     */
    public static function malformedCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(self::DIRECTORY . '/malformed.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($cases);

        foreach ($cases as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['name']);
            self::assertIsString($case['base64']);
            self::assertIsArray($case['items']);
            $limit = $case['maxLineBytes'] ?? JsonLineCodec::DEFAULT_MAX_LINE_BYTES;
            self::assertIsInt($limit);
            $atEnd = $case['atEnd'];
            self::assertTrue($atEnd === null || is_string($atEnd));

            yield $case['name'] => [(string) base64_decode($case['base64'], true), $limit, array_values($case['items']), $atEnd];
        }
    }

    /**
     * @return list<mixed>
     */
    private function outcomes(Decoder $decoder): array
    {
        $items = [];
        while (($item = $decoder->next()) !== null) {
            $items[] = $item instanceof RpcException ? 'error:' . $item->wireCode() : $item;
        }

        return $items;
    }

    public function testTheEncoderReproducesTheGoldenStreamByteForByte(): void
    {
        $messages = json_decode((string) file_get_contents(self::DIRECTORY . '/messages.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        $codec = new JsonLineCodec();
        $bytes = '';
        foreach ($messages as $message) {
            self::assertIsArray($message);
            $bytes .= $codec->encode($message);
        }

        self::assertSame((string) file_get_contents(self::DIRECTORY . '/messages.ndjson'), $bytes);
    }

    public function testTheDecoderReadsTheGoldenStreamBackToTheGoldenMessages(): void
    {
        $decoder = (new JsonLineCodec())->createDecoder();
        $decoder->feed((string) file_get_contents(self::DIRECTORY . '/messages.ndjson'));
        $expected = json_decode((string) file_get_contents(self::DIRECTORY . '/messages.json'), true, 64, JSON_THROW_ON_ERROR);

        self::assertSame($expected, $this->outcomes($decoder));
        self::assertNull($decoder->finish());
    }

    public function testTheGoldenStreamHoldsOneMessageOfEveryKind(): void
    {
        $messages = json_decode((string) file_get_contents(self::DIRECTORY . '/messages.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);
        $kinds = [];
        foreach ($messages as $message) {
            self::assertIsArray($message);
            self::assertIsString($message['kind']);
            $kinds[] = $message['kind'];
        }

        $unique = array_values(array_unique($kinds));
        sort($unique);
        self::assertSame(['bye', 'cancel', 'evt', 'hello', 'ping', 'pong', 'req', 'res'], $unique);
    }

    /**
     * @param list<mixed> $expectedItems
     */
    #[DataProvider('malformedCases')]
    public function testEveryMalformedCaseGivesTheDocumentedOutcomeWhenFedWhole(string $bytes, int $limit, array $expectedItems, ?string $atEnd): void
    {
        $decoder = (new JsonLineCodec($limit))->createDecoder();
        $decoder->feed($bytes);

        self::assertSame($expectedItems, $this->outcomes($decoder));
        $end = $decoder->finish();
        self::assertSame($atEnd, $end === null ? null : 'error:' . $end->wireCode());
    }

    /**
     * @param list<mixed> $expectedItems
     */
    #[DataProvider('malformedCases')]
    public function testEveryMalformedCaseGivesTheSameOutcomeWhenFedOneByteAtATime(string $bytes, int $limit, array $expectedItems, ?string $atEnd): void
    {
        $decoder = (new JsonLineCodec($limit))->createDecoder();
        $items = [];
        for ($index = 0; $index < strlen($bytes); $index++) {
            $decoder->feed($bytes[$index]);
            array_push($items, ...$this->outcomes($decoder));
        }

        self::assertSame($expectedItems, $items);
        $end = $decoder->finish();
        self::assertSame($atEnd, $end === null ? null : 'error:' . $end->wireCode());
    }
}
