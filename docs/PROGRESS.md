# Raphp Progress Tracker

**Last updated:** 2026-11-06
**Current position (2026-11-07):** **Phase 2 is finished (Checkpoint 2 was reported; you said "continue", which I take as passed). Phase 2B is in progress: Phase 2B is finished: P2B.1 to P2B.5 are done. Checkpoint 2B is reported (live demo, tests green); I stop for your review before Phase 3.** `make check` exits 0 with **1,242 tests, 9,008 assertions** (`make fast` runs the quick ones in about 8 s). Your last commit is `01b9a69` (P2B.4); the P2B.5 work and the demo are **uncommitted**.
**How this file works:** I update it at the end of every step and whenever something is decided or found. The plan lives in `IMPLEMENTATION_PLAN.md`, the design in `FRAMEWORK.md`, the evidence in `SPIKE_REPORT.md`. This file only records what has actually happened.

Legend: ✅ done and verified · 🔄 in progress · ⏳ not started · ⛔ blocked · ⚠️ done with caveats

---

## 1. Status at a glance

| Phase | Name | Status | Steps done |
|---|---|---|---|
| 0 | Spikes and verification | ✅ finished (accepted when you started Phase 1) | 9 of 9 (P0.0 to P0.8) |
| 1 | Repository foundation | ✅ finished, **awaiting your Checkpoint 1 review** | 6 of 6 (P1.1 to P1.6) |
| 2 | Runtime core and IPC | ✅ finished (Checkpoint 2 passed on your "continue") | 13 of 13 (P2.1 to P2.13) |
| 2B | Tier 1 standard library | ✅ steps done, **awaiting your Checkpoint 2B review** | 5 of 5 (P2B.1 to P2B.5) |
| 3 | `libraphp` shim MVP | ⏳ not started | 0 |
| 4 | UI framework | ⏳ not started | 0 |
| 5 | `rlm` | ⏳ not started | 0 |
| 6 | Standalone packaging | ⏳ not started | 0 |
| 7 | Runtime tier 2 | ⏳ not started | 0 |
| 8 | Tier 3 and hardening | ⏳ not started | 0 |

**Production code so far:** configuration, logging, clock, container, the error model (Phase 1), and the kernel, the background task runner and the NDJSON codec (Phase 2). The shim, the `rlm` CLI and every IPC component above the codec do not exist yet.

---

## 2. Documents

| Document | State | Notes |
|---|---|---|
| `docs/FRAMEWORK.md` | ✅ **v0.3**, Phase 0 results and audit incorporated | Open design gaps R14 to R22 are recorded in section 16 with no solution yet |
| `docs/IMPLEMENTATION_PLAN.md` | ✅ updated after Phase 0 | New acceptance checks and steps are labelled "added after the Phase 0 audit" |
| `docs/SPIKE_REPORT.md` | ✅ written | The evidence record: every number, every caveat, what was not tested |
| `docs/PROGRESS.md` | ✅ this file | |

**Review history of the two design docs** (details in `SPIKE_REPORT.md` section 6):
- A 5-lens audit with adversarial verification found 33 real issues (40 candidates, 7 rejected).
- Round 1: two editor agents applied them. Four independent reviewers then found 23 further issues (mostly low).
- Round 2: two editors applied those plus my cross-document decisions. Fresh reviewers passed both files (`ok: true`) with 17 low-severity items left.
- I applied those 17 myself. After that, **no agent re-reviewed the files**. I checked mechanically instead: all tables have consistent columns, and the known-wrong claims (`stream_socket_pair` for workers, "preload blocks CLI FFI", `PHP_BINARY` in micro, `amphp/process` spawning workers) are gone.

---

## 3. Phase 0 results

| Step | What | Status | Result |
|---|---|---|---|
| **P0.0** | Headless shim: queue + `eventfd` | ✅ | **0 of 20,000** events lost when PHP stalled 300 ms (a pipe lost 88%). FFI call ~125–240 ns. Round trip p50 14–56 µs |
| **P0.1** | Window loop on a non-main thread (R1) | ⚠️ | **Pass** on Wayland and X11. Window created on the shim thread, 1000 round trips p50 65–114 µs. You confirmed a window appeared. Clicks and key presses untested |
| **P0.2** | Socket pair through `proc_open` (O1) | ✅ | **Pass, with a mandatory condition:** `SOCK_CLOEXEC`, which makes `ext-sockets` a hard requirement. 16 MiB back-pressure test passed exactly |
| **P0.3** | Parent-death signal | ✅ | **Pass.** Child gone 5–9 ms after a master SIGKILL. Without it the child was still alive after 3 s |
| **P0.4** | Native chrome on GNOME (R2) | ✅ decided | **Your decision:** option (a), GNOME first. **Chrome experiment passed:** a real portal file dialog requested from PHP returned your chosen path as an event; the close button produced `close_requested`. Dialog was unparented; look not yet reported |
| **P0.5** | glibc PHP with FFI (R3) | ✅ | **Pass, with a condition.** PHP CLI 21.0 MB, `micro.sfx` 18.9 MB, glibc-dynamic, all 9 extensions. Spikes 1 to 3 pass on it. The shim's glibc floor (2.34 / 2.43) is higher than PHP's (2.17) |
| **P0.6** | Self re-exec in micro (R4) | ✅ | **Pass.** `PHP_BINARY` is empty; `/proc/self/exe` works. A 19.5 MB single file spawned itself as a worker over fd 3 |
| **P0.7** | Embedded shim via `memfd` | ⚠️ | **Pass** for the headless shim. Windowed shim through `memfd` inside micro not tested |
| **P0.8** | Report and doc updates | ✅ | `SPIKE_REPORT.md` written; FRAMEWORK v0.3 and the plan updated (section 2) |

### What was *not* tested
Clicks and key presses; a tray icon or menu; how the dialog looks; attaching the dialog to the window; responsiveness while the dialog is open; IME; any other distro, glibc or architecture; more than one worker at a time; `proc_open` while the multi-threaded shim is alive; the windowed shim through `memfd` inside micro; the real duration of PHP's `configure`.

---

## 3A. Phase 1 progress

| Step | What | Status | Result |
|---|---|---|---|
| **P1.1** | Project decisions | ✅ | **PHP floor 8.5 or newer** (confirmed by you). **PHP-DI 7** as the container. **PHP-CS-Fixer** with `@PER-CS3.0`. **Copyright holder Codizium.** Composer vendor stays a placeholder (`raphp/raphp` after the rename, D28). Recorded as D22 to D26. Exact tool versions close in P1.3 |
| **P1.2** | Repository scaffold | ✅ | `LICENSE` (MIT), `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `.editorconfig`, `composer.json`, `.gitignore` (your `/spikes` line kept), and the layout (`php/src`, `php/tests`, `shim`, `rlm`, `examples`). `composer validate --strict` valid; PHP 8.5.12 and all five extensions pass the platform check; a clean install works; an executable (`phpstan`) installs on the mount and runs. Nothing is committed |
| **P1.3** | Tooling and `make check` | ✅ | `make check` exits 0: formatter clean, PHPStan (`level: max`) clean, 7 PHPUnit tests pass. **Versions:** PHPUnit 13.4.6, PHPStan 2.3.5 (+ strict-rules, deprecation-rules, disallowed-calls 4.15.0), PHP-CS-Fixer 3.95.28 (`@PER-CS3.0`). **Failures shown on purpose:** bad formatting is caught with a diff; `proc_open()` in `php/src` is rejected; `stream_socket_pair()` is rejected in `php/src` and allowed in `php/tests`; a real PHP 8.5 deprecation fails PHPUnit. **Caveat:** the Rust step is *skipped* (no `shim/Cargo.toml` until P3.1), so `cargo fmt`, `clippy` and `cargo test` have not actually run. Only their presence is confirmed |
| — | **Rename to Raphp** (D28) | ✅ | Code and config by me (`raphp/raphp`, `Raphp\` namespace, `rlm/`); docs by three editors and two reviewers. Both reviewers passed and I fixed their two low notes. `make check` still exits 0. The old name remains only in the history rows D22, D27 and D28, in the spike code and its measurement rows, and in the former-name notes in README, CONTRIBUTING and CHANGELOG |
| **P1.4** | Continuous integration | ✅ | **Proven.** Your push (`f221905`) triggered the first run: it succeeded in 18 s on a GitHub runner with PHP 8.5.12 and `ffi` enabled. The "Run every check" step showed the formatter clean, PHPStan `[OK]`, PHPUnit `OK (7 tests, 8 assertions)`, and the Rust step skipped as designed. `.github/workflows/ci.yml`; actions `checkout@v7`, `setup-php@v2`, `rust-toolchain@stable`, pinned by tag, not commit. **Watch:** CI's stable Rust is newer than yours (rustfmt 1.10.0 and clippy 0.1.99 against 1.9.0 and 0.1.95), so a lint could pass locally and fail in CI later. P3.1 should pin the toolchain |
| **P1.5** | Composition root skeleton | ✅ | `make check` exits 0: **302 tests, 481 assertions**, PHPStan `level: max`. Typed `Config` from `raphp.json` plus environment (strict `ConfigLoader`), UTC PSR-20 `SystemClock`, Monolog PSR-3 logger with an enum `LogLevel` and the PSR-20 clock, PHP-DI container (`ContainerFactory`). **Attacked and fixed:** a PCRE `$` trailing-newline hole, weak path checks, `null` read as absent, unbounded pool, a crash on a non-string env value, control characters in error output. **Independent review:** 20 findings confirmed, all applied. **Mutation testing:** 60 mutations, 58 killed, 2 equivalent survivors. **Not covered:** the logger's default `php://stderr`; duplicate JSON keys keep the last (R25); no real concurrency test. Needs your approval: D29 |
| **P1.6** | Error model | ✅ | `make check` exits 0: **415 tests, 810 assertions**, no skips. `ErrorCode` enum (the nine codes of FRAMEWORK 6.6), abstract `RaphpException`, abstract `RpcException` with `wireCode()`, `toWire()` and `fromWire()`, nine final exceptions, and `ApplicationException` for `E_APP_*`. Every code round-trips through its string and through real JSON. **Mutation testing: 56 mutations, all 56 killed.** `InvalidConfig` is now `InvalidConfigException` and extends `RaphpException`. Needs your approval: D32 |

