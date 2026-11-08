<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

use Raphp\Error\RaphpException;

/**
 * The shim library could not be made ready: PHP's FFI is missing or switched off, the header or the
 * library cannot be read, or the library speaks another version of the ABI. The message says which, and
 * what to do about it.
 */
final class ShimLoadException extends RaphpException {}
