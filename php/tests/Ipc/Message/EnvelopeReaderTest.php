<?php

declare(strict_types=1);

namespace Raphp\Tests\Ipc\Message;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raphp\Error\ApplicationException;
use Raphp\Error\InternalErrorException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RequestCancelledException;
use Raphp\Ipc\Codec\JsonLineCodec;
use Raphp\Ipc\Message\Bye;
use Raphp\Ipc\Message\Cancel;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Event;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Message\Ping;
use Raphp\Ipc\Message\Pong;
use Raphp\Ipc\Message\Request;
use Raphp\Ipc\Message\Response;

final class EnvelopeReaderTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../../../../docs/protocol/golden';

    /**
     * @return list<array<array-key, mixed>>
     */
    private function golden(): array
    {
        $messages = json_decode((string) file_get_contents(self::GOLDEN . '/messages.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($messages);

        /** @var list<array<array-key, mixed>> $messages */
        return $messages;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function refusal(array $data): string
    {
        try {
            (new EnvelopeReader())->read($data);
        } catch (ProtocolException $exception) {
            return $exception->getMessage();
        }

        self::fail('Expected ProtocolException for ' . json_encode($data));
    }

    // ------------------------------------------------------------ valid messages

    public function testEveryGoldenMessageReadsAndWritesBackToTheSameBytes(): void
    {
        $reader = new EnvelopeReader();
        $codec = new JsonLineCodec();
        $bytes = '';
        foreach ($this->golden() as $data) {
            $bytes .= $codec->encode($reader->read($data)->toArray());
        }

        self::assertSame((string) file_get_contents(self::GOLDEN . '/messages.ndjson'), $bytes);
    }

    public function testTheGoldenMessagesBecomeTheRightTypesWithTheRightFields(): void
    {
        $reader = new EnvelopeReader();
        [$hello, $request, $progress, $result, $failure, $cancel, $appEvent, $echo, $ping, $pong, $bye] = array_map($reader->read(...), $this->golden());

        self::assertEquals(new Hello(1, 'worker', 'main', 4242, []), $hello);
        self::assertEquals(new Request(17, 'files.scan', ['path' => '/tmp/some dir']), $request);
        self::assertEquals(new Event('progress', ['done' => 40, 'total' => 100], 17), $progress);
        self::assertInstanceOf(Response::class, $result);
        self::assertSame(17, $result->id);
        self::assertSame(['count' => 100, 'ratio' => 0.5, 'ok' => true, 'next' => null], $result->result);
        self::assertFalse($result->isFailure());
        self::assertInstanceOf(Response::class, $failure);
        self::assertSame(18, $failure->id);
        self::assertInstanceOf(RequestCancelledException::class, $failure->error);
        self::assertSame('cancelled by the caller', $failure->error->getMessage());
        self::assertEquals(new Cancel(17), $cancel);
        self::assertEquals(new Event('app.theme_changed', ['dark' => true]), $appEvent);
        self::assertInstanceOf(Request::class, $echo);
        self::assertSame("caf\u{e9} \u{20ac}5 \u{1f600}\nsecond line\ttab \"quoted\" back\\slash", $echo->params['text']);
        self::assertEquals(new Ping(), $ping);
        self::assertEquals(new Pong(), $pong);
        self::assertEquals(new Bye('shutdown'), $bye);
    }

    public function testTheSmallestValidFormOfEachKindIsAccepted(): void
    {
        $reader = new EnvelopeReader();

        self::assertEquals(new Request(1, 'a'), $reader->read(['kind' => 'req', 'id' => 1, 'method' => 'a']));
        self::assertEquals(new Event('a'), $reader->read(['kind' => 'evt', 'method' => 'a']));
        self::assertEquals(new Hello(1, 'worker', 'main', 9), $reader->read(['kind' => 'hello', 'proto' => 1, 'role' => 'worker', 'name' => 'main', 'pid' => 9]));
        self::assertEquals(new Bye(), $reader->read(['kind' => 'bye']));
        self::assertEquals(new Ping(), $reader->read(['kind' => 'ping']));
        self::assertEquals(new Pong(), $reader->read(['kind' => 'pong']));
    }

    public function testExtraKeysFromANewerPeerAreIgnored(): void
    {
        $reader = new EnvelopeReader();

        self::assertEquals(new Ping(), $reader->read(['kind' => 'ping', 'trace' => 'abc', 'x' => [1]]));
        self::assertEquals(new Request(2, 'a', ['p' => 1]), $reader->read(['kind' => 'req', 'id' => 2, 'method' => 'a', 'params' => ['p' => 1], 'deadline' => 5]));
        self::assertEquals(new Event('a'), $reader->read(['kind' => 'evt', 'id' => 17, 'method' => 'a']));
    }

    public function testATraceIdIsKeptAndWrittenBack(): void
    {
        $request = (new EnvelopeReader())->read(['kind' => 'req', 'id' => 3, 'method' => 'a', 'trace' => 'abc-123']);

        self::assertEquals(new Request(3, 'a', [], 'abc-123'), $request);
        self::assertSame('abc-123', $request->toArray()['trace']);
    }

    public function testAnEmptyParamsObjectIsAccepted(): void
    {
        self::assertEquals(new Request(1, 'a'), (new EnvelopeReader())->read(['kind' => 'req', 'id' => 1, 'method' => 'a', 'params' => []]));
    }

    public function testAResultMayBeAnyJsonValue(): void
    {
        $reader = new EnvelopeReader();

        foreach ([null, true, 0, 1.5, 'text', [1, 2], ['a' => 1], []] as $result) {
            $response = $reader->read(['kind' => 'res', 'id' => 3, 'result' => $result]);

            self::assertInstanceOf(Response::class, $response);
            self::assertSame($result, $response->result);
            self::assertFalse($response->isFailure());
        }
    }

    public function testAnErrorResponseKeepsItsCodeMessageAndData(): void
    {
        $response = (new EnvelopeReader())->read(['kind' => 'res', 'id' => 3, 'error' => ['code' => 'E_APP_DISK_FULL', 'message' => 'no space', 'data' => ['free' => 0]]]);

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->isFailure());
        self::assertInstanceOf(ApplicationException::class, $response->error);
        self::assertSame('E_APP_DISK_FULL', $response->error->wireCode());
        self::assertSame(['free' => 0], $response->error->data);
    }

    public function testAnUnknownErrorCodeBecomesAnInternalError(): void
    {
        $response = (new EnvelopeReader())->read(['kind' => 'res', 'id' => 3, 'error' => ['code' => 'E_FROM_THE_FUTURE', 'message' => 'm']]);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(InternalErrorException::class, $response->error);
    }

    public function testAMalformedErrorObjectGivesAFailureThatIsAProtocolError(): void
    {
        $response = (new EnvelopeReader())->read(['kind' => 'res', 'id' => 3, 'error' => ['message' => 'no code']]);

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->isFailure());
        self::assertInstanceOf(ProtocolException::class, $response->error);
    }

    public function testAnErrorAlongsideANullResultIsStillAFailure(): void
    {
        $response = (new EnvelopeReader())->read(['kind' => 'res', 'id' => 3, 'result' => null, 'error' => ['code' => 'E_INTERNAL', 'message' => 'm']]);

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->isFailure());
    }

    public function testANullErrorMeansSuccess(): void
    {
        $response = (new EnvelopeReader())->read(['kind' => 'res', 'id' => 3, 'result' => 5, 'error' => null]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(5, $response->result);
        self::assertFalse($response->isFailure());
    }

    // ------------------------------------------------------------ refused messages

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function refusedMessages(): iterable
    {
        // the kind itself
        yield 'no kind' => [['id' => 1], 'needs a string "kind"'];
        yield 'kind null' => [['kind' => null], 'needs a string "kind"'];
        yield 'kind a number' => [['kind' => 1], 'needs a string "kind"'];
        yield 'kind a list' => [['kind' => ['req']], 'needs a string "kind"'];
        yield 'kind unknown' => [['kind' => 'nope'], 'unknown message kind "nope"'];
        yield 'kind in the wrong case' => [['kind' => 'REQ'], 'unknown message kind "REQ"'];
        yield 'kind empty' => [['kind' => ''], 'unknown message kind ""'];
        yield 'kind with a space' => [['kind' => 'req '], 'unknown message kind "req "'];
        yield 'an empty object' => [[], 'needs a string "kind"'];

        // request
        yield 'request without id' => [['kind' => 'req', 'method' => 'a'], 'needs an integer "id"'];
        yield 'request id a string' => [['kind' => 'req', 'id' => '1', 'method' => 'a'], 'needs an integer "id"'];
        yield 'request id a float' => [['kind' => 'req', 'id' => 1.0, 'method' => 'a'], 'needs an integer "id"'];
        yield 'request id true' => [['kind' => 'req', 'id' => true, 'method' => 'a'], 'needs an integer "id"'];
        yield 'request id null' => [['kind' => 'req', 'id' => null, 'method' => 'a'], 'needs an integer "id"'];
        yield 'request id zero' => [['kind' => 'req', 'id' => 0, 'method' => 'a'], 'invalid req message: "id" must be a positive integer'];
        yield 'request id negative' => [['kind' => 'req', 'id' => -1, 'method' => 'a'], 'invalid req message: "id" must be a positive integer'];
        yield 'request without method' => [['kind' => 'req', 'id' => 1], 'needs a string "method"'];
        yield 'request method a number' => [['kind' => 'req', 'id' => 1, 'method' => 5], 'needs a string "method"'];
        yield 'request method null' => [['kind' => 'req', 'id' => 1, 'method' => null], 'needs a string "method"'];
        yield 'request method empty' => [['kind' => 'req', 'id' => 1, 'method' => ''], 'invalid req message: "method" must not be empty'];
        yield 'request params a list' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'params' => [1, 2]], 'invalid req message: "params" must be an object'];
        yield 'request params a string' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'params' => 'x'], '"params" must be an object'];
        yield 'request params null' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'params' => null], '"params" must be an object'];
        yield 'request params a number' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'params' => 3], '"params" must be an object'];

        yield 'request trace a number' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'trace' => 5], 'needs a string "trace"'];
        yield 'request trace null' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'trace' => null], 'needs a string "trace"'];
        yield 'request trace with a space' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'trace' => 'a b'], 'invalid req message: "trace" must be'];
        yield 'request trace empty' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'trace' => ''], 'invalid req message: "trace" must be'];
        yield 'request trace with an escape character' => [['kind' => 'req', 'id' => 1, 'method' => 'a', 'trace' => "x\x1b[31m"], 'invalid req message: "trace" must be'];

        // response
        yield 'response without id' => [['kind' => 'res', 'result' => 1], 'needs an integer "id"'];
        yield 'response id zero' => [['kind' => 'res', 'id' => 0, 'result' => 1], 'invalid res message: "id" must be a positive integer'];
        yield 'response with neither result nor error' => [['kind' => 'res', 'id' => 1], 'needs a "result" or an "error"'];
        yield 'response with a null error and no result' => [['kind' => 'res', 'id' => 1, 'error' => null], 'needs a "result" or an "error"'];
        yield 'response with both' => [['kind' => 'res', 'id' => 1, 'result' => 1, 'error' => ['code' => 'E_INTERNAL', 'message' => 'm']], 'holds both a "result" and an "error"'];
        yield 'response error a string' => [['kind' => 'res', 'id' => 1, 'error' => 'boom'], '"error" must be an object'];
        yield 'response error a number' => [['kind' => 'res', 'id' => 1, 'error' => 5], '"error" must be an object'];
        yield 'response error true' => [['kind' => 'res', 'id' => 1, 'error' => true], '"error" must be an object'];

        // event
        yield 'event without method' => [['kind' => 'evt'], 'needs a string "method"'];
        yield 'event method empty' => [['kind' => 'evt', 'method' => ''], 'invalid evt message: "method" must not be empty'];
        yield 'event ref a string' => [['kind' => 'evt', 'method' => 'a', 'ref' => '1'], 'needs an integer "ref"'];
        yield 'event ref null' => [['kind' => 'evt', 'method' => 'a', 'ref' => null], 'needs an integer "ref"'];
        yield 'event ref zero' => [['kind' => 'evt', 'method' => 'a', 'ref' => 0], 'invalid evt message: "ref" must be a positive integer'];
        yield 'event params a list' => [['kind' => 'evt', 'method' => 'a', 'params' => ['x']], '"params" must be an object'];
        yield 'event params a string' => [['kind' => 'evt', 'method' => 'a', 'params' => 'x'], '"params" must be an object'];

        // cancel
        yield 'cancel without ref' => [['kind' => 'cancel'], 'needs an integer "ref"'];
        yield 'cancel ref a float' => [['kind' => 'cancel', 'ref' => 2.5], 'needs an integer "ref"'];
        yield 'cancel ref zero' => [['kind' => 'cancel', 'ref' => 0], 'invalid cancel message: "ref" must be a positive integer'];
        yield 'cancel ref negative' => [['kind' => 'cancel', 'ref' => -4], 'must be a positive integer'];

        // hello
        $hello = ['kind' => 'hello', 'proto' => 1, 'role' => 'worker', 'name' => 'main', 'pid' => 9];
        yield 'hello without proto' => [array_diff_key($hello, ['proto' => 1]), 'needs an integer "proto"'];
        yield 'hello proto a string' => [['proto' => '1'] + $hello, 'needs an integer "proto"'];
        yield 'hello proto zero' => [['proto' => 0] + $hello, 'invalid hello message: "proto" must be a positive integer'];
        yield 'hello without role' => [array_diff_key($hello, ['role' => 1]), 'needs a string "role"'];
        yield 'hello role empty' => [['role' => ''] + $hello, 'invalid hello message: "role" must not be empty'];
        yield 'hello without name' => [array_diff_key($hello, ['name' => 1]), 'needs a string "name"'];
        yield 'hello name a number' => [['name' => 7] + $hello, 'needs a string "name"'];
        yield 'hello name empty' => [['name' => ''] + $hello, 'invalid hello message: "name" must not be empty'];
        yield 'hello without pid' => [array_diff_key($hello, ['pid' => 1]), 'needs an integer "pid"'];
        yield 'hello pid a string' => [['pid' => 'x'] + $hello, 'needs an integer "pid"'];
        yield 'hello pid zero' => [['pid' => 0] + $hello, 'invalid hello message: "pid" must be a positive integer'];
        yield 'hello caps a string' => [['caps' => 'a'] + $hello, '"caps" must be a list of strings'];
        yield 'hello caps null' => [['caps' => null] + $hello, '"caps" must be a list of strings'];
        yield 'hello caps an object' => [['caps' => ['a' => 'b']] + $hello, '"caps" must be a list of strings'];
        yield 'hello caps with a number' => [['caps' => ['a', 1]] + $hello, '"caps" must be a list of strings'];

        // bye
        yield 'bye reason a number' => [['kind' => 'bye', 'reason' => 5], 'needs a string "reason"'];
        yield 'bye reason null' => [['kind' => 'bye', 'reason' => null], 'needs a string "reason"'];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('refusedMessages')]
    public function testAMessageThatBreaksTheRulesIsAProtocolErrorThatSaysWhy(array $data, string $expected): void
    {
        self::assertStringContainsString($expected, $this->refusal($data));
    }

    public function testTheProtocolErrorForAValueRuleKeepsTheCauseAndTheWireCode(): void
    {
        try {
            (new EnvelopeReader())->read(['kind' => 'cancel', 'ref' => 0]);
            self::fail('Expected ProtocolException.');
        } catch (ProtocolException $exception) {
            self::assertSame('E_PROTOCOL', $exception->wireCode());
            self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());
        }
    }

    public function testAnUntrustedKindIsQuotedShortenedAndFreeOfRawControlCharacters(): void
    {
        $message = $this->refusal(['kind' => "bad\nkind\x1b[31m\0" . str_repeat('x', 500)]);

        self::assertStringNotContainsString("\n", $message);
        self::assertStringNotContainsString("\x1b", $message);
        self::assertStringNotContainsString("\0", $message);
        self::assertStringContainsString('...', $message);
        self::assertLessThan(120, strlen($message));
    }

    public function testAKindThatIsNotValidUtf8StillGivesAProtocolError(): void
    {
        self::assertStringContainsString('unknown message kind "bad', $this->refusal(['kind' => "bad\xff"]));
    }

    public function testObjectsWithDigitKeysAreRefusedBecauseTheyHaveNoKind(): void
    {
        self::assertStringContainsString('needs a string "kind"', $this->refusal([0 => 'a', 1 => 'b']));
    }
}