**Phase 1 findings**
- **F20:** Composer shells out to git to detect the project version, so the mount's "dubious ownership" problem affects Composer too (a warning plus a defaulted version), not only git. Git ignores `safe.directory` in a repository's own config, so the fix is a **global** entry (you already have one for tinypay, comanager and jobAI) or `git -c safe.directory=...` per command. I correct my earlier offer of a "repo-local" entry, which was never possible.
- **F20 resolved:** you ran the global `safe.directory` command. Git and Composer now work without the workaround, which I confirmed.
- **F21:** on this mount Composer installs executables as regular proxy files instead of symlinks, and they run. F19's open question is closed.
- **F30:** `Exception::getCode()` must return an int in PHP, so the string wire code is `wireCode()`.
- **F31:** PHPStan rejects assertions it can prove statically (an `assertTrue` on something that is always true, or an `instanceof` the type system already decides). Facts like "a module exception is not an IPC error" belong to the type system, not to a runtime test.
- **F23:** in PCRE `$` also matches just before a trailing newline, so `/^name$/` accepts `"name\n"`. Use `\z`.
- **F24:** PHP turns digit-only JSON object keys into integer array keys, and `{"0": ...}` decodes to a *list*. Names are therefore required to start with a letter, and the error message explains it.
- **F25:** Monolog 3.12.1 takes a PSR-20 clock and a timezone in its constructor; without them it stamps records in the process's local timezone and ignores the container's clock.
- **F26:** PHP-DI 7.1.1's circular-dependency guard is per container, not per Fiber. A service whose construction suspends makes a second Fiber fail with a misleading "Circular dependency" error (R24). A characterisation test pins it.
- **F27:** PHPUnit 13 deprecates `expectExceptionMessage()`. The tests catch the exception and assert on its message instead.
- **F28:** `addcslashes` writes TAB and CR as `\t` and `\r` and other control characters in octal (`\000`, `\037`, `\177`). Both are safe; the tests accept either form.
- **F29:** with PHP's JSON depth of 64, arrays nested 60 deep inside `php.ini` parse and 61 do not (measured, not calculated).
- **F22:** PHPUnit 13 narrows `error_reporting()` inside tests to 245 on purpose, so a test cannot assert the value (my first version of the test failed for this reason). The test now proves the behaviour instead. An expected deprecation still counts as an issue under `failOnDeprecation` unless the test also carries `#[IgnoreDeprecations]`.

**Public name chosen by you: Raphp** (Ralph PHP, pronounced "raf-pee"), D27. `raphp` is free on crates.io, npm and Packagist; a GitHub *user* named `raphp` exists and is **not** yours: your account is `ralphxp` and the repository is `ralphxp/raphp`. That blocks nothing, because Composer vendor names are independent of GitHub handles.

**Names I suggested earlier** (weak signals only, now superseded):
`axiphy` is free on GitHub, crates.io, npm and Packagist. `deltrix`, `lemnis`, `nablix`, `orthon`, `phasora`, `quadrix`, `sigmora` and `tessar` are free on crates.io, npm and Packagist but their GitHub handles exist. `kernix`, `orbix` and `vectrix` are taken in several places. Every ordinary dictionary word tried (lemma, axiom, vertex, radix, torus and others) was taken on GitHub, npm and crates.io.

---

## 3B. Phase 2 progress

| Step | What | Status | Result |
|---|---|---|---|
| **P2.1** | Kernel: loop bootstrap, signals, shutdown | ✅ | `Raphp\Core\Kernel`. Exit codes: 0 clean, 1 uncaught exception or failed hook, 130 SIGINT, 143 SIGTERM, or the code given to `stop()`; the first reason to stop wins. Shutdown hooks run last-registered first within one shared time budget (default 5 s); a failing hook is logged and the rest still run; a hook that runs out of budget is abandoned and logged. A second signal during shutdown ends the process at once. **11 integration tests** run a real child PHP process (exceptions, SIGINT, SIGTERM, double signal, hook order, hook failure, hook timeout). Repeated 8 times for flakiness: stable. The double-signal case was also run by hand: exit 130, only `READY` on stdout |
| **P2.2** | Supervised background task runner | ✅ | `Raphp\Async\BackgroundTasks`: `run()`, `count()`, `awaitAll()`, `cancelAll()`. A failing task is logged at error level with its name and exception, never raises `UnhandledFutureError`, and the loop keeps running; a cancelled task is logged at debug. **12 tests**, including a check that no future ever goes unhandled. Amp starts a task on the next tick, not inside `run()`, so `count()` right after `run()` is already 1 (a first version of the docs and tests assumed otherwise; corrected) |
| **P2.3** | NDJSON codec | ✅ | `Raphp\Ipc\Codec\{Codec, Decoder, JsonLineCodec, JsonLineDecoder}`. Encoder: compact, no raw newline, ASCII only (non-ASCII as `\uXXXX`), slashes unescaped, `[]` becomes `{}`, a list is refused. Decoder: pull-based (`feed`, `next`, `finish`, `isDead`), reads in linear time, a bad line gives an `E_PROTOCOL` item and decoding **continues**, an oversize line (complete, or an unfinished one past the limit) gives one `E_PAYLOAD_TOO_LARGE` and the decoder then **stops and discards input** (memory stays bounded). **Golden files** in `docs/protocol/golden/` (all eight message kinds plus malformed cases) pinned byte for byte and read back whole and byte by byte. Tests cover every possible split point, one byte at a time, 40 random chunkings, oversize, invalid UTF-8, invalid JSON. **Mutation testing: 29 mutations, 26 killed; 3 survivors are equivalent** (an unreachable defensive `is_array` guard and two compaction changes that affect speed, not results). It found two real gaps (a wrong rejection of `{"0":"a"}`, which PHP decodes to a list but which is a valid JSON object, and no test for a partial line pending across buffer compaction); both fixed and tested. Needs your approval: D33 (and D34 for P2.1) |

