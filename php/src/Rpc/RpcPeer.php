<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use function Amp\async;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;

use function Amp\delay;

use Amp\TimeoutCancellation;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use Raphp\Async\BackgroundTasks;
use Raphp\Error\ErrorCode;
use Raphp\Error\InternalErrorException;
use Raphp\Error\PayloadTooLargeException;
use Raphp\Error\ProtocolException;
use Raphp\Error\RequestCancelledException;
use Raphp\Error\RequestTimeoutException;
use Raphp\Error\RpcException;
use Raphp\Error\Text;
use Raphp\Error\WorkerCrashedException;
use Raphp\Error\WorkerUnavailableException;
use Raphp\Ipc\Codec\Codec;
use Raphp\Ipc\Codec\Decoder;
use Raphp\Ipc\Message\Bye;
use Raphp\Ipc\Message\Cancel;
use Raphp\Ipc\Message\Envelope;
use Raphp\Ipc\Message\EnvelopeReader;
use Raphp\Ipc\Message\Event;
use Raphp\Ipc\Message\Hello;
use Raphp\Ipc\Message\Ping;
use Raphp\Ipc\Message\Pong;
use Raphp\Ipc\Message\Request;
use Raphp\Ipc\Message\Response;
use Raphp\Ipc\Message\Validation;
use Raphp\Ipc\Protocol;
use Raphp\Ipc\Transport\Transport;
use Raphp\Rpc\Internal\ClosureHandler;
use Raphp\Rpc\Internal\ClosureProgressReporter;
use Raphp\Rpc\Internal\OutboundQueue;
use Raphp\Rpc\Internal\PendingCall;
use Raphp\Rpc\Internal\Slots;
use Revolt\EventLoop;
use Throwable;

/**
 * One end of an RPC connection. Both ends are the same class: either may call the other
 * (FRAMEWORK 6.4).
 *
 * **Calling.** {@see self::call()} sends a request and waits for its answer. Calls run at the same
 * time and answers may come back in any order. A call can report progress to its caller, be
 * cancelled, and time out; either way a `cancel` message tells the other side to stop. A failed
 * call throws the {@see RpcException} the other side sent (or one made here: `E_TIMEOUT`,
 * `E_CANCELLED`, `E_WORKER_UNAVAILABLE`, `E_WORKER_CRASHED`).
 *
 * **Answering.** A request from the other side goes through the inbound middleware to the
 * handler, in its own fiber. What the handler throws becomes an error response: an
 * {@see RpcException} keeps its code, cancellation becomes `E_CANCELLED`, anything else becomes
 * `E_INTERNAL` with a message that tells nothing (the exception is logged here instead).
 *
 * **Closing.** When the connection ends, every call still waiting fails: with `E_WORKER_CRASHED`
 * when the other side vanished without saying `bye` (a `bye` closes the connection at once, so
 * what follows it is never read), with `E_WORKER_UNAVAILABLE` after a local
 * {@see self::close()} or a `bye` (unless the `bye` names an error code, which the calls then
 * fail with). A protocol violation by the other side fails the calls with that error and closes
 * the connection after a last `bye` that names it. {@see self::shutdown()} does the same on purpose.
 *
 * **Handshake and heartbeat (FRAMEWORK 6.5, 6.8).** With a `hello` given, each side sends it first
 * and expects the other's before anything else; a different protocol version, a second `hello`,
 * another message first, or none in time ends the connection with `bye` and `E_PROTOCOL`. With a
 * ping interval set, a `ping` goes out after that much silence, and the peer is declared dead after
 * a number of silent intervals. Silence means no bytes at all, not no `pong`: a peer in the middle
 * of a huge message is alive, even though its `pong` waits behind that message (finding R19).
 *
 * **Never blocking the reader.** One fiber reads and another writes. The reader only queues what
 * it needs to send, so two peers that both send a lot cannot block each other (finding F38).
 * Senders that are not the reader wait when the outbound queue is full.
 */
