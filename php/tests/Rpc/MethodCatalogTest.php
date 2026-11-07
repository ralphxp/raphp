<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Rpc\MethodCatalog;
use Raphp\Tests\Rpc\Fixture\ChildService;
use Raphp\Tests\Rpc\Fixture\Counter;
use Raphp\Tests\Rpc\Fixture\DuplicateName;
use Raphp\Tests\Rpc\Fixture\EmptyName;
use Raphp\Tests\Rpc\Fixture\FileService;
use Raphp\Tests\Rpc\Fixture\IdempotentWithoutRpc;
use Raphp\Tests\Rpc\Fixture\LongName;
use Raphp\Tests\Rpc\Fixture\NotPublic;
use Raphp\Tests\Rpc\Fixture\OtherService;
use Raphp\Tests\Rpc\Fixture\StaticRpc;

final class MethodCatalogTest extends TestCase
{
    public function testEveryMethodWithTheAttributeIsFoundAndNothingElse(): void
    {
        $catalog = MethodCatalog::fromClasses([FileService::class]);

        $names = array_map(static fn($descriptor): string => $descriptor->name, $catalog->all());
        sort($names);

        self::assertSame(
            ['ctx.inspect', 'ctx.optional', 'ctx.slow', 'fail.boom', 'fail.params', 'files.delete', 'files.scan', 'flaky.read', 'math.add', 'math.describe', 'math.untyped'],
            $names,
        );
        self::assertFalse($catalog->has('notExposed'), 'a public method without the attribute is not an RPC method');
    }

    public function testADescriptorSaysWhereTheMethodIsAndWhetherItIsIdempotent(): void
    {
        $catalog = MethodCatalog::fromClasses([FileService::class]);

        $scan = $catalog->get('files.scan');
        self::assertNotNull($scan);
        self::assertSame(FileService::class, $scan->class);
        self::assertSame('scan', $scan->method);
        self::assertTrue($scan->idempotent);
        self::assertFalse($catalog->get('files.delete')?->idempotent);
        self::assertNull($catalog->get('no.such'));
    }

    public function testOnlyMethodsMarkedIdempotentAreIdempotent(): void
    {
        $catalog = MethodCatalog::fromClasses([FileService::class]);

        self::assertTrue($catalog->isIdempotent('files.scan'));
        self::assertTrue($catalog->isIdempotent('flaky.read'));
        self::assertFalse($catalog->isIdempotent('files.delete'));
        self::assertFalse($catalog->isIdempotent('math.add'));
        self::assertFalse($catalog->isIdempotent('no.such.method'), 'an unknown method is not idempotent');
    }

    public function testSeveralClassesAreCombinedInTheOrderGiven(): void
    {
        $catalog = MethodCatalog::fromClasses([OtherService::class, FileService::class]);

        self::assertSame('other.ping', $catalog->all()[0]->name);
        self::assertTrue($catalog->has('files.scan'));
        self::assertTrue($catalog->has('other.ping'));
    }

    public function testAnEmptyListGivesAnEmptyCatalog(): void
    {
        self::assertSame([], MethodCatalog::fromClasses([])->all());
    }

    public function testAnInheritedRpcMethodBelongsToTheParentAndIsNotDeclaredAgain(): void
    {
        $catalog = MethodCatalog::fromClasses([FileService::class, ChildService::class]);

        self::assertSame(ChildService::class, $catalog->get('child.extra')?->class);
        self::assertSame(FileService::class, $catalog->get('files.scan')?->class);
    }

    public function testAClassThatInheritsRpcMethodsOnlyAddsItsOwn(): void
    {
        $catalog = MethodCatalog::fromClasses([ChildService::class]);

        self::assertSame(['child.extra'], array_map(static fn($descriptor): string => $descriptor->name, $catalog->all()));
    }

    // ------------------------------------------------------------ mistakes are found at once

    public function testTheSameNameTwiceIsRefusedAndBothPlacesAreNamed(): void
    {
        try {
            MethodCatalog::fromClasses([FileService::class, DuplicateName::class]);
            self::fail('Expected LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('"files.scan" is declared twice', $exception->getMessage());
            self::assertStringContainsString(FileService::class . '::scan()', $exception->getMessage());
            self::assertStringContainsString(DuplicateName::class . '::again()', $exception->getMessage());
        }
    }

    public function testTheSameClassGivenTwiceIsADuplicate(): void
    {
        $this->expectException(LogicException::class);

        MethodCatalog::fromClasses([OtherService::class, OtherService::class]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function misuse(): iterable
    {
        yield 'a method that is not public' => [NotPublic::class, 'is not a public instance method'];
        yield 'a static method' => [StaticRpc::class, 'is not a public instance method'];
        yield 'idempotent without an rpc method' => [IdempotentWithoutRpc::class, 'marked #[Idempotent] but not #[RpcMethod]'];
        yield 'an empty name' => [EmptyName::class, '"method" must not be empty'];
        yield 'a name that is too long' => [LongName::class, 'at most 256 bytes'];
    }

    #[DataProvider('misuse')]
    public function testAnAttributeUsedWronglyIsRefusedWithTheMethodNamed(string $class, string $reason): void
    {
        try {
            MethodCatalog::fromClasses([$class]);
            self::fail('Expected LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
            self::assertStringContainsString($class . '::', $exception->getMessage());
        }
    }

    public function testAClassThatDoesNotExistIsRefused(): void
    {
        try {
            MethodCatalog::fromClasses(['Raphp\\Tests\\Rpc\\Fixture\\NoSuchClass']);
            self::fail('Expected LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('does not exist', $exception->getMessage());
        }
    }

    public function testAClassWithNoRpcMethodsAddsNothing(): void
    {
        self::assertSame([], MethodCatalog::fromClasses([Counter::class])->all());
    }
}
