<?php

declare(strict_types=1);

namespace Raphp\Tests\Examples;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Raphp\Tests\Support\ChildProcess;

/**
 * Runs `tools/bench/bench.php --quick` so the benchmark script cannot rot unnoticed. This checks that
 * it runs to the end and prints every section; the numbers of a quick run mean nothing.
 */
#[Group('slow')]
final class BenchmarkScriptTest extends TestCase
{
    public function testTheQuickRunCompletesAndReportsEverySection(): void
    {
        $result = (new ChildProcess())->run(__DIR__ . '/../../../tools/bench/bench.php', ['--quick'], timeout: 120.0);

        self::assertFalse($result->timedOut);
        self::assertSame(0, $result->exitCode, $result->stderr);
        foreach (['== Machine', '== A. Soak', '== B. Concurrency', '== C. Large payloads', '== D. Small calls', '== G. Plateau', '== Master memory', '== E. Read chunk', '== F. Worker start-up', 'RESULT: PASS'] as $section) {
            self::assertStringContainsString($section, $result->stdout);
        }

        self::assertStringContainsString('refused with E_PAYLOAD_TOO_LARGE', $result->stdout);
        self::assertSame('', $result->stderr);
    }
}
