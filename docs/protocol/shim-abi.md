# The shim's C ABI (`libraphp`), version 0.3

Steps P3.1 to P3.3. The commands that `raphp_send` carries, and what the shim answers with, are in `shim-commands.md`. The header is `shim/include/raphp.h`, generated from the Rust sources by `make header` (cbindgen plus a small filter, see "The header") and committed. PHP loads it with `FFI::cdef` and loads `libraphp.so`. This page is the contract: what each function promises, what a failure looks like, and what happens when the shim itself fails. Where something is decided now but wired in a later step, it says so.

## The functions (all of the ABI so far)

Version 0.1 (P3.1) had the first five; 0.2 (P3.2) added the four that carry events to PHP; 0.3 (P3.3) adds `raphp_diagnostics` and the status `CLOSED`.

| Function | What it does |
|---|---|
| `uint32_t raphp_abi_version(void)` | `major << 16 \| minor`. 0.3 is `3`. PHP compares it before anything else. A minor bump adds functions; a major bump breaks. 0.x is not stable. |
| `RaphpShim* raphp_init(const char* cfg, size_t len)` | Starts the shim and its UI thread. `cfg` is UTF-8, may be empty, and may be null when `len` is 0. Returns **null** on failure; the reason is `raphp_last_error(NULL, ...)` for the calling thread. (In PHP a null pointer arrives as `null`.) The configuration is only checked for UTF-8 so far; it is read from P3.3 on. |
| `RaphpStatus raphp_send(const RaphpShim*, const char* batch, size_t len)` | Sends a batch of commands (UTF-8 JSON) and returns at once; the UI thread applies it. A text that is not a batch is refused here (`INVALID_ARGUMENT`); problems inside the commands come back as events. `CLOSED` once the shim has stopped taking commands. See `shim-commands.md`. |
| `size_t raphp_last_error(const RaphpShim*, char* out, size_t cap)` | Copies the last error's text into `out` (UTF-8, **not** NUL-terminated, at most `cap` bytes, never cutting a character) and returns the **full** length, so PHP can ask again with a bigger buffer. A null `shim` means "the reason the calling thread's last `raphp_init` failed". A null `out` only returns the length. Works on a poisoned shim. |
| `RaphpStatus raphp_shutdown(RaphpShim*)` | Asks the UI thread to stop, waits a bounded time, frees the handle. The handle is invalid afterwards whatever the result. Works on a poisoned shim. |

| `int raphp_event_fd(const RaphpShim*)` | The descriptor that becomes readable when events are queued. It belongs to the shim: close-on-exec (a worker does not inherit it, tested), valid until `raphp_shutdown`, never closed by PHP. -1 for a null handle. Works on a poisoned shim. |
| `intptr_t raphp_poll(const RaphpShim*, char* out, size_t cap)` | Copies whole events into `out`, one JSON line each, every line ending in `\n`, as many as fit, in order. Returns the bytes written; `0` when nothing is queued; `RAPHP_STATUS_BUFFER_TOO_SMALL` (-6) when the **next** event is larger than `cap` (see "A large event"). Any other negative value is a `RaphpStatus`. A null `out` is allowed only with `cap` 0. Works on a poisoned shim. |
| `size_t raphp_next_event_size(const RaphpShim*)` | The size in bytes, newline included, of the event at the head of the queue; 0 when empty or the handle is null. After -6 it is the buffer size to poll with. Works on a poisoned shim. |
| `size_t raphp_diagnostics(const RaphpShim*, char* out, size_t cap)` | One line of JSON with the counters (batches, commands applied and failed, widgets, and per window `redraws_requested` and `frames_drawn`), read like `raphp_last_error`: the full length is returned. Read-only, works on a poisoned shim, never waits for the UI thread. See `shim-commands.md`. |
| `size_t raphp_max_event_bytes(void)` | The largest event the shim queues, newline included (1 MiB). A poll buffer of this size can never be too small. |

The draft in `FRAMEWORK.md` 7.3 had `raphp_shutdown` returning `void` and `raphp_send` returning `int`; both return `RaphpStatus`, and `raphp_poll` returns `intptr_t`.

### Status codes

Zero is success and every failure is negative, so PHP can test `< 0`.

| Code | Name | Meaning |
|---|---|---|
| 0 | `RAPHP_STATUS_OK` | Done. |
| -1 | `..._INVALID_ARGUMENT` | A null pointer, a length that does not fit, or text that is not UTF-8. The shim is unharmed. |
| -2 | `..._POISONED` | The shim failed earlier (see below) and refuses every call except `raphp_last_error` and `raphp_shutdown`. |
| -3 | `..._PANICKED` | This very call panicked. The shim is now poisoned. |
| -4 | `..._UNSUPPORTED` | Well formed but not supported (an unknown command). The shim is unharmed. |
| -5 | `..._TIMED_OUT` | `raphp_shutdown` could not stop the UI thread in time and left it behind (see "Shutdown"). |
| -6 | `..._BUFFER_TOO_SMALL` | `raphp_poll` only: the next event is larger than the buffer. Nothing was copied; the event stays queued. |
| -7 | `..._CLOSED` | `raphp_send`: the shim has stopped taking commands (`app.quit` was applied, or its UI thread has ended). Reading events and shutting down still work. |

