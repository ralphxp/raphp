<?php

declare(strict_types=1);

namespace Raphp\Tests\Examples;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Raphp\Tests\Support\ChildProcess;

/**
 * Runs `examples/large-file` so it cannot rot unnoticed. The numbers it prints depend on the machine, so
 * only the shape of the output is checked, plus the one fact the demonstration exists for: while the file
 * is streamed the timer keeps ticking.
 */
#[Group('slow')]
final class LargeFileExampleTest extends TestCase
{
    private const string MAIN = __DIR__ . '/../../../examples/large-file/main.php';

    public function testItStreamsTheFileWhileTheTimerKeepsTicking(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['--mib=64'], timeout: 60.0);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString("Reading 64 MiB with Raphp's Filesystem::openRead()", $result->stdout);
        if (preg_match('/Done: 64 MiB in [0-9.]+ s .* ticked (\d+) times; the longest gap between ticks was ([0-9.]+) ms/', $result->stdout, $match) !== 1) {
            self::fail('the summary line is missing: ' . $result->stdout);
        }
        self::assertGreaterThan(10, (int) $match[1], 'the timer ticked while the file was read');
        self::assertLessThan(200.0, (float) $match[2], 'the loop was not stuck for the length of the read');
    }

    public function testTheBlockingComparisonStillReadsTheWholeFile(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['--mib=16', '--blocking'], timeout: 60.0);

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertStringContainsString('plain fread() loop', $result->stdout);
        self::assertStringContainsString('Done: 16 MiB', $result->stdout);
    }

    public function testABadCommandLineIsRefused(): void
    {
        $result = (new ChildProcess())->run(self::MAIN, ['--mib=0']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('usage:', $result->stderr);
    }

    public function testNoTemporaryFileIsLeftBehind(): void
    {
        $before = glob(sys_get_temp_dir() . '/raphp-large-file-*');

        (new ChildProcess())->run(self::MAIN, ['--mib=4'], timeout: 60.0);

        self::assertSame($before, glob(sys_get_temp_dir() . '/raphp-large-file-*'));
    }
}
