<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

/**
 * Where a supervised worker is in its life (FRAMEWORK 5.3).
 *
 * `starting` and `handshaking` come first, then `ready`, the only state in which calls are routed
 * to it. `draining` means no new calls while the ones in flight finish, before a recycle or a stop.
 * `crashed` waits for a restart. `exited` is a worker that was stopped on purpose, `degraded` one
 * that crashed too often and is not restarted any more.
 */
enum WorkerState: string
{
    case Starting = 'starting';
    case Handshaking = 'handshaking';
    case Ready = 'ready';
    case Draining = 'draining';
    case Crashed = 'crashed';
    case Exited = 'exited';
    case Degraded = 'degraded';
}
