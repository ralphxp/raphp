<?php

declare(strict_types=1);

namespace Raphp\Ui\Ffi;

/**
 * The picture of a headless window: RGBA, four bytes a pixel, rows from the top.
 */
final readonly class Frame
{
    public function __construct(
        public int $width,
        public int $height,
        public string $pixels,
    ) {}
}
