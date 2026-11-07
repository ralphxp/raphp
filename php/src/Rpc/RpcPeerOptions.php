<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use InvalidArgumentException;

/**
 * Limits of one {@see RpcPeer}. The defaults are my numbers, not measured ones: P2.13 checks
 * them (FRAMEWORK 6.8).
 *
 * - `maxInFlight`: calls this side has sent and not had answered. A call over the limit waits.
 * - `maxQueuedCalls`: calls waiting for a free slot. A call over this fails with `E_WORKER_UNAVAILABLE`.
 * - `maxInbound`: requests from the peer being handled at once. One over the limit is answered
 *   with `E_WORKER_UNAVAILABLE`.
 * - `outboundBuffer`: messages waiting to be written. A sender that finds it full waits.
 * - `pingInterval`: seconds of silence after which a `ping` is sent; null turns the heartbeat off.
 *   The peer is declared dead after `missedPings` intervals with nothing heard at all (FRAMEWORK
 *   6.8). Off by default: whoever owns the connection (the supervisor) turns it on.
 * - `handshakeTimeout`: seconds to wait for the other side's `hello`, when a handshake is used.
 * - `flushTimeout`: seconds a last message (a `bye`) is given to leave before the connection is
 *   closed regardless.
 */
final readonly class RpcPeerOptions
{
    public const int DEFAULT_MAX_IN_FLIGHT = 64;

    public const int DEFAULT_MAX_QUEUED_CALLS = 1024;

    public const int DEFAULT_MAX_INBOUND = 256;

    public const int DEFAULT_OUTBOUND_BUFFER = 256;

    public const int DEFAULT_MISSED_PINGS = 3;

    public const float DEFAULT_HANDSHAKE_TIMEOUT = 5.0;

    public const float DEFAULT_FLUSH_TIMEOUT = 2.0;

    /**
     * @throws InvalidArgumentException when a limit is not at least 1
     */
    public function __construct(
        public int $maxInFlight = self::DEFAULT_MAX_IN_FLIGHT,
        public int $maxQueuedCalls = self::DEFAULT_MAX_QUEUED_CALLS,
        public int $maxInbound = self::DEFAULT_MAX_INBOUND,
        public int $outboundBuffer = self::DEFAULT_OUTBOUND_BUFFER,
        public ?float $pingInterval = null,
        public int $missedPings = self::DEFAULT_MISSED_PINGS,
        public float $handshakeTimeout = self::DEFAULT_HANDSHAKE_TIMEOUT,
        public float $flushTimeout = self::DEFAULT_FLUSH_TIMEOUT,
    ) {
        if ($maxInFlight < 1 || $maxQueuedCalls < 1 || $maxInbound < 1 || $outboundBuffer < 1 || $missedPings < 1) {
            throw new InvalidArgumentException('every peer limit must be at least 1');
        }

        if (($pingInterval !== null && $pingInterval <= 0.0) || $handshakeTimeout <= 0.0 || $flushTimeout <= 0.0) {
            throw new InvalidArgumentException('every interval and timeout must be greater than 0');
        }
    }
}
