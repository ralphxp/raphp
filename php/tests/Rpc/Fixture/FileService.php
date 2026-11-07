<?php

declare(strict_types=1);

namespace Raphp\Tests\Rpc\Fixture;

use Amp\Cancellation;
use Amp\CancelledException;

use function Amp\delay;

use Raphp\Error\InvalidParamsException;
use Raphp\Rpc\Attribute\Idempotent;
use Raphp\Rpc\Attribute\RpcMethod;
use Raphp\Rpc\ProgressReporter;
use Raphp\Rpc\RpcRequest;
use RuntimeException;

/**
 * A service with RPC methods of every parameter style the framework understands.
 */
class FileService
{
    public static int $stopped = 0;

    public function __construct(public readonly Counter $counter) {}

    /**
     * @return array<string, mixed>
     */
    #[RpcMethod('files.scan'), Idempotent]
    public function scan(string $path, int $limit = 10, ?ProgressReporter $progress = null): array
    {
        $progress?->report(['scanned' => 1]);

        return ['path' => $path, 'limit' => $limit];
    }

    #[RpcMethod('files.delete')]
    public function delete(string $path): bool
    {
        return $path !== '';
    }

    #[RpcMethod('math.add')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * @param array<array-key, mixed> $tags
     *
     * @return array<string, mixed>
     */
    #[RpcMethod('math.describe')]
    public function describe(float $factor, int|string $label, ?string $note = null, bool $loud = false, array $tags = [], mixed $extra = null): array
    {
        return compact('factor', 'label', 'note', 'loud', 'tags', 'extra');
    }

    /**
     * @param mixed $anything
     */
    #[RpcMethod('math.untyped')]
    public function untyped($anything): mixed
    {
        return $anything;
    }

    /**
     * @return array<string, mixed>
     */
    #[RpcMethod('ctx.inspect')]
    public function inspect(RpcRequest $request, Cancellation $cancellation): array
    {
        return ['method' => $request->method, 'trace' => $request->trace, 'cancellation' => $cancellation::class];
    }

    #[RpcMethod('ctx.optional')]
    public function optional(?Cancellation $cancellation = null): bool
    {
        return $cancellation !== null;
    }

    #[RpcMethod('ctx.slow')]
    public function slow(Cancellation $cancellation): string
    {
        try {
            delay(10, true, $cancellation);
        } catch (CancelledException $exception) {
            ++self::$stopped;

            throw $exception;
        }

        return 'finished';
    }

    #[RpcMethod('fail.params')]
    public function failParams(): never
    {
        throw new InvalidParamsException('rejected by the method', ['why' => 'test']);
    }

    #[RpcMethod('fail.boom')]
    public function boom(): never
    {
        throw new RuntimeException('secret detail');
    }

    #[RpcMethod('flaky.read'), Idempotent]
    public function flakyRead(): string
    {
        return 'unused here: the retry tests use their own handlers';
    }

    public function notExposed(): string
    {
        return 'a public method without the attribute is not an RPC method';
    }
}
