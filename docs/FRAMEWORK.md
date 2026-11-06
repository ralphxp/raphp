# Niphy Framework Document

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
Niphy is a long-running, event-driven runtime and application framework for PHP, with an optional native GUI. It is written the way the PHP community writes PHP (section 4.3).

Node.js is only an analogy for the *direction*: a persistent process, an event loop, non-blocking I/O, workers for parallelism, one CLI, single-file executables. Niphy does **not** copy Node's API shapes, naming or code style. **[Decided]**

Niphy provides:

1. **An async runtime.** An event loop, a standard library (filesystem, sockets, HTTP, processes, workers), and a single CLI. It is useful with no GUI at all.
2. **An optional native GUI framework.** The UI is drawn and managed through FFI. Niphy does not render HTML and does not embed a browser engine.
3. **Standalone apps.** A finished app ships as a single binary that needs no PHP installed.

Architecturally, a **master** process owns the UI, **worker** processes run application logic, and **IPC** (Unix domain sockets carrying JSON) connects them.

### 1.2 Goals
- **G1.** Async-first runtime. Application code never blocks the event loop. **[Decided]**
- **G2.** Process isolation between UI and logic. A worker crash cannot close the window. **[Decided]**
- **G3.** Native GUI via FFI through a Rust shim library, with a hybrid design: native where the desktop provides it (file dialogs, clipboard, theme; GNOME has no server-side decorations, so winit draws the title bar), and custom-drawn content including menus (7.7). **[Decided]**
- **G4.** Standalone binaries. **[Decided]**
- **G5.** One front-door CLI, `nlm` (niphy libs manager). **[Decided]**
- **G6.** Linux first. **[Decided]**
- **G7.** Runtime scope is "all tiers", delivered in stages (section 4.2). **[Decided]**
- **G8.** Follow PHP community standards and idioms: PSRs, Composer, PER Coding Style, strict types, interfaces and dependency injection. No Node-style APIs. **[Decided]**
- **G9.** Licensed under the MIT License, with semantic versioning. **[Decided]**

### 1.3 Non-goals
- Niphy does not render HTML or ship a webview. A developer may *serve* HTML from the runtime (for example from an HTTP server), but that is the developer's code. **[Decided]**
- Niphy does not imitate Node.js APIs or style: no promise chaining, no fluent "cascading" method chains as the primary API, no copied naming. **[Decided]**
- No Windows or macOS support in the MVP. **[Decided]**
- No accessibility (screen readers) in the MVP. The design must not preclude it (section 8.10). **[Decided]**
- No custom PHP engine, no PHP fork, no custom event loop. **[Proposed]**
- No niphy-specific package registry. PHP packages come from Packagist. **[Proposed]**
- No network-exposed IPC. Everything stays on the local machine. **[Proposed]**

---

## 2. Glossary

| Term | Meaning |
|---|---|
| **Runtime** | The async part: event loop, standard library, process and IPC machinery. Works without a GUI. |
| **Master** | The process the user launches. It runs the Revolt loop and supervises workers. If the GUI is enabled it also owns the shim and the window. With no GUI it is simply the "main" process. |
| **Worker** | A child PHP process that runs application logic and async tasks. Spawned and supervised by the master. |
| **Shim** (`libniphy`) | A Rust shared library loaded into the master via FFI. It owns the UI thread, window, layout, drawing, and the native dialog, clipboard and theme integrations. |
| **IPC** | The master-to-worker channel: Unix domain stream sockets, one JSON object per line. |
| **Command** | A message from PHP to the shim ("create widget", "set text"). |
| **Event** | A message from the shim to PHP ("button clicked", "window resized"). |
| **`nlm`** | Niphy libs manager, the CLI. Initializes projects, manages PHP and native libraries, runs apps, builds binaries. |
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
  `app` / `nlm run`  ──▶ │   ├─ UI layer (PHP): widget tree, diff, command batcher               │
                         │   ├─ Supervisor: spawns, watches, restarts workers                    │
                         │   └─ IPC endpoints ───────────────────────────────┐                   │
                         │                                                   │                   │
                         │   FFI ◀──── commands (batched) ────▶ libniphy     │                   │
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
- **Headless mode:** master (no shim) + workers. Used for servers, CLIs and tests. The same runtime code path, with `niphy/ui` simply not loaded.
- **Single-process mode:** no workers. Handlers run in the master. Appropriate for small apps and for debugging. **[Proposed]**

### 3.4 One binary, several roles
A built app is one executable that re-executes itself with a role flag (`--role=worker`). In development, `nlm` runs the same entry point under a managed PHP. **[Proposed]** Re-execution from inside a micro binary works, using `readlink('/proc/self/exe')` (10.2). **[Verified, P0.6]**

---

## 4. Runtime

### 4.1 Event loop
- One Revolt loop per process, in the master and in every worker. **[Proposed]**
- Application code runs inside Fibers started by `async()`. Code reads sequentially and suspends on I/O. **[Proposed]**
- **Rule:** nothing on the loop may block. Blocking calls (`sleep`, `file_get_contents`, PDO, `curl_exec`) freeze the whole process. `nlm doctor` and the docs list safe replacements. CPU-heavy work goes to a process pool (4.4).
- **Rule:** cancel the Revolt watchers on a stream before closing that stream. Otherwise `stream_select` crashes on a dead resource. **[Verified, P0.2]**

### 4.2 Standard library tiers **[Decided: all tiers, staged]**
Niphy exposes its own namespaced services (`Niphy\Filesystem`, `Niphy\Network`, `Niphy\Http`, ...) as interfaces with default implementations over curated Amp packages. We do not reimplement what Amp already does well, and the interfaces keep us free to swap implementations.

| Tier | Contents | Built on |
|---|---|---|
| **1** | Loop and timers, streams, fs, child processes, signals, workers, IPC | `revolt/event-loop`, `amphp/byte-stream`, `amphp/file`, `amphp/process` for user commands (not for niphy workers, see 5.1) |
| **2** | TCP/UDP/Unix sockets, HTTP client and server, WebSocket | `amphp/socket`, `amphp/http-client`, `amphp/http-server`, `amphp/websocket-*` |
| **3** | TLS, crypto, zlib, DNS, os/path/url helpers, permissions model | PHP extensions (`openssl`, `sodium`, `zlib`), `amphp/dns` |

`ext-uv` and `ext-eio` are optional accelerators for async I/O. They are not installed on the dev machine, and the runtime must work without them. **[Proposed]**