final class RpcPeer
{
    private const string PROGRESS_METHOD = 'progress';

    private readonly OutboundQueue $outbound;

    private readonly Slots $slots;

    private readonly BackgroundTasks $tasks;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $finished;

    private readonly RpcHandler $inboundChain;

    private readonly RpcHandler $outboundChain;

    private int $nextId = 0;

    /** @var array<int, PendingCall> calls sent, answer not yet here; each holds one slot */
    private array $pending = [];

    /** @var array<int, true> calls given up on (timeout, cancel) whose answer is still expected; each holds one slot */
    private array $abandoned = [];

    /** @var array<int, DeferredCancellation> requests from the other side being handled */
    private array $inbound = [];

    private bool $started = false;

    /** The other side's `hello`, once it has arrived. */
    private ?Hello $remote = null;

    /** @var DeferredFuture<Hello> */
    private readonly DeferredFuture $handshake;

    /** Monotonic seconds at which bytes last arrived from the other side. */
    private float $lastHeard = 0.0;

    private readonly DeferredCancellation $stop;

    private ?string $handshakeTimer = null;

    private ?string $flushTimer = null;

    private bool $closed = false;

    private ?RpcException $closedBecause = null;

    /**
     * @param RpcHandler $handler answers the other side's requests, usually a {@see HandlerRegistry}
     * @param (Closure(string, array<array-key, mixed>): void)|null $onEvent called for each event that belongs to no request, with its method and params
     * @param Hello|null $hello what this side announces first; null means no handshake is used
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly Codec $codec,
        private readonly EnvelopeReader $reader,
        private readonly LoggerInterface $logger,
        RpcHandler $handler,
        private readonly RpcPeerOptions $options = new RpcPeerOptions(),
        MiddlewareStack $inboundMiddleware = new MiddlewareStack(),
        MiddlewareStack $outboundMiddleware = new MiddlewareStack(),
        private readonly ?Closure $onEvent = null,
        private readonly ?Hello $hello = null,
    ) {
        $this->outbound = new OutboundQueue($options->outboundBuffer, $options->outboundBuffer);
        $this->slots = new Slots($options->maxInFlight, $options->maxQueuedCalls);
        $this->tasks = new BackgroundTasks($logger);
        $this->finished = new DeferredFuture();
        $this->handshake = new DeferredFuture();
        $this->handshake->getFuture()->ignore();
        $this->stop = new DeferredCancellation();
        $this->inboundChain = $inboundMiddleware->wrap($handler);
        $this->outboundChain = $outboundMiddleware->wrap(new ClosureHandler($this->transmit(...)));
    }

    /**
     * Starts reading and writing. Call it once.
     *
     * @throws LogicException when called twice
     */
    public function start(): void
    {
        if ($this->started) {
            throw new LogicException('the peer was already started');
        }

        $this->started = true;
        $this->lastHeard = $this->now();

        if ($this->hello !== null) {
            $this->queueNow($this->hello);
            $this->handshakeTimer = EventLoop::delay($this->options->handshakeTimeout, function (): void {
                $this->handshakeTimer = null;
                if ($this->remote === null) {
                    $this->failProtocol(new ProtocolException("no hello arrived within {$this->options->handshakeTimeout} seconds"));
                }
            });
        }

        async($this->writeLoop(...))->ignore();
        async($this->readLoop(...))->ignore();
        if ($this->options->pingInterval !== null) {
            $interval = $this->options->pingInterval;
            async(fn() => $this->heartbeatLoop($interval))->ignore();
        }
    }

    /**
     * The other side's `hello`, or null while it has not arrived (or when no handshake is used).
     */
    public function remote(): ?Hello
    {
        return $this->remote;
    }

