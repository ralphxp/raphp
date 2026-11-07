<?php

declare(strict_types=1);

namespace Raphp\Tests\Process;

use PHPUnit\Framework\TestCase;
use Raphp\Process\SelfCommand;
use Raphp\Process\SpawnException;

final class SelfCommandTest extends TestCase
{
    private string $link = '';

    protected function setUp(): void
    {
        $this->link = sys_get_temp_dir() . '/raphp-self-' . bin2hex(random_bytes(4));
        symlink('/usr/bin/some-program', $this->link);
    }

    protected function tearDown(): void
    {
        @unlink($this->link);
    }

    public function testUnderPlainPhpTheCommandIsTheInterpreterAndTheEntryScript(): void
    {
        self::assertSame(['/usr/bin/some-program', '/app/main.php'], (new SelfCommand($this->link, 'cli'))->resolve('/app/main.php'));
    }

    public function testInAStandaloneBinaryTheCommandIsTheBinaryItself(): void
    {
        self::assertSame(['/usr/bin/some-program'], (new SelfCommand($this->link, 'micro'))->resolve());
        self::assertSame(['/usr/bin/some-program'], (new SelfCommand($this->link, 'micro'))->resolve('/ignored.php'));
    }

    public function testWithoutAnEntryScriptPlainPhpCannotStartAnotherCopy(): void
    {
        foreach ([null, ''] as $entry) {
            try {
                (new SelfCommand($this->link, 'cli'))->resolve($entry);
                self::fail('Expected SpawnException.');
            } catch (SpawnException $exception) {
                self::assertStringContainsString('entry script', $exception->getMessage());
            }
        }
    }

    public function testAnExecutablePathThatCannotBeReadIsReported(): void
    {
        $this->expectException(SpawnException::class);

        (new SelfCommand('/nonexistent/raphp-no-such-link', 'cli'))->resolve('/app/main.php');
    }

    public function testTheRealProcRunsAndNamesThisInterpreter(): void
    {
        $command = (new SelfCommand())->resolve('/app/main.php');

        self::assertSame('/app/main.php', $command[1]);
        self::assertStringStartsWith('/', $command[0]);
        self::assertFileExists($command[0]);
    }
}
