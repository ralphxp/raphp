<?php

declare(strict_types=1);

namespace Raphp\Ipc\Codec;

use InvalidArgumentException;
use JsonException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;

/**
 * NDJSON: one compact JSON object per line, UTF-8, each line ending in "\n" (FRAMEWORK 6.3).
 *
 * Encoding never pretty-prints, so a message never contains a raw newline: JSON escapes the
 * newlines inside strings. Slashes stay readable and non-ASCII characters are written as
 * \uXXXX escapes, so every line is plain ASCII.
 *
 * A line may be at most `$maxLineBytes` long, not counting the newline (default 8 MiB), nested at most
 * `$maxDepth` levels (default 64) and hold at most `$maxContainers` arrays and objects together
 * (default 200,000). The last one bounds memory: a short line of tiny arrays decodes to some 60
 * times its size in PHP. The three limits are checked when encoding and when decoding, so this codec
 * never writes a message its own decoder would refuse. The container count is the number of `[`
 * and `{` characters, which also counts the ones inside strings.
 */
final readonly class JsonLineCodec implements Codec
{
    public const int DEFAULT_MAX_LINE_BYTES = 8_388_608;

    public const int DEFAULT_MAX_DEPTH = 64;

    public const int DEFAULT_MAX_CONTAINERS = 200_000;

    /** @var int<1, max> */
    private int $maxLineBytes;

    /** @var int<1, max> */
    private int $maxDepth;

    /** @var int<1, max> */
    private int $maxContainers;

    /**
     * @throws InvalidArgumentException when a limit is not positive
     */
    public function __construct(
        int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        int $maxContainers = self::DEFAULT_MAX_CONTAINERS,
    ) {
        if ($maxLineBytes < 1 || $maxDepth < 1 || $maxContainers < 1) {
            throw new InvalidArgumentException('the line size limit, the depth limit and the container limit must be positive');
        }

        $this->maxLineBytes = $maxLineBytes;
        $this->maxDepth = $maxDepth;
        $this->maxContainers = $maxContainers;
    }

    public function encode(array $message): string
    {
        if ($message !== [] && array_is_list($message)) {
            throw new ProtocolException('a message must be a JSON object, not a list');
        }

        try {
            $line = json_encode($message === [] ? (object) [] : $message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION, $this->maxDepth);
        } catch (JsonException $exception) {
            throw new ProtocolException('the message cannot be encoded as JSON: ' . $exception->getMessage(), null, $exception);
        }

        if (strlen($line) > $this->maxLineBytes) {
            throw new PayloadTooLargeException("the message is larger than the limit of {$this->maxLineBytes} bytes", ['limit' => $this->maxLineBytes]);
        }

        if (JsonLineDecoder::containers($line) > $this->maxContainers) {
            throw new PayloadTooLargeException("the message holds more than {$this->maxContainers} arrays and objects", ['limit' => $this->maxContainers]);
        }

        return $line . "\n";
    }

    public function createDecoder(): Decoder
    {
        return new JsonLineDecoder($this->maxLineBytes, $this->maxDepth, $this->maxContainers);
    }
}
