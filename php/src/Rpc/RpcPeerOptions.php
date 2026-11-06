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
 */
final readonly class RpcPeerOptions
{
    public const int DEFAULT_MAX_IN_FLIGHT = 64;

    public const int DEFAULT_MAX_QUEUED_CALLS = 1024;

    public const int DEFAULT_MAX_INBOUND = 256;

    public const int DEFAULT_OUTBOUND_BUFFER = 256;

    /**
     * @throws InvalidArgumentException when a limit is not at least 1
     */
    public function __construct(
        public int $maxInFlight = self::DEFAULT_MAX_IN_FLIGHT,
        public int $maxQueuedCalls = self::DEFAULT_MAX_QUEUED_CALLS,
        public int $maxInbound = self::DEFAULT_MAX_INBOUND,
        public int $outboundBuffer = self::DEFAULT_OUTBOUND_BUFFER,
    ) {
        if ($maxInFlight < 1 || $maxQueuedCalls < 1 || $maxInbound < 1 || $outboundBuffer < 1) {
            throw new InvalidArgumentException('every peer limit must be at least 1');
        }
    }
}
