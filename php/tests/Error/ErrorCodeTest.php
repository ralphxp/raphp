<?php

declare(strict_types=1);

namespace Raphp\Tests\Error;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\ErrorCode;

final class ErrorCodeTest extends TestCase
{
    /** The codes exactly as FRAMEWORK 6.6 lists them. */
    private const array CODES_IN_THE_DESIGN = [
        'E_METHOD_NOT_FOUND',
        'E_INVALID_PARAMS',
        'E_CANCELLED',
        'E_TIMEOUT',
        'E_WORKER_CRASHED',
        'E_WORKER_UNAVAILABLE',
        'E_PAYLOAD_TOO_LARGE',
        'E_PROTOCOL',
        'E_INTERNAL',
    ];

    /**
     * @return iterable<string, array{ErrorCode}>
     */
    public static function codes(): iterable
    {
        foreach (ErrorCode::cases() as $code) {
            yield $code->value => [$code];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notFrameworkCodes(): iterable
    {
        yield 'lower case' => ['e_timeout'];
        yield 'mixed case' => ['E_Timeout'];
        yield 'leading space' => [' E_TIMEOUT'];
        yield 'trailing newline' => ["E_TIMEOUT\n"];
        yield 'without the prefix' => ['TIMEOUT'];
        yield 'an unknown code' => ['E_UNKNOWN'];
        yield 'an application code' => ['E_APP_SOMETHING'];
        yield 'empty' => [''];
        yield 'a case name instead of a value' => ['Timeout'];
    }

    public function testTheCodesAreExactlyThoseInTheDesign(): void
    {
        self::assertSame(self::CODES_IN_THE_DESIGN, array_map(static fn(ErrorCode $code): string => $code->value, ErrorCode::cases()));
    }

    #[DataProvider('codes')]
    public function testEveryCodeRoundTripsThroughItsString(ErrorCode $code): void
    {
        self::assertSame($code, ErrorCode::from($code->value));
        self::assertSame($code, ErrorCode::tryFrom($code->value));
        self::assertSame($code, ErrorCode::fromWire($code->value));
        self::assertSame($code->value, ErrorCode::fromWire($code->value)->value ?? null);
    }

    #[DataProvider('codes')]
    public function testEveryCodeIsAnUpperCaseWireString(ErrorCode $code): void
    {
        self::assertMatchesRegularExpression('/^E_[A-Z]+(_[A-Z]+)*\z/', $code->value);
    }

    #[DataProvider('notFrameworkCodes')]
    public function testOnlyAnExactFrameworkCodeIsFound(string $wire): void
    {
        self::assertNull(ErrorCode::fromWire($wire));
    }
}
