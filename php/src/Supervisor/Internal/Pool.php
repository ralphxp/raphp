<?php

declare(strict_types=1);

namespace Raphp\Supervisor\Internal;

use Amp\Future;
use Raphp\Rpc\Internal\WaitList;
use Raphp\Supervisor\WorkerDefinition;

/**
 * The members of one logical worker, and what they share.
 *
 * @internal
 */
final class Pool
{
    /** Where round-robin routing goes next. */
    public int $cursor = 0;

    /** @var list<Future<null>> the running supervision loops of the members */
    public array $runs = [];

    /**
     * @param list<Member> $members
     */
    public function __construct(
        public readonly WorkerDefinition $definition,
        public readonly array $members,
        public readonly WaitList $changed,
    ) {}
}
