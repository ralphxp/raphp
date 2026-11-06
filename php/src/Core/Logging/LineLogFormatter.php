<?php

declare(strict_types=1);

namespace Raphp\Core\Logging;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;
use Override;

/**
 * One log record per line: `[time] channel.LEVEL: message {"context":"as json"}`.
 *
 * Monolog's own line format leaves a trailing space when a record has no context. This
 * formatter removes it, so every line ends exactly where its content ends.
 */
final class LineLogFormatter extends LineFormatter
{
    public function __construct()
    {
        parent::__construct(
            format: "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            dateFormat: 'Y-m-d\TH:i:s.vP',
            allowInlineLineBreaks: false,
            ignoreEmptyContextAndExtra: true,
        );
    }

    #[Override]
    public function format(LogRecord $record): string
    {
        return rtrim(parent::format($record)) . "\n";
    }
}
