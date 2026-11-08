<?php

/**
 * Loads the shim library with the real loader and says what happened, for the tests that need a PHP started
 * with other settings (ffi.enable). Arguments: the library path, the header path.
 *
 * Prints "LOADED <abi>" or "FAILED <message>".
 */

declare(strict_types=1);

use Psr\Log\NullLogger;
use Raphp\Ui\Ffi\Loader;
use Raphp\Ui\Ffi\ShimLoadException;

require __DIR__ . '/../../../vendor/autoload.php';

try {
    $library = (new Loader(new NullLogger()))->load($argv[1] ?? '', $argv[2] ?? '');
    echo 'LOADED ' . $library->version . "\n";
} catch (ShimLoadException $exception) {
    echo 'FAILED ' . $exception->getMessage() . "\n";
}