### Ownership and strings

- Strings are UTF-8 with an explicit length. Nothing relies on a NUL terminator.
- The shim copies every input. PHP owns every buffer it passes in. The shim never returns an allocation, so PHP never frees anything.
- A handle comes from `raphp_init` and is freed by `raphp_shutdown`. Using it after that is undefined behaviour; the PHP `Shim` class (P3.5) must make that impossible. A null handle is refused with `INVALID_ARGUMENT`.
- The functions may be called from any thread, concurrently (tested with eight threads). They never wait for the UI thread.

## Panics in a call

Every `extern "C"` function runs under `catch_unwind`, and a test fails if one does not. A panic therefore never unwinds into PHP. What happens instead:

1. The call returns `PANICKED`.
2. The shim is **poisoned**: permanently. Every later call returns `POISONED` at once, with the same answer each time.
3. The first cause is kept and stays readable through `raphp_last_error` (later failures are consequences and do not replace it).
4. `raphp_shutdown` still works and does not hang.

Poisoning is permanent on purpose: after a panic the shim's internal state cannot be trusted, and a half-working window is worse than a stopped one. A new shim can be started with a new `raphp_init`.

Verified through the real FFI (`ShimAbiTest`, using a debug build with the `test-hooks` feature, which adds a command that panics): the panic comes back as `-3` and the PHP process carries on.

The standard Rust panic message goes to the process's stderr (the default hook). It is useful in logs and is left alone; routing it into the logger is a choice for P3.5.

## When the shim's own UI thread fails (R14: the written contract)

The UI thread is started by `raphp_init`, runs the window system, and never calls PHP. The rules:

**1. The build never aborts.** `panic = "unwind"` is set in the workspace profile for release and dev, and a test fails if anything else is set or the build is made another way. With `panic = "abort"` a UI-thread panic would end the master and, through the parent-death signal, every worker, which defeats the isolation the design is for.

**2. The UI thread's body runs under `catch_unwind`.** If it panics, the thread's resources are released by unwinding, the shim is poisoned with `the UI thread panicked: <message>`, and the process lives on. If the body returns without having been asked to stop, the shim is poisoned with `the UI thread ended without being asked to`. If it returns after a stop request, that is the orderly end. All three are tested with real threads.

