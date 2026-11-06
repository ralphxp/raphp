<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Message;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\InternalErrorException;
use Raphp\Error\RequestCancelledException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\Bye;
use Raphp\Ipc\Message\Cancel;
use Raphp\Ipc\Message\Envelope;
use Raphp\Ipc\Message\Event;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Message\Kind;
use Raphp\Ipc\Message\Ping;
use Raphp\Ipc\Message\Pong;
use Raphp\Ipc\Message\Request;
use Raphp\Ipc\Message\Response;

final class MessagesTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): Envelope}>
     */
    public static function invalidValues(): iterable
    {
        yield 'request id zero' => [static fn(): Envelope => new Request(0, 'a.b')];
        yield 'request id negative' => [static fn(): Envelope => new Request(-3, 'a.b')];
        yield 'request method empty' => [static fn(): Envelope => new Request(1, '')];
        yield 'request params a list' => [static fn(): Envelope => new Request(1, 'a.b', [1, 2])];
        yield 'request trace empty' => [static fn(): Envelope => new Request(1, 'a.b', [], '')];
        yield 'request trace with a space' => [static fn(): Envelope => new Request(1, 'a.b', [], 'a b')];
        yield 'request trace with a newline' => [static fn(): Envelope => new Request(1, 'a.b', [], "a\nb")];
        yield 'request trace with a trailing newline' => [static fn(): Envelope => new Request(1, 'a.b', [], "abc\n")];
        yield 'request trace non-ASCII' => [static fn(): Envelope => new Request(1, 'a.b', [], "caf\u{e9}")];
        yield 'request trace too long' => [static fn(): Envelope => new Request(1, 'a.b', [], str_repeat('x', 65))];
        yield 'response id zero' => [static fn(): Envelope => Response::success(0)];
        yield 'failure id negative' => [static fn(): Envelope => Response::failure(-1, new InternalErrorException('x'))];
        yield 'event method empty' => [static fn(): Envelope => new Event('')];
        yield 'event ref zero' => [static fn(): Envelope => new Event('a', [], 0)];
        yield 'event params a list' => [static fn(): Envelope => new Event('a', ['x'])];
        yield 'cancel ref zero' => [static fn(): Envelope => new Cancel(0)];
        yield 'hello proto zero' => [static fn(): Envelope => new Hello(0, 'worker', 'main', 1)];
        yield 'hello role empty' => [static fn(): Envelope => new Hello(1, '', 'main', 1)];
        yield 'hello name empty' => [static fn(): Envelope => new Hello(1, 'worker', '', 1)];
        yield 'hello pid zero' => [static fn(): Envelope => new Hello(1, 'worker', 'main', 0)];
    }

    /**
     * @param Closure(): Envelope $build
     */
    #[DataProvider('invalidValues')]
    public function testAValueThatBreaksTheProtocolRulesCannotBeBuilt(Closure $build): void
    {
        $this->expectException(InvalidArgumentException::class);

        $build();
    }

    public function testEveryKindHasItsWireString(): void
    {
        self::assertSame(
            ['req', 'res', 'evt', 'cancel', 'hello', 'bye', 'ping', 'pong'],
            array_map(static fn(Kind $kind): string => $kind->value, Kind::cases()),
        );
    }

    public function testEachMessageReportsItsKind(): void
    {
        self::assertSame(Kind::Request, (new Request(1, 'a'))->kind());
        self::assertSame(Kind::Response, Response::success(1)->kind());
        self::assertSame(Kind::Event, (new Event('a'))->kind());
        self::assertSame(Kind::Cancel, (new Cancel(1))->kind());
        self::assertSame(Kind::Hello, (new Hello(1, 'worker', 'main', 1))->kind());
        self::assertSame(Kind::Bye, (new Bye())->kind());
        self::assertSame(Kind::Ping, (new Ping())->kind());
        self::assertSame(Kind::Pong, (new Pong())->kind());
    }

    public function testTheKindOfEveryMessageIsTheFirstOrSecondKeyOnTheWire(): void
    {
        $messages = [new Request(1, 'a'), Response::success(1), new Event('a'), new Cancel(1), new Hello(1, 'w', 'n', 1), new Bye(), new Ping(), new Pong()];

        foreach ($messages as $message) {
            self::assertSame($message->kind()->value, $message->toArray()['kind']);
        }
    }

    public function testAnEmptyParamsObjectIsWrittenAsAnObjectNotAList(): void
    {
        $codec = new JsonLineCodec();

        self::assertSame('{"id":1,"kind":"req","method":"a.b","params":{}}' . "\n", $codec->encode((new Request(1, 'a.b'))->toArray()));
        self::assertSame('{"kind":"evt","method":"a","params":{}}' . "\n", $codec->encode((new Event('a'))->toArray()));
    }

    public function testATraceIdIsWrittenLastAndLeftOutWhenThereIsNone(): void
    {
        self::assertSame(['id', 'kind', 'method', 'params'], array_keys((new Request(1, 'a'))->toArray()));
        self::assertSame(['id', 'kind', 'method', 'params', 'trace'], array_keys((new Request(1, 'a', [], 'abc-1.2_x'))->toArray()));
        self::assertSame('abc-1.2_x', (new Request(1, 'a', [], 'abc-1.2_x'))->toArray()['trace']);
    }

    public function testTheLongestAndShortestTraceIdsAreAccepted(): void
    {
        self::assertSame(64, strlen((string) (new Request(1, 'a', [], str_repeat('x', 64)))->trace));
        self::assertSame('x', (new Request(1, 'a', [], 'x'))->trace);
    }

    public function testAnEventWithoutARefLeavesTheKeyOut(): void
    {
        self::assertArrayNotHasKey('ref', (new Event('a'))->toArray());
        self::assertSame(7, (new Event('a', [], 7))->toArray()['ref']);
    }

    public function testASuccessCarriesItsResultEvenWhenItIsNullAndNeverAnErrorKey(): void
    {
        $wire = Response::success(4)->toArray();

        self::assertSame(['id' => 4, 'kind' => 'res', 'result' => null], $wire);
        self::assertFalse(Response::success(4)->isFailure());
    }

    public function testAFailureCarriesTheErrorObjectAndNoResultKey(): void
    {
        $response = Response::failure(4, new RequestCancelledException('stopped', ['why' => 'user']));

        self::assertTrue($response->isFailure());
        self::assertSame(['id' => 4, 'kind' => 'res', 'error' => ['code' => 'E_CANCELLED', 'message' => 'stopped', 'data' => ['why' => 'user']]], $response->toArray());
    }

    public function testByeAndHelloKeepTheirFieldsInTheDocumentedOrder(): void
    {
        self::assertSame(['kind', 'proto', 'role', 'name', 'pid', 'caps'], array_keys((new Hello(1, 'worker', 'main', 9, ['a']))->toArray()));
        self::assertSame(['kind' => 'bye', 'reason' => 'shutdown'], (new Bye('shutdown'))->toArray());
    }

    public function testNoCapabilitiesIsAnEmptyListOnTheWire(): void
    {
        self::assertSame('{"kind":"hello","proto":1,"role":"worker","name":"main","pid":9,"caps":[]}' . "\n", (new JsonLineCodec())->encode((new Hello(1, 'worker', 'main', 9))->toArray()));
    }
}
