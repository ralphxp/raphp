<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

/**
 * The shim has stopped taking commands (`app.quit` was applied, the UI thread ended, or the shim was
 * closed from PHP). Events can still be read until the shim is closed.
 */
final class ShimClosedException extends ShimException {}
