# Raphp Framework Document

**Status:** Draft v0.3, 2026-11-06. The Phase 0 spike results (P0.0 to P0.7) and the documentation audit are incorporated. All numbered questions (O1 to O5 and O7 to O13; there is no O6) are resolved or deferred: O5 to Phase 6 by design, and the exact tool versions of O11 to P1.3 (the formatter itself was decided in P1.1, D25). Two un-numbered items are parked, see `IMPLEMENTATION_PLAN.md` section 12. Risks R14 to R22 in section 16 are design gaps found in the audit. They are recorded as Open and have no solution yet.
**Companion documents:** `IMPLEMENTATION_PLAN.md`, `SPIKE_REPORT.md` (Phase 0 detail)

## 0. How to read this document

Every non-trivial statement carries a status tag, so you can see what is settled and what is still my opinion.

| Tag | Meaning |
|---|---|
| **[Decided]** | You made this decision in conversation. |
| **[Proposed]** | My recommendation. It needs your approval before it is binding. |
| **[Open]** | A real question with no recommendation yet, or one that needs your input. |
| **[Verified]** | Backed by a spike result. Evidence is in Appendix A (spike 1 stage A), Appendix C (later Phase 0 spikes) and `SPIKE_REPORT.md`. |
| **[Source-checked]** | Read directly in the named library's source code at the stated version. It is not a spike measurement and no code was run. |
| **[Unverified]** | A claim I believe but have not tested. Each one maps to a later spike or check, for example IME and text input (R8, section 8.7), which maps to spike P3.11a. |

If a section contradicts something you said, the section is wrong. Tell me and I will fix it.

---

## 1. Vision, goals, non-goals

### 1.1 Vision
Raphp is a long-running, event-driven runtime and application framework for PHP, with an optional native GUI. It is written the way the PHP community writes PHP (section 4.3).

Node.js is only an analogy for the *direction*: a persistent process, an event loop, non-blocking I/O, workers for parallelism, one CLI, single-file executables. Raphp does **not** copy Node's API shapes, naming or code style. **[Decided]**

Raphp provides:

1. **An async runtime.** An event loop, a standard library (filesystem, sockets, HTTP, processes, workers), and a single CLI. It is useful with no GUI at all.
2. **An optional native GUI framework.** The UI is drawn and managed through FFI. Raphp does not render HTML and does not embed a browser engine.
3. **Standalone apps.** A finished app ships as a single binary that needs no PHP installed.

Architecturally, a **master** process owns the UI, **worker** processes run application logic, and **IPC** (Unix domain sockets carrying JSON) connects them.

### 1.2 Goals
- **G1.** Async-first runtime. Application code never blocks the event loop. **[Decided]**
- **G2.** Process isolation between UI and logic. A worker crash cannot close the window. **[Decided]**
- **G3.** Native GUI via FFI through a Rust shim library, with a hybrid design: native where the desktop provides it (file dialogs, clipboard, theme; GNOME has no server-side decorations, so winit draws the title bar), and custom-drawn content including menus (7.7). **[Decided]**
- **G4.** Standalone binaries. **[Decided]**
- **G5.** One front-door CLI, `rlm` (Raphp libs manager). **[Decided]**
- **G6.** Linux first. **[Decided]**
- **G7.** Runtime scope is "all tiers", delivered in stages (section 4.2). **[Decided]**
- **G8.** Follow PHP community standards and idioms: PSRs, Composer, PER Coding Style, strict types, interfaces and dependency injection. No Node-style APIs. **[Decided]**
- **G9.** Licensed under the MIT License, with semantic versioning. **[Decided]**

### 1.3 Non-goals
- Raphp does not render HTML or ship a webview. A developer may *serve* HTML from the runtime (for example from an HTTP server), but that is the developer's code. **[Decided]**
- Raphp does not imitate Node.js APIs or style: no promise chaining, no fluent "cascading" method chains as the primary API, no copied naming. **[Decided]**
- No Windows or macOS support in the MVP. **[Decided]**
- No accessibility (screen readers) in the MVP. The design must not preclude it (section 8.10). **[Decided]**
- No custom PHP engine, no PHP fork, no custom event loop. **[Proposed]**
- No Raphp-specific package registry. PHP packages come from Packagist. **[Proposed]**
- No network-exposed IPC. Everything stays on the local machine. **[Proposed]**

---

## 2. Glossary

| Term | Meaning |
|---|---|
| **Runtime** | The async part: event loop, standard library, process and IPC machinery. Works without a GUI. |
| **Master** | The process the user launches. It runs the Revolt loop and supervises workers. If the GUI is enabled it also owns the shim and the window. With no GUI it is simply the "main" process. |
| **Worker** | A child PHP process that runs application logic and async tasks. Spawned and supervised by the master. |
| **Shim** (`libraphp`) | A Rust shared library loaded into the master via FFI. It owns the UI thread, window, layout, drawing, and the native dialog, clipboard and theme integrations. |
| **IPC** | The master-to-worker channel: Unix domain stream sockets, one JSON object per line. |
| **Command** | A message from PHP to the shim ("create widget", "set text"). |
| **Event** | A message from the shim to PHP ("button clicked", "window resized"). |
| **`rlm`** | Raphp libs manager, the CLI. Initializes projects, manages PHP and native libraries, runs apps, builds binaries. |
| **Revolt** | The PHP event-loop standard (`revolt/event-loop`) that Amp v3 builds on. |
| **Fiber** | PHP 8.1+ language feature for cooperative suspension. Revolt uses it to make async code look synchronous. |
| **Micro** | `phpmicro`: a PHP SAPI that lets a phar be appended to a binary, producing a single file. |
| **Spc** | `static-php-cli`: a tool that builds a custom PHP binary with chosen extensions. |

---

## 3. Architecture overview

### 3.1 Process topology

```
                         ┌────────────────────────── master process ───────────────────────────┐
  user launches          │  Revolt loop                                                          │
  `app` / `rlm run`  ──▶ │   ├─ UI layer (PHP): widget tree, diff, command batcher               │
                         │   ├─ Supervisor: spawns, watches, restarts workers                    │
                         │   └─ IPC endpoints ───────────────────────────────┐                   │
                         │                                                   │                   │
                         │   FFI ◀──── commands (batched) ────▶ libraphp     │                   │
                         │       ◀──── events (eventfd + poll) ──  (Rust)    │                   │
                         │                                         UI thread │                   │
                         │                                         window, layout, drawing       │
                         └──────────────────────────────────────────────┼────┘
                                                                        │ AF_UNIX stream
                                   ┌────────────────────────────────────┴───────────────┐
                                   ▼                                                    ▼
                           ┌─ worker A (PHP) ─┐                                 ┌─ worker B (PHP) ─┐
                           │ Revolt loop      │                                 │ Revolt loop      │
                           │ app logic, tasks │                                 │ pool / CPU work  │
                           └──────────────────┘                                 └──────────────────┘
```

### 3.2 The three boundaries

| # | Boundary | Mechanism | Format | Section |
|---|---|---|---|---|
| 1 | Worker ⇄ master | AF_UNIX stream socket | NDJSON, request/response envelope | 6 |
| 2 | Master (PHP) ⇄ shim | FFI calls + in-memory event queue + `eventfd` wake-up | JSON batches (MVP) | 7 |
| 3 | Shim ⇄ OS | Rust: winit, rendering, portals | native | 7 |

PHP never receives a callback from C. All shim-to-PHP traffic is data that PHP pulls. This is a deliberate design rule, because FFI callbacks are only safe on the thread that registered them and cannot propagate exceptions through C frames. **[Decided: shim owns its own UI thread]**

### 3.3 Modes
- **GUI mode:** master + shim + workers.
- **Headless mode:** master (no shim) + workers. Used for servers, CLIs and tests. The same runtime code path, with `raphp/ui` simply not loaded.
- **Single-process mode:** no workers. Handlers run in the master. Appropriate for small apps and for debugging. **[Proposed]**

### 3.4 One binary, several roles
A built app is one executable that re-executes itself with a role flag (`--role=worker`). In development, `rlm` runs the same entry point under a managed PHP. **[Proposed]** Re-execution from inside a micro binary works, using `readlink('/proc/self/exe')` (10.2). **[Verified, P0.6]**

**The worker's side [Implemented, P2.11: `Raphp\Worker\{WorkerArguments, WorkerRuntime}`].** A worker's entry script parses its command line with `WorkerArguments::parse($argv)` (`--role=worker`, `--name`, `--parent-pid` are required and checked strictly; `--no-pdeathsig` is a test flag; any other `--option=value` and positional argument is left to the application) and calls `WorkerRuntime::run($arguments, $handler)`, then exits with the code it returns. The runtime, in order: protects the process from outliving its master (the kernel's death signal, then the parent check; without ext-ffi or libc it refuses to run); **ignores SIGINT** (a terminal's Ctrl-C reaches the whole process group, and a worker that died of it would look like a crash and be restarted: the master ends its workers in order instead); **installs no SIGTERM handler** (the default action ends the process even when its loop is busy, and a loop-bound handler would not run then, F43); opens descriptor 3 and checks that it is a socket; says `hello`, waits for the master's (the peer's handshake timeout, 5 s by default), and serves calls until the master closes the connection or says `bye`. Exit codes: 0 an orderly end, 1 a failure (no hello from the master, a protocol error, a different protocol version), 2 a command line that is not a worker's (message on stderr), 3 no channel, 4 the parent is not the master that started it, 5 the death signal cannot be armed.

---

## 4. Runtime

### 4.1 Event loop
- One Revolt loop per process, in the master and in every worker. **[Proposed]**
- Application code runs inside Fibers started by `async()`. Code reads sequentially and suspends on I/O. **[Proposed]**
- **Rule:** nothing on the loop may block. Blocking calls (`sleep`, `file_get_contents`, PDO, `curl_exec`) freeze the whole process. `rlm doctor` and the docs list safe replacements. CPU-heavy work goes to a process pool (4.4).
- **Rule:** cancel the Revolt watchers on a stream before closing that stream. Otherwise `stream_select` crashes on a dead resource. **[Verified, P0.2]**

### 4.2 Standard library tiers **[Decided: all tiers, staged]**
Raphp exposes its own namespaced services (`Raphp\Filesystem`, `Raphp\Network`, `Raphp\Http`, ...) as interfaces with default implementations over curated Amp packages. We do not reimplement what Amp already does well, and the interfaces keep us free to swap implementations.

| Tier | Contents | Built on |
|---|---|---|
| **1** | Loop and timers, streams, fs, child processes, signals, workers, IPC | `revolt/event-loop`, `amphp/byte-stream`, Raphp's own cooperative filesystem service (D44; `amphp/file` is not used), `amphp/process` for user commands (not for Raphp workers, see 5.1) |
| **2** | TCP/UDP/Unix sockets, HTTP client and server, WebSocket | `amphp/socket`, `amphp/http-client`, `amphp/http-server`, `amphp/websocket-*` |
| **3** | TLS, crypto, zlib, DNS, os/path/url helpers, permissions model | PHP extensions (`openssl`, `sodium`, `zlib`), `amphp/dns` |

`ext-uv`, `ext-ev`, `ext-event` and `ext-eio` are optional accelerators for async I/O. None is required, and none is installed by default on the dev machine. **[Decided, D46; verified in P2B.4]** Raphp selects no driver itself: Revolt picks one when the loop is first used (ext-uv, else ext-ev, else ext-event, else its own `stream_select`), unless `REVOLT_DRIVER` names a class, so loading an extension is enough. `Raphp\Runtime\Accelerators` reports which are loaded and which driver is running (for `rlm doctor` and logs). ext-eio is detected but unused: the filesystem service has no eio variant. The whole test suite was run with ext-uv 0.3.1 and with ext-ev 1.2.3 (built from pecl, loaded with `-d extension=`, in the master and the workers) and passes under both, after one fixture that relied on plain `pcntl_signal()` was changed (F55). **Rule: signals are handled through `EventLoop::onSignal()`, never a plain `pcntl_signal()` handler** (`pcntl_signal(SIG, SIG_IGN)`, which the kernel handles, is fine): under ext-uv libuv restarts the interrupted wait, so a plain handler ran only when something else woke the loop (measured: 319 ms instead of 214 ms with a 120 ms gap, and unbounded if nothing else happens). ext-event was not tested.

**Filesystem [Decided, D44; implemented and measured in P2B.1].** PHP cannot read a regular file without blocking, and without ext-eio or ext-uv the only asynchronous route is helper processes. `amphp/file` falls back to a pool of `amphp/parallel` child processes, which do not die with their master (F46) and bypass the spawner (D18), so Raphp does not use it. `Raphp\Filesystem\Filesystem` is an interface (`read`, `write`, `stat`, `list`, `openRead`) with `CooperativeFilesystem` as the default: ordinary file functions in 64 KiB chunks, and after each 2 ms slice of work the event loop gets a turn (`delay(0)`), with cancellation checked at the same points. The loop stalls for about one slice (2.2 ms measured) while a large file is streamed with `openRead()`. `read()` returns a string, and putting its pieces together is one copy that cannot be spread out: a 32 MiB file stalls the loop for 50 to 90 ms at the end and takes three to six times as long as `file_get_contents()`, so `read()` is for small files and `openRead()` for large ones (`docs/BENCHMARKS.md`). A single chunk can still block as long as the disk takes to deliver it. Watching a path for changes is not provided: it needs inotify, ext-eio or ext-uv. ext-eio and ext-uv adapters behind the same interface are possible later (P2B.4).