| **P2.4** | Message types | ✅ | `Raphp\Ipc\Message\`: `Kind` enum (`req`, `res`, `evt`, `cancel`, `hello`, `bye`, `ping`, `pong`), an `Envelope` interface, and eight final readonly messages (`Request`, `Response` with `success()` and `failure()`, `Event`, `Cancel`, `Hello`, `Bye`, `Ping`, `Pong`). `EnvelopeReader` turns a decoded line into a typed message or throws `ProtocolException` (`E_PROTOCOL`). **Split of duties:** the reader checks field *types* (a float, string, bool or `null` where an integer belongs is refused; `null` params are refused); the message constructors check *values* (positive ids, non-empty names, params not a list) and the reader passes their complaint on. Extra keys are ignored (a newer peer may add fields). A response needs exactly one of `result` or `error`. Untrusted text in an error message is quoted, shortened to 32 bytes and free of control characters. **98 new tests**, including: every golden message reads into the right type and **writes back to the golden bytes**; about 70 refused-message cases, each checked for its reason. **Mutation testing: 55 mutations, all 55 killed** (one survivor exposed a weak test; strengthened). Needs your approval: D35 |

| **P2.5** | Transport and stream transport | ✅ | `Raphp\Ipc\Transport\{Transport, StreamTransport}`. A transport moves bytes (the codec frames them). Over an in-process socket pair, **31 tests** show: ordered delivery; both directions at once; a 16 MiB write that waits for a slow reader while the loop keeps ticking (a ticker fired more than 5 times in 150 ms) and arrives intact (MD5); half-close; close with a read or a write waiting (read ends with `null`, write fails with `ClosedException`, no loop errors); 50 close cycles with pending reads leave no stray descriptors; a waiting read can be cancelled; two fibers writing at once never interleave their bytes. `close()` frees the descriptor at once (the watchers are cancelled first, F5). **Two findings shaped the code (F38, F39).** Mutation testing: see the results line below. Needs your approval: D36 |
| **P2.6** | RPC peer and middleware | ✅ (after review fixes) | `Raphp\Rpc\{RpcPeer, RpcPeerOptions, HandlerRegistry, MiddlewareStack, RpcMiddleware, RpcHandler, RpcRequest, RpcResponse, ProgressReporter}`, built-in middleware `TimingMiddleware`, `TraceIdMiddleware`, `ErrorMappingMiddleware`, internal `WaitList`, `Slots`, `OutboundQueue`. Optional `trace` field added to `req`. One reader fiber that never waits on a write, one writer fiber over a bounded queue (F38). Out-of-order answers, progress, cancel, timeout, error mapping, closing, limits and a 3,000-call two-way flood are tested. **Suite: 843 tests, 7,608 assertions, stable over 6 repeated runs** (one flaky timing test found and fixed). **Mutation testing: 116 mutants, 88 killed first time; after adding tests for the real gaps, 13 of the 26 re-tried survivors remain (see below).** **Independent review: 28 confirmed findings, all but one low-priority group fixed (see below).** Needs your approval: D37 |

| **P2.7** | Handshake, versioning, heartbeat | ✅ | `RpcPeer` takes an optional `Hello`: it is sent first and the other side's is required before anything but `ping`, `pong` and `bye`. A different `proto`, a second `hello`, a message sent too early, or no `hello` in 5 s gets `bye` with `E_PROTOCOL: ...` and a close; waiting calls and `awaitHandshake()` fail with `E_PROTOCOL`. New: `remote()`, `awaitHandshake()`, a graceful `shutdown(reason)` that flushes a last `bye` within a timeout, protocol violations now say why with a `bye`, a `bye` reason that starts with an error code is that error for the receiver. **Heartbeat:** a `ping` after one silent interval, the peer declared dead (`E_WORKER_CRASHED`) after `missedPings` silent intervals; off unless the owner sets an interval (intended 2 s, three misses). **Silence means no bytes at all, not no `pong`**, and a process that stalled itself does not blame its peer. **R19 resolved** by the written contract in FRAMEWORK 6.8. **91 tests added** (suite 891). **Mutation testing: 48 mutants, 40 killed first time; I tightened tests for the 8 survivors; 7 are now killed and 1 is equivalent** (a stale timer whose callback does nothing). Needs your approval: D38 |

| **P2.8** | Attribute-based handlers | ✅ | `#[RpcMethod('name')]` and `#[Idempotent]`; `MethodCatalog` reads them from classes with no objects needed (so a caller learns which remote methods are idempotent from a shared contract class); `AttributeHandlers` registers the methods from a PSR-11 container, fetching each service once at boot (R24); parameters are filled from `params` by name and type, with no conversion, unknown names refused, and `RpcRequest`, `Cancellation` and `ProgressReporter` injected; `RetryMiddleware` repeats **only** `#[Idempotent]` methods and only for transient failures, with a doubling wait that the caller's cancel ends. Misuse (a name twice, not public, static, idempotent without rpc, bad name, an object or variadic or by-reference parameter) is refused when built, naming the place. **77 tests added** (suite 968), including end to end through a live peer with a real PHP-DI container and a retry over a real connection. **Mutation testing: 50 mutants, 48 killed first time; the 2 survivors were one equivalent probe and one real gap (a non-nullable `Cancellation` with no cancellation), now tested.** Needs your approval: D39 |

