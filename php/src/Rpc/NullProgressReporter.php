<?php

declare(strict_types=1);

namespace Raphp\Rpc;

/**
 * A {@see ProgressReporter} that reports nowhere: for requests that did not arrive over a
 * connection, and for tests.
 */
final readonly class NullProgressReporter implements ProgressReporter
{
    public function report(array $params = []): void {}
}