    /**
     * Waits for the other side's `hello` and returns it.
     *
     * @throws LogicException when this peer was built without a `hello`, so there is no handshake
     * @throws RpcException when the handshake fails or the connection ends first
     * @throws CancelledException
     */
    public function awaitHandshake(?Cancellation $cancellation = null): Hello
    {
        if ($this->hello === null) {
            throw new LogicException('this peer was built without a hello, so there is no handshake to wait for');
        }

        return $this->handshake->getFuture()->await($cancellation);
    }

    /**
     * Ends the connection on purpose: calls still waiting fail with `E_WORKER_UNAVAILABLE`, requests
     * being handled are cancelled, and a last `bye` with the given reason is sent before the
     * connection is closed (within the flush timeout). Returns at once; use {@see self::awaitClosed()}
     * to wait for the end.
     */
    public function shutdown(string $reason = 'shutdown'): void
    {
        $this->closeBecause(new WorkerUnavailableException('the connection was shut down here'), new Bye(Text::truncate($reason, Validation::MAX_TEXT_BYTES)));
    }

    /**
     * Calls a method on the other side and waits for the result.
     *
     * @param array<array-key, mixed> $params
     * @param float|null $timeout seconds to wait for the answer, counted from this call; null waits without limit
     * @param (Closure(array<array-key, mixed>, string): void)|null $onProgress called for each progress event of this call; it runs on the read loop, so it must be quick
     *
     * @throws RpcException when the call fails
     */
    public function call(string $method, array $params = [], ?float $timeout = null, ?Closure $onProgress = null, ?Cancellation $cancellation = null): mixed
    {
        $response = $this->outboundChain->handle(new RpcRequest($method, $params, null, $timeout, $onProgress), $cancellation);
        if ($response->error !== null) {
            throw $response->error;
        }

        return $response->result;
    }

    /**
     * Sends an event that belongs to no request and expects no answer. Waits when the outbound
     * queue is full.
     *
     * @param array<array-key, mixed> $params
     *
     * @throws RpcException when the connection is closed, or the event cannot be encoded
     * @throws CancelledException
     */
    public function notify(string $method, array $params = [], ?Cancellation $cancellation = null): void
    {
        if ($this->closed) {
            throw $this->unavailable();
        }

        $this->outbound->push($this->codec->encode((new Event($method, $params))->toArray()), $cancellation);
    }

    /**
     * Closes the connection now. Calls still waiting fail with `E_WORKER_UNAVAILABLE`, requests
     * being handled are cancelled, and nothing more is written.
     */
    public function close(): void
    {
        $this->closeBecause(new WorkerUnavailableException('the connection was closed here'));
    }

