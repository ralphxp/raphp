<?php

declare(strict_types=1);

namespace Raphp\Ipc\Codec;

use Raphp\Error\RpcException;

/**
 * The incremental reading side of a connection: bytes go in as they arrive, whole messages come
 * out in order.
 *
 * Usage:
 *
 *     $decoder->feed($bytes);
 *     while (($item = $decoder->next()) !== null) {
 *         // a message (array) or a problem (RpcException), in the order they arrived
 *     }
 *
 * A problem with one line (bad JSON, not an object) is returned in order and the decoder goes on
 * with the next line, so valid messages around it are never lost. An oversize line is different:
 * there is no safe way to find the next message, so the decoder reports it once and then
 * {@see self::isDead()} is true and it discards all further input.
 */
interface Decoder
{
    /**
     * Adds received bytes. Never throws.
     */
    public function feed(string $bytes): void;

    /**
     * Returns the next complete item, or null when more input is needed.
     *
     * @return array<array-key, mixed>|RpcException|null
     */
    public function next(): array|RpcException|null;

    /**
     * Call once at end of input. Returns a problem if the input stopped in the middle of a
     * message, or null if it ended cleanly.
     */
    public function finish(): ?RpcException;

    /**
     * True after an oversize line: the decoder has stopped and ignores further input.
     */
    public function isDead(): bool;
}
