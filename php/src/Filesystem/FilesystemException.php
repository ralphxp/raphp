<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

use RuntimeException;

/**
 * A file operation failed. The message names the path and the reason PHP gave.
 */
class FilesystemException extends RuntimeException {}