    /**
     * Pings after silence and declares the peer dead after too many silent intervals. Silence is
     * measured in bytes heard, so a slow transfer of a large message keeps the peer alive.
     */
    private function heartbeatLoop(float $interval): void
    {
        $deadAfter = $interval * $this->options->missedPings;
        $lastTick = $this->now();

        try {
            while (!$this->closed) {
                delay($interval, true, $this->stop->getCancellation());
                $now = $this->now();

                // A peer that has not said hello yet is still starting (a worker process takes time to
                // boot). The handshake timeout judges that; counting the wait as missed heartbeats
                // would kill a slow start on a loaded machine.
                if ($this->hello !== null && $this->remote === null) {
                    $this->lastHeard = $now;
                    $lastTick = $now;

                    continue;
                }

                // If this process itself was not running for a long while (a blocking call, a stopped
                // process), the silence says nothing about the peer: give it a fresh start.
                if ($now - $lastTick > $interval * 2) {
                    $this->lastHeard = $now;
                    $lastTick = $now;

                    continue;
                }

                $lastTick = $now;
                $silence = $now - $this->lastHeard;

                if ($silence >= $deadAfter) {
                    $this->logger->warning('the peer is silent, declaring it dead', ['seconds' => round($silence, 3)]);
                    $this->closeBecause(new WorkerCrashedException("the peer sent nothing for {$this->options->missedPings} ping intervals"));

                    return;
                }

                if ($silence >= $interval) {
                    $this->queueNow(new Ping(), 'ping');
                }
            }
        } catch (CancelledException) {
            // the connection closed while waiting
        }
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Why the connection ended: the error calls were failed with. Null while it is open.
     */
    public function closedBecause(): ?RpcException
    {
        return $this->closedBecause;
    }

    /**
     * Waits until the connection is closed and every request being handled has finished.
     */
    public function awaitClosed(?Cancellation $cancellation = null): void
    {
        $this->finished->getFuture()->await($cancellation);
        $this->tasks->awaitAll($cancellation);
    }

    // ------------------------------------------------------------ calling

    /**
     * The end of the outbound chain: puts the request on the wire and waits for the answer. Local
     * failures come back as failure responses, so middleware sees one kind of outcome.
     */
    private function transmit(RpcRequest $request, ?Cancellation $cancellation): RpcResponse
    {
        if ($this->closed) {
            return RpcResponse::failure($this->unavailable());
        }

        // Refuse a call that can never be valid before anything is taken for it (a slot, an id).
        new Request(1, $request->method, $request->params, $request->trace);

        $timeout = $request->timeout === null ? null : new TimeoutCancellation($request->timeout, "the call to {$request->method} took longer than {$request->timeout} seconds");
        $combined = $this->combine($timeout, $cancellation);

        try {
            $combined?->throwIfRequested();
            $this->slots->acquire($combined);
        } catch (CancelledException $exception) {
            return RpcResponse::failure($this->interrupted($request, $timeout, $cancellation, $exception));
        } catch (RpcException $exception) {
            return RpcResponse::failure($this->closedBecause ?? $exception);
        }

        $id = ++$this->nextId;
        $call = new PendingCall($request->onProgress);
        $this->pending[$id] = $call;

        try {
            $line = $this->codec->encode((new Request($id, $request->method, $request->params, $request->trace))->toArray());
            $this->outbound->push($line, $combined);
        } catch (CancelledException $exception) {
            $this->forget($id);

            return RpcResponse::failure($this->interrupted($request, $timeout, $cancellation, $exception));
        } catch (RpcException $exception) {
            $this->forget($id);

            return RpcResponse::failure($exception);
        }

        try {
            return $call->response->getFuture()->await($combined);
        } catch (CancelledException $exception) {
            if ($call->response->isComplete()) {
                return $call->response->getFuture()->await();
            }

            // The request is out and the other side may still be working on it. Keep the slot
            // until it answers (or the connection closes), so abandoned calls stay bounded.
            unset($this->pending[$id]);
            $this->abandoned[$id] = true;
            $this->queueNow(new Cancel($id));

            return RpcResponse::failure($this->interrupted($request, $timeout, $cancellation, $exception));
        }
    }

    private function combine(?Cancellation $first, ?Cancellation $second): ?Cancellation
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return new CompositeCancellation($first, $second);
    }

    private function interrupted(RpcRequest $request, ?TimeoutCancellation $timeout, ?Cancellation $cancellation, CancelledException $cause): RpcException
    {
        if ($cancellation?->isRequested() !== true && $timeout?->isRequested() === true) {
            return new RequestTimeoutException("the call to {$request->method} timed out after {$request->timeout} seconds", ['method' => $request->method], $cause);
        }

        return new RequestCancelledException("the call to {$request->method} was cancelled", ['method' => $request->method], $cause);
    }

    /**
     * Drops a call that was never sent.
     */
    private function forget(int $id): void
    {
        unset($this->pending[$id]);
        $this->slots->release();
    }

    private function unavailable(): WorkerUnavailableException
    {
        return new WorkerUnavailableException('the connection is closed', null, $this->closedBecause);
    }

    // ------------------------------------------------------------ writing

