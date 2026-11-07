<?php

declare(strict_types=1);

namespace Raphp\Process;

enum OutputStream: string
{
    case Stdout = 'stdout';

    case Stderr = 'stderr';
}