### 4.3 API conventions **[Proposed]**
- **Standards first.** PER Coding Style (the successor to PSR-12) and PSR-1; PSR-4 autoloading; PSR-3 logging; PSR-11 container; PSR-14 events; PSR-20 clock. PSR-7/17/18 for HTTP where the underlying Amp library supports them, with adapters otherwise (decided in tier 2). **[Proposed]**
- **Language idioms.** `declare(strict_types=1)` in every file; typed properties and parameters; `readonly` classes for value objects; enums instead of string constants; named arguments for options; attributes for metadata (for example marking worker handler methods); PHPDoc generics where the type system falls short. **[Proposed]**
- **Naming.** PascalCase classes, camelCase methods and properties, PascalCase enum cases, Composer package names as `vendor/package`, semantic versioning. **[Proposed]**
- **Design.** Interfaces plus constructor injection. No static facades, no global state in the framework core. Services are plain objects wired by a PSR-11 container. **[Decided, O9]**
- **No fluent cascading.** Configuration uses constructors with named arguments and immutable value objects, not long method chains. **[Decided]**
- **Async.** Operations suspend the current Fiber and return the value, as in Amp v3. Parallelism is explicit (Amp's `async()`, `await()`, `Future\awaitAll()`). No promise chaining. **[Decided, O10]**
- Cancellation uses Amp's `Cancellation` objects, accepted as an optional trailing argument.
- Errors are typed exceptions. The abstract base `Raphp\Error\RaphpException` and the IPC errors (`RpcException` and its subclasses) live in `Raphp\Error\*`. A module's own exceptions live in the module's namespace and extend `RaphpException` (for example `Raphp\Core\Config\InvalidConfigException`). IPC errors map to the codes in 6.6. **[Proposed, D32]**
- Streams use Amp's readable and writable stream interfaces.

### 4.4 Heavy work
Which PHP calls block, how long they stalled the loop when measured, and what to use instead is in `docs/BLOCKING_CALLS.md`. Async only helps with I/O waits. CPU-bound work goes to a worker process pool (`amphp/parallel`). It must never run inside a UI handler or on the master loop. **[Proposed]** **[Verified, P2.10: no]** `amphp/parallel` 2.4.0 (with `amphp/process` 2.1.1) starts each child as `sh -c { ... }`, so the child's parent is a shell and not the master, and a child **survived its master being killed with SIGKILL** (still running six seconds later, in both its process-context factories; the default factory also chose a process context). Its children therefore have no parent-death protection, and a master that dies would leave CPU workers running. **Raphp does not build its CPU pool on `amphp/parallel`.** A pool of CPU workers is an ordinary supervised pool of Raphp workers (5.2 to 5.6), which are protected by the channel and by the kernel's death signal (P2.9). `amphp/parallel` stays a library an application may use at its own risk. *(Fork contexts were not tested.)*

### 4.5 Signals and shutdown
SIGINT and SIGTERM are handled through Revolt, which needs `pcntl` (without it, registering a signal handler throws `UnsupportedFeatureException`). `posix` is needed by `amphp/process`, which calls `posix_kill` to signal child processes, and by the worker orphan check (5.6). **[Source-checked: the Revolt and amphp/process sources were read, not run in a spike.]** Both are present on the dev machine and are in the required-extension list (9.2). The master starts graceful shutdown (5.5) and exits with a defined code. **[Implemented, P2.1]** `Raphp\Core\Kernel` is the same for master and worker: exit 0 when the application's `main` returns or `stop(0)` is called, 1 after an uncaught exception or a failed shutdown hook, 130 after SIGINT and 143 after SIGTERM (128 plus the signal), or the code passed to `stop()`; the first reason to stop wins. Shutdown hooks run one at a time, last registered first, within one shared time budget (default 5 s). A hook that fails is logged and the rest still run; a hook that runs out of budget is abandoned and logged; either makes an otherwise clean exit non-zero. A second SIGINT or SIGTERM during shutdown ends the process at once with that signal's code. A worker's SIGTERM handling must end the process (5.6). The shim's own exit and UI-thread failure are not yet part of this sequence (R14). **[Proposed]**

**Background tasks [Implemented, P2.2].** `Raphp\Async\BackgroundTasks` runs fire-and-forget work. A failure is logged at error level with the task's name and exception; it never reaches Amp's unhandled-future error and never stops the loop. A task ended by `cancelAll()` is logged at debug level. A task starts on the next loop tick, not inside `run()`.

### 4.6 Long-running memory
PHP leaks and fragmentation matter once a process runs for days. Workers can be recycled after N requests or M megabytes (configurable), with in-flight work drained first. **[Proposed]**

**[Open, R20: headless baseline recorded in P2.13, UI workload in P4.8]** **[Implemented for workers, P2.10: `RecyclePolicy`]** A worker is replaced after `maxCalls` calls, or when its resident memory (read from `/proc/<pid>/status`, at most once a second and after a call) passes `maxMemoryBytes`. It stops taking calls at once, the calls in flight finish (up to the drain timeout, default 30 s), it is stopped with the sequence above and a new process starts with no waiting and no crash counted; `recycled` is reported with the reason. Nothing is recycled unless a limit is set. *(Measured in P2.13: `VmRSS` includes pages shared with other processes, about 40 of a fresh PHP process's 56 MiB; `RssAnon` is the private part. See F50 in `docs/PROGRESS.md`: the threshold has to be set above that baseline, or the measure changed.)*

The master cannot be recycled, yet it holds the most long-lived state (all view code, handlers, the widget tree, and every JSON decode for UI traffic, see 8.4). Worker recycling does not address it. A memory budget and monitoring plan for the master is not yet defined.

### 4.7 Futures, cancellation and errors

Raphp has **no promises**. The only "value that is not ready yet" type is `Amp\Future<T>`. **[Source-checked]** against `amphp/amp` v3.1.3.

**Using futures**
- `async(Closure $fn, ...$args): Future` starts work in a new Fiber. `$future->await(?Cancellation)` suspends the caller until the result (or exception) arrives.
- Application code awaits and uses ordinary `try`/`catch`/`finally`. `Future::map()`, `catch()` and `finally()` exist, but they are for library internals. Raphp application code does not chain them. **[Decided, O10]**
- `DeferredFuture` bridges callback or event sources into futures. Raphp uses it internally, for example to match an IPC `res` message to the request that is waiting for it (by `id`) and to turn shim events into awaitable values. It is not part of the everyday application API.

**Combining futures** (functions in the `Amp\Future` namespace, for example `Amp\Future\awaitAll()`; Amp v3.1.3, source-checked):

| Function | Behavior |
|---|---|
| `await($futures)` | Wait for all. The first error is thrown. |
| `awaitAll($futures)` | Wait for all. Returns `[errors, values]`. |
| `awaitFirst($futures)` | First one to finish, success or error. Throws `CompositeLengthException` if `$futures` is empty. |
| `awaitAny($futures)` | First one to succeed. Errors are ignored unless all fail, in which case it throws `CompositeException`. Throws `CompositeLengthException` if `$futures` is empty. |
| `awaitAnyN($n, $futures)` | The first `n` successes. Throws `CompositeException` if too many futures error to reach `n`, and `CompositeLengthException` if `$futures` is empty. |

**Cancellation** uses Amp's types: `Cancellation`, `DeferredCancellation`, `TimeoutCancellation`, `SignalCancellation`, `CompositeCancellation`, `NullCancellation`, and `CancelledException`.
- Every Raphp API that waits or does I/O takes `?Cancellation $cancellation = null` as its **last** parameter. **[Proposed]**
- An IPC `cancel` message trips a `DeferredCancellation` handed to the handler (6.7).

**Unhandled futures.** A future that errors and is destroyed without being awaited raises `UnhandledFutureError`, unless `ignore()` was called. **[Source-checked]**: `Internal/FutureState.php` constructs `UnhandledFutureError`, and `Future::ignore()` exists. For fire-and-forget work, Raphp provides a supervised task runner that logs failures (PSR-3) instead of crashing or silently swallowing them. **[Proposed]**

**Uncaught errors.** An uncaught exception on the master loop is logged, triggers graceful shutdown, and exits non-zero. In a worker it exits non-zero so the supervisor restarts it (5.4). **[Decided, O12]**

**ReactPHP interop** (awaiting ReactPHP promises inside Raphp code) is not planned for the MVP. Parked (`IMPLEMENTATION_PLAN.md` section 12).

### 4.8 Middleware and pipelines

**What PHP does.** The community standard is **PSR-15** (`process(ServerRequestInterface, RequestHandlerInterface)`), built on **PSR-7** immutable messages.

**What Amp does.** `amphp/http-server` does **not** implement PSR-7 or PSR-15. It has its own `Amp\Http\Server\Middleware` interface, `handleRequest(Request $request, RequestHandler $requestHandler): Response`, with its own `Request` class. It uses PSR-7 only for the URI type. **[Source-checked]** against `amphp/http-server` v3.4.8: `Middleware::handleRequest(Request, RequestHandler): Response`, and PSR-7 types appear only in `Request.php` and `Push.php`. The pattern is the same onion shape as PSR-15, but the types differ.

**Raphp's approach** **[Decided, O13]**

1. **HTTP server (tier 2).** Use Amp's native `Middleware` and `RequestHandler` as they are. We do not invent a third HTTP middleware API. A PSR-15 bridge is built only if users need existing PSR-15 middleware, and that is decided when tier 2 starts.
2. **Raphp's own pipelines** (worker RPC dispatch, master-side worker calls, UI event handling) use one contract shaped like PSR-15 so PHP developers recognize it:

```php
interface RpcMiddleware
{
    public function process(
        RpcRequest $request,
        RpcHandler $next,
        ?Cancellation $cancellation = null,
    ): RpcResponse;
}
```

```php
final class TimingMiddleware implements RpcMiddleware
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(RpcRequest $request, RpcHandler $next, ?Cancellation $cancellation = null): RpcResponse
    {
        $start = hrtime(true);   // monotonic; wall-clock time can step
        try {
            return $next->handle($request, $cancellation);   // may suspend; no promises needed
        } finally {
            $elapsedNs = hrtime(true) - $start;
            $this->logger->info('rpc handled', [
                'method' => $request->method,
                'ms' => round($elapsedNs / 1_000_000, 2),
            ]);
        }
    }
}
```

The example measures elapsed time with monotonic `hrtime(true)` instead of the PSR-20 clock, because wall-clock time can step. **[Proposed]**

Rules:
- Requests and responses are **immutable** value objects with `with*()` methods.
- Execution order is registration order, forming an onion. A middleware may short-circuit by returning without calling `$next`.
- **[Implemented, P2.6]** `Raphp\Rpc\{RpcMiddleware, RpcHandler, RpcRequest, RpcResponse, MiddlewareStack}` follow the contract above. A peer has two stacks: the **inbound** one wraps the handler that answers the other side's requests, the **outbound** one wraps the code that puts a call on the wire. `RpcRequest` is immutable with `withParams()`, `withTrace()` and `withTimeout()`; some fields belong to one side (`timeout` and `onProgress` to the caller, `progress` to the callee). The end of a chain returns an `RpcResponse` (a success or a failure) for remote **and** local outcomes alike, so middleware sees one kind of result; a handler's exceptions pass through as exceptions. Built in: `TimingMiddleware` (monotonic clock, logs method, trace, milliseconds and outcome), `TraceIdMiddleware` (gives an outgoing call a random trace id), `ErrorMappingMiddleware` (maps chosen exception classes to framework error codes). `HandlerRegistry` maps method names to closures and is itself the final handler.

**Attribute handlers [Implemented, P2.8].** An RPC method is an ordinary public method marked `#[RpcMethod('files.scan')]`; `#[Idempotent]` says repeating it is safe.
- **`MethodCatalog::fromClasses([...])`** reads the attributes from the classes alone, with no object needed, so the process that only *calls* a method can build the same catalog from a shared contract class and learn which methods are idempotent. Mistakes are refused when the catalog is built: a name used twice (both places are named), an empty name or one over 256 bytes, `#[RpcMethod]` on a method that is not a public instance method, and `#[Idempotent]` without `#[RpcMethod]`. A public method without the attribute is not an RPC method. An inherited method belongs to the class that declares it.
- **`AttributeHandlers`** turns a catalog into handlers of a `HandlerRegistry`, taking each service from a PSR-11 container **once, at boot** (building a service inside a request's Fiber could suspend, and PHP-DI's loop guard does not know Fibers: R24). The PHP method name is not the RPC name.
- **Parameters are filled from `params` by name and type.** `RpcRequest`, `Cancellation` and `ProgressReporter` are filled by the framework. Every other parameter takes the value of the same name; it may be `int`, `float`, `string`, `bool`, `array`, `mixed`, an untyped parameter, a union of these, or nullable. No conversion is done: `"1"` is not an `int`, `1.5` is not an `int`, a whole `1.0` is not an `int`, `null` goes only where `null` is allowed; an `int` is accepted for a `float` (PHP widens it). A parameter with a default may be left out, any other must be present, and **a name the method does not have is an error** (a typo must not pass silently). All of these answer `E_INVALID_PARAMS` with `data: {"param": "<name>"}` before the method runs. A method that could never be called correctly (an object, an intersection, a variadic or by-reference parameter) is refused at boot, naming the parameter.
- **`RetryMiddleware(catalog, maxAttempts = 3, delay = 0.1)`** (outbound) repeats a failed call **only if the catalog marks the method `#[Idempotent]`** and only for a failure that may go away by itself (`E_WORKER_UNAVAILABLE`, `E_WORKER_CRASHED`, `E_TIMEOUT`, configurable). Anything else is tried once, because a method that is not marked could do its work twice, and an error about the call itself would only repeat. The wait doubles between attempts and the caller's cancellation ends it at once with `E_CANCELLED`. While the connection is closed, a retry only helps once the supervisor (P2.10) has replaced the worker.

Because middleware runs inside Fibers, it can wait on I/O and look entirely sequential. Fibers are what remove the need for promise-returning middleware.
- Built-in middleware (all optional): timing and logging, trace id propagation, error mapping to the `E_*` codes (6.6), validation, and a retry middleware that applies only to methods marked idempotent through an attribute.
- Stacks are assembled at boot from the PSR-11 container.
- Middleware wraps flow. **Notifications** that must not alter flow use PSR-14 events (4.9).

### 4.9 Other common patterns

| Need | PHP idiom | Raphp plan |
|---|---|---|
| Notifications and observers | PSR-14 event dispatcher | Listeners may suspend. They are invoked in order within the dispatching Fiber. |
| Wiring services | PSR-11 container, constructor injection | PHP-DI 7 (D24), wired only in `Raphp\Core\Container\ContainerFactory`. No facades (O9). **Construction must not suspend a Fiber:** PHP-DI's circular-dependency guard is kept per container, not per Fiber (verified with 7.1.1, R24), so do asynchronous initialisation in a separate step after the container is built. |
| Logging | PSR-3 | Monolog (`Raphp\Core\Logging\LoggerFactory`). One line per record on stderr, timestamps in UTC taken from the container's PSR-20 clock, level an enum (`Raphp\Core\Logging\LogLevel`, D30). Worker logs are aggregated at the master (14). |
| Configuration | Typed config objects | Loaded from `raphp.json` plus environment by `Raphp\Core\Config\ConfigLoader` (rules in 9.2). No global `config()` helper. |
| Time | PSR-20 clock | For wall-clock timestamps and testable "what time is it" logic (injected, so tests control it). Elapsed-time measurement uses a monotonic source (`hrtime(true)` or `Amp\now()`), because wall-clock time can step. Timers use Amp's `delay()`. The container's logger takes its time from the same clock, in UTC. **[Proposed]** |
| Streams and async iteration | `amphp/pipeline` (`Pipeline`, `ConcurrentIterator`) and byte streams | Used for progress streams and multi-value results. |
| Retry, backoff, circuit breaker | Small value-object policies | A `RetryPolicy` in Raphp, applied through middleware. |
| Resource cleanup | `try`/`finally`, Amp `Closable` | Never rely on destructors for correctness. |
| Locks and semaphores | `amphp/sync` | Used where shared resources need coordination. |
| Caching | A cache interface | PSR-16 adapter only if needed. |

---

## 5. Process model and supervision

### 5.1 Spawning **[Proposed]**
The master spawns workers with raw `proc_open`, re-executing the same binary with `--role=worker --name=<worker>`. Rules:
- The command must be an **array** (the path from `readlink('/proc/self/exe')`, then `--role=worker`, `--name=<worker>`), never a string. A string makes `/bin/sh` the child's parent, which breaks parent-death detection (5.6). **[Verified: spikes P0.2, P0.3 and P0.6 use array commands. That a string breaks PDEATHSIG is derived from how PDEATHSIG works (finding F2), not a separate test.]** **[Decided and implemented, P2.9: `Raphp\Process\{ProcessSpawner, SelfCommand, WorkerSpec}`]** The command line is the program, its fixed arguments, then `--role=worker --name=<name> --parent-pid=<the master's pid>`, then any flags of the worker's own. In a micro binary the program is the binary itself (`SelfCommand`); under plain PHP it is the interpreter followed by the entry script. `--no-pdeathsig` (test only) tells the worker not to ask the kernel for a death signal. A program that does not exist, or a directory that does not exist, is a `SpawnException` at once with the reason from the system.
- The IPC channel is passed as descriptor 3 (6.2). It must be created with `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, ...)` plus `socket_export_stream` on both ends. Every descriptor Raphp creates must be close-on-exec, otherwise workers inherit other endpoints and never see EOF. The parent closes its copy of the child's end right after `proc_open` returns. **[Verified, spike P0.2]**
- stdout and stderr are captured as pipes.
- Workers may rely only on fds 0 to 3. The parent's own script descriptor is also inherited (observed as fd 4 in spike P0.2). It is harmless for EOF detection and not part of the contract. **[Verified, F4]**
- Raphp workers are not spawned through `amphp/process`. Its POSIX runner wraps every command in a `/bin/sh` script with a trap on INT, TERM, QUIT and HUP, and uses descriptor 3 for its own pid pipe. `Process::start` also turns an array command into one shell string. **[Source-checked: read in the `amphp/process` source, F18. Not run in a spike.]**
- **Application commands [Implemented, P2B.2: `ProcessRunner`, D45].** A command the application needs (`git`, `tar`, a converter) is not a worker, and it runs through `Raphp\Process\ProcessRunner` over `amphp/process` (the only place that may call `Process::start`, enforced by PHPStan). The command is a list (no shell, nothing quoted or expanded); a missing program is reported by name before anything starts. Each command runs under `setsid` as the leader of its own process group, so that **stopping it stops everything it started**: signalling only the command's pid left its children running as orphans (measured, F53). Cancellation, a timeout, output over the limit (16 MiB per captured stream, my number), a failing output callback and `stopAll()` all send SIGTERM to the group, wait up to a grace (2 s) for it to empty, then SIGKILL. Both output streams are read at once; output can be captured, streamed to a callback, or both. `amphp/process` gives the command descriptors 0 to 2 only (the rest are pointed at `/dev/null`), so it cannot use the master's sockets. If something the command started keeps its pipes open after it ended, the run returns after one second with `outputComplete` false. **Limits:** a command is in its own session, so Ctrl-C at the terminal does not reach it (the application cancels the call or calls `stopAll()`), and a master killed with SIGKILL does not stop its running commands (they are not workers and have no death signal); call `stopAll()` from a shutdown hook for an orderly end.
- **[Open, R17]** Descriptors that the shim's libraries create (Wayland or X11 connection, D-Bus, portals, clipboard, IME) are not created by Raphp, so the close-on-exec rule does not cover them. Combining `proc_open` with a live multi-threaded shim in the master has not been tested. The contract is not yet defined.
- **Output and reaping [Resolved, P2.9, risk R18: `OutputCapture`, `WorkerHandle`].**
  - **Output is always drained.** Both pipes are read from the start of the process to its end, whether or not anybody asks for the output, so a worker that writes more than a pipe holds (about 64 KiB) never blocks on it (tested with a 5 MB flood). Each line is logged with the worker's name in the context: stdout at `info`, stderr at `warning`. The last 64 KiB of each stream is kept for diagnostics (`output()`), so memory is bounded however much a worker writes. A line over 8 KiB is cut and logged in pieces; a last line without a newline is logged when the stream ends; blank lines are not logged. Output is the worker's own trusted code, so there is no limit on how fast lines are logged.
  - **The exit status is collected by one owner.** `WorkerHandle` reads it with `proc_get_status`, which also reaps the process, so **no zombie is left** (tested). Nothing else may call `pcntl_waitpid` on a worker: the status would be lost. The status is either an exit code or the signal that ended the process, and it is kept: asking again returns the same value. `waitForExit()` polls (5 ms at first, 50 ms at most) and can be cancelled.
  - **After the process ended, output is given one second to finish.** If another process (a grandchild) still holds the pipes, the wait ends after that second, a warning is logged, and the pipes are closed from the master's side (tested).
  - **Ending a worker.** `terminate()` is SIGTERM, `kill()` is SIGKILL, `stop($grace)` is SIGTERM and then SIGKILL after the grace time. Signalling a worker that has already ended returns false.

### 5.2 Declaring workers
Workers are declared in `raphp.json` (section 9.2) as `name → entry file`, with an optional pool size. A pool of size N is N separate processes behind one logical name, load-balanced round-robin or least-busy. **[Implemented, P2.10: `Raphp\Supervisor\{Supervisor, WorkerDefinition, Routing}`]** The members of a pool of more than one are named `<name>-1`, `<name>-2` and so on. Round-robin takes the members in turn and skips any that is not ready; least-busy takes the ready member with the fewest calls in flight (the first one on a tie).

### 5.3 Lifecycle states
`starting → handshaking → ready → draining → exited | crashed | degraded`

- A worker is only routable in `ready`.
- A call to a worker that is not `ready` waits up to a configurable timeout, then fails with `E_WORKER_UNAVAILABLE`.
- `crashed → starting` happens when the restart policy (5.4) restarts the worker.
- `degraded` is a terminal state, reached from `crashed` when restarts exceed K in a window of T seconds (5.4). The supervisor does not restart a `degraded` worker, and this document defines no recovery path out of it. **[Proposed]**
- A call to a `degraded` worker does not wait for the timeout. It fails immediately with `E_WORKER_UNAVAILABLE`, because no restart is coming. **[Proposed]**
- **[Implemented, P2.10]** The state names are `starting`, `handshaking`, `ready`, `draining`, `crashed`, `exited` and `degraded`. A worker that ends without being asked to is `crashed`, whatever its exit code; one ended by a stop is `exited`. The exit status comes from `WorkerHandle` (5.1, R18 resolved). A call that finds no ready member waits up to the definition's ready timeout (default 5 s) and then fails with `E_WORKER_UNAVAILABLE`; the wait ends early if a member becomes ready, and at once, with an error that says so, if every member is `degraded` or the supervisor is stopping. A worker that never completes the handshake within its timeout (default 10 s) counts as crashed.

### 5.4 Restart policy **[Proposed]**
- On crash: fail all in-flight calls with `E_WORKER_CRASHED`. Retrying is the caller's decision, because the call may not be idempotent.
- Restart with exponential backoff (100 ms doubling to 10 s).
- If restarts exceed K in a window of T seconds, mark the worker `degraded`, stop restarting, and emit an app-level event so the UI can show an error. K and T are configurable.
- **[Implemented, P2.10: `RestartPolicy`, `WorkerEvent`]** Crashes are counted over a sliding window (default 5 restarts in 60 s): the crash after the K-th restart makes the worker `degraded`, and crashes older than the window are forgotten. The wait before a restart doubles with the number of crashes in the window, from 100 ms up to 10 s (all four numbers are mine, not measured). Calls in flight when a worker dies fail with `E_WORKER_CRASHED` (the connection sees the end of input); the supervisor never repeats a call on its own (see `RetryMiddleware`, 4.8). **A worker that stops answering pings is killed and restarted**: the heartbeat (6.8, default 2 s and three misses for supervised workers) closes its connection, and the supervisor then ends the process with SIGTERM and SIGKILL, because a hung process would otherwise be left running. Every change is reported as a PSR-14 `WorkerEvent` (`started`, `ready`, `crashed`, `restarting` with its delay, `degraded`, `recycled`, `draining`, `terminating`, `killing`, `exited`); a listener that throws is logged and does not disturb the supervisor.

### 5.5 Graceful shutdown
`bye` message, then a grace period (default 5 s), then SIGTERM, then SIGKILL. The same sequence applies when the master exits.

**[Implemented, P2.10: `Supervisor::stop($grace)`]** For each worker, at the same time: it stops taking new calls (`draining`); the calls in flight are given up to `$grace` seconds to finish; then `bye` is sent and the worker has up to `$grace` seconds to end (`terminating` is reported if it does not); then SIGTERM, and after the definition's terminate grace (default 2 s) SIGKILL (`killing`); at the end `exited`. A worker waiting to restart is not restarted. Calls made after `stop()` fail with `E_WORKER_UNAVAILABLE`. A cooperative worker is stopped with `bye` alone, which is tested; a worker that ignores `bye` and SIGTERM is ended by SIGKILL, in that order, which is tested too. Wiring `stop()` into the master's own shutdown hooks is done where the master is assembled (P2.12).

**[R14: contract written in P3.1, D47 proposed]** This sequence covers workers. The shim fits around it as follows: the shim's shutdown hook is registered after the supervisor's, so with the kernel's last-registered-first order it runs first (the window closes at once; `raphp_shutdown` waits a bounded 3 s for the UI thread and detaches it if it is stuck), and the workers then drain as above. If the shim's UI thread fails, the shim is poisoned and the master stops itself with the error exit code through this same sequence, unless the application asked to continue headless. What runs when `exit()` is called inside a Fiber is still to be checked in P4.5.

### 5.6 Orphan prevention
A worker exits when its IPC socket reaches EOF. In addition, at startup it calls `prctl(PR_SET_PDEATHSIG, SIGTERM)` through libc FFI, so the kernel sends it SIGTERM when the master dies, including when the master is SIGKILLed and no cleanup code runs. Rules:
1. After the call, the worker compares `posix_getppid()` with the pid the master passed in and exits if they differ. This closes the startup race where the master died before `prctl` ran. The spike passed the expected parent pid as an argument. The convention is `--parent-pid=<pid>` on the command line (5.1), checked by `Raphp\Process\OrphanGuard` after the signal is requested.
2. Workers are spawned with an array command (5.1). With a string command, `/bin/sh` is the parent and the death signal is tied to the wrong process.
3. The worker's SIGTERM handling must actually end the process. After PDEATHSIG nobody remains to escalate to SIGKILL (5.5). Revolt signal handlers only run on a loop tick (4.5), so a worker whose loop is blocked or busy may not act on the signal if it has installed a handler. Whether a busy worker is covered is not tested.
4. Every worker needs ext-ffi and ext-posix, even a worker that never loads the shim. `libc.so.6` must be loadable, which is another reason for a glibc build (10.2). FFI is permitted by the default `ffi.enable=preload` in CLI and micro (10.2).

**[Verified, P2.9, in `make check`]** With real workers started by the real spawner: a master killed with SIGKILL takes every worker with it by end of input alone (workers started with the death signal off), and by the kernel's signal alone (workers that ignore end of input); with both off the worker survives (the control). The spawn test bound is one second. A worker's parent in `/proc/<pid>/stat` is the master and not `/bin/sh`; a worker given a wrong parent pid exits with code 4; SIGTERM ends an idle worker. **A worker whose loop is busy and that has installed a SIGTERM handler does not end on SIGTERM** (still running 0.7 s later; only SIGKILL ends it). So: a worker must not install a SIGTERM handler that depends on the event loop (the default action ends it), CPU work must stay off the worker's loop (4.4), and a supervisor must escalate to SIGKILL (`WorkerHandle::stop()` does).

**[Verified, spike P0.3]** With a default SIGTERM action and an idle loop, the child was gone 5 to 9 ms after a master SIGKILL. Without `prctl` (control test) the child was still alive at the end of the 3 s observation window. The busy-loop behavior in rule 3 is reasoning, not a spike result.

### 5.7 Hot reload
`rlm dev` watches source files. A change restarts the affected worker only. The window and UI state survive. Worker in-memory state is lost by design, and the docs must say so. **[Proposed]**

**[Open, R21]** This covers worker code only. Because views and handlers run in the master (8.4), most development edits land in master code, and this section does not say what happens then (the window and UI state would be lost). It also does not say whether a restart caused by a file change counts toward the K-in-T `degraded` rule (5.4), or what happens to in-flight calls during a reload.

---

## 6. IPC protocol specification (v1)

### 6.1 Transport interface
A `Transport` interface hides the mechanism so Windows named pipes can be added later without touching protocol code. The only MVP implementation is AF_UNIX stream sockets. **[Decided]**

**Contract [Implemented, P2.5: `Raphp\Ipc\Transport\Transport`, `StreamTransport`].** A transport moves bytes, not messages (framing is the codec's job, 6.3).
- `read()` waits for the next chunk and returns `null` at end of input (the peer finished sending, or this side closed). Only one read may wait at a time. A waiting read can be cancelled.
- `write()` waits until all the bytes are handed to the operating system, so a fast sender cannot pile up data nobody has taken (back-pressure). Several fibers may write at once; writes stay in order and each call's bytes stay together. It fails with `ClosedException` (a `StreamException`) when this side was ended or closed or the peer is gone.
- `end()` says nothing more will be written. It **waits for the writes still pending**, then the peer sees end of input; a write started after `end()` fails. This side can still read. Amp's own `end()` does not wait (it fails the queued writes, F39), so `StreamTransport` does the waiting itself.
- `close()` closes both directions at once, fails pending writes, ends a pending read with `null`, and frees the descriptor immediately. The event-loop watchers are cancelled before the descriptor is released (F5), so a read that is waiting never meets a closed stream. Closing twice is harmless.
- `isClosed()` is true once both directions are finished. `onClose()` runs its callback once, when that happens.
- The owner closes the transport when `read()` returns `null`.

### 6.2 Connection establishment **[Decided, O1; changes the earlier plan]**
The earlier plan used a path-based socket plus a random token handshake. A simpler and stronger alternative:

- **Primary:** the master creates a pair per worker with `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, $raw)` (ext-sockets) and wraps both ends with `socket_export_stream`. It passes the child's end to `proc_open` as descriptor 3, then, once `proc_open` has returned, `fclose`s its own copy of the child's end and sets its own end non-blocking. The child opens descriptor 3 with `php://fd/3`. There is no filesystem path, nothing to authenticate, and only the parent and child hold the descriptor. `stream_socket_pair` must not be used: it does not set close-on-exec, so the child inherits the parent's end (and other descriptors) and never sees EOF. Every descriptor Raphp creates must be close-on-exec, because `proc_open` children inherit any descriptor that is not. This makes `ext-sockets` a hard requirement. **[Verified, spike P0.2]**
- **Secondary (for attachable clients such as `rlm` inspectors or independently started workers) [Proposed]:** a listening socket at `$XDG_RUNTIME_DIR/raphp/<app-id>/<session>/<name>.sock`, directory mode 0700, socket mode 0600, with a token in `hello`. An optional `SO_PEERCRED` uid check could be added on top. **[Open, R22]** PHP 8.5.12 with ext-sockets does not define the `SO_PEERCRED` constant here, and `amphp/socket` 2.4.1 has no peer-credential API. No working route from PHP is confirmed, so how this path authenticates beyond the token is unspecified. This path is secondary and is not built before it is needed.