    private function writeLoop(): void
    {
        try {
            while (($line = $this->outbound->pop()) !== null) {
                $this->transport->write($line);
            }

            // The queue ended: either the connection was closed (nothing left to do) or a last
            // message was queued and is now written, so the connection can go.
            $this->hardClose();
        } catch (StreamException $exception) {
            $this->closeBecause(new WorkerCrashedException('writing to the peer failed', null, $exception));
        } catch (Throwable $exception) {
            $this->logger->error('rpc write loop failed', ['exception' => $exception]);
            $this->closeBecause(new InternalErrorException('the connection failed unexpectedly', null, $exception));
        }
    }

    /**
     * Queues a message from the read loop or a cancel path, without ever waiting. These messages
     * are bounded: past the queue's reserve the peer is not reading what is sent to it, and the
     * connection is closed rather than let the queue grow.
     *
     * @param string|null $key a message with the same key that is still waiting makes this one redundant
     */
    private function queueNow(Envelope $envelope, ?string $key = null): void
    {
        try {
            $line = $this->codec->encode($envelope->toArray());
        } catch (RpcException $exception) {
            $this->logger->error('a message could not be encoded', ['kind' => $envelope->kind()->value, 'exception' => $exception]);

            return;
        }

        if (!$this->outbound->pushNow($line, $key) && !$this->closed) {
            $this->logger->warning('the peer does not read what is sent to it, closing the connection');
            $this->closeBecause(new WorkerUnavailableException('the peer does not read what is sent to it'));
        }
    }

    // ------------------------------------------------------------ reading

    private function readLoop(): void
    {
        $decoder = $this->codec->createDecoder();

        try {
            while (($chunk = $this->transport->read()) !== null) {
                $this->lastHeard = $this->now();
                $decoder->feed($chunk);
                if (!$this->drain($decoder)) {
                    return;
                }
            }

            $unfinished = $decoder->finish();
            if ($unfinished !== null) {
                $this->logger->warning('the peer closed the connection in the middle of a message');
            }

            $this->closeBecause(new WorkerCrashedException('the peer closed the connection'));
        } catch (StreamException $exception) {
            $this->closeBecause(new WorkerCrashedException('reading from the peer failed', null, $exception));
        } catch (Throwable $exception) {
            $this->logger->error('rpc read loop failed', ['exception' => $exception]);
            $this->closeBecause(new InternalErrorException('the connection failed unexpectedly', null, $exception));
        }
    }

    /**
     * Handles every complete message in the decoder.
     *
     * @return bool false when the connection was closed
     */
    private function drain(Decoder $decoder): bool
    {
        while (($item = $decoder->next()) !== null) {
            if ($item instanceof RpcException) {
                $this->failProtocol($item);

                return false;
            }

            try {
                $envelope = $this->reader->read($item);
            } catch (ProtocolException $exception) {
                $this->failProtocol($exception);

                return false;
            }

            $this->dispatch($envelope);
            if ($this->closed) {
                return false;
            }
        }

        return true;
    }

    private function failProtocol(RpcException $problem): void
    {
        $this->logger->warning('the peer broke the protocol, closing the connection', ['code' => $problem->wireCode(), 'problem' => $problem->getMessage()]);
        $this->closeBecause($problem, new Bye(Text::truncate($problem->wireCode() . ': ' . $problem->getMessage(), Validation::MAX_TEXT_BYTES)));
    }

    private function dispatch(Envelope $envelope): void
    {
        if ($this->hello !== null && $this->remote === null && !$envelope instanceof Hello && !$envelope instanceof Bye && !$envelope instanceof Ping && !$envelope instanceof Pong) {
            $this->failProtocol(new ProtocolException("expected hello first, got {$envelope->kind()->value}"));

            return;
        }

        if ($envelope instanceof Request) {
            $this->accept($envelope);
        } elseif ($envelope instanceof Response) {
            $this->complete($envelope);
        } elseif ($envelope instanceof Event) {
            $this->deliver($envelope);
        } elseif ($envelope instanceof Cancel) {
            $this->cancelInbound($envelope->ref);
        } elseif ($envelope instanceof Ping) {
            $this->queueNow(new Pong(), 'pong');
        } elseif ($envelope instanceof Bye) {
            $this->closeBecause($this->reasonOfBye($envelope));
        } elseif ($envelope instanceof Hello) {
            $this->receivedHello($envelope);
        }
        // A pong needs nothing: arriving at all was already counted as proof of life.
    }

