<?php

declare(strict_types=1);

namespace Raphp\Filesystem;

enum FileType: string
{
    case File = 'file';

    case Directory = 'directory';

    case Link = 'link';

    /** A device, a socket, a pipe. */
    case Other = 'other';
}
