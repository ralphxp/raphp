<?php

declare(strict_types=1);

namespace Raphp\Stream;

use RuntimeException;

/**
 * A line is longer than the limit. The line is not delivered, and the reader or buffer that raised
 * this is finished.
 */
final class LineTooLongException extends RuntimeException {}
