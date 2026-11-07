<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * The command ran longer than the time it was given, and was stopped.
 */
final class CommandTimeoutException extends CommandException {}