**[Decided, O1]** Socket pair is primary. Verified in Phase 0 (spike P0.2) with the `SOCK_CLOEXEC` condition above. The test used one worker only. Back-pressure: a 16 MiB write to a stalled child worked with a non-blocking writer, the loop kept ticking (max gap about 19 ms), and all 16,777,217 bytes arrived.

### 6.3 Framing
- **NDJSON:** one JSON object per line, terminated by `\n`. JSON string escaping guarantees no raw newline inside a message. **[Decided]**
- Encoders must not pretty-print. UTF-8 only. The PHP encoder writes non-ASCII as `\uXXXX` and leaves slashes alone, so every line is plain ASCII. A message is always an object: an empty one is `{}`, never `[]`.
- Maximum line size is configurable (default 8 MiB, counted without the newline; a line of exactly the limit is accepted). Larger fails with `E_PAYLOAD_TOO_LARGE`. Bulk binary data does not belong here (6.9).
- **Limits [Implemented, P2.3 and the review of P2.6].** Besides the line size, a message may nest at most **64 levels** of arrays and objects (the message itself is level 1; the encoder and the decoder accept exactly the same depth, so the codec never writes what its own decoder refuses) and hold at most **200,000 arrays and objects** together. The second limit bounds memory: PHP needs about 60 bytes of memory for each byte of a line like `[1],[1],[1],...`, so an 8 MiB line would decode to roughly 490 MB without it. The count is the number of `[` and `{` characters in the line, so brackets inside strings count too; a message that really needs more has outgrown this channel (6.9). All three limits are checked on encode and on decode.
- **Decoder contract [Implemented, P2.3].** Input arrives in chunks cut anywhere, and the result must not depend on where. A bad line (empty, not starting with `{`, invalid JSON, invalid UTF-8, nesting deeper than 64 levels, more than 200,000 arrays and objects) is an `E_PROTOCOL` problem for that line only, and decoding **continues** with the next line. A line over the limit, complete or still unfinished, is an `E_PAYLOAD_TOO_LARGE` problem, reported once, after which the decoder **stops and discards all further input**: it cannot find the next message boundary safely, and an unfinished line must not be buffered without end. The owner of the connection closes it. Input that ends in the middle of a message is an `E_PROTOCOL` problem at end of input. The decoder is pull-based (`feed`, `next`, `finish`) so the transport decides when to read.
- **Golden files [Implemented, P2.3].** `docs/protocol/golden/` holds one message of every kind (the PHP encoder must reproduce it byte for byte) and the malformed cases with the exact outcome a decoder must give. The Rust shim's tests will read the same files.
- **What does not survive a round trip.** A float with no fraction stays a float (`10.0` is written `10.0`, not `10`). A JSON key that appears twice keeps its **last** value (PHP's rule; there is no duplicate-key error, R25). An empty object nested inside a message (`{}`) decodes to an empty PHP array and is written back as `[]`: only the top-level message and `params` are written as objects when empty. Hand-written code that must send an empty nested object has to say so with `(object) []`.
- A JSON object whose keys are `"0"`, `"1"`, ... decodes to a list in PHP (F24, F32). It is still an object on the wire; the envelope layer (6.4) rejects it for lacking the required fields.
- A `Codec` interface permits another format later (MessagePack is available as an extension on the dev machine). JSON stays the MVP format. **[Proposed]**

### 6.4 Envelope

```jsonc
{ "id": 17, "kind": "req", "method": "files.scan", "params": {"path": "/tmp"}, "trace": "9f2c4a1b7d3e5f60" }
{ "kind": "evt", "ref": 17, "method": "progress", "params": {"done": 40, "total": 100} }
{ "id": 17, "kind": "res", "result": {"count": 100} }
{ "id": 17, "kind": "res", "error": {"code": "E_CANCELLED", "message": "...", "data": null} }
{ "kind": "cancel", "ref": 17 }
{ "kind": "evt", "method": "app.theme_changed", "params": {"dark": true} }
{ "kind": "ping" }   { "kind": "pong" }
{ "kind": "hello", "proto": 1, "role": "worker", "name": "main", "pid": 4242, "caps": [] }
{ "kind": "bye", "reason": "shutdown" }
```

Rules:
- `id` belongs to requests and their responses: the sender assigns it, a positive integer, unique per connection direction. Either side may issue requests. An `evt` has no `id`, because nothing ever answers it. *(Fixed in P2.4: the first draft of the example gave a progress `evt` an `id`.)*
- A request produces zero or more `evt` messages with `ref = id` (progress or streaming), then exactly one `res`.
- Messages are ordered per connection. Requests run concurrently, so responses may arrive out of order.
- Unknown `kind` or malformed JSON: reply `E_PROTOCOL` where possible, then close.

**Reading rules [Implemented, P2.4].** `Raphp\Ipc\Message\EnvelopeReader` turns a decoded line into a typed message or refuses it with `E_PROTOCOL`:
- `kind` must be one of `req`, `res`, `evt`, `cancel`, `hello`, `bye`, `ping`, `pong`, exactly, in lower case.
- Required fields: `req` needs `id` and `method`; `res` needs `id` and exactly one of `result` or `error` (an `error` that is `null` counts as absent, and a non-null `error` next to a non-null `result` is refused); `evt` needs `method`; `cancel` needs `ref`; `hello` needs `proto`, `role`, `name`, `pid`. Optional: `params` (default `{}`), `ref` on `evt`, `caps` (default `[]`) and `reason` (default `""`).
- `req` may carry an optional `trace`: an id that follows one operation across processes so log lines on both sides can be matched. It is 1 to 64 characters from `A-Z a-z 0-9 _ . -` (it ends up in logs). *(Added in P2.6.)*
- Types are exact: an integer field must be an integer (not `1.0`, `"1"` or `true`), and `null` is never accepted as "absent" for a field that is present.
- Values: `id`, `ref`, `proto` and `pid` are positive integers; `method`, `role` and `name` are not empty; `method` is at most 256 bytes and a `bye` `reason` at most 256 bytes (both end up in logs and error messages); an error `message` received from a peer is cut to 2,048 bytes; `params` is an object with named keys, so a list is refused (F37).
- Extra keys are ignored, so a newer peer can add fields.
- An `error` object is rebuilt with the rules of 6.6, so an unknown code becomes `E_INTERNAL`.

### 6.5 Handshake and versioning
Both sides send `hello` immediately after connecting. `proto` is an integer. A mismatch is answered with `bye` and `E_PROTOCOL`. `caps` is a list of optional feature names (for example `"shm"` for the shared-memory lane). Breaking changes bump `proto`.

**[Implemented, P2.7: `Raphp\Rpc\RpcPeer` built with a `Hello`, `Raphp\Ipc\Protocol::VERSION`]**
- **Order.** `hello` is the first thing a peer sends, ahead of any call made at the same moment. Until the other side's `hello` has arrived, a peer accepts nothing but `hello`, `ping`, `pong` and `bye`; anything else is a protocol violation (`expected hello first`).
- **Version.** `proto` must be equal to this side's version. A different one, a second `hello`, or no `hello` within the handshake timeout (default 5 s) ends the connection: the peer sends `bye` with the reason `E_PROTOCOL: <what was wrong>` (for example `E_PROTOCOL: unsupported protocol version 2, this side speaks 1`), then closes. Calls waiting on that side fail with `E_PROTOCOL`, and so does `awaitHandshake()`.
- **After the hello.** `remote()` gives the other side's `Hello` (role, name, pid, capabilities); `awaitHandshake()` waits for it. Roles are free text for now.
- **`bye`.** A reason that starts with a framework error code and a colon (`E_PROTOCOL: ...`) is that error for the receiver's waiting calls. Any other reason, or none, is an orderly goodbye and the calls fail with `E_WORKER_UNAVAILABLE`. A reason is at most 256 bytes; longer ones are cut when sent.
- **Two ways to end a connection on purpose.** `shutdown(reason)` writes a `bye` after what is already queued and closes once it is out, or after the flush timeout (default 2 s) if the other side does not read. `close()` closes at once and says nothing.

### 6.6 Error codes
`E_METHOD_NOT_FOUND`, `E_INVALID_PARAMS`, `E_CANCELLED`, `E_TIMEOUT`, `E_WORKER_CRASHED`, `E_WORKER_UNAVAILABLE`, `E_PAYLOAD_TOO_LARGE`, `E_PROTOCOL`, `E_INTERNAL`. Application errors use `E_APP_*` with free-form `data`.

**In code** **[Proposed, D32, implemented and tested in P1.6]**

| Wire code | Exception (all in `Raphp\Error`) |
|---|---|
| `E_METHOD_NOT_FOUND` | `MethodNotFoundException` |
| `E_INVALID_PARAMS` | `InvalidParamsException` |
| `E_CANCELLED` | `RequestCancelledException` |
| `E_TIMEOUT` | `RequestTimeoutException` |
| `E_WORKER_CRASHED` | `WorkerCrashedException` |
| `E_WORKER_UNAVAILABLE` | `WorkerUnavailableException` |
| `E_PAYLOAD_TOO_LARGE` | `PayloadTooLargeException` |
| `E_PROTOCOL` | `ProtocolException` |
| `E_INTERNAL` | `InternalErrorException` |
| `E_APP_[A-Z0-9_]+` | `ApplicationException` (the code is chosen by the application, `data` is free-form) |

- `ErrorCode` is a string-backed enum of the nine framework codes. `RaphpException` is the abstract base of every exception the framework throws on purpose. `RpcException` extends it for errors that travel between processes. A module's own exceptions, such as `InvalidConfigException`, extend `RaphpException` but are never sent over IPC.
- The string code is `wireCode()`, not `getCode()`, because PHP requires `getCode()` to return an int.
- `toWire()` gives `{code, message, data}`, and `RpcException::fromWire()` rebuilds the right subclass. The stack trace and the previous exception do not cross the wire.
- A code that is neither a framework code nor a well-formed `E_APP_*` code (a newer peer, say) becomes an `InternalErrorException`. Its `data` keeps the original code, message and data, and its own message does not echo the code. **[Proposed]**
- A malformed error object (no string `code` or `message`) becomes a `ProtocolException`. Extra keys are ignored, so a newer peer can add fields.

**The RPC peer [Implemented, P2.6: `Raphp\Rpc\RpcPeer`].** Both ends of a connection are the same class, so either may call the other.
- **Two fibers, one rule.** One fiber reads and decodes; another writes from a bounded outbound queue (default 256 messages). The reader **never waits on a write**: it only queues what it must send (a `pong`, a `cancel`), so two peers that both send a lot cannot block each other (F38). A sender other than the reader waits when the queue is full (back-pressure).
- **Calls.** Ids count up from 1 on each connection. At most `maxInFlight` calls (default 64) are out at once; more wait in line, up to `maxQueuedCalls` (default 1024); a call beyond that fails at once with `E_WORKER_UNAVAILABLE`. Answers may come back in any order. A call may carry a timeout, a cancellation and a progress callback. The callback runs on the reader fiber, so it must be quick.
- **Timeout and cancel.** The caller fails locally (`E_TIMEOUT` or `E_CANCELLED`) and a `cancel` message goes out. The call keeps its slot until the other side answers (or the connection closes), so calls given up on stay bounded by the in-flight limit. The late answer is dropped without a log line.
- **Answering.** Each request runs in its own fiber through the inbound middleware to the handler; at most `maxInbound` (default 256) at once, one more is answered `E_WORKER_UNAVAILABLE`. A handler's `RpcException` keeps its code; cancellation becomes `E_CANCELLED`; anything else becomes `E_INTERNAL` with the fixed message `internal error` (the exception is logged here, never sent). A result that cannot be encoded becomes `E_INTERNAL`, one over the size limit becomes `E_PAYLOAD_TOO_LARGE`, so the caller is never left waiting. A request with an id already in use is a protocol violation.
- **Memory stays bounded when the other side stops reading** *(found by the independent review of P2.6, fixed)*. A request stays counted against `maxInbound` until its answer is in the outbound queue, not merely until its handler returns. Messages the reader itself must send (`pong`, `cancel`, the refusal of an over-limit request) go through a **reserve** of the same size as the queue (so at most twice the queue), and once even the reserve is full the connection is closed ("the peer does not read what is sent to it"). A `pong` is queued only if no `pong` is already waiting, so any number of pings costs one `pong`.
- **When the connection ends.** Calls still waiting fail with `E_WORKER_CRASHED` if the other side vanished without `bye` (end of input or a read or write error), with `E_WORKER_UNAVAILABLE` after a local `close()` or a received `bye`, and with the offending error after a protocol violation. Requests being handled are cancelled. Calls made afterwards fail with `E_WORKER_UNAVAILABLE`.
- **Protocol violations.** A line that is not a valid message, an oversize line, a duplicate request id or a broken handshake closes the connection. The peer first says why with a `bye` (`E_PROTOCOL: ...`, `E_PAYLOAD_TOO_LARGE: ...`), which is the "reply `E_PROTOCOL` where possible, then close" of 6.4; the farewell is given the flush timeout to leave. A peer that is not reading at all is cut off without one (see "Memory stays bounded").

### 6.7 Cancellation and timeouts
`cancel` is cooperative: the receiver trips the Amp `Cancellation` passed to the handler, and answers `E_CANCELLED` if the handler stops. Client-side timeouts send `cancel` and fail locally with `E_TIMEOUT`. A handler that ignores cancellation is the handler's bug, not a protocol failure.

### 6.8 Liveness and backpressure
- **Liveness [Implemented, P2.7]:** EOF is the primary death signal. After `pingInterval` seconds without hearing anything from the other side a `ping` goes out, and the other side is declared dead (`E_WORKER_CRASHED`, waiting calls fail) after `missedPings` such silent intervals. The intended setting for supervised workers is a 2 s interval and three misses; the heartbeat is **off unless the owner of the connection sets an interval**. *Silence* means no bytes at all, not no `pong`. If this process itself was not running for more than two intervals (a blocking call, a stopped process) the silence says nothing about the other side, and the clock starts again. A worker busy with CPU work on its loop misses pings, so CPU work must be offloaded (4.4), and the threshold must be tunable. *(P2B.1: before the other side's `hello` has arrived the heartbeat does not count silence or send pings: a worker process takes time to boot, which the handshake timeout judges. Counting it made a slow start on a loaded machine look like a crash, finding F51.)*
- **How early back-pressure starts [Measured, F38].** An AF_UNIX stream socket accepts only about 280 small writes (anything up to about 700 bytes each) before the next write must wait for the reader; larger writes fill the roughly 200 KB buffer after about 200 KB. So a peer that stops reading makes the sender wait after a few hundred small messages, not after 200 KB. The writer of an RPC peer must expect `write()` to wait and must not hold a lock the reader needs.
- **Backpressure:** each connection has a write-buffer high-water mark. A send returns a future that resolves when flushed. There is a cap on in-flight requests per worker (default to be chosen in Phase 2). Requests beyond it queue, and past a queue limit they fail with `E_WORKER_UNAVAILABLE`.
- High-rate UI events (mouse move, scroll) are coalesced in the shim (7.5), never in IPC.
- **R19: a large message in flight [Resolved, P2.7].** All messages share one ordered stream (6.3), so a message in flight delays everything behind it. The contract:
  1. **Delay is bounded by the transfer.** A `pong`, a `cancel` or an unrelated response waits at most as long as the message ahead of it takes to arrive: at most 8 MiB, and bulk data does not belong on this stream (6.9). Order is never broken: a `cancel` cannot overtake the message sent before it (tested).
  2. **Liveness does not depend on the `pong`.** Any bytes heard count as proof of life, so a peer slowly delivering a huge message is not declared dead although its `pong` waits behind it. A peer that sends nothing for the allowance is declared dead whatever else is true (both tested, with a 3 MB message that takes eight times the allowance to arrive).
  3. **At the size cap** the receiver stops, discards the rest without buffering it (P2.3), tells the sender `bye` with `E_PAYLOAD_TOO_LARGE`, and closes.
  4. **Measured (P2.13, `docs/BENCHMARKS.md`):** a small call that shares the stream with a 7 MiB message waited a median of 152 ms, about 25 ms per MiB on the development laptop, at most about 0.2 s at the 8 MiB cap. A `ping`'s own round trip was not measured separately; it uses the same queue and stream.

### 6.9 Large payloads **[Proposed, later]**
Bulk data (images, buffers) uses a shared-memory lane (`shmop` or FFI `mmap`). Only a handle travels over IPC, and capability `shm` is negotiated in `hello`.

### 6.10 Security
Same-uid only. Socket-pair inheritance means no path to attack. Every Raphp descriptor must be close-on-exec (6.2). This is also a security rule, so that a worker never holds another worker's endpoint. Path sockets use a 0700 directory, a 0600 socket and a token in `hello` as the baseline defence. A `SO_PEERCRED` uid check is an optional add-on and is **[Open, R22]** (see 6.2). IPC is never bound to a network interface. JSON decoding enforces depth and size limits.

---

## 7. The shim (`libraphp`)

### 7.1 Responsibilities
The shim owns everything that must run on a real thread against the OS: window, input, layout, text, drawing, native dialogs, clipboard and tray, plus drawn menus. PHP describes *what* to show and reacts to events. PHP never draws a pixel and never runs per frame. **[Decided]**

### 7.2 Threading model **[Decided: UI thread inside the shim, Linux]**
- The shim starts one UI thread at init. All toolkit state lives there.
- Every shim API function is callable from PHP's thread and returns without waiting on the UI thread.
- The UI thread never calls PHP.
- **Accepted cost:** macOS requires UI on the process main thread, so this design will not port to macOS as is. A later macOS port would need a native host process that embeds PHP. **[Decided]**
- On Linux, winit supports running its event loop off the main thread on Wayland and X11. **[Verified, P0.1 (R1)]** The winit loop ran on a non-main thread (`with_any_thread`) under both Wayland and X11, a window was created, the UI thread kept redrawing while PHP was blocked, and pointer events were delivered and coalesced. 1000 round trips had p50 of 65 to 114 µs. The user confirmed a window appeared. Caveats: the redraw counts were unthrottled CPU fills, not a rendering benchmark, and keyboard and click events are still untested.
- **[Resolved, P3.1, R14: contract in `docs/protocol/shim-abi.md`]** The UI thread's body runs under `catch_unwind`: a panic or an unrequested return poisons the shim (every later call returns `POISONED`, the cause stays readable) and the process lives on; the build never uses `panic = "abort"` (a test enforces it); shutdown waits a bounded time and detaches a stuck thread. A fatal signal or an `exit()` in native code cannot be contained in-process.

### 7.3 C ABI **[Version 0.4 implemented in P3.1 to P3.4; the full reference is `docs/protocol/shim-abi.md`]**

```c
typedef struct RaphpShim RaphpShim;
typedef int32_t RaphpStatus;                       // 0 ok, negative failures; see the reference

uint32_t    raphp_abi_version(void);               // major<<16 | minor; PHP checks at load
RaphpShim*  raphp_init(const char* cfg, size_t len);          // null on failure
RaphpStatus raphp_send(const RaphpShim*, const char* batch, size_t len);
size_t      raphp_last_error(const RaphpShim*, char* out, size_t cap);   // full length returned
RaphpStatus raphp_shutdown(RaphpShim*);            // bounded wait, frees the handle
int         raphp_event_fd(const RaphpShim*);      // wake-up descriptor (P3.2)
intptr_t    raphp_poll(const RaphpShim*, char* out, size_t cap);   // whole events, 0 none, -6 too small
size_t      raphp_next_event_size(const RaphpShim*);
size_t      raphp_max_event_bytes(void);
size_t      raphp_diagnostics(const RaphpShim*, char* out, size_t cap);   // counters as JSON (P3.3)
```

The generated header is `shim/include/raphp.h` (ABI version 0.4); the ten functions above and the two headless ones (`raphp_headless_advance`, `raphp_headless_frame`: twelve in all) are all that exist so far.

Rules:
- Strings are UTF-8 with explicit lengths, never NUL-terminated assumptions.
- The shim copies every input. PHP owns every output buffer. The shim never returns an allocation to PHP, so there is nothing for PHP to free.
- `raphp_poll` returns whole events only **[Resolved, P3.2, R16; contract in `docs/protocol/shim-abi.md`]**: bytes written (every line ending in `\n`), `0` for none, `RAPHP_STATUS_BUFFER_TOO_SMALL` (-6) if the next event exceeds `cap`. In that case nothing is copied, **the event stays at the head** (nothing overtakes it), the wake-up is raised again, and `raphp_next_event_size` gives the buffer size needed. A single event is at most 1 MiB (`raphp_max_event_bytes`): a larger one is replaced by a small `event.too_large` event. PHP's drain loop grows its buffer on -6 and never leaves until the poll returns 0. Tested in Rust and through PHP's FFI.
- The header is generated with `cbindgen`, committed to the repo, and loaded on the PHP side via `FFI::cdef`.
- **Panics never cross the ABI [Implemented and tested, P3.1].** Every `extern "C"` function runs under `catch_unwind` (a test fails if one does not). A panic in a call returns `PANICKED` and poisons the shim; later calls return `POISONED`; `raphp_last_error` and `raphp_shutdown` keep working. Poisoning is permanent: a new shim needs a new `raphp_init`. Verified through the real FFI.
- **The UI thread and the shutdown order [Resolved as a contract, P3.1, R14; D47 proposed]:** see 7.2 and `docs/protocol/shim-abi.md`. The master's reaction to a poisoned shim and the shutdown order (the shim's hook runs first, so the window closes at once and the workers drain after) are proposed in D47 and are built in P3.5 and checked in P4.5.
- ABI compatibility: minor bumps add functions, major bumps break. `rlm doctor` and runtime startup both check `raphp_abi_version()`.

### 7.4 Command protocol (PHP → shim)
- A **batch** is a JSON array of commands, or an object `{"batch": <id>, "commands": [...]}` whose id the shim repeats in every error and in a closing `batch.done` event. PHP builds one batch per loop tick and sends it in a single FFI call. **[Verified feasible, Appendix A; implemented in P3.3, reference `docs/protocol/shim-commands.md`]**
- **[Resolved, P3.3, R15: the written contract is in `docs/protocol/shim-commands.md`]** Commands are applied in the order sent; events are in the order produced; `batch.done` marks the moment a batch took effect, so events before it were made against the old tree and events after it against the new one (a click on a widget the batch destroys arrives before the done event); after `widget.destroy` no event about that widget is queued; ids only go up and are never reused, so a stale event can never be mistaken for a newer widget's; every error names its batch and the command's index, and a failed command does not stop its batch; a text set can carry the revision PHP last saw (`base_rev`) and is refused (`stale_text`) if the user has typed since. All tested, through the real C functions and golden files.
- Operation families (MVP): `window.create|set|set_root|destroy`, `widget.create|set|append|insert|remove|destroy`, `app.quit` (**implemented, P3.3**); `canvas.*` (draw lists for custom-drawn widgets), `dialog.*`, `clipboard.*` come with their steps.
- Widgets have PHP-assigned integer ids so later commands can refer to them without round trips.
- JSON is the MVP encoding because it is debuggable. A binary encoding is introduced only if profiling demands it (Appendix A: 200 commands encode in ~40 µs).

### 7.5 Event protocol (shim → PHP) **[Proposed]**
- Events are appended as NDJSON to an **in-memory queue** inside the shim. An `eventfd` is written to wake PHP. **[Verified, Appendix A]**
- PHP's loop watches the eventfd. On wake: **clear the eventfd first, then drain with `raphp_poll` until it returns 0.** This order cannot lose a wake-up. **[Verified, P3.2]** with the real descriptor: an event queued between clearing and draining is not lost; after clear and drain the descriptor is quiet; the shim raises the wake-up when the queue goes from empty to non-empty and whenever a poll leaves events behind. A concurrent stress test (8 producers, a slow consumer) lost no event. (On a too-small buffer, see R16 in 7.3.)
- **The fd is only a wake-up, never the buffer.** A pipe-as-buffer design was tested and dropped 88% of events when PHP stalled for 300 ms. The in-memory queue lost none (Appendix A). **[Verified, Appendix A]**
- **Coalescing rules [Implemented and tested, P3.2]:** continuous events (pointer move, scroll, resize) carry **state, not deltas** and keep only the latest per window and kind: a new one replaces an earlier queued one **if no discrete event was queued in between**, so a discrete event is never overtaken and never overtakes. Discrete events (click, key, text input, focus, close, errors) are never merged or dropped and keep their order. A raw wheel delta is not a continuous event: the shim's scroll containers consume it and PHP learns the offset.
- **Queue limits [Decided, O7; implemented, P3.2]:** discrete events are never dropped. Past a **soft limit of 1 MiB** queued, an older continuous event is removed even across a discrete one (the newest goes last) and superseded ones are swept; past a **hard limit of 4 MiB**, with nothing left to merge, the producer waits for PHP (back-pressure on the UI thread, by design). Shutdown and a failure of the shim wake any waiting producer. When the shim fails it queues one last `shim.failed` event and accepts no more. The limits are my numbers, unmeasured (D48).

### 7.6 Rendering and layout **[Proposed]**
- Layout: Taffy (flexbox subset).
- Text: cosmic-text.
- Raster: CPU rasterization for the MVP (tiny-skia or softbuffer). GPU (wgpu/Vello) later.
- Redraw on dirty regions, paced by the compositor's frame callbacks. Animations run inside the shim and need no PHP per frame.
- **[Verified, P0.1]** The UI thread kept redrawing while PHP was blocked. The redraw counts were unthrottled CPU fills, not a rendering benchmark.
- Phase 0 finding F8: redraws must be paced to the compositor and dirty-driven, not polled. **[Proposed]**

### 7.7 Native chrome (hybrid, reading (i)) **[Decided]**
Native (OS-provided): file dialogs through the `xdg-desktop-portal` FileChooser, clipboard through `smithay-clipboard` on Wayland, the system theme setting, and an optional tray. The window itself is created through winit, and its decorations are winit's client-side ones on GNOME/Wayland, which offers no server-side decorations. Drawn by Raphp inside the window: menus and popups. Everything inside the window is custom-drawn.

Findings on GNOME 50.5 Wayland **[Verified, P0.4; Decided: option (a), GNOME first, improve later]**:
1. winit 0.30.13 has no `xdg_popup` support, so menus cannot extend beyond the window and are drawn in-window.
2. GNOME offers no server-side decoration protocol, so winit draws the title bar.
3. The compositor offers no `data-control` protocol, so clipboard must use `smithay-clipboard`, not `arboard`.
4. The tray needs a StatusNotifierWatcher, which stock GNOME lacks unless the AppIndicator extension is enabled (installed but disabled on this machine), so the tray is an optional feature.
5. The FileChooser portal is available, and `zwp_text_input_manager_v3` is offered.

Not a P0.4 finding **[Unverified]**: Wayland does not let apps position their own windows. This is a general protocol fact that was not tested here.

The GTK4 window path (b) is not pursued. **[Decided]** **[Verified, chrome experiment]** A real portal file dialog requested from PHP through the shim (`rfd` 0.17.2, helper thread, not attached to the window) returned the chosen path to PHP as an event, and the title bar's close button produced `close_requested` in PHP. **[Decided, D31]** You accepted the native chrome as the chrome experiment showed it (the winit client-side window with its title bar, rounded corners and shadows, and the portal file dialog), without reservation. **[Unverified]** The tray, whether the dialog attaches to the window, window responsiveness while the dialog is open, and IME were not exercised. Those checks are deferred to plan steps P3.12a to P3.12c (tray, dialog parenting; clipboard is built in P3.11b) and P3.11a (IME).

### 7.8 Headless backend for tests **[Decided, O4]**
The shim exposes a `headless` backend with no window system dependency. It runs layout and the command/event machinery without drawing to a screen, so CI can test the whole PHP-to-shim path on machines with no display. **[Implemented, P3.4; D50 proposed]** The same UI loop, model and commands run on a backend with a deterministic clock (it moves only through `raphp_headless_advance`), an in-memory RGBA picture for each window (size times `scale`, within a memory budget) and the frame pace of a configurable refresh rate; `raphp_headless_frame` reads the pictures. A batch asks for a frame only for a real change, and a window gets at most one frame per interval (F8). Until drawing exists (P3.8) every frame is blank white. The details are in `docs/protocol/shim-abi.md`. The default backend is headless until the window backend (P3.6) exists.

### 7.9 Build and distribution
One Rust `cdylib` per architecture, linked against glibc. The version is pinned in the project lock file. `rlm` fetches prebuilt binaries and verifies checksums. Building from source requires a Rust toolchain.

**glibc floor.** The shim's highest required `GLIBC_` symbol version was 2.34 (headless) and 2.43 (windowed), measured with `objdump -T` on this machine (host glibc 2.43). PHP and the app need only 2.17. So the distributable floor is set by how the shim is built, not by PHP. **[Verified, F14]** Not yet run on any other distro. Building the shim against an old glibc (for example `cargo-zigbuild` with a `.2.17` target, or an old-distro container) would avoid raising the floor (D21, R23). **[Decided, D21, approved by you on 2026-11-06]** The mechanism (`cargo-zigbuild` or a container) is chosen and verified in P3.1 and P6.1.

---

## 8. UI framework (PHP side)

### 8.1 Layers
`Widget API (declarative PHP) → virtual tree → diff → command batcher → shim`

### 8.2 API sketch **[Proposed, illustrative, not final]**

```php
declare(strict_types=1);

final class FilesScreen implements Screen
{
    public function __construct(
        private readonly WorkerClient $worker,
    ) {
    }

    public function build(ScreenState $state): Widget
    {
        return new Column(children: [
            new TextInput(value: $state->path, onChange: $state->setPath(...)),
            new Button(label: 'Scan', onClick: $this->scan(...)),
            new ProgressBar(value: $state->progress, visible: $state->busy),
            new Label(text: $state->result ?? ''),
        ]);
    }

    // The framework runs event handlers in a Fiber, so awaiting is just a call.
    private function scan(ScreenState $state): void
    {
        $state->update(busy: true);

        $result = $this->worker->call(
            method: 'files.scan',
            params: ['path' => $state->path],
            onProgress: fn (array $p) => $state->update(progress: $p['done'] / $p['total']),
        );

        $state->update(busy: false, result: $result);
    }
}
```

Widgets are immutable value objects built with constructors and named arguments. `build()` is a pure function of state, so the framework can diff the result. Handlers are first-class callables, and `ScreenState` is injected rather than captured from globals.

### 8.3 State and latency **[Proposed]**
- **UI-local state** (text being typed, scroll position, hover, focus) lives in the shim and master and never crosses IPC per keystroke.
- **App state** lives in the master (simple apps) or in a worker (heavier apps).
- Flow: shim event → handler in master → optional `worker->call()` → state change → re-render → diff → one command batch.
- Text inputs commit to app state on blur or after a debounce, not per keystroke.

### 8.4 Where UI code runs **[Decided, O2]**
View definitions and event handlers run in the **master**, because it owns the shim. Workers hold logic and data. The alternative (model in the worker, view patches streamed to the master, Elm style) puts IPC latency on every interaction. The cost of this choice is that the master holds the most long-lived state and cannot be recycled (4.6, R20), and that most edits during development land in master code (5.7, R21).

### 8.5 MVP widget set
Window, Column, Row (flex), Label, Button, TextInput, Checkbox, ProgressBar, ScrollView, Canvas. Later: virtualized List, Table, Tabs, Menu, Dialogs.

### 8.6 Layout, HiDPI, theming
Flexbox subset through Taffy. The shim reports the scale factor, and layout is in logical pixels. Theme tokens (colors, spacing, type scale) with light and dark variants that follow the system setting. Fonts through fontconfig.

### 8.7 Input and text
Keyboard, pointer and scroll events come from the shim. Text input and IME on Wayland (text-input protocol) is a known hard area for custom-drawn UIs **[Unverified]** and will get its own spike (P3.11a) before the TextInput widget is finalized. P0.4 found only that `zwp_text_input_manager_v3` is offered on GNOME 50.5.

### 8.8 Dialogs and menus
File dialogs and clipboard are native through the shim. Menus are drawn in-window (7.7).

### 8.9 Canvas widget
An escape hatch for custom drawing (charts, editors, games). PHP sends draw lists, and the shim rasterizes them. Per-frame drawing from PHP is discouraged. Animation belongs in the shim.

### 8.10 Accessibility readiness **[Decided: later, but don't preclude]**
From day one every widget has a stable id, a semantic role and an optional label. When accessibility is built (AccessKit has a C API), the data already exists.

---

## 9. `rlm` (Raphp libs manager)

### 9.1 Role **[Decided: replaces the earlier `run` command idea]**
`rlm` is the single front door for the project: it wraps Composer for PHP packages, manages native libraries, and runs and builds the app.

| Command | Purpose |
|---|---|
| `rlm init` | Scaffold a project (`raphp.json`, `composer.json`, entry files) |
| `rlm add <pkg>` / `remove` | Add or remove a PHP package (delegates to Composer) |
| `rlm install` | Install PHP packages and native libs from lock files |
| `rlm update` | Update within constraints |
| `rlm run [file]` | Run the app or a script |
| `rlm dev` | Run with file watching and worker hot reload |
| `rlm build` | Produce the standalone binary |
| `rlm doctor` | Check ffi, extensions, shim ABI, display, glibc |
| `rlm lib list\|pin\|verify` | Inspect and manage native libs |

### 9.2 Manifest **[Proposed]**
`composer.json` stays the source of truth for PHP dependencies. `raphp.json` holds Raphp-specific configuration. `rlm add` runs `composer require` underneath.

```jsonc
{
  "name": "acme/files",
  "entry": "src/main.php",
  "workers": { "main": { "entry": "src/worker.php", "pool": 1 } },
  "ui": { "enabled": true, "backend": "default" },
  "libs": { "libraphp": "^0.1" },
  "php": { "extensions": ["ffi", "pcntl", "posix", "sockets"], "ini": {} },
  "build": { "output": "dist/files", "embedShim": true }
}
```

**Rules and environment overrides** **[Proposed, D29, implemented and tested in P1.5]**

`Raphp\Core\Config\ConfigLoader` enforces these rules. It reports every problem it finds, together, capped at 50 with a count of the rest.
- **Strict keys.** An unknown key at any level is an error. An explicit `null` is never treated as "absent": it is rejected.
- **Names.** `name` is `vendor/package` (lowercase letters, digits, `.`, `_`, `-`). Worker names, library names and `ui.backend` start with a lowercase letter. ini setting names start with a letter or `_`. Extension names are lowercase letters, digits and `_`. No name may be only digits, because PHP turns such array keys into integers.
- **Paths** (`entry`, a worker's `entry`, `build.output`) must be clean relative paths inside the project: no leading `/`, no `.`, `..` or empty segment, no backslash, no control character.
- **Limits** (guards against accidents, not design limits): the file at most 1 MiB; JSON nesting within PHP's depth of 64; each string at most 1024 bytes; a worker pool from 1 to 256. Strings may not contain control characters.
- **The file** is resolved once with `realpath()` and read from that path, so the checks and the read see the same real local file. Stream wrappers such as `file://` and `phar://` are rejected. A UTF-8 byte order mark is rejected with a clear message. `basePath` is the absolute directory of the resolved file.
- **Environment overrides.** `RAPHP_LOG_LEVEL` (a PSR-3 level name, any case, default `info`), `RAPHP_IPC_TRACE` and `RAPHP_UI_ENABLED` (booleans: `1`, `true`, `yes`, `on` or `0`, `false`, `no`, `off`, any case). `RAPHP_UI_ENABLED` overrides `ui.enabled` from the file; the other two exist only in the environment. An empty value counts as unset, and any other invalid value is an error. The loader never reads global state: the entry point passes `getenv()`.
- **Output safety.** Error messages escape control characters (including DEL and the C1 range), shorten long keys and values, and escape the file path, so text written in a manifest cannot change how an error is displayed.
- **Known limitation (R25).** If a JSON object repeats a key, PHP keeps the last one without a warning. Detecting that needs a custom JSON parser.

### 9.3 Native libs **[Proposed]**
Prebuilt `libraphp` per architecture is stored under `~/.local/share/rlm/libs/<name>/<version>/`. `rlm.lock` records versions and sha256. The runtime loads native libs only from a verified store path and never relies on `LD_LIBRARY_PATH`.

### 9.4 PHP provisioning **[Decided, O3]**
Option A: use the system PHP and let `rlm doctor` report what is missing (this machine needed `php-ffi` installed first). Option B: download a managed static-php-cli PHP build into the lib store so versions are reproducible. Default: B, with A as an opt-out. `rlm` itself is written in PHP and distributed as a standalone binary, bootstrapped from a prebuilt build. **[Verified, P0.5]** A static-php-cli build of PHP 8.5.12 with the needed extensions succeeded on this machine (10.2), so a managed build is feasible. The minimum glibc is still to be chosen (R23, O5).

### 9.5 Dev experience
`rlm dev`: file watcher, worker-only restart, IPC trace (`RAPHP_IPC_TRACE=1`), log aggregation in the terminal.

---

## 10. Packaging and standalone builds

### 10.1 Pipeline **[Proposed]**
`sources → composer install --no-dev → phar → append to micro.sfx → binary`, plus the shim either embedded or shipped beside it. Ini defaults can be baked into the build (`spc micro:combine -I "key=value"`), but none is needed for FFI (10.2).

### 10.2 Constraints and Phase 0 findings
- **glibc, not musl.** Fully static musl builds cannot `dlopen`, so FFI could not load the shim or system libraries. A glibc dynamic Spc build with the `ffi` extension is needed. static-php-cli documents that musl static builds cannot use `ffi`. **[Verified, P0.5 (R3), with a condition]** static-php-cli 2.8.6 with `SPC_TARGET=native-native-gnu.2.17` built PHP 8.5.12 CLI (21.0 MB) and `micro.sfx` (18.9 MB), dynamically linked against glibc only, with `ffi`, `pcntl`, `posix`, `sockets`, `mbstring`, `openssl`, `zlib`, `sodium`, `phar` (plus opcache). The trailing number in the target is the glibc compatibility floor of the PHP binary. The condition is the shim's own glibc floor (7.9, R23). Zig 0.17.1 and pkg-config are installed automatically into the work directory. Build times on this machine: sources 5.5 min, libffi 7.3 min (one-time Zig cache warm-up), zlib 10 s, openssl 9.8 min, libsodium 2.7 min, and compile of 616 objects at about 34 objects per minute. The PHP configure duration is unknown, because the machine hibernated during the build.
- **Self re-exec.** Inside a micro binary `PHP_BINARY` is **empty**. `readlink('/proc/self/exe')` returns the binary itself and works even when it is launched through `PATH` from another directory. The master re-executed itself as a worker with fd 3 inherited: same binary, different pid, role dispatch worked, the worker's `prctl` returned 0, and the worker saw EOF when the master closed its end and exited 0. A phar appended to `micro.sfx` runs. A single-file app was 19.5 MB (18.9 MB micro plus 0.66 MB phar including the shim). **[Verified, P0.6 (R4)]**
- **Embedded shim.** Storing the shim `.so` inside the phar, writing it to `memfd_create` and loading it with `dlopen("/proc/self/fd/N")` works inside micro for the headless shim. **[Verified, P0.7]** The windowed shim was verified under the glibc CLI PHP, but **not** through `memfd` inside micro. **[Unverified]** The fallback is a sidecar directory next to the binary.
- **`ffi.enable`.** PHP's own default is `preload`, and `FFI::cdef` is **allowed** in plain CLI with that default, both with the system PHP and with the new build, via `-r` and via a file, and also in the micro SAPI. Spike 1's `-d ffi.enable=1` was not needed. Baking `ffi.enable=1` into a micro build (`spc micro:combine -I "ffi.enable=1"`) works but is not needed for FFI. **[Verified, correction of an earlier wrong claim that `preload` blocks FFI in plain CLI]**
- **`FFI::new()`.** In PHP 8.5, calling `FFI::new()` statically is deprecated. Use the instance form `$ffi->new(...)`. The deprecation was hidden on the system PHP because `display_errors` is off and `error_reporting` excludes `E_DEPRECATED`. **[Verified, F11]**
- **Raw `proc_open`, array command, `/proc/self/exe`.** See 5.1 and 5.6 (D18, D19).

### 10.3 Output layouts
Single file (shim embedded) or a bundle directory (binary plus `lib/`). The size of either is measured in Phase 6 rather than estimated here.

---

## 11. Platform support

| Platform | Status |
|---|---|
| Linux x86_64, glibc, Wayland and X11 | **MVP.** Dev environment: Fedora 44, GNOME 50.5 on Wayland, host glibc 2.43, PHP 8.5.12 (NTS), Rust 1.95. The winit loop was verified on a non-main thread under both Wayland and X11 (P0.1). **[Verified]** |
| Linux aarch64 | Later |
| Minimum glibc version for distributed binaries | Deferred to Phase 6, to be chosen from a test build (O5). Two inputs set the floor: the `SPC_TARGET` glibc suffix for the PHP binary (10.2) and the glibc the Rust shim is built against (7.9, R23). |
| Windows | Later. No native named-pipe server in PHP, so this needs FFI to `CreateNamedPipe` or a loopback TCP fallback. |
| macOS | Later. Requires a different threading design (7.2). |

---

## 12. Security model

- **Trust boundary:** same-user processes on one machine. No network exposure.
- **Workers are not sandboxed** in the MVP. A future option is Landlock or seccomp. Parked (`IMPLEMENTATION_PLAN.md` section 12).
- **FFI is unsafe by nature.** A bug there can crash or corrupt the process. All FFI code lives in dedicated `Ffi` namespaces, each behind a typed interface. The UI shim binding lives in `Raphp\Ui\Ffi` (part of `raphp/ui`). The runtime's libc calls (`prctl` in 5.6, and `mmap` for the shm lane if used) live in a separate runtime-side namespace, chosen in Phase 2, that does not depend on `raphp/ui`, so headless mode (3.3) can use it without loading the UI package. **[Proposed]**
- **Untrusted input:** JSON size and depth limits, strict UTF-8, no `unserialize` on IPC data.
- **Supply chain:** `rlm` verifies checksums for native libs and writes `rlm.lock`.

---

## 13. Testing strategy

| Layer | Tooling |
|---|---|
| PHP unit | PHPUnit: codec round-trip, partial reads, malformed JSON, RPC timeout and cancel |
| Rust unit | `cargo test`: command parser, coalescing, layout |
| Protocol conformance | Golden NDJSON files shared by PHP and Rust tests |
| Integration | Spawn real workers, `SIGKILL` them, assert restart and clean failures |
| Shim via FFI | Load the shim in headless mode, send commands, assert events |
| GUI smoke | A virtual compositor in CI **[Decided, O4]** |
| Binary | Build, `ldd`, run in a minimal container with no PHP |
| Soak | 10k+ IPC round trips and sustained events, watching `memory_get_usage()` on both sides. **Headless soak done in P2.13** (`tools/bench/bench.php`, `docs/BENCHMARKS.md`): 50,000 round trips plus bursts, tolerance 4 MiB resident and 256 KiB `memory_get_usage()` per side after warm-up (D43, proposed). The UI-event workload is measured in P4.8. |
| Static analysis | PHPStan strict, `clippy` |

---

## 14. Observability and debugging

- Structured logs. Worker stdout/stderr and log events are aggregated at the master with the worker name and a level.
- Envelope may carry an optional `trace` id propagated through calls. **[Proposed]**
- `RAPHP_IPC_TRACE=1` logs every IPC message.
- `RAPHP_LOG_LEVEL` sets the log level: a PSR-3 level name, any case, default `info`. Logs are one line per record on stderr with UTC timestamps (4.9, 9.2).
- Debuggers: Xdebug with multiple processes needs documented setup.
- Crash reports: master records worker exit status and the last N log lines. How the exit status is collected and how worker stdout and stderr are drained is not yet specified (R18).

---

## 15. Decision log

| ID | Decision | Status | Evidence / note |
|---|---|---|---|
| D1 | Revolt + Fibers (Amp v3) in master and worker | Decided (O10) | Revolt 1.0.9 installs and runs here |
| D2 | NDJSON over AF_UNIX for worker IPC | Decided | |
| D3 | Rust shim `libraphp` with C ABI | Decided | |
| D4 | Hybrid GUI, reading (i); native chrome option (a), GNOME first | Decided | P0.4: winit window, portal dialogs, in-window menus, optional tray (7.7) |
| D5 | Shim-owned UI thread, no C-to-PHP callbacks | Decided | Verified P0.1 for the windowed case (Wayland and X11); UI-thread panic policy is open (R14) |
| D6 | Events: in-memory queue + `eventfd`, drained with `raphp_poll` | **Verified** | Appendix A |
| D7 | Linux first | Decided | |
| D8 | `rlm` replaces the earlier `run` command idea (it was `niphy run`, then `nlm`) | Decided | |
| D9 | Composer under `rlm` for PHP packages; constructor injection, no facades | Decided (O9) | |
| D10 | Socket-pair inheritance as primary IPC channel | Decided (O1) | Verified P0.2; requires `socket_create_pair` with `SOCK_CLOEXEC` |
| D11 | JSON for shim commands in the MVP | Proposed | Appendix A: ~40 µs per 200-command batch |
| D12 | Accessibility later, widget ids and roles from day one | Decided | |
| D13 | PHP community conventions (PER-CS, PSRs, strict types), PHPStan strict, PHPUnit | Decided (G8, O11) | The formatter was decided in P1.1 (D25). Exact versions fixed in P1.3: PHPUnit 13.4.6, PHPStan 2.3.5 (strict-rules 2.1.1, deprecation-rules 2.0.5, disallowed-calls 4.15.0), PHP-CS-Fixer 3.95.28 |
| D14 | No Node-style APIs, no promise chaining, no fluent cascading | Decided | |
| D15 | MIT license, semantic versioning | Decided (O8) | |
| D16 | Uncaught errors: master logs and shuts down non-zero; worker exits non-zero and is restarted | Decided (O12) | |
| D17 | Middleware: Amp-native for HTTP, one PSR-15-shaped contract for Raphp RPC, PSR-15 bridge only if needed | Decided (O13) | |
| D18 | Workers are spawned with raw `proc_open` and an array command, never `amphp/process` | Source-checked (F18) | Source read in `amphp/process`, no code run. The array command is used in spikes P0.2, P0.3 and P0.6 (5.1) |
| D19 | Self re-exec uses `readlink('/proc/self/exe')`; `PHP_BINARY` is empty in micro | Verified | P0.6 (10.2) |
| D20 | Use the instance form `$ffi->new()`; `ffi.enable=preload` already permits FFI in CLI and micro | Verified | F11, F12 (10.2) |
| D21 | Build the Rust shim against an old glibc (for example `cargo-zigbuild` with a `.2.17` target, or an old-distro container) so the shim does not raise the distributable floor | Decided (approved by you on 2026-11-06) | F14, R23 (7.9). The mechanism is chosen and verified in P3.1 and P6.1 |
| D22 | `niphy` **was** the code name, until the rename on 2026-11-06 (D28). The public name is **Raphp** (D27). Nothing was ever published under the code name. | Decided | History: the code name appeared in the `Niphy\` namespace, `libniphy`, `niphy.json`, `NIPHY_*` variables, the C symbol prefix, and the acronym `nlm` (niphy libs manager) |
| D23 | **PHP floor: 8.5 or newer** (`php >= 8.5`). CI runs on PHP 8.5 for now, and newer versions are added as they ship. PHPUnit 13 (needs PHP 8.5.12 or newer) is therefore usable, and the floor equals the managed PHP build. | Decided (P1.1) | You confirmed "8.5+". PHP 8.3 active support ended 31 Dec 2025, 8.4 ends 31 Dec 2026, 8.5 ends 31 Dec 2027 (php.net, 2026-11-06) |
| D24 | **Container: PHP-DI 7.** The core depends only on `psr/container`. | Decided (P1.1) | PHP-DI 7.1.1 requires PHP 8.0 or newer. Autowiring fits constructor injection (O9). Verified in P1.5 with 7.1.1: its circular-dependency guard is not Fiber-aware, so construction must not suspend (R24). If that rule becomes too limiting, revisit D24 (alternatives: resolve services before the loop starts, or a Fiber-aware wrapper) |
| D25 | **Formatter: PHP-CS-Fixer** with the `@PER-CS3.0` rule set, pinned so a tool update cannot silently change the rules. | Decided (P1.1) | The rule sets `@PER-CS`, `@PER-CS1.0` and `@PER-CS3.0` exist in PHP-CS-Fixer 3.95.28. The `@PER-CS3.0` name is confirmed there; the exact pin is checked in P1.3 |
| D26 | **Copyright holder: Codizium.** The MIT `LICENSE` reads "Copyright (c) 2026 Codizium". | Decided (P1.1) | The composer vendor name stays a placeholder (`raphp/raphp`), per D28 |
| D27 | **Public name: Raphp** (Ralph PHP, pronounced "raf-pee"). `niphy` was the code name until the rename (D22, D28). | Decided | Chosen by you. On 2026-11-06 `raphp` is free on crates.io, npm and Packagist; a GitHub user named `raphp` already exists and is not yours (your account is `ralphxp`, the repository is `ralphxp/raphp`; Composer vendor names are independent of GitHub handles). The acronym for the CLI was decided with the rename: `rlm` (Raphp libs manager), D28 |
| D28 | **Rename done:** code name `niphy` becomes Raphp. Namespace `Raphp\`, `libraphp`, `raphp.json`, `RAPHP_*` variables, C prefix `raphp_`, CLI `nlm` becomes `rlm` (Raphp libs manager), directory `nlm/` becomes `rlm/`, Composer placeholder `raphp/raphp`. Spike code under `spikes/` keeps the old names. | Decided | Owner accepted the lead's recommendation for the rename and for `rlm`. Date: 2026-11-06 |
| D29 | **Manifest and environment rules** (9.2): strict keys, name patterns, clean relative paths, limits (1 MiB file, worker pool 1 to 256, strings 1024 bytes, 50 problems shown), and the environment overrides `RAPHP_LOG_LEVEL`, `RAPHP_IPC_TRACE` and `RAPHP_UI_ENABLED`. | Decided (you, 2026-11-07: "approved, continue") | The limits are guards against accidents and are my numbers, not measured ones |
| D30 | **The log level is an enum** (`Raphp\Core\Logging\LogLevel`, the eight PSR-3 levels) held by `Config`. The logger writes UTC timestamps and takes its time from the PSR-20 clock. | Proposed (follows 4.3 and the PSR-20 choice) | The P1.5 review found that a string level broke the "enums instead of string constants" rule. Monolog 3.12.1 supports a clock and a timezone natively |
| D31 | **The native chrome is accepted as the chrome experiment showed it:** a winit client-side window (title bar, rounded corners, shadows), portal file dialogs, and the rest of option (a). The look is settled and is built in P3.6 and P3.12 without a further look review. | Decided (you, 2026-11-06: "great in human terms, implement it with no doubts") | Tray, dialog parenting and window responsiveness during a dialog remain implementation checks. They are not doubts about the look |
| D32 | **Error model:** an abstract `RaphpException` base; IPC errors are `RpcException` subclasses, one per code in 6.6, plus `ApplicationException` (`E_APP_[A-Z0-9_]+`); the wire code is `wireCode()`; an unknown code becomes `InternalErrorException` (the original is kept in `data`) and a malformed error object becomes `ProtocolException`. | Decided (you, 2026-11-07: "approved, continue") | The unknown-code rule is my choice: the design is silent on it. The `Exception` suffix on the class names follows common PHP practice |

| D33 | **Codec contract:** limits of line size, nesting depth (64) and arrays-and-objects count (200,000), checked on both encode and decode; the decoder is pull-based; a bad line is an `E_PROTOCOL` item and decoding continues; an oversize line (complete or unfinished) is fatal, reported once, and the decoder then discards all input; the encoder writes ASCII-only compact lines; shared golden files pin the format for the Rust side. | Decided (you, 2026-11-07: "approved, continue") | The fatal-oversize rule is my choice: after an oversize line the next boundary cannot be found safely. A malformed line is recoverable because its end is known. The 200,000 count is my number: it keeps a hostile line to roughly 80 MB, and is a constructor argument |
| D34 | **Kernel contract:** exit codes 0, 1, 130, 143 or the code given to `stop()`; hooks run last-registered first within one shared budget (default 5 s); a second signal during shutdown ends the process at once. | Decided (you, 2026-11-07: "approved, continue") | Follows O12 (log, shut down gracefully, non-zero exit). The 5 s budget and the 128-plus-signal codes are my choices |
| D35 | **Message rules:** the reading rules under 6.4 (exact kinds, exact types, positive ids, extra keys ignored, a response has exactly one of `result` or `error`) and the spec change that an `evt` has no `id`. | Decided (you, 2026-11-07: "approved, continue") | Ignoring extra keys and the positive-id rule are my choices. The `evt` id was a mistake in my first example, not a design decision |

| D36 | **Transport contract:** bytes only; `write()` waits (back-pressure); `end()` waits for pending writes and then refuses new ones; `close()` is immediate, cancels watchers first and frees the descriptor; only one read may wait. | Decided (you, 2026-11-07: "approved, continue") | `end()` waiting is my choice: Amp's `end()` loses queued writes (F39). The 64 KiB read chunk is not measured; P2.13 tunes it |
| D37 | **RPC peer contract:** one reader fiber that never waits on a write, one writer fiber over a bounded queue plus an equal reserve for what the reader must send (beyond it the connection is closed); limits of 64 calls in flight, 1024 waiting, 256 inbound, 256 queued messages; a given-up call keeps its slot until answered; a protocol violation closes the connection with no reply; handler errors other than `RpcException` become `E_INTERNAL` with a fixed message; `E_WORKER_CRASHED` for a vanished peer, `E_WORKER_UNAVAILABLE` for a local close or `bye`. | Decided (you, 2026-11-07: "approved, continue") | The four limits are my numbers, not measured (P2.13). "No reply before closing" is narrower than 6.4 says and is my choice until P2.7 |
| D38 | **Handshake and liveness:** `hello` first and required before anything else but `ping`, `pong` and `bye`; protocol version must be equal; a refused connection gets `bye` with `E_PROTOCOL: ...` and closes; a `bye` reason that starts with an error code is that error for the receiver; liveness is judged by bytes heard, not by `pong`, with defaults of 2 s and three misses set by the owner of the connection (off otherwise); a process that stalled itself does not blame its peer; `shutdown()` flushes a last `bye` within a 2 s timeout. | Decided (you, 2026-11-07: "approved, continue") | "Bytes heard, not pong" is my choice and the fix for R19. The 5 s handshake timeout and 2 s flush timeout are my numbers. Version equality (no negotiation) follows from "breaking changes bump proto" |
| D39 | **Attribute handlers:** methods are declared with `#[RpcMethod(name)]` and `#[Idempotent]`; parameters are filled by name and type with no conversion, unknown names are an error, and the three framework types are injected; services are taken from the container at boot; only methods the catalog marks idempotent are retried, and only for transient failures. | **Decided** (approved by you on 2026-11-07; implemented in P2.8) | "Unknown parameter names are an error" and "no conversion" are my choices (strict, so typos and wrong types show at once); the retry defaults (3 attempts, 0.1 s doubling) are my numbers |
| D40 | **Process spawner:** array command only, `--role=worker --name=<name> --parent-pid=<pid>`; the channel is descriptor 3 from a close-on-exec socket pair and the master closes the worker's end right after the start; stdin is `/dev/null`; stdout and stderr are always drained, logged by line and kept for 64 KiB; one owner (`WorkerHandle`) collects the exit status; `stop()` is SIGTERM then SIGKILL; the parent-death signal is requested in a runtime-side libc namespace separate from the UI package. | **Decided** (approved by you on 2026-11-07; implemented in P2.9) | The 64 KiB kept, the 8 KiB line cut, the one-second output grace and the logging levels are my numbers. R17 (a live multi-threaded shim in the master) is NOT resolved here: it stays with P3.5 |
| D41 | **Supervision:** states and events as in 5.3 to 5.5; a sliding-window restart policy (5 restarts in 60 s, waits of 100 ms doubling to 10 s); a hung worker (heartbeat lost) is killed and restarted; `stop()` drains in-flight calls, then `bye`, SIGTERM and SIGKILL with an event at each stage; recycling after a call count or a memory size drains first and counts no crash; events are PSR-14 `WorkerEvent` objects. | **Decided** (approved by you on 2026-11-07; implemented in P2.10) | Every number (5 and 60 s, 100 ms and 10 s, the 5 s, 2 s, 10 s and 30 s timeouts) is mine and not measured: P2.13 checks them. Recycling by memory reads the worker's resident memory from `/proc`, so it is Linux only |
| D42 | **Worker entry:** a strict worker command line; the runtime refuses to run without the parent-death safeguard; SIGINT is ignored in a worker and no SIGTERM handler is installed; descriptor 3 must be a socket; exit codes 0 (orderly end), 1 (failure), 2 (usage), 3 (no channel), 4 (wrong parent), 5 (no safeguard). | **Decided** (approved by you on 2026-11-07; implemented in P2.11) | Ignoring SIGINT is my choice: it makes Ctrl-C a master-only event, and the headless example (P2.12) relies on it. The exit-code numbers other than 4 are mine |
| D43 | **Memory tolerance and master baseline:** steady-state growth after warm-up of at most 4 MiB resident and 256 KiB `memory_get_usage()` per 45,000 calls on each side; after a burst, repeating it must not raise resident memory by more than the same 4 MiB (the plateau rule); the headless master's baseline is about 56 MiB resident, 15.5 MiB private, 1.2 MiB `memory_get_usage()` at boot. Later phases are held to these, and P4.8 adds the UI-event workload. | **Decided** (approved by you on 2026-11-07; implemented in P2.13) | The numbers are mine, chosen before the first run; the measured growth was +144 KiB and +7 KiB, far inside them, so they may be too loose to catch a slow leak: tighten them if you prefer |
| D44 | **Filesystem:** an interface with a cooperative default (chunked reads and writes, the event loop gets a turn after each 2 ms slice, cancellation at the same points); no `amphp/file`; no file watching; `read()` for small files, `openRead()` for large ones. | **Decided** (your choice on 2026-11-07; implemented and measured in P2B.1) | The 64 KiB chunk and 2 ms slice are my numbers; the slice was measured (stall about one slice, no speed lost against 10 ms or 50 ms) |
| D45 | **Application commands:** `ProcessRunner` over `amphp/process` for commands only (workers stay on the spawner, D18); a command is a list; each runs in its own session and process group under `setsid` (so Linux with util-linux); stopping means SIGTERM to the group, a grace, then SIGKILL; output bounded and drained from both streams; commands are not tied to the master's life. | **Decided** (approved by you on 2026-11-07; implemented in P2B.2) | The 16 MiB output limit, the 2 s grace and the 1 s output grace are my numbers. Using `setsid` means a command is no longer in the terminal's process group |
| D46 | **Accelerators:** Raphp selects no event-loop driver itself (Revolt does, by its documented order, or `REVOLT_DRIVER`); a detector reports what is loaded and running; signals only through `EventLoop::onSignal()`; ext-eio is detected but unused; the suite passes under ext-uv and ext-ev. | Proposed (verified in P2B.4, needs your approval) | ext-event and the `with accelerator` performance were not measured, only correctness. The rule on signals is the one real constraint found |
| D47 | **Shim failure and shutdown (R14):** the build never uses `panic = abort`; every ABI function runs under `catch_unwind`; a panic in a call or on the UI thread, or a UI thread that ends by itself, poisons the shim for good; shutdown waits a bounded time (3 s) and detaches a stuck thread. **Proposed for the master side:** a poisoned shim is a fatal error of the UI (log, orderly stop with the error exit code, workers get `bye`) unless the application asked to continue headless; the shim's shutdown hook runs first, the supervisor's after. | Proposed (the shim side is implemented and tested in P3.1; the master side is built in P3.5 and checked in P4.5; needs your approval) | The 3 s limit is my number. Whether an application may continue headless after a UI failure, and the order, are your call |
| D48 | **Shim event queue (R16, O7):** one JSON line per event, at most 1 MiB; discrete events never merged or dropped; continuous events carry state (never deltas) and the latest replaces an earlier one only if no discrete event is between them; past 1 MiB queued older continuous events are removed, past 4 MiB the producer waits for PHP; a poll never skips or splits, and a too-small buffer leaves the event at the head and says how large a buffer it needs; an event over the limit becomes a small `event.too_large` event; a failed shim queues one `shim.failed` event and accepts no more. | Proposed (implemented and tested in P3.2, needs your approval) | The 1 MiB, 1 MiB and 4 MiB are my numbers and unmeasured. Back-pressure freezes the window when PHP stops polling: that is O7, your earlier decision |
| D49 | **Shim command protocol and ordering contract (R15):** a batch is an array or `{batch, commands}`; strict commands (an unknown field is an error); ids in two series that only go up and are never reused; `window.destroy` and `widget.destroy` destroy what they own; failed commands change nothing and do not stop the batch; every error names batch and index; `batch.done` ends every object batch and marks when it took effect; the diagnostics already include a batch when its done event is read; `app.quit` ends the loop in order and later sends are `CLOSED`; `base_rev` and `stale_text` protect typed text. Widget types and properties as in the reference (the MVP set). | Proposed (implemented and tested in P3.3, needs your approval) | The limits (8 MiB, 100,000 commands, 100,000 widgets, 64 windows, 64 levels, 64 KiB text) are my numbers. The strictness (an unknown field is refused) and the rule that ids never go back are choices you may want different |
| D50 | **Headless backend:** the default backend until P3.6; a manual clock moved only by `raphp_headless_advance` (also a barrier and the one call that waits for the UI thread); RGBA pictures of size times `scale` rounded up within a budget (a refused window or resize is a `limit` command error that changes nothing); a frame only for a real change, one per window per interval, each window with its own pace; `redraws_requested` exact at `batch.done`, `frames_drawn` after the barrier; a strict configuration. | Proposed (implemented and tested in P3.4, needs your approval) | The 60 Hz default, the 256 MiB budget and the 10 s wait are my numbers. Whether the default backend should stay headless once the window backend exists is a question for P3.6 |

The questions table in section 17 is the record for O2, O3, O4, O5 and O7; they have no separate D rows.

---

## 16. Risk register

| ID | Risk | Likelihood / impact | Mitigation | Status |
|---|---|---|---|---|
| R1 | winit loop on a non-main thread misbehaves on Wayland or X11 | Medium / High | Spike P0.1 | **Verified** (Wayland and X11). Caveats: keyboard and click events untested; redraw counts are not a rendering benchmark |
| R2 | Native menus and tray conflict with a winit window (GTK3 vs GTK4) | Medium / Medium | Spike P0.4 | **Decided: option (a)**, GNOME first (winit window, portal dialogs, in-window menus, optional tray). File dialog verified end to end (unparented); tray and dialog parenting deferred to P3.12a to P3.12c (clipboard is built in P3.11b) |
| R3 | glibc static PHP + FFI + shim embedding does not work as assumed | Medium / High | Spikes P0.5 and P0.7 | **Verified, with the R23 condition** (shim glibc floor). Windowed shim through `memfd` inside micro is untested |
| R4 | Self re-exec fails inside micro | Low / High | Spike P0.6 | **Verified** (use `/proc/self/exe`; `PHP_BINARY` is empty) |
| R5 | FFI callbacks are fragile | n/a | Eliminated by design (no callbacks) | **Mitigated** |
| R6 | Event loss under PHP stalls | n/a | Queue + eventfd | **Verified** |
| R7 | FFI call overhead hurts | Low / Medium | Batching; measured ~125–240 ns/call | **Verified low** |
| R8 | IME and text input on a custom-drawn UI | High / Medium | Dedicated spike (P3.11a) before TextInput is finalized | Unverified |
| R9 | PHP memory growth in long-lived processes | Medium / Medium | Worker recycling (does not cover the master, see R20) | Proposed |
| R10 | "All tiers" scope creep | High / High | Tiered milestones, review gate per phase | Proposed |
| R11 | Amp API churn or version drift | Low / Medium | Pin versions, interface layer over Amp | Proposed |
| R12 | A CPU-bound worker misses heartbeats and is wrongly killed | Medium / Medium | Offload CPU work; tunable threshold | Proposed |
| R13 | Distributing a Rust `.so` complicates installs | Medium / Medium | `rlm` fetch + checksum, embedded fallback | Proposed |
| R14 | A panic or exit on the shim's own UI thread was not covered by the panic rule, and the shim had no place in the shutdown order (7.3, 5.5) | Medium / Medium | Contract written and tested in P3.1 (`docs/protocol/shim-abi.md`): no `panic = abort` (tested), the UI thread's body under `catch_unwind` poisons the shim instead, bounded shutdown, the shim's hook runs first. The master's reaction and the order are D47 (proposed), built in P3.5, checked in P4.5. Cannot be contained: `exit()`/`abort()` or a fatal signal in native code | **Resolved** (contract and tests); master side pending |
| R15 | No ordering contract between the command queue and the event queue (stale events for removed widgets, command errors not tied to their batch, re-renders racing typed text) (7.4) | Medium / Medium | Contract written and tested in P3.3 (`docs/protocol/shim-commands.md`): batch ids and `batch.done`, ids never reused, no events about destroyed widgets, errors with batch and index, `base_rev` for text. P4.2, P4.3 and P4.6 test against it | **Resolved** (contract and shim side); the PHP side is tested in Phase 4 |
| R16 | An event larger than the `raphp_poll` buffer could stall all UI input if the drain loop left on the "too small" answer (7.3) | Medium / Low | Contract written and tested in P3.2 (`docs/protocol/shim-abi.md`): the event stays at the head, `raphp_next_event_size` gives the size, the wake-up is raised again, events over 1 MiB are replaced by a small error event | **Resolved** |
| R17 | `proc_open` forks while a live multi-threaded shim exists in the master, which also holds descriptors Raphp did not create (display, D-Bus, portals). Never tested | Not assessed / Not assessed | None yet. Needs evidence with a real window (Phase 3) before a rule is written | **Open** |
| R18 | No rule for reaping workers (exit status, non-blocking collection) or for draining their stdout and stderr (5.1, 5.3, 14) | Medium / Low | Contract written in 5.1: output always drained, one owner collects the exit status, no zombie, one second of output grace. Tested in P2.9 | **Resolved** |
| R19 | `ping`, `pong` and `cancel` can be starved behind a very large message on the single ordered stream, and the receiver's behavior at the size cap is unspecified (6.3, 6.8) | Medium / Low | Contract written in 6.8: delay bounded by the transfer, liveness judged by bytes heard not by `pong`, `bye` at the size cap. Tested in P2.7. Measured in P2.13: a call behind a 7 MiB message waits about 0.15 s | **Resolved** |
| R20 | The master cannot be recycled yet holds the most long-lived state (all view code, 8.4); R9's mitigation does not cover it | Not assessed / Not assessed | P2.13 recorded the headless master's baseline and a growth tolerance and plateau rule (D43, proposed). The UI-event workload and the master's budget with a GUI are P4.8 | **Open** (headless part measured) |
| R21 | Hot reload (5.7) covers worker code only, while most edits land in master code; reload versus crash accounting is undefined | Not assessed / Not assessed | None yet. Phase 5 (`rlm dev`) must define it | **Open** |
| R22 | `SO_PEERCRED` is not exposed by PHP's sockets extension here, so the secondary path-socket authentication is unspecified (6.2, 6.10) | Not assessed / Not assessed | The secondary path is not needed for the MVP, and the socket-pair primary has no path to attack | **Open** |
| R23 | The shim's glibc floor (2.34 headless, 2.43 windowed) is higher than PHP's (2.17), so the shim sets the distributable floor | Not assessed / Not assessed | D21: build the shim against an old glibc. `make shim` now prints the figure for each build (P3.1): GLIBC_2.34 on this machine for the release build of the P3.1 shim. The mechanism for an older glibc (cargo-zigbuild or a container) is not chosen or verified yet (P6.1). Not yet run on any other distro | **Open** (D21 decided; the shim build recipe is not verified yet) |
| R24 | PHP-DI's circular-dependency guard is per container, not per Fiber, so a service whose construction suspends makes a second Fiber fail with a misleading "Circular dependency" error (4.9) | Not assessed / Not assessed | Rule: construct services without suspending, and initialise asynchronously after the container is built. A characterisation test in `ContainerFactoryTest` fails if PHP-DI changes. Revisit D24 if the rule is too limiting | **Verified** (7.1.1), rule adopted |
| R25 | A manifest that repeats a JSON key silently keeps the last value (`json_decode` behaviour) (9.2) | Not assessed / Not assessed | Documented in 9.2. Detecting it needs a custom JSON parser, which is not planned | **Open**, accepted for now |
| R26 | Every queue and limit in the RPC layer counts messages, not bytes (64 in flight, 1,024 waiting, 256 outbound, 256 inbound), so the bytes held grow with the number of large calls in flight. Measured: about 4 MiB of master memory per 4 MiB call in flight, linear, up to 88 MiB at 16; at the defaults 64 calls of 8 MiB would hold about 512 MiB (6.3, `docs/BENCHMARKS.md`) | Low to Medium / Medium | None yet. A byte budget for the outbound queue and for calls in flight is the obvious fix; a design decision, not a tuning | **Open** |

---

## 17. Open questions and accepted recommendations

All numbered questions are resolved or deferred (O5 to Phase 6, the exact O11 tool versions to P1.3). No item is waiting on your approval: D21 (building the shim against an old glibc) was approved on 2026-11-06. Accepted recommendations are recorded as decisions in the sections they affect. O8 is MIT with semantic versioning. O5 is deferred to Phase 6 by design. The Phase 0 spikes confirmed O1 (socket pair, with the `SOCK_CLOEXEC` condition) and O3 (a managed static PHP build is feasible), and settled the native-chrome question as option (a), GNOME first.

The design gaps found in the audit are not numbered questions. They are recorded as Open risks R14 to R22 in section 16, each with the phase that must resolve it. The one proposed decision, D21 (build the shim against an old glibc, see R23), was approved on 2026-11-06.

| ID | Question | Resolution | Status |
|---|---|---|---|
| O1 | IPC connection mechanism | Socket pair inherited by the child is primary, created with `socket_create_pair` and `SOCK_CLOEXEC` (verified, P0.2). Path sockets with 0700/0600 modes and a token are secondary (6.2). A `SO_PEERCRED` check is an optional add-on and is open (R22). | **Accepted** |
| O2 | Where view and handlers run | In the master. Workers hold logic and data (8.4). | **Accepted** |
| O3 | PHP provisioning | `rlm` manages a PHP build by default, with system PHP as an opt-out (9.4). A static-php-cli build was verified in P0.5. | **Accepted** |
| O4 | Headless shim backend and virtual compositor for CI | Yes (7.8, 13). | **Accepted** |
| O5 | Minimum glibc | Deferred to Phase 6, to be chosen from a test build. Two inputs set the floor: the `SPC_TARGET` suffix for PHP and the glibc the shim is built against (11, R23, D21). | **Accepted (deferred)** |
| O7 | Event queue overflow policy | Coalesce harder, then back-pressure, never drop discrete events (7.5). | **Accepted** |
| O8 | License and versioning scheme | MIT license, semantic versioning. | **Decided** |
| O9 | Constructor injection, PSR-11 container, no facades | Yes (4.3). | **Accepted** |
| O10 | Amp v3 Fiber style, no promise chaining | Yes (4.3, 4.7). | **Accepted** |
| O11 | PER-CS, PHPStan strict, PHPUnit, and PHP-CS-Fixer or PHP_CodeSniffer | Yes. The formatter is PHP-CS-Fixer (D25). Exact tool versions and the PHPStan configuration are fixed in P1.3. | **Accepted** |
| O12 | Uncaught error policy | Master: log, graceful shutdown, non-zero exit. Worker: exit non-zero so the supervisor restarts it (4.7). | **Accepted** |
| O13 | Middleware: Amp-native for HTTP, one Raphp PSR-15-shaped contract for RPC, PSR-15 bridge only if needed | Yes (4.8). | **Accepted** |

---

## Appendix A: Spike 1, stage A evidence

**What it tested:** a Rust `cdylib` with a UI thread, a command queue from PHP, and events returned to PHP's Revolt loop with no C-to-PHP callbacks. It is headless (no window). Code is under `spikes/01-shim-roundtrip/` and is throwaway.

**Environment:** Fedora 44, PHP 8.5.12 NTS, Revolt 1.0.9, Rust 1.95. Single machine, a desktop session running, not a quiet benchmark host. Treat numbers as orders of magnitude.

| Measurement | Result | Notes |
|---|---|---|
| Sequential ping to pong round trip (5000, queue + eventfd design) | p50 **14–56 µs** across four runs; p99 37 µs–1.7 ms | High variance: cross-thread wake-ups are scheduler dependent. Best case equals the earlier pipe design. |
| Batch burst, PHP reading concurrently (1000 / 5000 / 20000 events) | **0 lost** | Races with the reader, so it proves little by itself. |
| 20,000 events while PHP is **blocked 300 ms** | Pipe-as-buffer design: **2,416 received, 17,584 dropped (88%)**. Queue + eventfd design: **20,000 received, 0 lost**, peak queue 540,000 bytes | The deciding result for D6. |
| Pure FFI call cost (`abs()`, no syscall) | **~124–242 ns/call** (two runs) | An earlier ~1.2 µs figure was `getpid`, a real syscall, and was mislabeled. |
| `niphy_send("[]")` (spike code, predates the rename) | ~1.2–1.8 µs/call | Includes allocation, channel send and waking the UI thread. |
| `json_encode` of a 200-command batch | ~36–71 µs (all reruns; see `SPIKE_REPORT.md` section 3) | Supports D11 (JSON is fine at first). |
| UI-thread-originated events waking Revolt (20 ms ticks, 1 s) | 49–50 ticks, mean gap 20.0 ms, max 20.2–21.7 ms | PHP's own timer kept firing normally alongside. |
| Shim ready after init | 0.55–0.84 ms | |
| Shutdown (join UI thread) | 4–5 ms | |

**What this does not prove:** nothing here involved a real window, a compositor, input events, drawing, or any non-main-thread windowing behavior. Those were covered afterwards by spike P0.1 (Appendix C).

---

## Appendix B: End-to-end flow example (target behavior)

User clicks "Scan":

1. Compositor delivers the click to the shim's UI thread (winit).
2. The shim queues `{"ev":"widget.click","id":12}` and writes the eventfd.
3. The master's Revolt loop wakes, clears the eventfd, drains `raphp_poll`.
4. The UI layer finds widget 12's handler and runs it in a Fiber: `busy = true`.
5. State change triggers re-render and diff. One command batch (`widget.set` for the progress bar) goes to the shim in one FFI call.
6. The handler calls `worker->call('files.scan', ...)`: a `req` envelope goes over the worker's socket.
7. The worker's loop runs the scan, sending `evt` progress messages with `ref` set to the request id.
8. Each progress event reaches the master, updates state, and produces another small command batch. The shim redraws on its own thread.
9. The worker sends the final `res`. The handler sets `busy = false` and stores the result. One more batch updates the label.
10. At no point did PHP block the shim's UI thread, and a worker crash at step 7 would fail the call with `E_WORKER_CRASHED` while the window stays open.

---

## Appendix C: Phase 0 results (summary)

Detail, commands and raw numbers are in `docs/SPIKE_REPORT.md`. Everything here was run on one machine (Fedora 44, GNOME 50.5 Wayland, host glibc 2.43, PHP 8.5.12), not a quiet benchmark host. Treat numbers as orders of magnitude.

| Spike | Result | Used in |
|---|---|---|
| P0.0 shim events | In-memory queue plus `eventfd` lost 0 of 20,000 events when PHP stalled 300 ms. Pipe-as-buffer lost 88%. FFI call about 125 to 240 ns. Round trip p50 14 to 56 µs. | 7.5, D6, R6, R7 |
| P0.1 windowed shim (R1) | **Passes.** winit loop on a non-main thread under Wayland and X11, window created, UI thread kept redrawing while PHP was blocked, pointer events delivered and coalesced. 1000 round trips p50 65 to 114 µs. Keyboard and click events untested. Redraw counts are unthrottled CPU fills. | 7.2, 7.6, R1 |
| P0.2 socket pair (F1, F2, F4, F5) | Socket pair as fd 3 through `proc_open` works only with `socket_create_pair(AF_UNIX, SOCK_STREAM \| SOCK_CLOEXEC, ...)` plus `socket_export_stream`. `stream_socket_pair` leaks descriptors and the child never sees EOF. A 16 MiB write to a stalled child worked with a non-blocking writer. 2000 round trips p50 about 106 to 265 µs. One worker only. Revolt watchers must be cancelled before a stream is closed. | 5.1, 6.2, D10 |
| P0.3 parent death (F3) | `prctl(PR_SET_PDEATHSIG, SIGTERM)` via FFI killed the child 5 to 9 ms after a master SIGKILL. Without it (control test) the child was still alive at the end of the 3 s observation window. A `getppid()` guard handles the startup race. | 5.6 |
| P0.4 native chrome (F6, F7) | GNOME 50.5 Wayland: no server-side decorations, no `xdg_popup` in winit 0.30.13, no `data-control` (use `smithay-clipboard`), no StatusNotifierWatcher unless AppIndicator is enabled, FileChooser portal and text-input v3 available. Option (a) decided. A real portal file dialog and the close button were verified end to end by the chrome experiment (dialog unparented); the look was accepted (D31); the tray and dialog parenting are not yet exercised (P3.12). | 7.7, R2 |
| P0.5 static PHP (R3) | **Passes with a condition.** static-php-cli 2.8.6, `SPC_TARGET=native-native-gnu.2.17`: PHP 8.5.12 CLI (21.0 MB) and `micro.sfx` (18.9 MB), glibc only, with `ffi`. The condition is R23. | 10.2, R3 |
| F12, F11 | `ffi.enable=preload` already allows `FFI::cdef` in CLI and micro. In PHP 8.5, use `$ffi->new()`, not the static form. | 10.2, D20 |
| P0.6 self re-exec (R4) | **Passes.** `PHP_BINARY` is empty in micro. `/proc/self/exe` works. Master re-executed itself as a worker with fd 3. Single-file app 19.5 MB. | 3.4, 10.2, D19, R4 |
| P0.7 embedded shim | **Passes (headless).** Shim `.so` in the phar, `memfd_create`, `dlopen` via `/proc/self/fd/N` works inside micro. The windowed shim was not tested through `memfd` inside micro. | 10.2 |
| F14 glibc floors | PHP, `micro.sfx` and the app need up to 2.17. The Rust shim needs up to 2.34 (headless) and 2.43 (windowed). Not run on another distro. | 7.9, R23, D21 |
| F18 amphp/process | Read in source: wraps commands in a `/bin/sh` script and uses fd 3 for its own pid pipe. Workers use raw `proc_open`. | 5.1, D18 |
| F19 project mount | The project directory is on a root-owned mount with 777 permissions. git printed "detected dubious ownership" for repos cloned there. The same error hits `git init`'d repositories and Composer (which probes git for the project version). Git ignores `safe.directory` in a repository's own config, so the fix is a global entry or `git -c safe.directory=...`. Composer works on the mount: it installs executables as regular proxy files, not symlinks, and they run (checked in P1.2). | P1.2 |
