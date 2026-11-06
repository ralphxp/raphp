<?php

declare(strict_types=1);

namespace Raphp\Error;

/**
 * The framework's error codes as they appear on the wire (FRAMEWORK 6.6).
 *
 * The values are the exact strings sent in an IPC `error` object. Application errors use
 * their own `E_APP_*` codes and are not part of this enum (see {@see ApplicationException}).
 */
enum ErrorCode: string
{
    case MethodNotFound = 'E_METHOD_NOT_FOUND';
    case InvalidParams = 'E_INVALID_PARAMS';
    case Cancelled = 'E_CANCELLED';
    case Timeout = 'E_TIMEOUT';
    case WorkerCrashed = 'E_WORKER_CRASHED';
    case WorkerUnavailable = 'E_WORKER_UNAVAILABLE';
    case PayloadTooLarge = 'E_PAYLOAD_TOO_LARGE';
    case Protocol = 'E_PROTOCOL';
    case Internal = 'E_INTERNAL';

    /**
     * Looks a code up by its wire string. The match is exact and case-sensitive. Returns null
     * for anything that is not a framework code, including application codes.
     */
    public static function fromWire(string $code): ?self
    {
        return self::tryFrom($code);
    }
}
