<?php

declare(strict_types=1);

namespace Raphp\Rpc;

/**
 * How a handler tells the caller how far it has got: each report becomes an `evt` message with
 * `ref` set to the request's id (FRAMEWORK 6.4). Reporting may wait when the connection is
 * backed up, and does nothing once the connection is closed.
 */
interface ProgressReporter
{
    /**
     * @param array<array-key, mixed> $params
     */
    public function report(array $params = []): void;
}
