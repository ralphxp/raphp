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
 * A line may be at most `$maxLineBytes` long, not counting the newline (default 8 MiB). That
 * limit is checked when encoding and when decoding.
 */
final readonly class JsonLineCodec implements Codec
{
    public const int DEFAULT_MAX_LINE_BYTES = 8_388_608;

    public const int DEFAULT_MAX_DEPTH = 64;

    /** @var int<1, max> */
    private int $maxLineBytes;

    /** @var int<1, max> */
    private int $maxDepth;

    /**
     * @throws InvalidArgumentException when a limit is not positive
     */
    public function __construct(int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES, int $maxDepth = self::DEFAULT_MAX_DEPTH)
    {
        if ($maxLineBytes < 1 || $maxDepth < 1) {
            throw new InvalidArgumentException('the line size limit and the depth limit must be positive');
        }

        $this->maxLineBytes = $maxLineBytes;
        $this->maxDepth = $maxDepth;
    }

    public function encode(array $message): string
    {
        if ($message !== [] && array_is_list($message)) {
            throw new ProtocolException('a message must be a JSON object, not a list');
        }

        try {
            $line = json_encode($message === [] ? (object) [] : $message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ProtocolException('the message cannot be encoded as JSON: ' . $exception->getMessage(), null, $exception);
        }

        if (strlen($line) > $this->maxLineBytes) {
            throw new PayloadTooLargeException("the message is larger than the limit of {$this->maxLineBytes} bytes", ['limit' => $this->maxLineBytes]);
        }

        return $line . "\n";
    }

    public function createDecoder(): Decoder
    {
        return new JsonLineDecoder($this->maxLineBytes, $this->maxDepth);
    }
}
