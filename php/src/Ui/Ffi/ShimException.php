<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use Raphp\Error\RaphpException;
use Throwable;

/**
 * A call into the shim failed. `$status` is the shim's `RaphpStatus` (negative), and the message carries
 * the reason the shim gave through `raphp_last_error`.
 */
class ShimException extends RaphpException
{
    public function __construct(string $message, public readonly int $status = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
