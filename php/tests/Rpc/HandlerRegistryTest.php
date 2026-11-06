<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use LogicException;
use PHPUnit\Framework\TestCase;
use Raphp\Error\MethodNotFoundException;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\RpcRequest;

final class HandlerRegistryTest extends TestCase
{
    public function testARegisteredHandlerAnswersItsMethodWithItsResult(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('math.double', static fn(RpcRequest $request): int|float => 2 * array_sum($request->params));

        $response = $registry->handle(new RpcRequest('math.double', ['n' => 21]));

        self::assertFalse($response->isFailure());
        self::assertSame(42, $response->result);
    }

    public function testAnUnknownMethodGivesAMethodNotFoundFailureNotAnException(): void
    {
        $response = (new HandlerRegistry())->handle(new RpcRequest('nope'));

        self::assertTrue($response->isFailure());
        self::assertInstanceOf(MethodNotFoundException::class, $response->error);
        self::assertSame(['method' => 'nope'], $response->error->data);
    }

    public function testMethodNamesAreMatchedExactly(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('Files.Scan', static fn(): string => 'x');

        self::assertTrue($registry->has('Files.Scan'));
        self::assertFalse($registry->has('files.scan'));
        self::assertTrue($registry->handle(new RpcRequest('files.scan'))->isFailure());
    }

    public function testTheSameMethodCannotBeRegisteredTwice(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('a', static fn(): int => 1);

        $this->expectException(LogicException::class);

        $registry->register('a', static fn(): int => 2);
    }

    public function testAnEmptyMethodNameCannotBeRegistered(): void
    {
        $this->expectException(LogicException::class);

        (new HandlerRegistry())->register('', static fn(): int => 1);
    }

    public function testMethodsListsWhatIsRegisteredInOrder(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('b', static fn(): int => 1);
        $registry->register('a', static fn(): int => 1);

        self::assertSame(['b', 'a'], $registry->methods());
    }

    public function testExceptionsFromAHandlerPassThroughForTheMiddlewareOrThePeerToMap(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('boom', static function (): never {
            throw new \RuntimeException('x');
        });

        $this->expectException(\RuntimeException::class);

        $registry->handle(new RpcRequest('boom'));
    }
}
