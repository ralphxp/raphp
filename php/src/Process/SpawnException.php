<?php

declare(strict_types=1);

namespace Raphp\Process;

use Raphp\Error\RaphpException;

/**
 * A worker could not be started, or a process-level prerequisite is missing (an extension, a
 * socket flag, the inherited channel). It is a local failure, never sent over IPC.
 */
final class SpawnException extends RaphpException {}
