<?php

declare(strict_types=1);

namespace Raphp\Supervisor;

/**
 * What happened to a worker, as reported in a {@see WorkerEvent}.
 */
enum WorkerEventType: string
{
    /** The process was started. */
    case Started = 'started';

    /** The handshake is done: the worker takes calls. */
    case Ready = 'ready';

    /** The process ended without being asked to, or never became ready. */
    case Crashed = 'crashed';

    /** A restart is scheduled after a crash; `delay` says in how many seconds. */
    case Restarting = 'restarting';

    /** Too many crashes in too short a time: the worker is not restarted any more. */
    case Degraded = 'degraded';

    /** The worker was replaced on purpose (after so many calls, or because of its memory); `detail` says why. */
    case Recycled = 'recycled';

    /** A graceful stop asked the worker to end: no new calls, in-flight calls finished, `bye` sent. */
    case Draining = 'draining';

    /** The worker did not end in time and was sent SIGTERM. */
    case Terminating = 'terminating';

    /** The worker did not end after SIGTERM and was sent SIGKILL. */
    case Killing = 'killing';

    /** The worker was stopped on purpose and has ended. */
    case Exited = 'exited';
}
