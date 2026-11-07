<?php

declare(strict_types=1);

namespace Raphp\Rpc\Attribute;

use Attribute;

/**
 * Marks a public method as callable over RPC under the given name, for example
 * `#[RpcMethod('files.scan')]` (FRAMEWORK 6.4). Found by {@see \Raphp\Rpc\MethodCatalog}.
 *
 * The method's parameters are filled from the call's `params` by name and type. Three types are
 * filled by the framework instead: {@see \Raphp\Rpc\RpcRequest}, {@see \Amp\Cancellation} and
 * {@see \Raphp\Rpc\ProgressReporter}.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RpcMethod
{
    public function __construct(public string $name) {}
}
