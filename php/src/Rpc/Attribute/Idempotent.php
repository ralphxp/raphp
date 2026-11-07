<?php

declare(strict_types=1);

namespace Raphp\Rpc\Attribute;

use Attribute;

/**
 * Says that calling this {@see RpcMethod} twice has the same effect as calling it once, so a
 * failed call may safely be repeated. Only such methods are retried automatically (see
 * {@see \Raphp\Rpc\Middleware\RetryMiddleware}); it has no effect without `#[RpcMethod]`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Idempotent {}