| **P2.9** | Process spawner | ✅ | `ChannelFactory` (the only maker of socket pairs, close-on-exec, typed error if `ext-sockets` or the flag is missing), `ProcessSpawner` (the only code allowed to call `proc_open`; array command; `--role=worker --name=<name> --parent-pid=<pid>`; stdin `/dev/null`; the channel as descriptor 3, the master closes the worker's end at once), `WorkerHandle` (signals, `stop()` = SIGTERM then SIGKILL, exit status collected by one owner with no zombie), `OutputCapture` (stdout and stderr always drained, logged by line, last 64 KiB kept), `OrphanGuard` and `ParentDeathSignal` (libc FFI in a runtime-side namespace that does not load the UI package), `SelfCommand`, `InheritedChannel`. **The six acceptance checks all run in `make check` against real processes**: no shared sockets; closing A's end ends A while B runs on; a master killed with SIGKILL ends its workers by end of input alone and by the kernel signal alone, with a control that survives; the worker's parent is the master, not a shell; SIGTERM ends a worker and a wrong parent pid exits with code 4. **R18 resolved** (contract in FRAMEWORK 5.1). **R17 not resolved**: stays with P3.5. **67 tests added** (suite 1,035; stable over repeated runs, no stray processes). **Mutation testing: 48 mutants, 40 killed first time; the 4 real survivors (polling slowed without limit, recent output kept without bound, carriage returns kept, wrong default signal) are now killed; the other 4 are unobservable** (an explicit `fclose` or `proc_close` of a resource PHP frees anyway, and a missing `SOCK_CLOEXEC` that cannot be simulated). Needs your approval: D40 |

| **P2.10** | Supervisor | ✅ | `Raphp\Supervisor\*`: declared workers and pools (`<name>-1`, `<name>-2`, ...), the states `starting, handshaking, ready, draining, crashed, exited, degraded`, a sliding-window `RestartPolicy` (waits double from 100 ms to 10 s; the crash after 5 restarts in 60 s makes a worker `degraded`), a worker that stops answering pings is killed and restarted, routing (round-robin, least-busy), `RecyclePolicy` (after a call count or when the worker's memory passes a limit, in-flight calls drain first and no crash is counted), `stop()` (calls in flight finish, `bye`, SIGTERM, SIGKILL, with an event at each stage), and PSR-14 `WorkerEvent`s. **The `amphp/parallel` check is done: its children do not die with the master** (they run under `sh -c` and survived a SIGKILL): Raphp builds CPU pools from supervised Raphp workers instead. **81 tests added** (suite 1,088); about 25 of them run real processes. **Mutation testing: 38 mutants, 31 killed first time; 5 real survivors (SIGTERM not proven sent, a worker that never says hello left running, calls during shutdown waiting 5 s, least-busy picking the busiest, dead workers listed) are now killed; 2 are unobservable** (a defensive guard on recycling, and a late-answer race I could not provoke). Needs your approval: D41 |

| **P2.11** | Worker runtime entry | ✅ | `Raphp\Worker\WorkerArguments` (strict parse of the worker command line, a message fit to print) and `WorkerRuntime`: protects the process from outliving its master (death signal, then parent check; refuses to run without ext-ffi), **ignores SIGINT** (Ctrl-C belongs to the master, otherwise a terminal interrupt would kill workers and cause crash restarts), **installs no SIGTERM handler** (F43), opens descriptor 3, says hello, serves calls, ends with a meaningful exit code (0 orderly, 1 failure, 2 usage, 3 no channel, 4 wrong parent, 5 no safeguard). The worker fixture behind every process and supervisor test now runs on the real runtime. **Done-when check:** two workers, death signal off, master killed with SIGKILL: both end by end of input alone. **Finding F47:** descriptor 3 can be something other than a socket (PHP's own handle on the script file) in a process no master started, so `InheritedChannel` now checks it. **33 tests added** (suite 1,121). **Mutation testing: 21 mutants, 19 killed; the 2 survivors were a redundant handshake timeout (removed) and an unobservable `fclose`.** Needs your approval: D42 |
| **P2.12** | Headless example | ✅ | `examples/headless-worker`: a command-line master (`main.php`: `demo`, `job`, `crash`) and a worker (`worker.php`, `ReportService` with `#[RpcMethod]` methods) built only from the framework's public pieces: `Kernel`, `Supervisor`, `WorkerDefinition`, `AttributeHandlers`, `WorkerRuntime`. **Run live (done-when):** a 10-step job with a progress bar; a job cancelled half way (worker log shows it stopped during step 11); a worker that SIGKILLs itself in a call (`E_WORKER_CRASHED`, supervisor logs `crashed ... killed by signal 9`, restarts in 0.2 s, a new pid answers); a job on the new worker; ordered stop (`draining`, `exited`). **Real Ctrl-C:** SIGINT sent to the whole process group (master and worker, as a terminal does): the master cancelled the job, the worker ignored the signal, was told `bye` and exited, master exit code 130, no stray process. One bug found by running it: a timer callback passed its timer id into `DeferredCancellation::cancel()`, which the kernel turned into a shutdown (fixed). **4 tests added** (`HeadlessWorkerExampleTest`, slow group, runs the example for real; suite 1,125). Checks: `make check` once (green); one deliberate breakage of the Ctrl-C wiring made the Ctrl-C test fail. No mutation run: no new framework code (the example and a test-helper parameter only). `phpstan.neon` now also analyses `examples/` (clean). |
| **P2.13** | Soak and performance baseline | ✅ | `tools/bench/bench.php` (`make bench`, `--quick`, `--large-concurrency`) and `docs/BENCHMARKS.md`. Real master, real worker, the supervisor's call path. **Soak:** 50,000 round trips, ~5,900 calls/s, p50 0.16 ms; growth after warm-up: master +144 KiB resident / +7 KiB PHP usage, worker +0 / +16 B (tolerance, fixed before the run: 4 MiB / 256 KiB): **pass**. Bursts to 1,000 in flight and 8 MiB messages; repeating them adds nothing (plateau check): **pass**. R19 numbers: a small call behind a 7 MiB message waits ~150 ms. R20 baseline: master 56 MiB resident, 15.5 MiB private at boot. Checked my unmeasured numbers: none needed changing; 64 KiB chunk fastest of five; worker start 130 ms, so the 0.1 s first restart wait roughly doubles first-crash recovery. **Findings F48 to F50** (below). **1 test added** (smoke test of the script; suite 1,126). `phpstan.neon` and the formatter now also cover `tools/bench`. Needs your approval: D43 |
| **P2B.1** | Filesystem service | ✅ | `Raphp\Filesystem\{Filesystem, CooperativeFilesystem, CooperativeReadStream, FileStat, FileType, exceptions}` and the internal `TimeSlice`: `read`, `write`, `stat`, `list`, `openRead`, all cancellable; 64 KiB chunks and a turn for the event loop after each 2 ms slice; in the container. **Your decision:** an own cooperative driver rather than `amphp/file`, whose fallback is child processes that outlive their master (F46) (D44, Decided). **Blocking-call detector test:** streaming a 40 MiB file, longest gap between 1 ms ticks about 2 ms; the built-in `file_get_contents()` stalled the loop 35 to 40 ms. Measured (`docs/BENCHMARKS.md`): the stall is about one slice, and 2 ms loses no speed; **`read()` of a large file is 3 to 6 times slower than the built-in and still stalls 50 to 90 ms** (F52), so it is documented for small files and `openRead()` is for large ones. **A flaky test found a real bug (F51).** **35 tests added** (suite 1,161, run in full twice). Mutation testing: 25 mutants, 24 killed (1 unprovokable guard); the heartbeat fix 3 mutants, 2 killed, 1 equivalent. Watching files is not provided. Needs nothing from you |
| **P2B.2** | Process runner | ✅ | `Raphp\Process\ProcessRunner` over `amphp/process` (2.1.2 added) for application commands only: `run()` (a list, no shell; directory, environment, input, timeout, cancellation, capture and/or a streaming callback), `stopAll()`, `runningCount()`; `ProcessResult`, three exceptions. **Found by measuring (F53):** `amphp/process` signals only the command's pid, so children of a command are left running as orphans; each command now runs under `setsid` as the leader of its own group and stopping signals the group (SIGTERM, 2 s grace, SIGKILL). PHPStan forbids `Process::start()` outside the runner (guard shown to fire). **35 tests with real processes**, including a timeout that kills a command and two background children, SIGTERM-before-SIGKILL, `stopAll()`. Mutation testing: 25 mutants, 24 killed (a real gap fixed: non-executable file in `PATH`; one equivalent). Suite 1,196 (run in full twice). One mutation-harness baseline failure was not reproduced in 11 reruns (unknown cause). Limits: own session (Ctrl-C does not reach commands); not stopped if the master is SIGKILLed. Needs your approval: D45 |
| **P2B.3** | Stream helpers | ✅ | `Raphp\Stream\{LineBuffer, LineReader, ProgressStream, LineTooLongException}`: bounded line splitting (for chunks and for streams, also as an `amphp/pipeline` pipeline) and a stream wrapper that reports progress (throttled, exact at the end) for a worker's `ProgressReporter`. Piping is Amp's own `pipe()`; I added nothing there. **F54, measured:** Amp's `splitLines()` has no line limit and re-scans its buffer per chunk: 2.9 s against 46 ms for 16 MB without a newline. **33 tests**, mutation testing 22 of 22 killed (after two gaps found and fixed). Suite 1,230. Not added: `Filesystem::openWrite` (a file copy with progress would need it). Needs nothing from you |
| **P2B.4** | Optional accelerators | ✅ | `Raphp\Runtime\{Accelerator, Accelerators}`: reports which of ext-uv, ext-ev, ext-event, ext-eio are loaded and which Revolt driver runs. Raphp selects nothing itself (Revolt does, by a documented order). **Verified with the real extensions** (you installed the two `-devel` packages; I built ext-uv 0.3.1 and ext-ev 1.2.3 in a scratch folder): the whole suite, in master and workers, first had 2 failures of 1,238 under ext-uv; after the fixes both drivers pass all 1,238. **F55, a real difference:** under ext-uv a plain `pcntl_signal()` handler waits for the next event (rule: signals only through `EventLoop::onSignal()`; Raphp's own code already does). The other failure was my own detector control comparing single samples (now three). Not done: ext-eio unused, ext-event untested, no speed measured. 8 tests, mutation testing 10 of 10 killed. Needs your approval: D46 |
| **P2B.5** | Blocking-calls guide | ✅ | `docs/BLOCKING_CALLS.md` (documentation only, as planned): 14 blocking PHP calls with the **measured** stall of the loop (`tools/bench/blocking.php`), the replacement and whether it exists yet; replacements measured too; a stall-detector recipe and a PHPStan `disallowed-calls` recipe, both checked. Honest gaps listed (no chunked write, no delete/rename/mkdir, network in Phase 7, DNS in Phase 8). Also measured: the first `ProcessRunner` call stalls 25 to 54 ms, later ones 5 to 11 ms, and a spawn from an 870 MiB master 18 ms. 4 tests for the demo example. Needs nothing from you |

**P2.6 closing notes**
- **Independent adversarial review: applied (2026-11-07).** Six lenses, two skeptics per finding, run `wf_b96d35d5-8a2`, 76 agents: 35 candidates, 28 survived verification, 7 refuted. Full result: `~/.claude/projects/-mnt-C62A254E2A253CB5-Cod-dev-niphy/de5231df-0354-49b9-9f83-be10c9530b1d/subagents/workflows/wf_b96d35d5-8a2/journal.jsonl`. **Fixed, each with a test that fails without the fix** (checked by re-introducing the bug as a mutant: 26 of 28 such mutants killed, the 2 survivors explained below):
  - **Memory exhaustion by a peer that stops reading (2 high findings):** a request now stays counted against `maxInbound` until its answer is in the outbound queue (the flood test sends requests one at a time and the peer cuts the connection after about 290; with the old code it accepted all 1,500). Messages the reader itself must send go through a reserve equal to the queue size and past that the connection is closed; a `pong` is queued only if none is already waiting, so 20,000 pings cost a handful of pongs.
  - **One message could kill the process:** a line of tiny arrays costs about 59 bytes of memory per byte of line (measured), so 8 MiB decoded to about 490 MB. New limit of **200,000 arrays and objects** per message, checked before decoding and on encode.
  - **Encoder and decoder disagreed on depth:** the decoder refused exactly the depth the encoder allowed (PHP's `json_decode` needs the limit one higher than `json_encode`). Both now accept exactly 64 levels, the message being level 1. Also: a float with no fraction stays a float (`10.0`), and the round-trip limits (empty nested object, duplicate keys keep the last value) are written down in FRAMEWORK 6.3 and pinned by tests.
  - **An invalid call leaked a slot and a pending entry:** calls are validated before anything is taken for them.
  - **Inbound middleware had no real test:** five tests now run it through a live peer (order, short circuit, changing the request, error mapping, timing with the trace id).
  - **Kernel:** `run()` no longer hangs if the shutdown phase as a whole fails (exit code 1 instead); a `main` left over from a finished run can no longer stop a later run; hooks run once and are not repeated by a later run.
  - **BackgroundTasks:** a task that returns a `Future` now lasts until that future is done and its failure is logged (no more `UnhandledFutureError`); a `CancelledException` that is not from `cancelAll()` (the task's own timeout) is logged as a failure; a logger that throws no longer loses the error or breaks `awaitAll()` (last resort: PHP's error log).
  - **Smaller:** callers waiting for a slot now get the real reason the connection ended; `method` names and a `bye` reason are capped at 256 bytes and a peer's error message at 2,048 bytes (cut on a character boundary); the progress test now really delivers a late progress event; the golden files now contain a `trace` field; the `Validation` docblock no longer claims a rule that does not exist.
  - **Not fixed:** several `RpcPeer` failure branches have no test (a write error, a throwable in the read loop, an answer arriving in the same instant as a timeout): the first two are hard to provoke from outside and the third is a race. They are defensive code, not verified.
- **13 mutants still survive** after the second round. I judge most equivalent (a redundant guard, bookkeeping that no longer matters once the connection is closed, a progress report after close that is caught anyway, a race that cannot be set up reliably): `notify on a closed peer allowed`, `answer that arrived in the same moment is thrown away`, `given-up call still pending`, `caller cancel reported as timeout when both`, `write failure ignored`, `drain result ignored`, `answers sent after close`, `answers do not wait for room`, `progress after close raises`, `pending slots not released at close`, `abandoned slots not released at close`, `waiting callers not woken at close`, `wake-up lost when it races a cancel`. I have **not** verified each one individually. `answers do not wait for room` is the one most worth a second look: it could let a stalled far end make the outbound queue grow past its bound.
- Not yet written for P2.6: the Result block in `IMPLEMENTATION_PLAN.md`, the `CHANGELOG.md` line, the file list in section 7 of this file, and the change-log entry. FRAMEWORK 6.4 and decision D37 are written.
- Unverified: the default limits (64 in flight, 1024 waiting, 256 inbound, 256 queued) are my numbers; P2.13 should measure them.

**Phase 2 findings**
- **F40:** `json_decode` needs a depth limit one higher than `json_encode` for the same nesting, so a codec that passed one limit to both refused at decode what it wrote at encode (found by the review of P2.6; fixed).
- **F41:** measured memory cost of a decoded JSON line: about 59 bytes per byte of line for `[1],[1],...` and about 54 for small objects; empty arrays and plain numbers cost 7 to 10. The line-size limit alone does not bound memory.
- **F55 (measured, P2B.4):** under ext-uv a plain `pcntl_signal()` handler is delayed while the loop is idle: libuv restarts the interrupted wait, so PHP code runs only at the next event (measured 319 ms against 214 ms with another signal waking the loop, unbounded without one). ext-ev and the default driver run it at once; `EventLoop::onSignal()` works on all. Rule recorded in D46 and `CONTRIBUTING.md`. Raphp's code was already compliant; a test fixture was not and was fixed.
- **F54 (measured, P2B.3):** `Amp\ByteStream\splitLines()` has no limit on line length (a source that never sends a newline grows the buffer without bound), strips every trailing carriage return rather than the one that belongs to the line ending, and explodes the whole buffer again for each chunk, so a long line costs time in proportion to its square: 2.9 s for 16 MB in 4 KiB chunks, against 46 ms for Raphp's `LineBuffer`. On ordinary text the two are equal.
- **F53 (measured, P2B.2):** `amphp/process` signals only the command's own pid (both `signal()` and `kill()`), and starts no process group. A command that starts children (a shell script, `make`) leaves them running as orphans when it is stopped; `sleep` processes were seen reparented to init. The runner starts each command under `setsid` (which executes in place, so the pid is the group id) and signals the group. Side effects, both documented: the command no longer receives the terminal's Ctrl-C, and it is not stopped if the master is killed with SIGKILL.
- **F52 (measured, P2B.1):** `Filesystem::read()` assembles a whole file from chunks, which is one copy that cannot be spread over turns of the loop: for 32 MiB it takes 120 to 230 ms (three to six times `file_get_contents()`), still stalls the loop 50 to 90 ms at the end, and needs twice the file's size in memory. The chunk size does not matter. It is documented as the method for small files; `openRead()` streams large ones with a stall of about one slice (2 ms).
- **F51 (found, P2B.1, a real bug in P2.7):** the heartbeat counted the time before the other side's `hello` as silence. A worker process takes time to boot, so with a short heartbeat on a loaded machine a slow start was declared a crash and restarted, until the worker was marked degraded. With the production defaults (2 s, three misses) it needs a 6 s start, so it was rare; it showed as `SupervisorTest::testAWorkerThatStopsAnsweringPingsIsKilledAndRestarted` failing about one run in six under load. Fixed: until the hello has arrived nothing is counted and no ping is sent (the handshake timeout judges the start); a test that failed before covers it.
- **F50 (measured, P2.13):** the recycling threshold reads `VmRSS`, which counts pages shared between processes: a fresh PHP process shows 52 to 56 MiB resident but only 13 to 16 MiB private (`RssAnon`). `RecyclePolicy::maxMemoryBytes` (D41) has to be set above that baseline, and a worker's own growth is better measured by `RssAnon`. I did not change `ProcessMemory`; it is a one-line change to decide.
- **F49 (measured, P2.13):** a message needs about 6 times its size in PHP memory on the master and about 5.5 times on the worker while it passes through, so an 8 MiB message needs about 48 MiB of headroom. Safe under the default 128M `memory_limit`, not under 64M. The 8 MiB cap was chosen without this measurement.
- **F48 (measured, P2.13):** every queue and limit in the RPC layer counts messages, not bytes. Master memory grows linearly with the number of large calls in flight (about 4 MiB per 4 MiB call, 88 MiB at 16 in flight); at the defaults 64 calls of 8 MiB would hold about 512 MiB. Nothing changed; recorded as risk R26 in `FRAMEWORK.md`. A byte budget is the likely fix, a design decision.
- **F47 (found, P2.11):** in a process that no master started, descriptor 3 can be an open file (PHP's own handle on the script), so "open descriptor 3" succeeded and the worker went on to fail later with a confusing exit code. `InheritedChannel` now checks that descriptor 3 is a socket, and the no-channel case has its own exit code (3).
- **F46 (verified, P2.10):** `amphp/parallel` 2.4.0 runs each child as `sh -c { ... }`, so the child's parent is a shell, and the child survived the SIGKILL of the master (the same cause as F18). Its worker pools have no parent-death protection. Raphp's CPU pool is therefore a supervised pool of its own workers. Fork contexts were not tested.
- **F43 (new, tested):** a worker whose loop is busy and that has installed a SIGTERM handler through the event loop does **not** end on SIGTERM (still running 0.7 s later); only SIGKILL ends it. The handler is queued for the loop, and a busy loop never runs it. Consequences: a worker must not install such a handler (the default action ends it), CPU work stays off the worker's loop, and the supervisor escalates to SIGKILL, which `WorkerHandle::stop()` does. The same holds for the kernel's death signal if a handler is installed.
- **F44:** PHP reports a missing program or directory at once: `proc_open` with an array command returns false with the system's reason (`posix_spawn() failed: No such file or directory`), so a spawn error is immediate and a worker that was never found cannot look like a worker that exited.
- **F45:** `getmypid()` returns `int|false`; sending `false` as the parent pid would have produced an empty `--parent-pid=`. The spawner now refuses to start a worker when it cannot tell its own pid.
- **F42:** a test for a flood that sends all requests in one burst proves nothing about handler lifetime: the whole burst is read as one batch, and the inbound limit is hit before any handler has started. Pace the requests.
- **F38 (measured):** an AF_UNIX stream socket accepts only about **278 small writes** (any size up to roughly 700 bytes) before the next write has to wait for the reader; 100-byte writes stop after 27.8 KB, 10 KB writes after about 170 KB. Back-pressure therefore starts after a few hundred small messages, not after 200 KB. My own test deadlocked on this (2,000 small writes and a reader in the same fiber). **P2.6 must design the sending path for it.**
- **F39 (read from source, then reproduced):** in amphp/byte-stream 2.1.3 `WritableStream::end()` does not wait for queued writes, although its documentation says it does. Once the stream is no longer writable the write watcher fails the remaining queued chunks with `ClosedException`. A `bye` followed by `end()` could lose the tail of a message. `StreamTransport::end()` therefore counts writes in flight and waits for them before ending the stream.
- **F36:** the first draft of the protocol example in FRAMEWORK 6.4 gave a progress `evt` an `id` equal to its `ref`. An event is never answered, so it has no use for an id. I removed it from the spec and from the golden files (the Rust side does not exist yet, so nothing else depended on it).
- **F37:** after `json_decode(..., true)` a JSON array and an object with digit keys both look like a PHP list, so a `params` list cannot be told from an object that way. `params` therefore must be an object with named keys, and a non-empty list is refused (same cause as F24 and F32).
- **F32:** PHP decodes a JSON object whose keys are `"0"`, `"1"`, ... to a *list* (same cause as F24). The decoder therefore cannot use "is it a list?" to tell objects from arrays. A line that starts with `{` and parses is an object, and the envelope layer (P2.4) must judge its keys.
- **F33:** `json_decode` reports invalid UTF-8 as `JSON_ERROR_UTF8`, so the decoder can say "invalid UTF-8" instead of a generic JSON error.
- **F34:** the oversize rule has to cover an *unfinished* line too, otherwise a peer that never sends a newline makes the decoder buffer without end. The decoder gives up once the pending bytes pass the limit, at most one chunk late.
- **F35:** the test tooling is part of the risk. My first mutation run left a mutated source file behind when the harness crashed on non-UTF-8 test output, and the "baseline" in the copy failed for a stale `vendor/`. I fixed the harness (restore in `finally`, lenient decoding) and verified that the copy equals the repository before trusting any result.

## 4. Findings

Full table with sources: `SPIKE_REPORT.md` section 4. All are now reflected in `FRAMEWORK.md` v0.3 (appendix C) and the plan.

| ID | Finding |
|---|---|
| F1 | Socket pairs must use `SOCK_CLOEXEC`; every Raphp descriptor must be close-on-exec |
| F2 | `proc_open` takes an array command, never a string (derived, not separately tested) |
| F3 | The worker's SIGTERM handling must terminate it |
| F4 | The parent leaks its script fd into children (harmless for EOF) |
| F5 | Cancel Revolt watchers before closing a watched stream |
| F6 | winit has no Wayland popup support; GNOME has no server-side decorations; the tray needs an extension |
| F7 | Clipboard needs `smithay-clipboard`, not `arboard` |
| F8 | Redraws must be dirty-driven and paced to the compositor |
| F9 | musl static builds cannot use `ffi`; a glibc target is required |
| F10 | `spc doctor --no-interaction` attempts fixes (including `sudo`) on its own |
| F11 | In PHP 8.5 use `$ffi->new()`; the static `FFI::new()` is deprecated |
| F12 | **Correction of my earlier claim:** `ffi.enable=preload` does not block FFI in CLI or micro |
| F13 | `PHP_BINARY` is empty in micro; use `/proc/self/exe` |
| F14 | The shim's glibc floor (2.34 / 2.43) is higher than PHP's (2.17) |
| F15 | `spc micro:combine -I` bakes ini. Sizes: micro 18.9 MB, php 21 MB, app 19.5 MB |
| F16 | Shim loads from inside the phar via `memfd_create` plus `dlopen` (headless) |
| F17 | Zig 0.17.1 and `pkg-config` are installed locally by `spc doctor` |
| F18 | `amphp/process` wraps commands in `/bin/sh` and uses fd 3 itself, so it cannot spawn workers (source read and confirmed by me) |
| F19 | The project directory is a root-owned 777 mount; git warns "dubious ownership" there (see F20, F21 in section 3A) |

### Open design gaps found by the audit (R14 to R23)
None has a solution yet. Each names the plan step whose acceptance must resolve it (`IMPLEMENTATION_PLAN.md` section 1, `FRAMEWORK.md` section 16).

| ID | Gap |
|---|---|
| R14 | A panic or exit on the shim's own UI thread is not covered |
| R15 | No ordering contract between the command queue and the event queue (defined in P3.3, tested in Phase 4) |
| R16 | An event larger than the poll buffer can stall all UI input |
| R17 | `proc_open` forks while a live multi-threaded shim exists in the master |
| R18 | No rule for reaping workers and draining their stdout/stderr |
| R19 | Pings and cancels can be starved behind a very large message |
| R20 | The master cannot be recycled yet holds the most long-lived state |
| R21 | Hot reload covers worker code only, but most edits land in master code |
| R22 | `SO_PEERCRED` is not exposed by PHP's sockets extension, so the secondary path-socket auth is unspecified |
| R23 | The shim's glibc floor is higher than PHP's (fix D21 approved on 2026-11-06; the build recipe is not verified yet) |
| R24 | PHP-DI's circular-dependency guard is not Fiber-aware: construct services without suspending (verified, rule adopted) |
| R25 | A manifest that repeats a JSON key keeps the last value silently (open, accepted) |

---

## 5. Waiting on you

| # | Need | Status |
|---|---|---|
| 1 | **Checkpoint 1 review.** You told me to continue to Phase 2, so I treat the Phase 1 review as passed. Say so if you want changes | **Answered by "continue the next phase"** |
| 2 | **Commit and push my work since `aa1e919`** (P1.5 hardening, P1.6, chrome decision, P2.1 to P2.3, docs). CI has not run on any of it. I make no commits | **Open** |
| 3 | ~~Approve or change D29 and D32 to D38~~ | **Approved by you on 2026-11-07 ("approved, continue")**, and recorded as Decided in `FRAMEWORK.md`. **D30** (log level is an enum, UTC timestamps from the PSR-20 clock) was not in that list and is still marked Proposed |
| 3b | **Approve or change D39** (attribute handlers: unknown parameter names are an error, no type conversion, retries only for `#[Idempotent]` methods and only for transient failures; retry defaults of 3 attempts and 0.1 s doubling are my numbers) | **Approved by you on 2026-11-07** (recorded as Decided) |
| 3c | **Approve or change D40** (the process spawner contract; the 64 KiB of output kept, the 8 KiB line cut and the one-second output grace are my numbers) | **Approved by you on 2026-11-07** (recorded as Decided) |
| 3d | **Approve or change D41** (supervision: states, a sliding-window restart policy, killing and restarting a hung worker, the stop order, recycling; **every number is mine and not measured**: 5 restarts in 60 s, waits of 100 ms doubling to 10 s, the 5 s, 2 s, 10 s and 30 s timeouts). Also a finding for you: CPU pools will not use `amphp/parallel` (F46) | **Approved by you on 2026-11-07** (recorded as Decided) |
| 3e | **Approve or change D42** (worker entry: SIGINT ignored in workers, no SIGTERM handler, a strict command line, exit codes 0 to 5; ignoring SIGINT is my choice and the headless example relies on it) | **Approved by you on 2026-11-07** (recorded as Decided) |
| 3f | **Approve or change D43** (the memory tolerance and the master's baseline from P2.13; the numbers are mine and probably too loose). **Decide on F48/R26** (a byte budget for queues and calls in flight) **and F50** (should the recycling threshold read private memory, `RssAnon`, instead of `VmRSS`?). I changed neither | **D43 approved by you on 2026-11-07** (Decided). **F48/R26 and F50 are still open** |
| 3g | **Approve or change D45** (application commands: each runs under `setsid` in its own group, so it is no longer in the terminal's process group and is not stopped if the master is SIGKILLed; output limit 16 MiB, grace periods 2 s and 1 s are my numbers) | **Approved by you on 2026-11-07** (recorded as Decided) |
| 3h | **Approve or change D46** (accelerators: Raphp selects no driver itself, signals only through `EventLoop::onSignal()`, ext-eio unused, suite verified under ext-uv and ext-ev) | **Open** |
| 4 | Packagist vendor `raphp`: availability unchecked (Packagist timed out). Check when you are ready to publish | Later |

Already answered: PHP 8.5 or newer, PHP-DI, PHP-CS-Fixer, copyright holder Codizium, public name Raphp, rename now (done), `rlm`, GitHub, global `safe.directory`, D21 approved, **the native chrome accepted without reservation (D31)**, commits are yours.

---

## 6. Decisions made so far

The authoritative log is `FRAMEWORK.md` section 15. In summary:

- **Product:** async PHP runtime plus optional native GUI. Node is only an analogy. PHP community conventions: PER-CS, PSRs, strict types, constructor injection, no facades, no fluent chaining, no promise chaining.
- **GUI:** hybrid (i), native chrome plus custom-drawn content. Rust shim `libraphp`. UI thread inside the shim. Native chrome option (a), GNOME first.
- **Platform:** Linux first.
- **Runtime:** Revolt + Amp v3 Fiber style, all tiers staged. NDJSON over AF_UNIX. Socket pair primary.
- **CLI:** `rlm` (Raphp libs manager, formerly `nlm`), wrapping Composer, managing native libs, managed PHP by default.
- **Quality:** PER-CS, PHPStan strict, PHPUnit. The formatter is PHP-CS-Fixer (D25).
- **License:** MIT, semantic versioning.
- **Naming:** the public name is **Raphp** (D27). The project was renamed from the code name `niphy` on 2026-11-06 (D28). Nothing is published.
- **Chrome (D31, decided):** the native chrome from the chrome experiment is accepted without reservation and built in P3.6 and P3.12.
- **P1.6 (D32, proposed):** the error model.
- **P2.1, P2.3, P2.4 and P2.5 (D34, D33, D35, D36, proposed):** the kernel's exit codes and shutdown budget; the codec and decoder contract; the message validation rules; the transport contract.
- **P1.5 (D29, D30, proposed):** manifest and environment rules with limits; the log level is an enum and the logger uses the PSR-20 clock in UTC.
- **Phase 1 tooling (D23 to D26):** PHP 8.5 or newer, PHP-DI 7, PHP-CS-Fixer with `@PER-CS3.0`, copyright holder Codizium.
- **Errors:** master logs and shuts down non-zero; worker exits non-zero and is restarted.
- **Middleware:** Amp-native for HTTP, one PSR-15-shaped contract for Raphp RPC.
- **New from Phase 0:** D18 raw `proc_open` with an array command, never `amphp/process` (source-checked); D19 self re-exec via `/proc/self/exe` (verified); D20 `$ffi->new()` and no `ffi.enable` baking needed (verified).
- **Deferred:** minimum glibc (Phase 6), accessibility, Windows and macOS.

---

## 7. Files created so far

**Project documents** (`docs/`): `FRAMEWORK.md`, `IMPLEMENTATION_PLAN.md`, `PROGRESS.md`, `SPIKE_REPORT.md`

**Repository root (Phase 1):** `LICENSE`, `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `.editorconfig`, `.gitignore`, `composer.json` (the author is your own name, edited by you), `composer.lock`, `Makefile`, `phpstan.neon`, `.php-cs-fixer.php`, `phpunit.xml`, `php/tests/EnvironmentTest.php`, `shim/rustfmt.toml`, `shim/clippy.toml`, placeholders in `php/src`, `php/tests`, `shim`, `rlm`, `examples`, and from P1.4 `.github/workflows/ci.yml`. **P1.5 adds** under `php/src/Core`: `Config/` (8 classes), `Logging/` (`LogLevel`, `LoggerFactory`, `LineLogFormatter`), `Clock/SystemClock`, `Container/ContainerFactory`; and under `php/tests`: `Core/Config/ConfigLoaderTest`, `Core/Logging/{LogLevelTest, LoggerFactoryTest}`, `Core/Clock/SystemClockTest`, `Core/Container/ContainerFactoryTest`, `Support/{ConfigFixture, FrozenClock, NeedsLoggerAndClock}`. **P1.6 adds** `php/src/Error` (`ErrorCode`, `RaphpException`, `RpcException`, `ApplicationException` and nine `*Exception` classes, one per code) and `php/tests/Error/{ErrorCodeTest, RpcExceptionTest, HierarchyTest}`; `InvalidConfig` was renamed `InvalidConfigException`. **Committed:** everything up to P1.3 (`initial commit` `83c8a97`), the rename and CI (`f221905`), and the first version of P1.5 (`aa1e919`), all pushed to `ralphxp/raphp`. **Not committed:** my P1.5 hardening (source and tests), the new `LogLevel` and `LineLogFormatter` classes, and the doc updates since `aa1e919`.

**Throwaway spikes** (`spikes/`, about 1.8 GB in total, most of it `04-static-php/work` at 1.4 GB and `shim-win/target` at 318 MB; both must be ignored by git):
- `01-shim-roundtrip/`: Rust shim stage A (`shim/`), windowed shim with file dialog (`shim-win/`), `spike01.php`, `spike01b.php`, `hold.php`, `chrome.php`
- `02-socketpair/`: `parent.php`, `child.php`, `fdcheck.php`
- `03-pdeathsig/`: `runner.php`, `middle.php`, `child.php`
- `04-static-php/`: `build.sh`, `progress.sh`, `bin/spc` (+ checksum), `work/` (sources, libraries, the built `php` and `micro.sfx`), `app/`, `app2/`, `make_phar.php`, `out/` (the combined binaries)
- `phase0-doc-audit-result.json`: the full audit result

**Outside the project:** the early planning file `~/.claude/plans/before-we-continue-this-twinkling-plum.md` (superseded), backups of the docs and workflow scratch files under the session scratchpad, and `perl` / `re2c` installed on your system.

**Phase 2 adds** under `php/src`: `Core/Kernel`, `Async/BackgroundTasks`, `Ipc/Codec/{Codec, Decoder, JsonLineCodec, JsonLineDecoder}`, `Ipc/Message/{Kind, Envelope, Validation, EnvelopeReader, Request, Response, Event, Cancel, Hello, Bye, Ping, Pong}`, `Ipc/Transport/{Transport, StreamTransport}`; `Rpc/{RpcPeer, RpcPeerOptions, RpcRequest, RpcResponse, RpcHandler, RpcMiddleware, MiddlewareStack, HandlerRegistry, ProgressReporter, NullProgressReporter}`, `Rpc/Middleware/{TimingMiddleware, TraceIdMiddleware, ErrorMappingMiddleware}`, `Rpc/Internal/{WaitList, Slots, OutboundQueue, PendingCall, ClosureHandler, ClosureProgressReporter, MethodInvoker, ParameterBinding, BindingKind}`, `Rpc/{MethodCatalog, MethodDescriptor, AttributeHandlers}`, `Rpc/Attribute/{RpcMethod, Idempotent}`, `Rpc/Middleware/RetryMiddleware`, `Process/{Channel, ChannelFactory, ExitStatus, InheritedChannel, OrphanGuard, OutputCapture, ProcessSpawner, SelfCommand, SpawnException, WorkerHandle, WorkerSpec}`, `Runtime/Libc/{ParentDeathSignal, LibcException}`, `Supervisor/{Supervisor, WorkerDefinition, WorkerState, WorkerEvent, WorkerEventType, RestartPolicy, RecyclePolicy, Routing}`, `Supervisor/Internal/{Member, Pool, ProcessMemory}`, `Worker/{WorkerArguments, WorkerRuntime}`, `Ipc/Protocol`, `Error/Text`; under `php/tests`: `Core/KernelTest`, `Async/BackgroundTasksTest`, `Ipc/Codec/{JsonLineCodecTest, JsonLineDecoderTest, GoldenFilesTest}`, `Ipc/Message/{MessagesTest, EnvelopeReaderTest}`, `Ipc/Transport/StreamTransportTest`, `Rpc/{RpcPeerTest, RpcPeerProtocolTest, RpcPeerLimitsTest, RpcPeerHandshakeTest, RpcPeerHeartbeatTest, HandlerRegistryTest, RpcValuesTest}`, `Error/TextTest`, `Rpc/Middleware/{MiddlewareTest, RetryMiddlewareTest}`, `Rpc/{MethodCatalogTest, AttributeHandlersTest}`, `Rpc/Fixture/*`, `Process/{ProcessSpawnerTest, ProcessOrphanTest, ChannelFactoryTest, SelfCommandTest, WorkerSpecTest}`, `Fixtures/{worker, master}.php`, `Supervisor/{SupervisorTest, SupervisorValuesTest}`, `Worker/{WorkerArgumentsTest, WorkerRuntimeTest}`, `Process/InheritedChannelTest`, `Support/{WorkerFixture, RecordingDispatcher}`, `tools/mutation/mutate.py`, `Rpc/Internal/{WaitListTest, SlotsTest, OutboundQueueTest}`, `Core/KernelInProcessTest`, `Support/{Recorder, AsyncTestCase, PeerPair, RawEnd, RawConnection}`, `Fixtures/kernel.php`, `Support/{ChildProcess, ChildResult}`; and `docs/protocol/golden/{messages.ndjson, messages.json, malformed.json, README.md}`. `composer.json` now requires `amphp/amp`, `amphp/byte-stream`, `revolt/event-loop`, `php-di/php-di`, `monolog/monolog` and the PSR interfaces.

**P2.12 adds:** `examples/headless-worker/{main.php, worker.php, README.md, src/{Application, ConsoleEvents, ReportService}.php}` and `php/tests/Examples/HeadlessWorkerExampleTest.php`; `composer.json` autoloads the example namespace (dev); `phpstan.neon` analyses `examples/`; `Support/ChildProcess::run()` takes a ready marker.

**P2.13 adds:** `tools/bench/{bench.php, worker.php}`, `docs/BENCHMARKS.md`, `php/tests/Examples/BenchmarkScriptTest.php`, a `make bench` target; `phpstan.neon` and `.php-cs-fixer.php` cover `tools/bench`.

**P2B.1 adds:** `php/src/Filesystem/{Filesystem, CooperativeFilesystem, CooperativeReadStream, FileStat, FileType, FilesystemException, FileNotFoundException}.php`, `Filesystem/Internal/TimeSlice.php`, `tools/bench/filesystem.php`; tests `Filesystem/{CooperativeFilesystemTest, BlockingCallDetectorTest, Internal/TimeSliceTest}`; `ContainerFactory` binds `Filesystem`; a fix in `Rpc/RpcPeer` (heartbeat).

**P2B.2 adds:** `php/src/Process/{ProcessRunner, ProcessResult, OutputStream, CommandException, CommandTimeoutException, CommandOutputLimitException}.php`, `Process/Internal/{ExecutableFinder, RunningCommand}.php`; test `Process/ProcessRunnerTest`; `composer.json` requires `amphp/process`; `phpstan.neon` forbids `Process::start()` outside the runner.

**P2B.3 adds:** `php/src/Stream/{LineBuffer, LineReader, ProgressStream, LineTooLongException}.php`, `tools/bench/lines.php`; tests `Stream/{LineBufferTest, LineReaderTest, ProgressStreamTest}`.

**P2B.4 adds:** `php/src/Runtime/{Accelerator, Accelerators}.php`; test `Runtime/AcceleratorsTest`; a fixture and a test changed (see F55); `CONTRIBUTING.md` section on running the suite under another driver.

**P2B.5 and Checkpoint 2B add:** `docs/BLOCKING_CALLS.md`, `tools/bench/blocking.php`, `examples/large-file/main.php`, `php/tests/Examples/LargeFileExampleTest.php`; the README lists two more documents.

**Not created yet:** the shim crate and the `rlm` CLI.

---

## 8. Environment

| Item | Value |
|---|---|
| OS / desktop | Fedora 44, GNOME 50.5 Classic, Wayland (XWayland available) |
| System PHP | 8.5.12 NTS with `php-ffi`; `display_errors` off, so deprecations are hidden |
| Built PHP | `spikes/04-static-php/work/buildroot/bin/php` (glibc, ffi, pcntl, posix, sockets, mbstring, openssl, zlib, sodium, phar) |
| Build toolchain | `static-php-cli` 2.8.6, Zig 0.17.1 (local to the build), `perl`, `re2c` |
| Rust / Composer | 1.95.0 / 2.10.3 |
| Cores | 4 |
| Containers | `podman` and `docker` available |
| Git | repository exists (`master`, one commit `83c8a97`, remote `https://github.com/ralphxp/raphp.git`); `gh` is logged in as `ralphxp` |

---

## 9. Change log

**2026-11-05**
- Established the master/worker/IPC model, then refined it through discussion: native GUI via FFI, hybrid design, Rust shim, `nlm`, Linux first.

**2026-11-06**
- Environment check found `php-ffi` missing; you installed it.
- Spike 1 stage A: found and fixed a design flaw (pipe as event buffer lost 88% of events) and corrected a mislabeled FFI-cost measurement.
- Wrote `FRAMEWORK.md`, revised it after you clarified that Node is only an analogy and PHP conventions apply, then recorded decisions O1 to O13 (MIT, O12, O13 included).
- Wrote `IMPLEMENTATION_PLAN.md`.
- Phase 0 started. P0.1 to P0.3 done. You first said "no window", then clarified you saw one that closed when the run ended.
- P0.4 findings gathered; you chose option (a), GNOME first.
- Created this tracker at your request.
- P0.5: you installed `perl` and `re2c`. `spc doctor` passed. The build ran in the background, across your overnight hibernation. I misread the resulting log gap as a slow `configure` and told you it would take hours; that was wrong, and I corrected it. Added `progress.sh` so you could watch it.
- P0.5 to P0.7 tested and passed (see section 3). Found that `ffi.enable=preload` does not block FFI in CLI or micro, correcting my earlier claim.
- A 5-lens audit with verification found 33 real doc issues; two editing rounds and my own final pass applied them. Wrote `SPIKE_REPORT.md`.
- You asked about native chrome. I explained it, and built the chrome experiment (window plus a real portal file dialog). You ran it and pasted the output: dialog and close button both worked end to end.
- Phase 0 finished. Checkpoint 0 pending.
- Phase 1 started on your instruction. You answered the P1.1 questions: PHP floor ("php 5", read as 8.5), PHP-DI, PHP-CS-Fixer, copyright holder Codizium. You also decided that `niphy` stays as the code name and the public name comes later.
- Checked your git repository: `master`, no commits, no remote, identity set, your `.gitignore` has `/spikes`. Found git and Composer both stop on "dubious ownership". Corrected my earlier claim that a repo-local `safe.directory` was possible.
- Suggested candidate public names and checked them against GitHub, crates.io, npm and Packagist.
- P1.1 and P1.2 done and recorded (D22 to D26 in `FRAMEWORK.md`).
- You confirmed the PHP floor as 8.5 or newer and chose the public name **Raphp**. Checked it against crates.io, npm and Packagist (free) and GitHub (a `raphp` handle exists).
- P1.3 done: installed PHPUnit, PHPStan with three extensions, and PHP-CS-Fixer; wrote the configs, `Makefile` and a smoke test. My first smoke test failed because PHPUnit 13 narrows `error_reporting()` on purpose (F22); I fixed the test, not the setup. Showed each deliberate failure being caught, then removed the temporary files.
- You answered: rename now (my recommendation), `nlm` becomes `rlm`, GitHub is the host, and you did the commit and the `safe.directory` entry. Your commit `83c8a97` is on `ralphxp/raphp`.
- Found that your GitHub account is `ralphxp` and `raphp` is the repository name, so the GitHub user `raphp` is not yours (it does not block anything).
- Renamed the project to Raphp: I did code and config (`composer.json`, namespaces, the test, the formatter folder list, `nlm/` to `rlm/`, lock hash); three editor agents did the docs; two reviewers passed both. I fixed their two low notes (a wrongly rewritten `raphp run`, and a stale CONTRIBUTING sentence). `make check` still exits 0.
- P1.4: wrote `.github/workflows/ci.yml`, using current action versions looked up on GitHub. It has not run yet.
- You answered: D21 approved, you like the window look (rounded corners, shadows), continue with P1.5. I verified your push: commit `f221905`, CI green on a runner with PHP 8.5.12 (P1.4 proven).
- P1.1 to P1.3 were already recorded; P1.5 built: typed config, strict loader, clock, logger, container. My own hostile-input probe found six real defects; all fixed with regression tests.
- Ran an independent adversarial review of P1.5 (three lenses, two skeptics per finding, 59 agents): 20 confirmed, 8 rejected. Applied all 20, including turning the log level into an enum and recording the PHP-DI Fiber limit as R24.
- Ran my own mutation test: 60 mutations, 4 survived, I added tests for the two real gaps, then 58 of 58 non-equivalent mutants were killed. Docs updated (FRAMEWORK 9.2, 4.9, D29, D30, R24, R25; plan; changelog).
- You committed the first version of P1.5 as `aa1e919` and pushed it; CI ran green (21 s). I had wrongly said it was all uncommitted; corrected here.
- You said the chrome experience was "great in human terms" and to implement it with no doubts. Recorded as D31: the look is settled, and the remaining checks (tray, dialog parenting, responsiveness) are implementation work, not doubts.
- P1.6 built: the error model (`ErrorCode`, `RaphpException`, `RpcException`, nine exceptions, `ApplicationException`) with wire conversion. PHPStan rejected two of my tautological tests and I replaced them. Mutation test: 56 of 56 killed.
- Checkpoint 1 check: a clean copy of the tree (no `vendor/`, no caches) installed from `composer.lock` and passed `make check` (415 tests). Phase 1 finished.
- You said "continue the next phase". Phase 2 started.
- P2.1 built and tested against a real child process (11 tests, repeated 8 times, plus a manual double-signal run).
- P2.2 built (12 tests). Found that Amp starts a task on the next tick; corrected my first assumption in the code, tests and docs.
- P2.3 built: codec, decoder, golden files, 520 tests overall. Mutation testing found two real gaps (digit-keyed objects wrongly rejected; no test for a partial line across buffer compaction); fixed, and 26 of 29 mutants are now killed with 3 equivalent survivors. My mutation harness first crashed and left a mutated file behind; fixed and verified the copy matches the repository.
- P2.4 built: message types and the reader, tested against the golden files (read, then write back byte for byte). Found and fixed a mistake in my own spec example (an `id` on a progress event). Mutation test: 55 of 55 killed after strengthening one test.
- P2.5 built: the transport. Two of my tests deadlocked before I understood why: an AF_UNIX socket takes only about 280 small writes before a write waits (F38), and Amp's `end()` loses queued writes (F39). Fixed the tests, and wrote an `end()` that waits.
- You paused the session. State: P2.6 built and green (800 tests), review and mutation survivors open, nothing committed since `aa1e919`.

**2026-11-07**
- You resumed. I read the review result and applied the confirmed findings (list under P2.6 above). My own first fix for the main memory bug was not enough: the test I wrote for it passed with and without the fix, because requests sent in one burst are stopped by the inbound limit before any handler runs. Re-introducing the bug as a mutant showed it; the test now sends requests one at a time and fails without the fix. Suite 843 tests, stable.
- P2.7 built: handshake, version check, graceful shutdown, heartbeat, and the R19 contract. A first test of mine for the handshake failed because the far end I played sent a response before its own hello, which the peer rightly refused. Mutation testing found five weak tests (closing was only rescued by the flush timeout, a leftover timer or heartbeat could keep a process alive, and others); tightened.
- You approved D29 and D32 to D38 ("approved, continue"); recorded as Decided.
- P2.8 built: attributes, catalog, handler registration, parameter binding, retry middleware; 968 tests, mutation tested. Needs your approval: D39.
- P2.9 built: spawner, handle, output capture, orphan guard; the six acceptance checks run against real processes. Found that a busy worker with a loop-bound SIGTERM handler does not end on SIGTERM (F43). You asked that the progress map always be updated; it is, and the rule is saved.
- You said time and resources must be reduced. Measured first: 5 test classes (real processes, timers, heartbeats) are 78% of the 31 s test run, and the mutation passes re-ran whole directories for every change (the last one had used over an hour without finishing). Changes: those 5 classes are tagged `slow` and `make fast` skips them (957 tests in about 8 s); the mutation tool is now in the repo (`tools/mutation/mutate.py`), runs only the tests of the changed file and stops at the first failure (the same 48 mutants took 8.5 minutes, about 7 times faster); and a written policy in CONTRIBUTING.md says which check runs when (mutation only on new code, 2 full runs at most, reviews at checkpoints).
- The faster tool found two mistakes of mine at once: a test whose `\r\n` had been written to the file as real line breaks (so it never sent a carriage return), and a missing test that a dead worker is noticed by the master.
- P2.10 built: the supervisor. Mutation testing with the faster tool: 38 mutants in 9.6 minutes, 5 real gaps found and fixed. Checked `amphp/parallel` as the plan required: its children do not die with the master (F46). The progress map is updated.
- P2.11 built: the worker runtime and its arguments; the fixture now runs on it. While recording it I made a mistake in a doc-editing script that emptied `docs/FRAMEWORK.md`; I restored it from your commit (it differs from the committed version by only the three P2.11 lines) and saved a rule so it cannot happen again.
- P2.12 built: the headless example, run live: progress, a cancellation, a deliberate crash with recovery, and a real process-group Ctrl-C. A first run found one bug of mine in the example (a timer id passed as the cancel reason); fixed. Four tests run the example for real. No decision needs your approval for this step.
- P2.13 built: the soak and performance baseline. It passed, and it found three things worth your decision rather than a silent fix: nothing bounds queue memory by bytes (F48), a message costs about 6 times its size in PHP memory (F49), and the recycling threshold counts shared pages (F50). I left all three alone. This is the last step of Phase 2: Checkpoint 2 is next.
- You said "continue" after Checkpoint 2, which I took as the review passed. F48 to F50 and D43 are still open decisions for you.
- P2B.1 built: the filesystem service. You chose an own cooperative driver over `amphp/file`. A test that failed one run in six led to a real heartbeat bug from P2.7 (F51), fixed. Measuring showed `read()` is poor for large files (F52); `openRead()` is the way for those.
- P2B.2 built: the process runner. Measuring `amphp/process` first showed it leaves a command's children running when it stops the command (F53), so the runner uses a process group per command. One mutation-harness baseline run failed once and could not be reproduced.
- You approved D39 to D43 and D45 (2026-11-07); recorded as Decided. Still open for you: F48/R26 (a byte budget for queues) and F50 (recycling on private memory), and D30.
- P2B.3 built: the stream helpers. Amp already has piping and splitting, so I added only what it lacks: a bounded line splitter (Amp's is unbounded and quadratic on long lines, F54) and a progress-reporting stream.
- P2B.4 built: the accelerator detector, verified by running the whole suite under real ext-uv and ext-ev (you installed the two `-devel` packages). It found one real difference (F55).
- P2B.5 built: the blocking-calls guide, written from measurements. Phase 2B is complete; Checkpoint 2B reported with a live demo (512 MiB read: timer gap 14 ms streaming against 347 ms blocking).
