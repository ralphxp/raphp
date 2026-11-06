<?php

declare(strict_types=1);

namespace Raphp\Error;

use RuntimeException;

/**
 * The base of every exception the framework throws on purpose, so callers can catch them all
 * with one type.
 *
 * It is abstract: throw a specific subclass. IPC errors live in this namespace and extend
 * {@see RpcException}; a module's own exceptions live in the module's namespace and extend
 * this class (for example `Raphp\Core\Config\InvalidConfigException`).
 */
abstract class RaphpException extends RuntimeException {}
