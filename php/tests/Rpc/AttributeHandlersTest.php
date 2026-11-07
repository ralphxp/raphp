<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc;

use function Amp\async;

use Amp\Cancellation;
use Amp\DeferredCancellation;

use function Amp\delay;

use Closure;
use DI\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Raphp\Error\InternalErrorException;
use Raphp\Error\InvalidParamsException;
use Raphp\Error\MethodNotFoundException;
use Raphp\Error\RequestCancelledException;
use Raphp\Rpc\AttributeHandlers;
use Raphp\Rpc\HandlerRegistry;
use Raphp\Rpc\MethodCatalog;
use Raphp\Rpc\RpcRequest;
use Raphp\Tests\Rpc\Fixture\ByReferenceParameter;
use Raphp\Tests\Rpc\Fixture\ChildService;
use Raphp\Tests\Rpc\Fixture\Counter;
use Raphp\Tests\Rpc\Fixture\FileService;
use Raphp\Tests\Rpc\Fixture\IntersectionParameter;
use Raphp\Tests\Rpc\Fixture\ObjectParameter;
use Raphp\Tests\Rpc\Fixture\OtherService;
use Raphp\Tests\Rpc\Fixture\UnionWithObject;
use Raphp\Tests\Rpc\Fixture\VariadicParameter;
use Raphp\Tests\Support\AsyncTestCase;
use Raphp\Tests\Support\PeerPair;
use RuntimeException;
use Throwable;

/**
 * Methods declared with attributes, registered from a real container and called through a live
 * peer, so everything from the attribute to the answer on the wire is exercised.
 */
final class AttributeHandlersTest extends AsyncTestCase
{
    private ?PeerPair $pair = null;

    protected function setUp(): void
    {
        parent::setUp();
        Counter::$built = 0;
        FileService::$stopped = 0;
    }

    protected function tearDown(): void
    {
        $this->pair?->close();
        $this->pair = null;
        parent::tearDown();
    }

    private function container(): ContainerInterface
    {
        return (new ContainerBuilder())->useAutowiring(true)->build();
    }

    /**
     * Opens two peers; the server answers with the methods of the given classes.
     *
     * @param list<string> $classes
     */
    private function serve(array $classes): PeerPair
    {
        $pair = PeerPair::open();
        $this->pair = $pair;
        (new AttributeHandlers($this->container()))->register($pair->serverHandlers, MethodCatalog::fromClasses($classes));

        return $pair;
    }