    private function receivedHello(Hello $remote): void
    {
        if ($this->hello === null) {
            $this->logger->debug('a hello arrived but this peer does not use a handshake');

            return;
        }

        if ($this->remote !== null) {
            $this->failProtocol(new ProtocolException('a second hello arrived'));

            return;
        }

        if ($remote->proto !== Protocol::VERSION) {
            $this->failProtocol(new ProtocolException("unsupported protocol version {$remote->proto}, this side speaks " . Protocol::VERSION));

            return;
        }

        $this->remote = $remote;
        if ($this->handshakeTimer !== null) {
            EventLoop::cancel($this->handshakeTimer);
            $this->handshakeTimer = null;
        }

        $this->handshake->complete($remote);
    }

    /**
     * Why the connection ends when the other side says `bye`. A reason that starts with a framework
     * error code and a colon (`E_PROTOCOL: unsupported protocol version 2`) is that error; anything
     * else is an ordinary, orderly goodbye.
     */
    private function reasonOfBye(Bye $bye): RpcException
    {
        if (preg_match('/\A(E_[A-Z0-9_]+): (.*)\z/s', $bye->reason, $found) === 1 && ErrorCode::fromWire($found[1]) !== null) {
            return RpcException::fromWire(['code' => $found[1], 'message' => "the peer said bye: {$found[2]}"]);
        }

        return new WorkerUnavailableException('the peer said bye' . ($bye->reason === '' ? '' : ": {$bye->reason}"));
    }

    private function complete(Response $response): void
    {
        $id = $response->id;

        if (isset($this->abandoned[$id])) {
            unset($this->abandoned[$id]);
            $this->slots->release();

            return;
        }

        $call = $this->pending[$id] ?? null;
        if ($call === null) {
            $this->logger->warning('a response arrived for a call nobody is waiting for', ['id' => $id]);

            return;
        }

        unset($this->pending[$id]);
        $this->slots->release();
        $call->response->complete($response->error !== null ? RpcResponse::failure($response->error) : RpcResponse::success($response->result));
    }

    private function deliver(Event $event): void
    {
        if ($event->ref === null) {
            if ($this->onEvent !== null) {
                $this->callBack(fn() => ($this->onEvent)($event->method, $event->params), 'event handler');
            }

            return;
        }

        $onProgress = ($this->pending[$event->ref] ?? null)?->onProgress;
        if ($onProgress !== null) {
            $this->callBack(fn() => $onProgress($event->params, $event->method), 'progress callback');
        }
    }