**3. What PHP sees.** The next call into the shim returns `POISONED`, and `raphp_last_error` says why. At the moment of poisoning the shim also queues a last event, `{"type":"shim.failed","reason":"..."}`, and raises the wake-up, so a master that is only waiting for events learns of it at once, not at its next call (built and tested in P3.2, including through PHP's FFI). Reading events still works on a poisoned shim, so that this last event can be read, after the events that came before it.

**4. What the master does (proposed, D47; built in P3.5 and checked in P4.5).** A poisoned shim means the window is gone and cannot come back. The PHP `Shim` class turns `POISONED` into one `ShimFailedException`. Unless the application has asked to carry on headless, the master treats it as a fatal error of the UI: it logs the cause, stops itself with the kernel's error exit code through the normal shutdown, and its workers receive `bye`. They are not killed by a crash and no abort is involved.

**5. The shutdown order (proposed, D47).** The kernel runs shutdown hooks last-registered-first. The shim's hook is registered **after** the supervisor's, so it runs **first**: the window closes at once when the application quits, and the workers drain afterwards with nothing left to show. `raphp_shutdown` on a poisoned shim returns immediately, because its UI thread has already ended.

**6. Shutdown is bounded.** `raphp_shutdown` waits up to a time limit (3 s, my number) for the UI thread. If a native call keeps it from stopping, the thread is **detached**, `TIMED_OUT` is returned, and the master carries on to exit; a detached thread does not keep a process alive. Tested with a thread that ignores the stop request.

**What cannot be contained.** A call of `exit()` or `abort()`, or a fatal signal (a segmentation fault inside a native library the window system uses), ends the whole process, because the UI lives in the master's process (D5). Nothing in-process can catch that. The shim itself never calls `exit` or `abort`. This is the price of the in-process design; the worker isolation protects the opposite direction (a worker crash cannot close the window).

**Not covered here.** What runs when `exit()` is called inside a PHP Fiber belongs to the PHP kernel and is checked in P4.5.

## Events (P3.2)

Events go from the shim to PHP through an in-memory queue. Each event is **one line of JSON** (no newline inside; the queue refuses an empty one or one with a newline). What the lines contain is defined with the command model in P3.3; the queue does not look inside them.

### How PHP reads them: the wake-up rule

The `eventfd` is only a wake-up call, never the buffer (a pipe used as the buffer lost 88% of the events when PHP stalled for 300 ms, spike 1). PHP's loop watches it and, when it is readable:

1. **clear it first** (read 8 bytes), and
2. **drain with `raphp_poll` until it returns 0.**

That order cannot lose a wake-up: an event queued between the two steps is picked up by the drain, and one queued after the drain empties the queue raises the wake-up again. The shim raises it when the queue goes from empty to non-empty, and again whenever a poll leaves events behind. Tested with the real descriptor: an event between clearing and draining is not lost, and after clear-and-drain the descriptor is quiet.

### Two kinds of event, and the rules that keep them honest

| | Examples | Rules |
|---|---|---|
| **Discrete** | click, key, text, focus, close, errors, `shim.failed` | Never merged, never dropped, order kept. |
| **Continuous** | pointer move, scroll position, resize | Carry **state, not deltas** (the pointer is at x,y; the size is w by h; the scroll offset is y), because only the latest is kept. |

**Coalescing.** A new continuous event replaces an earlier *queued* one with the same window and kind, **if no discrete event was queued in between**. So a discrete event is never overtaken and never overtakes: `move, click, move, move` becomes `move, click, move`, not `click, move`. A raw wheel delta must therefore not be sent as a continuous event: the shim's scroll containers (P3.10) consume wheel deltas themselves, and PHP only learns the resulting offset.

**Overflow (decision O7: coalesce harder, then back-pressure, never drop a discrete event).** The limits are in queued bytes (my numbers, unmeasured):

- Past the **soft limit (1 MiB)**: an older continuous event is removed even across a discrete one, and the newest goes last; superseded events are swept out. Nothing discrete is touched.
- Past the **hard limit (4 MiB)**, with nothing left to merge: the producer **waits** until PHP drains. This is back-pressure on the UI thread by design: a PHP that never polls freezes the window. Shutdown and a failure of the shim wake any waiting producer, so a stuck PHP cannot hold up `raphp_shutdown`.

### A large event (R16: the written contract)

The risk was that an event larger than PHP's poll buffer could stall all UI input: PHP stops its drain on the "too small" answer, the wake-up is already cleared, and the large event at the head blocks every later click and key. The contract:

1. **A single event is at most `raphp_max_event_bytes()` (1 MiB, newline included).** A larger one is refused by the queue and replaced by a small discrete error event, `{"type":"event.too_large","event":"<what>","bytes":N,"limit":1048576}`, so PHP is told instead of the event vanishing, and PHP's buffer need is bounded.
2. **`raphp_poll` never skips and never splits.** If the next event does not fit, it copies nothing, returns `RAPHP_STATUS_BUFFER_TOO_SMALL`, and **the event stays at the head** of the queue: nothing behind it can overtake it, and nothing is lost.
3. **PHP learns the size it needs** from `raphp_next_event_size`, makes a buffer at least that large, and polls again. A buffer of `raphp_max_event_bytes()` is always enough.
4. **The wake-up is raised again** when a poll leaves events behind, including the too-small answer, so a drain that stops early is called back and cannot leave later events stranded.
5. **PHP's rule for the drain loop:** on -6, grow the buffer and continue; never leave the loop until `raphp_poll` returns 0.

Tested at the Rust level (the large event stays, the one behind it arrives, in order; a buffer that fits only the first returns it and calls back) and through PHP's FFI (a 100 KB event with a 1 KB buffer, drained by growing).

### The failure event

When the shim is poisoned it queues one last discrete event, `{"type":"shim.failed","reason":"..."}`, whatever the limits, raises the wake-up, and accepts no more events (the queue is closed to producers, so none waits). PHP drains what is there, including that event, then sees 0. The reason is escaped as JSON.

## The glibc floor (R23, D21)

`make shim` builds the release library and prints the highest `GLIBC_x.y` symbol version it needs, and records it in `shim/target/release/glibc-floor.txt`. On this machine (glibc 2.43) it is **GLIBC_2.34**, the same as the Phase 0 headless figure. The mechanism for building against an older glibc (D21: `cargo-zigbuild` or a container) is **not chosen yet**: choosing it needs a download I did not make, and the plan verifies it in P6.1.

## The header

cbindgen writes C that PHP's `FFI::cdef()` cannot read: an include guard, and a C23 `#if __STDC_VERSION__ ...` branch around the status enum's underlying type. `cargo run -p xtask -- header` runs cbindgen and then a filter that keeps the pre-C23 branch and drops the directives; any other preprocessor line is an error, because FFI could not read it either. The filter has its own tests, and `ShimAbiTest` checks that the committed header has no preprocessor line and loads. `make header` regenerates it; `make rust` fails if the committed file differs from what the sources produce.

`FFI::cdef` resolves every declared function in the library at load time, so a header that names a function the library does not export fails to load. A null pointer returned from C arrives in PHP as `null`.