### 4.3 API conventions **[Proposed]**
- **Standards first.** PER Coding Style (the successor to PSR-12) and PSR-1; PSR-4 autoloading; PSR-3 logging; PSR-11 container; PSR-14 events; PSR-20 clock. PSR-7/17/18 for HTTP where the underlying Amp library supports them, with adapters otherwise (decided in tier 2). **[Proposed]**
- **Language idioms.** `declare(strict_types=1)` in every file; typed properties and parameters; `readonly` classes for value objects; enums instead of string constants; named arguments for options; attributes for metadata (for example marking worker handler methods); PHPDoc generics where the type system falls short. **[Proposed]**
- **Naming.** PascalCase classes, camelCase methods and properties, PascalCase enum cases, Composer package names as `vendor/package`, semantic versioning. **[Proposed]**
- **Design.** Interfaces plus constructor injection. No static facades, no global state in the framework core. Services are plain objects wired by a PSR-11 container. **[Decided, O9]**
- **No fluent cascading.** Configuration uses constructors with named arguments and immutable value objects, not long method chains. **[Decided]**
- **Async.** Operations suspend the current Fiber and return the value, as in Amp v3. Parallelism is explicit (Amp's `async()`, `await()`, `Future\awaitAll()`). No promise chaining. **[Decided, O10]**
- Cancellation uses Amp's `Cancellation` objects, accepted as an optional trailing argument.
- Errors are typed exceptions under `Niphy\Error\*`. IPC errors map to the codes in 6.6.
- Streams use Amp's readable and writable stream interfaces.

### 4.4 Heavy work
Async only helps with I/O waits. CPU-bound work goes to a worker process pool (`amphp/parallel`). It must never run inside a UI handler or on the master loop. **[Proposed]** **[Unverified]**: whether `amphp/parallel` pool children get parent-death protection (5.6). Check before relying on it.

### 4.5 Signals and shutdown
SIGINT and SIGTERM are handled through Revolt, which needs `pcntl` (without it, registering a signal handler throws `UnsupportedFeatureException`). `posix` is needed by `amphp/process`, which calls `posix_kill` to signal child processes, and by the worker orphan check (5.6). **[Source-checked: the Revolt and amphp/process sources were read, not run in a spike.]** Both are present on the dev machine and are in the required-extension list (9.2). The master starts graceful shutdown (5.5) and exits with a defined code. A worker's SIGTERM handling must end the process (5.6). The shim's own exit and UI-thread failure are not yet part of this sequence (R14). **[Proposed]**

### 4.6 Long-running memory
PHP leaks and fragmentation matter once a process runs for days. Workers can be recycled after N requests or M megabytes (configurable), with in-flight work drained first. **[Proposed]**

**[Open, R20]** The master cannot be recycled, yet it holds the most long-lived state (all view code, handlers, the widget tree, and every JSON decode for UI traffic, see 8.4). Worker recycling does not address it. A memory budget and monitoring plan for the master is not yet defined.

### 4.7 Futures, cancellation and errors

Niphy has **no promises**. The only "value that is not ready yet" type is `Amp\Future<T>`. **[Source-checked]** against `amphp/amp` v3.1.3.

**Using futures**
- `async(Closure $fn, ...$args): Future` starts work in a new Fiber. `$future->await(?Cancellation)` suspends the caller until the result (or exception) arrives.
- Application code awaits and uses ordinary `try`/`catch`/`finally`. `Future::map()`, `catch()` and `finally()` exist, but they are for library internals. Niphy application code does not chain them. **[Decided, O10]**
- `DeferredFuture` bridges callback or event sources into futures. Niphy uses it internally, for example to match an IPC `res` message to the request that is waiting for it (by `id`) and to turn shim events into awaitable values. It is not part of the everyday application API.

**Combining futures** (functions in the `Amp\Future` namespace, for example `Amp\Future\awaitAll()`; Amp v3.1.3, source-checked):

| Function | Behavior |
|---|---|
| `await($futures)` | Wait for all. The first error is thrown. |
| `awaitAll($futures)` | Wait for all. Returns `[errors, values]`. |
| `awaitFirst($futures)` | First one to finish, success or error. Throws `CompositeLengthException` if `$futures` is empty. |
| `awaitAny($futures)` | First one to succeed. Errors are ignored unless all fail, in which case it throws `CompositeException`. Throws `CompositeLengthException` if `$futures` is empty. |
| `awaitAnyN($n, $futures)` | The first `n` successes. Throws `CompositeException` if too many futures error to reach `n`, and `CompositeLengthException` if `$futures` is empty. |

**Cancellation** uses Amp's types: `Cancellation`, `DeferredCancellation`, `TimeoutCancellation`, `SignalCancellation`, `CompositeCancellation`, `NullCancellation`, and `CancelledException`.
- Every niphy API that waits or does I/O takes `?Cancellation $cancellation = null` as its **last** parameter. **[Proposed]**
- An IPC `cancel` message trips a `DeferredCancellation` handed to the handler (6.7).

**Unhandled futures.** A future that errors and is destroyed without being awaited raises `UnhandledFutureError`, unless `ignore()` was called. **[Source-checked]**: `Internal/FutureState.php` constructs `UnhandledFutureError`, and `Future::ignore()` exists. For fire-and-forget work, niphy provides a supervised task runner that logs failures (PSR-3) instead of crashing or silently swallowing them. **[Proposed]**

**Uncaught errors.** An uncaught exception on the master loop is logged, triggers graceful shutdown, and exits non-zero. In a worker it exits non-zero so the supervisor restarts it (5.4). **[Decided, O12]**

**ReactPHP interop** (awaiting ReactPHP promises inside niphy code) is not planned for the MVP. Parked (`IMPLEMENTATION_PLAN.md` section 12).

### 4.8 Middleware and pipelines

**What PHP does.** The community standard is **PSR-15** (`process(ServerRequestInterface, RequestHandlerInterface)`), built on **PSR-7** immutable messages.

**What Amp does.** `amphp/http-server` does **not** implement PSR-7 or PSR-15. It has its own `Amp\Http\Server\Middleware` interface, `handleRequest(Request $request, RequestHandler $requestHandler): Response`, with its own `Request` class. It uses PSR-7 only for the URI type. **[Source-checked]** against `amphp/http-server` v3.4.8: `Middleware::handleRequest(Request, RequestHandler): Response`, and PSR-7 types appear only in `Request.php` and `Push.php`. The pattern is the same onion shape as PSR-15, but the types differ.

**Niphy's approach** **[Decided, O13]**

1. **HTTP server (tier 2).** Use Amp's native `Middleware` and `RequestHandler` as they are. We do not invent a third HTTP middleware API. A PSR-15 bridge is built only if users need existing PSR-15 middleware, and that is decided when tier 2 starts.
2. **Niphy's own pipelines** (worker RPC dispatch, master-side worker calls, UI event handling) use one contract shaped like PSR-15 so PHP developers recognize it:

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
- Because middleware runs inside Fibers, it can wait on I/O and look entirely sequential. Fibers are what remove the need for promise-returning middleware.
- Built-in middleware (all optional): timing and logging, trace id propagation, error mapping to the `E_*` codes (6.6), validation, and a retry middleware that applies only to methods marked idempotent through an attribute.
- Stacks are assembled at boot from the PSR-11 container.
- Middleware wraps flow. **Notifications** that must not alter flow use PSR-14 events (4.9).

### 4.9 Other common patterns

| Need | PHP idiom | Niphy plan |
|---|---|---|
| Notifications and observers | PSR-14 event dispatcher | Listeners may suspend. They are invoked in order within the dispatching Fiber. |
| Wiring services | PSR-11 container, constructor injection | No facades (O9). Default container implementation chosen in Phase 1. |
| Logging | PSR-3 | Monolog as the default implementation. Worker logs are aggregated at the master (14). |
| Configuration | Typed config objects | Loaded from `niphy.json` plus environment. No global `config()` helper. |
| Time | PSR-20 clock | For wall-clock timestamps and testable "what time is it" logic (injected, so tests control it). Elapsed-time measurement uses a monotonic source (`hrtime(true)` or `Amp\now()`), because wall-clock time can step. Timers use Amp's `delay()`. **[Proposed]** |
| Streams and async iteration | `amphp/pipeline` (`Pipeline`, `ConcurrentIterator`) and byte streams | Used for progress streams and multi-value results. |
| Retry, backoff, circuit breaker | Small value-object policies | A `RetryPolicy` in niphy, applied through middleware. |
| Resource cleanup | `try`/`finally`, Amp `Closable` | Never rely on destructors for correctness. |
| Locks and semaphores | `amphp/sync` | Used where shared resources need coordination. |
| Caching | A cache interface | PSR-16 adapter only if needed. |

---

## 5. Process model and supervision

### 5.1 Spawning **[Proposed]**
The master spawns workers with raw `proc_open`, re-executing the same binary with `--role=worker --name=<worker>`. Rules:
- The command must be an **array** (the path from `readlink('/proc/self/exe')`, then `--role=worker`, `--name=<worker>`), never a string. A string makes `/bin/sh` the child's parent, which breaks parent-death detection (5.6). **[Verified: spikes P0.2, P0.3 and P0.6 use array commands. That a string breaks PDEATHSIG is derived from how PDEATHSIG works (finding F2), not a separate test.]** The exact argument list is decided in P2.9: in a micro binary the first element is the binary itself, but in development (non-micro, run by `nlm`) it is the PHP binary and the entry script must be passed too, and the expected parent pid is also an argument (5.6). **[Open]**
- The IPC channel is passed as descriptor 3 (6.2). It must be created with `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, ...)` plus `socket_export_stream` on both ends. Every descriptor niphy creates must be close-on-exec, otherwise workers inherit other endpoints and never see EOF. The parent closes its copy of the child's end right after `proc_open` returns. **[Verified, spike P0.2]**
- stdout and stderr are captured as pipes.
- Workers may rely only on fds 0 to 3. The parent's own script descriptor is also inherited (observed as fd 4 in spike P0.2). It is harmless for EOF detection and not part of the contract. **[Verified, F4]**
- Niphy workers are not spawned through `amphp/process`. Its POSIX runner wraps every command in a `/bin/sh` script with a trap on INT, TERM, QUIT and HUP, and uses descriptor 3 for its own pid pipe. `Process::start` also turns an array command into one shell string. **[Source-checked: read in the `amphp/process` source, F18. Not run in a spike.]**
- **[Open, R17]** Descriptors that the shim's libraries create (Wayland or X11 connection, D-Bus, portals, clipboard, IME) are not created by niphy, so the close-on-exec rule does not cover them. Combining `proc_open` with a live multi-threaded shim in the master has not been tested. The contract is not yet defined.
- **[Open, R18]** Reaping workers and draining their stdout and stderr are not yet specified.

### 5.2 Declaring workers
Workers are declared in `niphy.json` (section 9.2) as `name → entry file`, with an optional pool size. A pool of size N is N separate processes behind one logical name, load-balanced round-robin or least-busy. **[Proposed]**

### 5.3 Lifecycle states
`starting → handshaking → ready → draining → exited | crashed | degraded`

- A worker is only routable in `ready`.
- A call to a worker that is not `ready` waits up to a configurable timeout, then fails with `E_WORKER_UNAVAILABLE`.
- `crashed → starting` happens when the restart policy (5.4) restarts the worker.
- `degraded` is a terminal state, reached from `crashed` when restarts exceed K in a window of T seconds (5.4). The supervisor does not restart a `degraded` worker, and this document defines no recovery path out of it. **[Proposed]**
- A call to a `degraded` worker does not wait for the timeout. It fails immediately with `E_WORKER_UNAVAILABLE`, because no restart is coming. **[Proposed]**
- How the master learns a worker's exit status (to choose `exited` versus `crashed`) is not yet specified (R18).

### 5.4 Restart policy **[Proposed]**
- On crash: fail all in-flight calls with `E_WORKER_CRASHED`. Retrying is the caller's decision, because the call may not be idempotent.
- Restart with exponential backoff (100 ms doubling to 10 s).
- If restarts exceed K in a window of T seconds, mark the worker `degraded`, stop restarting, and emit an app-level event so the UI can show an error. K and T are configurable.

### 5.5 Graceful shutdown
`bye` message, then a grace period (default 5 s), then SIGTERM, then SIGKILL. The same sequence applies when the master exits.

**[Open, R14]** This sequence covers workers only. It does not say when the shim is shut down (`niphy_shutdown` joins the UI thread), what happens if the shim's own UI thread fails, or what runs when `exit()` is called from inside a Fiber. These must be defined before the shim is used in production code (Phase 3).

### 5.6 Orphan prevention
A worker exits when its IPC socket reaches EOF. In addition, at startup it calls `prctl(PR_SET_PDEATHSIG, SIGTERM)` through libc FFI, so the kernel sends it SIGTERM when the master dies, including when the master is SIGKILLed and no cleanup code runs. Rules:
1. After the call, the worker compares `posix_getppid()` with the pid the master passed in and exits if they differ. This closes the startup race where the master died before `prctl` ran. The spike passed the expected parent pid as an argument. The exact argument convention is decided in plan step P2.9 and must be reconciled with the worker command line in 5.1, which does not list a pid argument yet.
2. Workers are spawned with an array command (5.1). With a string command, `/bin/sh` is the parent and the death signal is tied to the wrong process.
3. The worker's SIGTERM handling must actually end the process. After PDEATHSIG nobody remains to escalate to SIGKILL (5.5). Revolt signal handlers only run on a loop tick (4.5), so a worker whose loop is blocked or busy may not act on the signal if it has installed a handler. Whether a busy worker is covered is not tested.
4. Every worker needs ext-ffi and ext-posix, even a worker that never loads the shim. `libc.so.6` must be loadable, which is another reason for a glibc build (10.2). FFI is permitted by the default `ffi.enable=preload` in CLI and micro (10.2).

**[Verified, spike P0.3]** With a default SIGTERM action and an idle loop, the child was gone 5 to 9 ms after a master SIGKILL. Without `prctl` (control test) the child was still alive at the end of the 3 s observation window. The busy-loop behavior in rule 3 is reasoning, not a spike result.

### 5.7 Hot reload
`nlm dev` watches source files. A change restarts the affected worker only. The window and UI state survive. Worker in-memory state is lost by design, and the docs must say so. **[Proposed]**

**[Open, R21]** This covers worker code only. Because views and handlers run in the master (8.4), most development edits land in master code, and this section does not say what happens then (the window and UI state would be lost). It also does not say whether a restart caused by a file change counts toward the K-in-T `degraded` rule (5.4), or what happens to in-flight calls during a reload.

---

## 6. IPC protocol specification (v1)

### 6.1 Transport interface
A `Transport` interface hides the mechanism so Windows named pipes can be added later without touching protocol code. The only MVP implementation is AF_UNIX stream sockets. **[Decided]**

### 6.2 Connection establishment **[Decided, O1; changes the earlier plan]**
The earlier plan used a path-based socket plus a random token handshake. A simpler and stronger alternative:

- **Primary:** the master creates a pair per worker with `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, $raw)` (ext-sockets) and wraps both ends with `socket_export_stream`. It passes the child's end to `proc_open` as descriptor 3, then, once `proc_open` has returned, `fclose`s its own copy of the child's end and sets its own end non-blocking. The child opens descriptor 3 with `php://fd/3`. There is no filesystem path, nothing to authenticate, and only the parent and child hold the descriptor. `stream_socket_pair` must not be used: it does not set close-on-exec, so the child inherits the parent's end (and other descriptors) and never sees EOF. Every descriptor niphy creates must be close-on-exec, because `proc_open` children inherit any descriptor that is not. This makes `ext-sockets` a hard requirement. **[Verified, spike P0.2]**
- **Secondary (for attachable clients such as `nlm` inspectors or independently started workers) [Proposed]:** a listening socket at `$XDG_RUNTIME_DIR/niphy/<app-id>/<session>/<name>.sock`, directory mode 0700, socket mode 0600, with a token in `hello`. An optional `SO_PEERCRED` uid check could be added on top. **[Open, R22]** PHP 8.5.12 with ext-sockets does not define the `SO_PEERCRED` constant here, and `amphp/socket` 2.4.1 has no peer-credential API. No working route from PHP is confirmed, so how this path authenticates beyond the token is unspecified. This path is secondary and is not built before it is needed.

**[Decided, O1]** Socket pair is primary. Verified in Phase 0 (spike P0.2) with the `SOCK_CLOEXEC` condition above. The test used one worker only. Back-pressure: a 16 MiB write to a stalled child worked with a non-blocking writer, the loop kept ticking (max gap about 19 ms), and all 16,777,217 bytes arrived.

### 6.3 Framing
- **NDJSON:** one JSON object per line, terminated by `\n`. JSON string escaping guarantees no raw newline inside a message. **[Decided]**
- Encoders must not pretty-print. UTF-8 only.
- Maximum line size is configurable (default 8 MiB). Larger fails with `E_PAYLOAD_TOO_LARGE`. Bulk binary data does not belong here (6.9).
- A `Codec` interface permits another format later (MessagePack is available as an extension on the dev machine). JSON stays the MVP format. **[Proposed]**

### 6.4 Envelope

```jsonc
{ "id": 17, "kind": "req", "method": "files.scan", "params": {"path": "/tmp"} }
{ "id": 17, "kind": "evt", "ref": 17, "method": "progress", "params": {"done": 40, "total": 100} }
{ "id": 17, "kind": "res", "result": {"count": 100} }
{ "id": 17, "kind": "res", "error": {"code": "E_CANCELLED", "message": "...", "data": null} }
{ "kind": "cancel", "ref": 17 }
{ "kind": "evt", "method": "app.theme_changed", "params": {"dark": true} }
{ "kind": "ping" }   { "kind": "pong" }
{ "kind": "hello", "proto": 1, "role": "worker", "name": "main", "pid": 4242, "caps": [] }
{ "kind": "bye", "reason": "shutdown" }
```

Rules:
- `id` is assigned by the sender, unique per connection direction. Either side may issue requests.
- A request produces zero or more `evt` messages with `ref = id` (progress or streaming), then exactly one `res`.
- Messages are ordered per connection. Requests run concurrently, so responses may arrive out of order.
- Unknown `kind` or malformed JSON: reply `E_PROTOCOL` where possible, then close.

### 6.5 Handshake and versioning
Both sides send `hello` immediately after connecting. `proto` is an integer. A mismatch is answered with `bye` and `E_PROTOCOL`. `caps` is a list of optional feature names (for example `"shm"` for the shared-memory lane). Breaking changes bump `proto`.

### 6.6 Error codes
`E_METHOD_NOT_FOUND`, `E_INVALID_PARAMS`, `E_CANCELLED`, `E_TIMEOUT`, `E_WORKER_CRASHED`, `E_WORKER_UNAVAILABLE`, `E_PAYLOAD_TOO_LARGE`, `E_PROTOCOL`, `E_INTERNAL`. Application errors use `E_APP_*` with free-form `data`.

### 6.7 Cancellation and timeouts
`cancel` is cooperative: the receiver trips the Amp `Cancellation` passed to the handler, and answers `E_CANCELLED` if the handler stops. Client-side timeouts send `cancel` and fail locally with `E_TIMEOUT`. A handler that ignores cancellation is the handler's bug, not a protocol failure.

### 6.8 Liveness and backpressure
- **Liveness:** EOF is the primary death signal. `ping` every 2 s, three misses means dead (configurable). A worker busy with CPU work on its loop misses pings, so CPU work must be offloaded (4.4), and the threshold must be tunable.
- **Backpressure:** each connection has a write-buffer high-water mark. A send returns a future that resolves when flushed. There is a cap on in-flight requests per worker (default to be chosen in Phase 2). Requests beyond it queue, and past a queue limit they fail with `E_WORKER_UNAVAILABLE`.
- High-rate UI events (mouse move, scroll) are coalesced in the shim (7.5), never in IPC.
- **[Open, R19]** `ping`, `pong`, `cancel` and normal requests share one ordered stream with lines of up to 8 MiB (6.3). A very large message in flight can delay a `ping` or `cancel` and every unrelated response behind it, and a healthy worker could be declared dead by the liveness rule. The receiver's behavior at the size cap (how an oversize line is discarded without buffering it) is also unspecified. Phase 2 must resolve this and measure ping latency during a large transfer. One tested data point exists: a 16 MiB write to a stalled child worked with a non-blocking writer (6.2), but ping latency during a large transfer was not measured.

### 6.9 Large payloads **[Proposed, later]**
Bulk data (images, buffers) uses a shared-memory lane (`shmop` or FFI `mmap`). Only a handle travels over IPC, and capability `shm` is negotiated in `hello`.

### 6.10 Security
Same-uid only. Socket-pair inheritance means no path to attack. Every niphy descriptor must be close-on-exec (6.2). This is also a security rule, so that a worker never holds another worker's endpoint. Path sockets use a 0700 directory, a 0600 socket and a token in `hello` as the baseline defence. A `SO_PEERCRED` uid check is an optional add-on and is **[Open, R22]** (see 6.2). IPC is never bound to a network interface. JSON decoding enforces depth and size limits.

---

## 7. The shim (`libniphy`)

### 7.1 Responsibilities
The shim owns everything that must run on a real thread against the OS: window, input, layout, text, drawing, native dialogs, clipboard and tray, plus drawn menus. PHP describes *what* to show and reacts to events. PHP never draws a pixel and never runs per frame. **[Decided]**

### 7.2 Threading model **[Decided: UI thread inside the shim, Linux]**
- The shim starts one UI thread at init. All toolkit state lives there.
- Every shim API function is callable from PHP's thread and returns without waiting on the UI thread.
- The UI thread never calls PHP.
- **Accepted cost:** macOS requires UI on the process main thread, so this design will not port to macOS as is. A later macOS port would need a native host process that embeds PHP. **[Decided]**
- On Linux, winit supports running its event loop off the main thread on Wayland and X11. **[Verified, P0.1 (R1)]** The winit loop ran on a non-main thread (`with_any_thread`) under both Wayland and X11, a window was created, the UI thread kept redrawing while PHP was blocked, and pointer events were delivered and coalesced. 1000 round trips had p50 of 65 to 114 µs. The user confirmed a window appeared. Caveats: the redraw counts were unthrottled CPU fills, not a rendering benchmark, and keyboard and click events are still untested.
- **[Open, R14]** The UI thread itself is not covered by the panic rule in 7.3. See 7.3.

### 7.3 C ABI (draft v0) **[Proposed]**

```c
typedef struct NiphyShim NiphyShim;

uint32_t   niphy_abi_version(void);                 // major<<16 | minor; PHP checks at load
NiphyShim* niphy_init(const char* cfg_json, size_t len);
int        niphy_event_fd(const NiphyShim*);        // readable when events are queued
intptr_t   niphy_poll(const NiphyShim*, char* out, size_t cap);
int        niphy_send(const NiphyShim*, const char* batch, size_t len);
size_t     niphy_last_error(const NiphyShim*, char* out, size_t cap);
void       niphy_shutdown(NiphyShim*);
```

Rules:
- Strings are UTF-8 with explicit lengths, never NUL-terminated assumptions.
- The shim copies every input. PHP owns every output buffer. The shim never returns an allocation to PHP, so there is nothing for PHP to free.
- `niphy_poll` returns whole events only: bytes written (always ending in `\n`), `0` for none, `-1` if the next single event exceeds `cap`. **[Open, R16]** What happens to the oversized event after `-1` is not defined: whether it stays at the head of the queue, how PHP learns the size it needs, and what PHP does next. If PHP leaves its drain loop on `-1`, the eventfd has already been cleared and the head event can block every later event, including clicks and key presses. Realistic large events are clipboard paste, drag-and-drop file lists and IME text. This must be resolved, with a test, before the ABI is frozen (Phase 3).
- The header is generated with `cbindgen`, committed to the repo, and loaded on the PHP side via `FFI::cdef`.
- **Panics never cross the ABI.** Every `extern "C"` function runs under `catch_unwind`. A panic marks the shim poisoned, emits an error event, and later calls fail cleanly.
- **[Open, R14]** This rule covers only calls into the shim. A panic or exit on the shim's own UI thread is not covered, and neither is the shim's place in the shutdown order (5.5). With `panic = "abort"` such a panic would abort the whole master process and, through PDEATHSIG (5.6), every worker, which defeats G2. With `panic = "unwind"` and no catch in the thread body, only the UI thread dies: the window disappears while the master keeps running, and no error event is written. The build setting, the thread-body policy and the master's reaction are not yet defined. Phase 3 must resolve this.
- ABI compatibility: minor bumps add functions, major bumps break. `nlm doctor` and runtime startup both check `niphy_abi_version()`.

### 7.4 Command protocol (PHP → shim)
- A **batch** is a JSON array of commands: `{"op": "...", ...}`. PHP builds one batch per loop tick and sends it in a single FFI call. **[Verified feasible, Appendix A]**
- **[Open, R15]** There is no ordering contract between the command queue and the event queue. Three races follow. The shim may already have queued a click for a widget that PHP then removes, so PHP drains a stale event for an id it no longer has. Commands are fire-and-forget, so a bad id or property can only produce an error event that is not linked to the batch that caused it. A re-render (8.2) can overwrite text the user typed after the last commit (8.3). The rules for widget-id reuse, error reporting, stale events and re-render of focused inputs are not yet defined. Plan step P3.3 (Phase 3) must define a written command/event ordering contract, and P4.2, P4.3 and P4.6 test against it. P4.2 and P4.3 refer to "ordering rules" without defining them.
- Operation families (MVP): `window.*`, `widget.create|set|append|insert|remove|destroy`, `canvas.*` (draw lists for custom-drawn widgets), `dialog.*`, `clipboard.*`, `app.quit`.
- Widgets have PHP-assigned integer ids so later commands can refer to them without round trips.
- JSON is the MVP encoding because it is debuggable. A binary encoding is introduced only if profiling demands it (Appendix A: 200 commands encode in ~40 µs).

### 7.5 Event protocol (shim → PHP) **[Proposed]**
- Events are appended as NDJSON to an **in-memory queue** inside the shim. An `eventfd` is written to wake PHP. **[Verified, Appendix A]**
- PHP's loop watches the eventfd. On wake: **clear the eventfd first, then drain with `niphy_poll` until it returns 0.** This order cannot lose a wake-up. **[Proposed]** This is a design rule. The order itself was not tested separately. (On `-1` from `niphy_poll`, see the open item R16 in 7.3.)
- **The fd is only a wake-up, never the buffer.** A pipe-as-buffer design was tested and dropped 88% of events when PHP stalled for 300 ms. The in-memory queue lost none (Appendix A). **[Verified, Appendix A]**
- **Coalescing rules** (applied in the shim, per window, per loop turn): pointer-move, scroll and resize keep only the latest value. Discrete events (click, key, text input, focus, close) are never coalesced or dropped. **[Proposed]** P0.1 observed pointer-move events delivered and coalesced. Scroll, resize and the discrete-event guarantees are untested, and keyboard and click events are untested.
- **Queue limits:** discrete events are never dropped. The queue policy beyond a size cap (back-pressure the UI, aggressive coalescing, or abort) is **[Decided, O7]**: coalesce harder first, then back-pressure, and never drop discrete events.

### 7.6 Rendering and layout **[Proposed]**
- Layout: Taffy (flexbox subset).
- Text: cosmic-text.
- Raster: CPU rasterization for the MVP (tiny-skia or softbuffer). GPU (wgpu/Vello) later.
- Redraw on dirty regions, paced by the compositor's frame callbacks. Animations run inside the shim and need no PHP per frame.
- **[Verified, P0.1]** The UI thread kept redrawing while PHP was blocked. The redraw counts were unthrottled CPU fills, not a rendering benchmark.
- Phase 0 finding F8: redraws must be paced to the compositor and dirty-driven, not polled. **[Proposed]**

### 7.7 Native chrome (hybrid, reading (i)) **[Decided]**
Native (OS-provided): file dialogs through the `xdg-desktop-portal` FileChooser, clipboard through `smithay-clipboard` on Wayland, the system theme setting, and an optional tray. The window itself is created through winit, and its decorations are winit's client-side ones on GNOME/Wayland, which offers no server-side decorations. Drawn by niphy inside the window: menus and popups. Everything inside the window is custom-drawn.

Findings on GNOME 50.5 Wayland **[Verified, P0.4; Decided: option (a), GNOME first, improve later]**:
1. winit 0.30.13 has no `xdg_popup` support, so menus cannot extend beyond the window and are drawn in-window.
2. GNOME offers no server-side decoration protocol, so winit draws the title bar.
3. The compositor offers no `data-control` protocol, so clipboard must use `smithay-clipboard`, not `arboard`.
4. The tray needs a StatusNotifierWatcher, which stock GNOME lacks unless the AppIndicator extension is enabled (installed but disabled on this machine), so the tray is an optional feature.
5. The FileChooser portal is available, and `zwp_text_input_manager_v3` is offered.

Not a P0.4 finding **[Unverified]**: Wayland does not let apps position their own windows. This is a general protocol fact that was not tested here.

The GTK4 window path (b) is not pursued. **[Decided]** **[Verified, chrome experiment]** A real portal file dialog requested from PHP through the shim (`rfd` 0.17.2, helper thread, not attached to the window) returned the chosen path to PHP as an event, and the title bar's close button produced `close_requested` in PHP. **[Unverified]** The tray, how the winit title bar and the dialog look, whether the dialog attaches to the window, window responsiveness while the dialog is open, and IME were not exercised. Those checks are deferred to plan steps P3.12a to P3.12c (tray, dialog parenting; clipboard is built in P3.11b) and P3.11a (IME).

### 7.8 Headless backend for tests **[Decided, O4]**
The shim exposes a `headless` backend with no window system dependency. It runs layout and the command/event machinery without drawing to a screen, so CI can test the whole PHP-to-shim path on machines with no display.

### 7.9 Build and distribution
One Rust `cdylib` per architecture, linked against glibc. The version is pinned in the project lock file. `nlm` fetches prebuilt binaries and verifies checksums. Building from source requires a Rust toolchain.

**glibc floor.** The shim's highest required `GLIBC_` symbol version was 2.34 (headless) and 2.43 (windowed), measured with `objdump -T` on this machine (host glibc 2.43). PHP and the app need only 2.17. So the distributable floor is set by how the shim is built, not by PHP. **[Verified, F14]** Not yet run on any other distro. Building the shim against an old glibc (for example `cargo-zigbuild` with a `.2.17` target, or an old-distro container) would avoid raising the floor (D21, R23). **[Proposed, needs user approval]**

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

## 9. `nlm` (niphy libs manager)

### 9.1 Role **[Decided: replaces `niphy run`]**
`nlm` is the single front door for the project: it wraps Composer for PHP packages, manages native libraries, and runs and builds the app.

| Command | Purpose |
|---|---|
| `nlm init` | Scaffold a project (`niphy.json`, `composer.json`, entry files) |
| `nlm add <pkg>` / `remove` | Add or remove a PHP package (delegates to Composer) |
| `nlm install` | Install PHP packages and native libs from lock files |
| `nlm update` | Update within constraints |
| `nlm run [file]` | Run the app or a script |
| `nlm dev` | Run with file watching and worker hot reload |
| `nlm build` | Produce the standalone binary |
| `nlm doctor` | Check ffi, extensions, shim ABI, display, glibc |
| `nlm lib list\|pin\|verify` | Inspect and manage native libs |

### 9.2 Manifest **[Proposed]**
`composer.json` stays the source of truth for PHP dependencies. `niphy.json` holds niphy-specific configuration. `nlm add` runs `composer require` underneath.

```jsonc
{
  "name": "acme/files",
  "entry": "src/main.php",
  "workers": { "main": { "entry": "src/worker.php", "pool": 1 } },
  "ui": { "enabled": true, "backend": "default" },
  "libs": { "libniphy": "^0.1" },
  "php": { "extensions": ["ffi", "pcntl", "posix", "sockets"], "ini": {} },
  "build": { "output": "dist/files", "embedShim": true }
}
```

### 9.3 Native libs **[Proposed]**
Prebuilt `libniphy` per architecture is stored under `~/.local/share/nlm/libs/<name>/<version>/`. `nlm.lock` records versions and sha256. The runtime loads native libs only from a verified store path and never relies on `LD_LIBRARY_PATH`.

### 9.4 PHP provisioning **[Decided, O3]**
Option A: use the system PHP and let `nlm doctor` report what is missing (this machine needed `php-ffi` installed first). Option B: download a managed static-php-cli PHP build into the lib store so versions are reproducible. Default: B, with A as an opt-out. `nlm` itself is written in PHP and distributed as a standalone binary, bootstrapped from a prebuilt build. **[Verified, P0.5]** A static-php-cli build of PHP 8.5.12 with the needed extensions succeeded on this machine (10.2), so a managed build is feasible. The minimum glibc is still to be chosen (R23, O5).

### 9.5 Dev experience
`nlm dev`: file watcher, worker-only restart, IPC trace (`NIPHY_IPC_TRACE=1`), log aggregation in the terminal.

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
- **FFI is unsafe by nature.** A bug there can crash or corrupt the process. All FFI code lives in dedicated `Ffi` namespaces, each behind a typed interface. The UI shim binding lives in `Niphy\Ui\Ffi` (part of `niphy/ui`). The runtime's libc calls (`prctl` in 5.6, and `mmap` for the shm lane if used) live in a separate runtime-side namespace, chosen in Phase 2, that does not depend on `niphy/ui`, so headless mode (3.3) can use it without loading the UI package. **[Proposed]**
- **Untrusted input:** JSON size and depth limits, strict UTF-8, no `unserialize` on IPC data.
- **Supply chain:** `nlm` verifies checksums for native libs and writes `nlm.lock`.

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
| Soak | 10k+ IPC round trips and sustained events, watching `memory_get_usage()` on both sides. The acceptance threshold and the master's memory plan are not yet defined (R20). |
| Static analysis | PHPStan strict, `clippy` |

---

## 14. Observability and debugging

- Structured logs. Worker stdout/stderr and log events are aggregated at the master with the worker name and a level.
- Envelope may carry an optional `trace` id propagated through calls. **[Proposed]**
- `NIPHY_IPC_TRACE=1` logs every IPC message.
- Debuggers: Xdebug with multiple processes needs documented setup.
- Crash reports: master records worker exit status and the last N log lines. How the exit status is collected and how worker stdout and stderr are drained is not yet specified (R18).

---

## 15. Decision log

| ID | Decision | Status | Evidence / note |
|---|---|---|---|
| D1 | Revolt + Fibers (Amp v3) in master and worker | Decided (O10) | Revolt 1.0.9 installs and runs here |
| D2 | NDJSON over AF_UNIX for worker IPC | Decided | |
| D3 | Rust shim `libniphy` with C ABI | Decided | |
| D4 | Hybrid GUI, reading (i); native chrome option (a), GNOME first | Decided | P0.4: winit window, portal dialogs, in-window menus, optional tray (7.7) |
| D5 | Shim-owned UI thread, no C-to-PHP callbacks | Decided | Verified P0.1 for the windowed case (Wayland and X11); UI-thread panic policy is open (R14) |
| D6 | Events: in-memory queue + `eventfd`, drained with `niphy_poll` | **Verified** | Appendix A |
| D7 | Linux first | Decided | |
| D8 | `nlm` replaces `niphy run` | Decided | |
| D9 | Composer under `nlm` for PHP packages; constructor injection, no facades | Decided (O9) | |
| D10 | Socket-pair inheritance as primary IPC channel | Decided (O1) | Verified P0.2; requires `socket_create_pair` with `SOCK_CLOEXEC` |
| D11 | JSON for shim commands in the MVP | Proposed | Appendix A: ~40 µs per 200-command batch |
| D12 | Accessibility later, widget ids and roles from day one | Decided | |
| D13 | PHP community conventions (PER-CS, PSRs, strict types), PHPStan strict, PHPUnit | Decided (G8, O11) | The formatter was decided in P1.1 (D25). Exact versions fixed in P1.3: PHPUnit 13.4.6, PHPStan 2.3.5 (strict-rules 2.1.1, deprecation-rules 2.0.5, disallowed-calls 4.15.0), PHP-CS-Fixer 3.95.28 |
| D14 | No Node-style APIs, no promise chaining, no fluent cascading | Decided | |
| D15 | MIT license, semantic versioning | Decided (O8) | |
| D16 | Uncaught errors: master logs and shuts down non-zero; worker exits non-zero and is restarted | Decided (O12) | |
| D17 | Middleware: Amp-native for HTTP, one PSR-15-shaped contract for niphy RPC, PSR-15 bridge only if needed | Decided (O13) | |
| D18 | Workers are spawned with raw `proc_open` and an array command, never `amphp/process` | Source-checked (F18) | Source read in `amphp/process`, no code run. The array command is used in spikes P0.2, P0.3 and P0.6 (5.1) |
| D19 | Self re-exec uses `readlink('/proc/self/exe')`; `PHP_BINARY` is empty in micro | Verified | P0.6 (10.2) |
| D20 | Use the instance form `$ffi->new()`; `ffi.enable=preload` already permits FFI in CLI and micro | Verified | F11, F12 (10.2) |
| D21 | Build the Rust shim against an old glibc (for example `cargo-zigbuild` with a `.2.17` target, or an old-distro container) so the shim does not raise the distributable floor | Proposed, needs user approval | F14, R23 (7.9) |
| D22 | `niphy` is the **code name**. The public name is **Raphp** (D27). Renaming the code must stay cheap and is scheduled separately. Nothing is published under the code name. | Decided | The code name appears in the `Niphy\` namespace, `libniphy`, `niphy.json`, `NIPHY_*` variables, the C symbol prefix, and the acronym `nlm` (niphy libs manager) |
| D23 | **PHP floor: 8.5 or newer** (`php >= 8.5`). CI runs on PHP 8.5 for now, and newer versions are added as they ship. PHPUnit 13 (needs PHP 8.5.12 or newer) is therefore usable, and the floor equals the managed PHP build. | Decided (P1.1) | You confirmed "8.5+". PHP 8.3 active support ended 31 Dec 2025, 8.4 ends 31 Dec 2026, 8.5 ends 31 Dec 2027 (php.net, 2026-11-06) |
| D24 | **Container: PHP-DI 7.** The core depends only on `psr/container`. | Decided (P1.1) | PHP-DI 7.1.1 requires PHP 8.0 or newer. Autowiring fits constructor injection (O9) |
| D25 | **Formatter: PHP-CS-Fixer** with the `@PER-CS3.0` rule set, pinned so a tool update cannot silently change the rules. | Decided (P1.1) | The rule sets `@PER-CS`, `@PER-CS1.0` and `@PER-CS3.0` exist in PHP-CS-Fixer 3.95.28. The `@PER-CS3.0` name is confirmed there; the exact pin is checked in P1.3 |
| D26 | **Copyright holder: Codizium.** The MIT `LICENSE` reads "Copyright (c) 2026 Codizium". | Decided (P1.1) | The composer vendor name stays a placeholder (`niphy/niphy`), per D22 |
| D27 | **Public name: Raphp** (Ralph PHP, pronounced "raf-pee"). `niphy` stays the code name until a rename is scheduled (D22). | Decided | Chosen by you. On 2026-11-06 `raphp` is free on crates.io, npm and Packagist; a GitHub handle `raphp` already exists. The acronym `nlm` (niphy libs manager) needs a decision when the rename happens |

The questions table in section 17 is the record for O2, O3, O4, O5 and O7; they have no separate D rows.

---

## 16. Risk register

| ID | Risk | Likelihood / impact | Mitigation | Status |
|---|---|---|---|---|
| R1 | winit loop on a non-main thread misbehaves on Wayland or X11 | Medium / High | Spike P0.1 | **Verified** (Wayland and X11). Caveats: keyboard and click events untested; redraw counts are not a rendering benchmark |
| R2 | Native menus and tray conflict with a winit window (GTK3 vs GTK4) | Medium / Medium | Spike P0.4 | **Decided: option (a)**, GNOME first (winit window, portal dialogs, in-window menus, optional tray). File dialog verified end to end (unparented); tray, dialog look and parenting deferred to P3.12a to P3.12c (clipboard is built in P3.11b) |
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
| R13 | Distributing a Rust `.so` complicates installs | Medium / Medium | `nlm` fetch + checksum, embedded fallback | Proposed |
| R14 | A panic or exit on the shim's own UI thread is not covered by the panic rule (7.3), and the shim has no place in the shutdown order (5.5). With `panic = "abort"` the whole master and, via PDEATHSIG, every worker could die (G2); with unwind and no catch, only the window would vanish silently | Not assessed / Not assessed | None yet. Phase 3 must define the build setting, the UI-thread policy, the master's reaction and the shutdown order | **Open** |
| R15 | No ordering contract between the command queue and the event queue: stale events for removed widgets, unlinked command errors, re-render overwriting typed text (7.4, 8.3) | Not assessed / Not assessed | None yet. Plan step P3.3 (Phase 3) must define a written ordering contract. P4.2, P4.3 and P4.6 test against it | **Open** |
| R16 | An event larger than the `niphy_poll` buffer can stall all UI input if the drain loop leaves on `-1` (7.3) | Not assessed / Not assessed | None yet. Must be resolved, with a test, before the ABI is frozen (Phase 3) | **Open** |
| R17 | `proc_open` forks while a live multi-threaded shim exists in the master, which also holds descriptors niphy did not create (display, D-Bus, portals). Never tested | Not assessed / Not assessed | None yet. Needs evidence with a real window (Phase 3) before a rule is written | **Open** |
| R18 | No rule for reaping workers (exit status, non-blocking collection) or for draining their stdout and stderr (5.1, 5.3, 14) | Not assessed / Not assessed | None yet. Phase 2 must define it before the supervisor | **Open** |
| R19 | `ping`, `pong` and `cancel` can be starved behind a very large message on the single ordered stream, and the receiver's behavior at the size cap is unspecified (6.3, 6.8) | Not assessed / Not assessed | None yet. Phase 2 must define it and measure ping latency during a large transfer | **Open** |
| R20 | The master cannot be recycled yet holds the most long-lived state (all view code, 8.4); R9's mitigation does not cover it | Not assessed / Not assessed | None yet. Needs a master memory budget and monitoring plan (Phases 2 and 4) | **Open** |
| R21 | Hot reload (5.7) covers worker code only, while most edits land in master code; reload versus crash accounting is undefined | Not assessed / Not assessed | None yet. Phase 5 (`nlm dev`) must define it | **Open** |
| R22 | `SO_PEERCRED` is not exposed by PHP's sockets extension here, so the secondary path-socket authentication is unspecified (6.2, 6.10) | Not assessed / Not assessed | The secondary path is not needed for the MVP, and the socket-pair primary has no path to attack | **Open** |
| R23 | The shim's glibc floor (2.34 headless, 2.43 windowed) is higher than PHP's (2.17), so the shim sets the distributable floor | Not assessed / Not assessed | D21: build the shim against an old glibc. Not yet run on any other distro | **Open** (D21 Proposed) |

---

## 17. Open questions and accepted recommendations

All numbered questions are resolved or deferred (O5 to Phase 6, the exact O11 tool versions to P1.3). The one item that still needs your approval is D21 (building the shim against an old glibc). Accepted recommendations are recorded as decisions in the sections they affect. O8 is MIT with semantic versioning. O5 is deferred to Phase 6 by design. The Phase 0 spikes confirmed O1 (socket pair, with the `SOCK_CLOEXEC` condition) and O3 (a managed static PHP build is feasible), and settled the native-chrome question as option (a), GNOME first.

The design gaps found in the audit are not numbered questions. They are recorded as Open risks R14 to R22 in section 16, each with the phase that must resolve it. One proposed decision needs your approval: D21 (build the shim against an old glibc, see R23).

| ID | Question | Resolution | Status |
|---|---|---|---|
| O1 | IPC connection mechanism | Socket pair inherited by the child is primary, created with `socket_create_pair` and `SOCK_CLOEXEC` (verified, P0.2). Path sockets with 0700/0600 modes and a token are secondary (6.2). A `SO_PEERCRED` check is an optional add-on and is open (R22). | **Accepted** |
| O2 | Where view and handlers run | In the master. Workers hold logic and data (8.4). | **Accepted** |
| O3 | PHP provisioning | `nlm` manages a PHP build by default, with system PHP as an opt-out (9.4). A static-php-cli build was verified in P0.5. | **Accepted** |
| O4 | Headless shim backend and virtual compositor for CI | Yes (7.8, 13). | **Accepted** |
| O5 | Minimum glibc | Deferred to Phase 6, to be chosen from a test build. Two inputs set the floor: the `SPC_TARGET` suffix for PHP and the glibc the shim is built against (11, R23, D21). | **Accepted (deferred)** |
| O7 | Event queue overflow policy | Coalesce harder, then back-pressure, never drop discrete events (7.5). | **Accepted** |
| O8 | License and versioning scheme | MIT license, semantic versioning. | **Decided** |
| O9 | Constructor injection, PSR-11 container, no facades | Yes (4.3). | **Accepted** |
| O10 | Amp v3 Fiber style, no promise chaining | Yes (4.3, 4.7). | **Accepted** |
| O11 | PER-CS, PHPStan strict, PHPUnit, and PHP-CS-Fixer or PHP_CodeSniffer | Yes. The formatter is PHP-CS-Fixer (D25). Exact tool versions and the PHPStan configuration are fixed in P1.3. | **Accepted** |
| O12 | Uncaught error policy | Master: log, graceful shutdown, non-zero exit. Worker: exit non-zero so the supervisor restarts it (4.7). | **Accepted** |
| O13 | Middleware: Amp-native for HTTP, one niphy PSR-15-shaped contract for RPC, PSR-15 bridge only if needed | Yes (4.8). | **Accepted** |

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
| `niphy_send("[]")` | ~1.2–1.8 µs/call | Includes allocation, channel send and waking the UI thread. |
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
3. The master's Revolt loop wakes, clears the eventfd, drains `niphy_poll`.
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
| P0.4 native chrome (F6, F7) | GNOME 50.5 Wayland: no server-side decorations, no `xdg_popup` in winit 0.30.13, no `data-control` (use `smithay-clipboard`), no StatusNotifierWatcher unless AppIndicator is enabled, FileChooser portal and text-input v3 available. Option (a) decided. A real portal file dialog and the close button were verified end to end by the chrome experiment (dialog unparented); the tray, look and dialog parenting are not yet exercised (P3.12). | 7.7, R2 |
| P0.5 static PHP (R3) | **Passes with a condition.** static-php-cli 2.8.6, `SPC_TARGET=native-native-gnu.2.17`: PHP 8.5.12 CLI (21.0 MB) and `micro.sfx` (18.9 MB), glibc only, with `ffi`. The condition is R23. | 10.2, R3 |
| F12, F11 | `ffi.enable=preload` already allows `FFI::cdef` in CLI and micro. In PHP 8.5, use `$ffi->new()`, not the static form. | 10.2, D20 |
| P0.6 self re-exec (R4) | **Passes.** `PHP_BINARY` is empty in micro. `/proc/self/exe` works. Master re-executed itself as a worker with fd 3. Single-file app 19.5 MB. | 3.4, 10.2, D19, R4 |
| P0.7 embedded shim | **Passes (headless).** Shim `.so` in the phar, `memfd_create`, `dlopen` via `/proc/self/fd/N` works inside micro. The windowed shim was not tested through `memfd` inside micro. | 10.2 |
| F14 glibc floors | PHP, `micro.sfx` and the app need up to 2.17. The Rust shim needs up to 2.34 (headless) and 2.43 (windowed). Not run on another distro. | 7.9, R23, D21 |
| F18 amphp/process | Read in source: wraps commands in a `/bin/sh` script and uses fd 3 for its own pid pipe. Workers use raw `proc_open`. | 5.1, D18 |
| F19 project mount | The project directory is on a root-owned mount with 777 permissions. git printed "detected dubious ownership" for repos cloned there. The same error hits `git init`'d repositories and Composer (which probes git for the project version). Git ignores `safe.directory` in a repository's own config, so the fix is a global entry or `git -c safe.directory=...`. Composer works on the mount: it installs executables as regular proxy files, not symlinks, and they run (checked in P1.2). | P1.2 |
