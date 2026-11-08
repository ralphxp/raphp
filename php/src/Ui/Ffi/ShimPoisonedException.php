<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

/**
 * The shim failed earlier (a panic or a failure of its UI thread) and refuses every call but reading
 * events and closing. Nothing can be repaired in place: close it and start a new one.
 */
final class ShimPoisonedException extends ShimException {}