    /**
     * Runs a callback supplied by the application on the read loop, so its failure cannot stop it.
     *
     * @param Closure(): mixed $callback
     */
    private function callBack(Closure $callback, string $what): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->logger->error("the {$what} failed", ['exception' => $exception]);
        }
    }

    // ------------------------------------------------------------ answering

    private function accept(Request $request): void
    {
        if (isset($this->inbound[$request->id])) {
            $this->failProtocol(new ProtocolException("request id {$request->id} is already in use"));

            return;
        }

        if (count($this->inbound) >= $this->options->maxInbound) {
            $this->queueNow(Response::failure($request->id, new WorkerUnavailableException('too many requests are being handled')));

            return;
        }

        $cancellation = new DeferredCancellation();
        $this->inbound[$request->id] = $cancellation;
        $this->tasks->run(fn(Cancellation $ignored) => $this->serve($request, $cancellation), "rpc request {$request->method}");
    }

    private function cancelInbound(int $id): void
    {
        ($this->inbound[$id] ?? null)?->cancel();
    }

    private function serve(Request $request, DeferredCancellation $cancellation): void
    {
        $id = $request->id;
        $progress = new ClosureProgressReporter(fn(array $params) => $this->reportProgress($id, $params));
        $rpcRequest = new RpcRequest($request->method, $request->params, $request->trace, null, null, $progress);

        try {
            $response = $this->inboundChain->handle($rpcRequest, $cancellation->getCancellation());
        } catch (CancelledException) {
            $response = RpcResponse::failure(new RequestCancelledException('the request was cancelled'));
        } catch (RpcException $exception) {
            $response = RpcResponse::failure($exception);
        } catch (Throwable $exception) {
            $this->logger->error('an rpc handler failed', ['method' => $request->method, 'trace' => $request->trace, 'exception' => $exception]);
            $response = RpcResponse::failure(new InternalErrorException('internal error'));
        }

        // The request stays counted until its answer is in the outbound queue: a request whose
        // handler is done but whose answer still waits for room is still holding memory.
        try {
            if (!$this->closed) {
                $this->outbound->push($this->encodeResponse($id, $response));
            }
        } catch (RpcException) {
            // The connection closed while this answer waited for room; nobody is left to read it.
        } finally {
            unset($this->inbound[$id]);
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function reportProgress(int $ref, array $params): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $this->outbound->push($this->codec->encode((new Event(self::PROGRESS_METHOD, $params, $ref))->toArray()));
        } catch (WorkerUnavailableException) {
            // closed meanwhile
        }
    }

    /**
     * The line for a response. A result that cannot be sent becomes an error response instead, so
     * the caller is never left waiting.
     */
    private function encodeResponse(int $id, RpcResponse $response): string
    {
        $envelope = $response->error !== null ? Response::failure($id, $response->error) : Response::success($id, $response->result);

        try {
            return $this->codec->encode($envelope->toArray());
        } catch (PayloadTooLargeException $exception) {
            $replacement = $exception;
        } catch (ProtocolException $exception) {
            $this->logger->error('a response could not be encoded', ['id' => $id, 'exception' => $exception]);
            $replacement = new InternalErrorException('the result could not be encoded');
        }

        return $this->codec->encode(Response::failure($id, $replacement)->toArray());
    }

    // ------------------------------------------------------------ closing

    /**
     * Ends the connection. Waiting calls fail with the reason and running requests are cancelled at
     * once. With a farewell, that last message is written first and the connection closes once it is
     * out (or after the flush timeout); without one it closes now.
     */
    private function closeBecause(RpcException $reason, ?Bye $farewell = null): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->closedBecause = $reason;

        $failure = RpcResponse::failure($reason);
        foreach ($this->pending as $call) {
            $call->response->complete($failure);
            $this->slots->release();
        }

        foreach ($this->abandoned as $ignored) {
            $this->slots->release();
        }

        $this->pending = [];
        $this->abandoned = [];

        foreach ($this->inbound as $cancellation) {
            $cancellation->cancel();
        }

        $this->slots->close();
        $this->stop->cancel();

        if (!$this->handshake->isComplete()) {
            $this->handshake->error($reason);
        }

        if ($farewell !== null && $this->outbound->pushNow($this->codec->encode($farewell->toArray()))) {
            // The writer drains the queue, writes the farewell and then closes (see writeLoop).
            $this->outbound->finish();
            $this->flushTimer = EventLoop::delay($this->options->flushTimeout, function (): void {
                $this->flushTimer = null;
                $this->hardClose();
            });

            return;
        }

        $this->hardClose();
    }

    /**
     * Drops what is queued, closes the transport and signals that the connection is over.
     */
    private function hardClose(): void
    {
        foreach ([&$this->handshakeTimer, &$this->flushTimer] as &$timer) {
            if ($timer !== null) {
                EventLoop::cancel($timer);
                $timer = null;
            }
        }

        unset($timer);

        $this->outbound->close();
        $this->transport->close();
        if (!$this->finished->isComplete()) {
            $this->finished->complete();
        }
    }
}
