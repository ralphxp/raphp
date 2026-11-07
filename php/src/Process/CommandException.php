<?php

declare(strict_types=1);

namespace Raphp\Process;

use RuntimeException;

/**
 * An application command could not be run, or was stopped (see {@see ProcessRunner}). Not about worker
 * processes: those fail with {@see SpawnException}.
 */
class CommandException extends RuntimeException {}
