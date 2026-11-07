<?php

declare(strict_types=1);

namespace Raphp\Runtime\Libc;

use Raphp\Error\RaphpException;

/**
 * A call into the C library is not possible here (no FFI, no libc) or failed.
 */
final class LibcException extends RaphpException {}
