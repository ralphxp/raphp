<?php

declare(strict_types=1);

namespace Raphp\Ipc\Codec;

use JsonException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RpcException;

/**
 * The decoder of {@see JsonLineCodec}. See {@see Decoder} for the contract.
 *
 * Memory is bounded: as soon as the bytes of an unfinished line exceed the limit the decoder
 * stops, so a peer that never sends a newline cannot make it buffer without end. Lines are cut
 * out of the buffer by offset, not by repeatedly shortening it, so a large chunk holding many
 * small messages is read in linear time.
 *
 * @internal built by {@see JsonLineCodec::createDecoder()}
 */
final class JsonLineDecoder implements Decoder
{
    private const int COMPACT_AFTER_BYTES = 65_536;

    private string $buffer = '';

    /** Where the first unread line starts. */
    private int $offset = 0;

    /** Where the search for the next newline resumes, so bytes are never scanned twice. */
    private int $scanFrom = 0;

    private bool $dead = false;

    /**
     * @param positive-int $maxLineBytes
     * @param positive-int $maxDepth
     */
    public function __construct(
        private readonly int $maxLineBytes,
        private readonly int $maxDepth,
    ) {}

    public function feed(string $bytes): void
    {
        if ($this->dead || $bytes === '') {
            return;
        }

        $this->compact();
        $this->buffer .= $bytes;
    }

    public function next(): array|RpcException|null
    {
        $newline = strpos($this->buffer, "\n", $this->scanFrom);
        if ($newline === false) {
            $this->scanFrom = strlen($this->buffer);

            return strlen($this->buffer) - $this->offset > $this->maxLineBytes ? $this->die() : null;
        }

        $length = $newline - $this->offset;
        if ($length > $this->maxLineBytes) {
            return $this->die();
        }

        $line = substr($this->buffer, $this->offset, $length);
        $this->offset = $newline + 1;
        $this->scanFrom = $this->offset;

        return $this->parse($line);
    }

    public function finish(): ?RpcException
    {
        if ($this->dead || strlen($this->buffer) - $this->offset === 0) {
            return null;
        }

        return new ProtocolException('the connection closed in the middle of a message');
    }

    public function isDead(): bool
    {
        return $this->dead;
    }

    /**
     * @return array<array-key, mixed>|RpcException
     */
    private function parse(string $line): array|RpcException
    {
        if ($line === '') {
            return new ProtocolException('an empty line: every line must hold one JSON object');
        }

        if ($line[0] !== '{') {
            return new ProtocolException('a message must be a JSON object, so a line must start with "{"');
        }

        try {
            $decoded = json_decode($line, true, $this->maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return new ProtocolException($exception->getCode() === JSON_ERROR_UTF8 ? 'a message holds invalid UTF-8' : 'a message is not valid JSON: ' . $exception->getMessage());
        }

        // A line that starts with "{" and parses is always an object. PHP turns an object with the
        // keys "0", "1", ... into a list, which is still an object on the wire, so no list check here.
        if (!is_array($decoded)) {
            return new ProtocolException('a message must be a JSON object');
        }

        return $decoded;
    }

    private function die(): PayloadTooLargeException
    {
        $this->dead = true;
        $this->buffer = '';
        $this->offset = 0;
        $this->scanFrom = 0;

        return new PayloadTooLargeException("a message is larger than the limit of {$this->maxLineBytes} bytes", ['limit' => $this->maxLineBytes]);
    }

    /**
     * Drops the bytes already read, but only when it pays off, to keep reading linear.
     */
    private function compact(): void
    {
        if ($this->offset === 0) {
            return;
        }

        if ($this->offset >= strlen($this->buffer)) {
            $this->buffer = '';
            $this->scanFrom = 0;
            $this->offset = 0;

            return;
        }

        if ($this->offset >= self::COMPACT_AFTER_BYTES) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->scanFrom -= $this->offset;
            $this->offset = 0;
        }
    }
}
