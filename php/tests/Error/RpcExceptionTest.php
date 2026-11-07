<?php

declare(strict_types=1);

namespace Raphp\Tests\Error;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\ApplicationException;
use Raphp\Error\ErrorCode;
use Raphp\Error\InternalErrorException;
use Raphp\Error\InvalidParamsException;
use Raphp\Error\MethodNotFoundException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RaphpException;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\RpcException;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class RpcExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{ErrorCode, class-string<RpcException>}>
     */
    public static function frameworkErrors(): iterable
    {
        yield 'E_METHOD_NOT_FOUND' => [ErrorCode::MethodNotFound, MethodNotFoundException::class];
        yield 'E_INVALID_PARAMS' => [ErrorCode::InvalidParams, InvalidParamsException::class];
        yield 'E_CANCELLED' => [ErrorCode::Cancelled, RequestCancelledException::class];
        yield 'E_TIMEOUT' => [ErrorCode::Timeout, RequestTimeoutException::class];
        yield 'E_WORKER_CRASHED' => [ErrorCode::WorkerCrashed, WorkerCrashedException::class];
        yield 'E_WORKER_UNAVAILABLE' => [ErrorCode::WorkerUnavailable, WorkerUnavailableException::class];
        yield 'E_PAYLOAD_TOO_LARGE' => [ErrorCode::PayloadTooLarge, PayloadTooLargeException::class];
        yield 'E_PROTOCOL' => [ErrorCode::Protocol, ProtocolException::class];
        yield 'E_INTERNAL' => [ErrorCode::Internal, InternalErrorException::class];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function dataValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'zero' => [0];
        yield 'an integer' => [42];
        yield 'a float' => [1.5];
        yield 'an empty string' => [''];
        yield 'a string' => ['text'];
        yield 'a list' => [[1, 'two', null]];
        yield 'a nested object' => [['field' => ['name' => 'path', 'problems' => ['required', 'too long']]]];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidApplicationCodes(): iterable
    {
        yield 'only the prefix' => ['E_APP_'];
        yield 'lower case suffix' => ['E_APP_lower'];
        yield 'a framework code' => ['E_TIMEOUT'];
        yield 'no E_ prefix' => ['APP_X'];
        yield 'a trailing newline' => ["E_APP_X\n"];
        yield 'a space' => ['E_APP_A B'];
        yield 'a dash' => ['E_APP_A-B'];
        yield 'empty' => [''];
        yield 'prefix in the middle' => ['X_E_APP_Y'];
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformedErrorObjects(): iterable
    {
        yield 'empty' => [[]];
        yield 'no message' => [['code' => 'E_TIMEOUT']];
        yield 'no code' => [['message' => 'text']];
        yield 'code is an integer' => [['code' => 5, 'message' => 'text']];
        yield 'code is null' => [['code' => null, 'message' => 'text']];
        yield 'code is empty' => [['code' => '', 'message' => 'text']];
        yield 'code is an array' => [['code' => ['E_TIMEOUT'], 'message' => 'text']];
        yield 'message is an integer' => [['code' => 'E_TIMEOUT', 'message' => 5]];
        yield 'message is null' => [['code' => 'E_TIMEOUT', 'message' => null]];
    }

    /**
     * @param class-string<RpcException> $class
     */
    #[DataProvider('frameworkErrors')]
    public function testEachFrameworkCodeHasItsOwnExceptionWithTheRightWireCode(ErrorCode $code, string $class): void
    {
        $exception = new $class('message');

        self::assertSame($code->value, $exception->wireCode());
        self::assertSame($code, ErrorCode::fromWire($exception->wireCode()));
    }

    public function testEveryErrorCodeIsCoveredByExactlyOneExceptionClass(): void
    {
        $covered = [];
        foreach (self::frameworkErrors() as [$code, $class]) {
            $covered[] = $code->value;
            self::assertTrue((new ReflectionClass($class))->isFinal(), "{$class} should be final");
        }

        self::assertSame(array_map(static fn(ErrorCode $code): string => $code->value, ErrorCode::cases()), $covered);
        self::assertSame(count($covered), count(array_unique($covered)));
    }

    /**
     * @param class-string<RpcException> $class
     */
    #[DataProvider('frameworkErrors')]
    public function testEveryFrameworkErrorRoundTripsThroughRealJson(ErrorCode $code, string $class): void
    {
        $original = new $class('it went wrong', ['detail' => [1, 2]]);

        $wire = json_decode(json_encode($original->toWire(), JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($wire);
        $rebuilt = RpcException::fromWire($wire);

        self::assertInstanceOf($class, $rebuilt);
        self::assertSame($code->value, $rebuilt->wireCode());
        self::assertSame('it went wrong', $rebuilt->getMessage());
        self::assertSame(['detail' => [1, 2]], $rebuilt->data);
    }

    /**
     * @param class-string<RpcException> $class
     */
    #[DataProvider('frameworkErrors')]
    public function testEveryFrameworkErrorIsARaphpRuntimeException(ErrorCode $code, string $class): void
    {
        $exception = new $class();

        self::assertInstanceOf(RpcException::class, $exception);
        self::assertInstanceOf(RaphpException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertInstanceOf(Throwable::class, $exception);
        self::assertSame(0, $exception->getCode());
        self::assertSame('', $exception->getMessage());
        self::assertNull($exception->data);
    }

    public function testAnErrorMessageFromAPeerIsCutToTheLimitAndMarkedAsCut(): void
    {
        $exception = RpcException::fromWire(['code' => 'E_INTERNAL', 'message' => str_repeat('a', 100_000)]);

        self::assertSame(RpcException::MAX_PEER_MESSAGE_BYTES + 3, strlen($exception->getMessage()));
        self::assertStringEndsWith('...', $exception->getMessage());
    }

    public function testAMessageExactlyAtTheLimitIsKeptWhole(): void
    {
        $text = str_repeat('a', RpcException::MAX_PEER_MESSAGE_BYTES);

        self::assertSame($text, RpcException::fromWire(['code' => 'E_INTERNAL', 'message' => $text])->getMessage());
    }

    public function testCuttingNeverSplitsAMultiByteCharacter(): void
    {
        foreach (["\u{e9}", "\u{20ac}", "\u{1f600}"] as $character) {
            for ($offset = 0; $offset < 4; $offset++) {
                $text = str_repeat('a', RpcException::MAX_PEER_MESSAGE_BYTES - $offset) . str_repeat($character, 20);
                $message = RpcException::fromWire(['code' => 'E_INTERNAL', 'message' => $text])->getMessage();

                self::assertSame(1, preg_match('//u', $message), 'the cut message must still be valid UTF-8');
                self::assertLessThanOrEqual(RpcException::MAX_PEER_MESSAGE_BYTES + 3, strlen($message));
            }
        }
    }

    public function testTheBaseClassesCannotBeThrownDirectly(): void
    {
        self::assertTrue((new ReflectionClass(RaphpException::class))->isAbstract());
        self::assertTrue((new ReflectionClass(RpcException::class))->isAbstract());
    }

    public function testTheWireShapeIsExactlyCodeMessageData(): void
    {
        $wire = (new RequestTimeoutException('too slow', 7))->toWire();

        self::assertSame(['code', 'message', 'data'], array_keys($wire));
        self::assertSame(['code' => 'E_TIMEOUT', 'message' => 'too slow', 'data' => 7], $wire);
    }

    public function testThePreviousExceptionIsKeptLocallyButNeverSentOnTheWire(): void
    {
        $previous = new RuntimeException('root cause');

        $exception = new WorkerCrashedException('the worker died', null, $previous);

        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(['code', 'message', 'data'], array_keys($exception->toWire()));
        self::assertStringNotContainsString('root cause', json_encode($exception->toWire(), JSON_THROW_ON_ERROR));
    }

    #[DataProvider('dataValues')]
    public function testDataOfAnyJsonShapeSurvivesTheWire(mixed $data): void
    {
        $wire = json_decode(json_encode((new InvalidParamsException('bad', $data))->toWire(), JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($wire);

        self::assertSame($data, RpcException::fromWire($wire)->data);
    }

    // ---------------------------------------------------------------- application errors

    public function testAnApplicationErrorCarriesItsOwnCodeAndData(): void
    {
        $exception = new ApplicationException('E_APP_FILE_LOCKED', 'the file is locked', ['path' => '/tmp/x', 'holder' => 4242]);

        self::assertSame('E_APP_FILE_LOCKED', $exception->wireCode());
        self::assertSame(['code' => 'E_APP_FILE_LOCKED', 'message' => 'the file is locked', 'data' => ['path' => '/tmp/x', 'holder' => 4242]], $exception->toWire());
        self::assertInstanceOf(RpcException::class, $exception);
        self::assertNull(ErrorCode::fromWire($exception->wireCode()));
    }

    #[DataProvider('dataValues')]
    public function testAnApplicationErrorRoundTripsThroughRealJson(mixed $data): void
    {
        $wire = json_decode(json_encode((new ApplicationException('E_APP_X1', 'm', $data))->toWire(), JSON_THROW_ON_ERROR), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($wire);

        $rebuilt = RpcException::fromWire($wire);

        self::assertInstanceOf(ApplicationException::class, $rebuilt);
        self::assertSame('E_APP_X1', $rebuilt->wireCode());
        self::assertSame('m', $rebuilt->getMessage());
        self::assertSame($data, $rebuilt->data);
    }

    #[DataProvider('invalidApplicationCodes')]
    public function testAMalformedApplicationCodeIsRefusedWhenBuilding(string $code): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApplicationException($code, 'message');
    }

    #[DataProvider('invalidApplicationCodes')]
    public function testAMalformedApplicationCodeOnTheWireIsNotTrustedAsAnApplicationError(string $code): void
    {
        $rebuilt = RpcException::fromWire(['code' => $code, 'message' => 'm']);

        self::assertNotInstanceOf(ApplicationException::class, $rebuilt);
    }

    public function testApplicationCodeValidationAcceptsCapitalsDigitsAndUnderscores(): void
    {
        foreach (['E_APP_A', 'E_APP_NOT_FOUND', 'E_APP_X1', 'E_APP_1', 'E_APP__', 'E_APP_A_B_C_9'] as $code) {
            self::assertTrue(ApplicationException::isValidCode($code), $code);
        }
    }

    // ---------------------------------------------------------------- reading the wire

    public function testAnUnknownCodeBecomesAnInternalErrorThatKeepsTheOriginalWithoutEchoingIt(): void
    {
        $rebuilt = RpcException::fromWire(['code' => 'E_FROM_THE_FUTURE', 'message' => "a\nb", 'data' => ['x' => 1]]);

        self::assertInstanceOf(InternalErrorException::class, $rebuilt);
        self::assertSame('an error with an unknown code was received', $rebuilt->getMessage());
        self::assertStringNotContainsString('E_FROM_THE_FUTURE', $rebuilt->getMessage());
        self::assertSame(['code' => 'E_FROM_THE_FUTURE', 'message' => "a\nb", 'data' => ['x' => 1]], $rebuilt->data);
    }

    /**
     * @param array<array-key, mixed> $error
     */
    #[DataProvider('malformedErrorObjects')]
    public function testAMalformedErrorObjectBecomesAProtocolError(array $error): void
    {
        $rebuilt = RpcException::fromWire($error);

        self::assertInstanceOf(ProtocolException::class, $rebuilt);
        self::assertStringContainsString('malformed error object', $rebuilt->getMessage());
    }

    public function testExtraKeysAreIgnoredSoANewerPeerCanAddFields(): void
    {
        $rebuilt = RpcException::fromWire(['code' => 'E_TIMEOUT', 'message' => 'm', 'retryAfter' => 5, 'trace' => 'x']);

        self::assertInstanceOf(RequestTimeoutException::class, $rebuilt);
        self::assertSame('m', $rebuilt->getMessage());
        self::assertNull($rebuilt->data);
    }

    public function testAMissingDataFieldIsNullButFalsyDataIsKept(): void
    {
        self::assertNull(RpcException::fromWire(['code' => 'E_TIMEOUT', 'message' => 'm'])->data);
        self::assertFalse(RpcException::fromWire(['code' => 'E_TIMEOUT', 'message' => 'm', 'data' => false])->data);
        self::assertSame(0, RpcException::fromWire(['code' => 'E_TIMEOUT', 'message' => 'm', 'data' => 0])->data);
        self::assertSame('', RpcException::fromWire(['code' => 'E_TIMEOUT', 'message' => 'm', 'data' => ''])->data);
    }

    public function testAnEmptyMessageIsValid(): void
    {
        $rebuilt = RpcException::fromWire(['code' => 'E_INTERNAL', 'message' => '']);

        self::assertInstanceOf(InternalErrorException::class, $rebuilt);
        self::assertSame('', $rebuilt->getMessage());
    }
}