    /**
     * @param Closure(): mixed $action
     */
    private function thrownBy(Closure $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Expected an exception.');
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function invalidParams(PeerPair $pair, string $method, array $params): InvalidParamsException
    {
        $error = $this->thrownBy(fn(): mixed => $pair->client->call($method, $params, timeout: 5.0));
        self::assertInstanceOf(InvalidParamsException::class, $error, "{$method} with " . json_encode($params));

        return $error;
    }

    // ------------------------------------------------------------ registration

    public function testAServiceFromTheContainerAnswersItsMethodsWithItsDependenciesInjected(): void
    {
        $pair = $this->serve([FileService::class]);

        self::assertSame(['path' => '/tmp', 'limit' => 10], $pair->client->call('files.scan', ['path' => '/tmp'], timeout: 5.0));
        self::assertSame(['path' => '/x', 'limit' => 3], $pair->client->call('files.scan', ['path' => '/x', 'limit' => 3], timeout: 5.0));
        self::assertSame(1, Counter::$built, 'the container built the dependency once');
    }

    public function testEachServiceIsFetchedOnceAtBootNotOncePerCall(): void
    {
        $fetched = [];
        $container = new class ($this->container(), $fetched) implements ContainerInterface {
            /** @param list<string> $fetched */
            public function __construct(private readonly ContainerInterface $inner, public array &$fetched) {}

            public function get(string $id): mixed
            {
                $this->fetched[] = $id;

                return $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                return $this->inner->has($id);
            }
        };
        $pair = PeerPair::open();
        $this->pair = $pair;
        (new AttributeHandlers($container))->register($pair->serverHandlers, MethodCatalog::fromClasses([FileService::class, OtherService::class]));

        self::assertSame([FileService::class, OtherService::class], $fetched, 'one fetch per service, at registration');

        $pair->client->call('files.delete', ['path' => 'a'], timeout: 5.0);
        $pair->client->call('files.delete', ['path' => 'b'], timeout: 5.0);
        self::assertCount(2, $fetched, 'calls must not go back to the container');
    }

    public function testBuildMakesANewRegistryWithEveryMethod(): void
    {
        $registry = (new AttributeHandlers($this->container()))->build(MethodCatalog::fromClasses([OtherService::class, FileService::class]));

        self::assertTrue($registry->has('other.ping'));
        self::assertTrue($registry->has('files.scan'));
        self::assertFalse($registry->has('notExposed'));
    }

    public function testASubclassUsesItsOwnInstanceAndTheInheritedMethodsStayWithTheParent(): void
    {
        $pair = $this->serve([FileService::class, ChildService::class]);

        self::assertSame('extra', $pair->client->call('child.extra', timeout: 5.0));
        self::assertSame(['path' => 'p', 'limit' => 10], $pair->client->call('files.scan', ['path' => 'p'], timeout: 5.0));
    }

    public function testANameThatIsAlreadyRegisteredIsRefused(): void
    {
        $registry = new HandlerRegistry();
        $registry->register('other.ping', static fn(): string => 'taken');

        $this->expectException(LogicException::class);

        (new AttributeHandlers($this->container()))->register($registry, MethodCatalog::fromClasses([OtherService::class]));
    }

    public function testAServiceTheContainerCannotProvideIsRefusedAtBoot(): void
    {
        $empty = new class implements ContainerInterface {
            public function get(string $id): never
            {
                throw new class ('nothing here') extends RuntimeException implements \Psr\Container\ContainerExceptionInterface {};
            }

            public function has(string $id): bool
            {
                return false;
            }
        };

        try {
            (new AttributeHandlers($empty))->build(MethodCatalog::fromClasses([OtherService::class]));
            self::fail('Expected LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(OtherService::class, $exception->getMessage());
            self::assertStringContainsString('nothing here', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupportedSignatures(): iterable
    {
        yield 'an object parameter' => [ObjectParameter::class, 'DateTimeImmutable'];
        yield 'a union with an object' => [UnionWithObject::class, 'stdClass'];
        yield 'a variadic parameter' => [VariadicParameter::class, 'variadic'];
        yield 'a by-reference parameter' => [ByReferenceParameter::class, 'by-reference'];
        yield 'an intersection type' => [IntersectionParameter::class, 'intersection'];
    }

    #[DataProvider('unsupportedSignatures')]
    public function testAMethodThatCouldNeverBeCalledCorrectlyIsRefusedAtBootWithTheParameterNamed(string $class, string $fragment): void
    {
        try {
            (new AttributeHandlers($this->container()))->build(MethodCatalog::fromClasses([$class]));
            self::fail('Expected LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString($fragment, $exception->getMessage());
            self::assertStringContainsString($class . '::', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------ filling parameters

    public function testEveryParameterStyleIsFilledFromTheParams(): void
    {
        $pair = $this->serve([FileService::class]);

        $result = $pair->client->call('math.describe', ['factor' => 2, 'label' => 'x', 'note' => 'n', 'loud' => true, 'tags' => ['a', 'b'], 'extra' => ['k' => 1]], timeout: 5.0);

        self::assertSame(['factor' => 2.0, 'label' => 'x', 'note' => 'n', 'loud' => true, 'tags' => ['a', 'b'], 'extra' => ['k' => 1]], $result);
    }

    public function testParametersWithDefaultsMayBeLeftOut(): void
    {
        $pair = $this->serve([FileService::class]);

        $result = $pair->client->call('math.describe', ['factor' => 1.5, 'label' => 7], timeout: 5.0);

        self::assertSame(['factor' => 1.5, 'label' => 7, 'note' => null, 'loud' => false, 'tags' => [], 'extra' => null], $result);
    }

    public function testNullIsAcceptedWhereTheTypeAllowsItAndAnIntegerWhereAFloatIsAsked(): void
    {
        $pair = $this->serve([FileService::class]);

        $result = $pair->client->call('math.describe', ['factor' => 3, 'label' => 'x', 'note' => null], timeout: 5.0);

        self::assertIsArray($result);
        self::assertSame(3.0, $result['factor'], 'an integer is accepted for a float parameter and PHP widens it');
        self::assertArrayHasKey('note', $result);
        self::assertNull($result['note']);
    }

    public function testAnUntypedParameterTakesAnything(): void
    {
        $pair = $this->serve([FileService::class]);

        foreach ([1, 'text', null, true, [1, 2], ['a' => 1]] as $value) {
            self::assertSame($value, $pair->client->call('math.untyped', ['anything' => $value], timeout: 5.0));
        }
    }

    public function testTheFrameworkFillsRequestCancellationAndProgress(): void
    {
        $pair = $this->serve([FileService::class]);
        $progress = [];

        $inspected = $pair->client->call('ctx.inspect', timeout: 5.0);
        $pair->client->call('files.scan', ['path' => '/p'], onProgress: static function (array $params) use (&$progress): void {
            $progress[] = $params;
        }, timeout: 5.0);

        self::assertIsArray($inspected);
        self::assertSame('ctx.inspect', $inspected['method'] ?? null);
        self::assertIsString($inspected['cancellation'] ?? null);
        self::assertTrue(is_a($inspected['cancellation'], Cancellation::class, true));
        self::assertSame([['scanned' => 1]], $progress);
        self::assertTrue($pair->client->call('ctx.optional', timeout: 5.0));
    }

    public function testAMethodThatNeedsACancellationStillWorksWhenTheCallerGaveNone(): void
    {
        $registry = (new AttributeHandlers($this->container()))->build(MethodCatalog::fromClasses([FileService::class]));

        $required = $registry->handle(new RpcRequest('ctx.inspect'));
        $optional = $registry->handle(new RpcRequest('ctx.optional'));

        self::assertIsArray($required->result);
        self::assertSame(\Amp\NullCancellation::class, $required->result['cancellation'], 'a parameter that cannot be null gets a cancellation that never fires');
        self::assertFalse($optional->result, 'a nullable parameter gets null when there is no cancellation');
    }

    public function testACancelStopsTheMethodThroughItsCancellationParameter(): void
    {
        $pair = $this->serve([FileService::class]);
        $cancel = new DeferredCancellation();
        $call = async(fn(): mixed => $pair->client->call('ctx.slow', cancellation: $cancel->getCancellation()));
        delay(0.05);

        $cancel->cancel();

        self::assertInstanceOf(RequestCancelledException::class, $this->thrownBy(fn(): mixed => $call->await()));
        delay(0.05);
        self::assertSame(1, FileService::$stopped, 'the method saw its cancellation');
    }

    // ------------------------------------------------------------ calls that do not fit

    public function testAMissingRequiredParameterIsInvalidParamsAndTheMethodIsNotCalled(): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->invalidParams($pair, 'math.add', ['a' => 1]);

        self::assertSame('"b" is required', $error->getMessage());
        self::assertSame(['param' => 'b'], $error->data);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'a string for an int' => ['math.add', ['a' => '1', 'b' => 2], 'a'];
        yield 'a float for an int' => ['math.add', ['a' => 1.5, 'b' => 2], 'a'];
        yield 'a float that looks whole for an int' => ['math.add', ['a' => 1.0, 'b' => 2], 'a'];
        yield 'a bool for an int' => ['math.add', ['a' => true, 'b' => 2], 'a'];
        yield 'null for an int' => ['math.add', ['a' => null, 'b' => 2], 'a'];
        yield 'an array for a string' => ['files.delete', ['path' => ['x']], 'path'];
        yield 'a number for a string' => ['files.delete', ['path' => 5], 'path'];
        yield 'null for a non-nullable string' => ['files.delete', ['path' => null], 'path'];
        yield 'a string for a float' => ['math.describe', ['factor' => '2', 'label' => 'x'], 'factor'];
        yield 'a bool for a union of int and string' => ['math.describe', ['factor' => 1, 'label' => true], 'label'];
        yield 'a string for a bool' => ['math.describe', ['factor' => 1, 'label' => 'x', 'loud' => 'yes'], 'loud'];
        yield 'an int for an array' => ['math.describe', ['factor' => 1, 'label' => 'x', 'tags' => 3], 'tags'];
        yield 'an int for a nullable string' => ['math.describe', ['factor' => 1, 'label' => 'x', 'note' => 3], 'note'];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('wrongTypes')]
    public function testAValueOfTheWrongTypeIsInvalidParamsNamingTheParameter(string $method, array $params, string $parameter): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->invalidParams($pair, $method, $params);

        self::assertSame(['param' => $parameter], $error->data);
        self::assertStringContainsString("\"{$parameter}\" must be of type", $error->getMessage());
    }

    public function testAParameterNameTheMethodDoesNotHaveIsInvalidParams(): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->invalidParams($pair, 'files.delete', ['path' => 'a', 'recursive' => true]);

        self::assertSame('unknown parameter "recursive"', $error->getMessage());
        self::assertSame(['param' => 'recursive'], $error->data);
    }

    public function testAnUnknownParameterNameIsCutToALengthThatIsSafeToEcho(): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->invalidParams($pair, 'files.delete', ['path' => 'a', str_repeat('n', 5000) => 1]);

        self::assertLessThan(120, strlen($error->getMessage()));
    }

    public function testExtraParamsOnAMethodWithNoParametersAreRefusedToo(): void
    {
        $pair = $this->serve([FileService::class]);

        $this->invalidParams($pair, 'ctx.optional', ['surprise' => 1]);
    }

    public function testAMethodThatThrowsAnRpcExceptionKeepsItsCodeAndData(): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->invalidParams($pair, 'fail.params', []);

        self::assertSame('rejected by the method', $error->getMessage());
        self::assertSame(['why' => 'test'], $error->data);
    }

    public function testAMethodThatThrowsAnythingElseIsAnInternalErrorThatLeaksNothing(): void
    {
        $pair = $this->serve([FileService::class]);

        $error = $this->thrownBy(fn(): mixed => $pair->client->call('fail.boom', timeout: 5.0));

        self::assertInstanceOf(InternalErrorException::class, $error);
        self::assertStringNotContainsString('secret', $error->getMessage());
    }

    public function testAMethodThatIsNotDeclaredIsNotFoundEvenIfItExistsOnTheClass(): void
    {
        $pair = $this->serve([FileService::class]);

        self::assertInstanceOf(MethodNotFoundException::class, $this->thrownBy(fn(): mixed => $pair->client->call('notExposed', timeout: 5.0)));
        self::assertInstanceOf(MethodNotFoundException::class, $this->thrownBy(fn(): mixed => $pair->client->call('scan', ['path' => 'a'], timeout: 5.0)), 'the PHP method name is not the RPC name');
    }
}
