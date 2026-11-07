<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

/**
 * Where the argument for one parameter of an RPC method comes from.
 *
 * @internal
 */
enum BindingKind
{
    /** The {@see \Raphp\Rpc\RpcRequest} itself. */
    case Request;

    /** The {@see \Amp\Cancellation} of the call. */
    case Cancellation;

    /** The {@see \Raphp\Rpc\ProgressReporter} of the call. */
    case Progress;

    /** A value from the call's `params`, found by the parameter's name. */
    case Param;
}
