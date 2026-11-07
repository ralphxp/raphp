<?php

declare(strict_types=1);

namespace Raphp\Process;

/**
 * The command wrote more output than the limit allows, and was stopped.
 */
final class CommandOutputLimitException extends CommandException {}
