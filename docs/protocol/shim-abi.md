# The shim's C ABI (`libraphp`), version 0.1

Step P3.1. The header is `shim/include/raphp.h`, generated from the Rust sources by `make header` (cbindgen plus a small filter, see "The header") and committed. PHP loads it with `FFI::cdef` and loads `libraphp.so`. This page is the contract: what each function promises, what a failure looks like, and what happens when the shim itself fails. Where something is decided now but wired in a later step, it says so.

## The functions (all of the ABI so far)

| Function | What it does |
|---|---|
| `uint32_t raphp_abi_version(void)` | `major << 16 \| minor`. 0.1 is `1`. PHP compares it before anything else. A minor bump adds functions; a major bump breaks. 0.x is not stable. |
| `RaphpShim* raphp_init(const char* cfg, size_t len)` | Starts the shim and its UI thread. `cfg` is UTF-8, may be empty, and may be null when `len` is 0. Returns **null** on failure; the reason is `raphp_last_error(NULL, ...)` for the calling thread. (In PHP a null pointer arrives as `null`.) The configuration is only checked for UTF-8 so far; it is read from P3.3 on. |
| `RaphpStatus raphp_send(const RaphpShim*, const char* batch, size_t len)` | Sends a batch of commands (UTF-8) and returns at once. So far only the empty batch `[]` (white space around it is allowed) is accepted; anything else is `UNSUPPORTED` until the command model (P3.3). |
| `size_t raphp_last_error(const RaphpShim*, char* out, size_t cap)` | Copies the last error's text into `out` (UTF-8, **not** NUL-terminated, at most `cap` bytes, never cutting a character) and returns the **full** length, so PHP can ask again with a bigger buffer. A null `shim` means "the reason the calling thread's last `raphp_init` failed". A null `out` only returns the length. Works on a poisoned shim. |
| `RaphpStatus raphp_shutdown(RaphpShim*)` | Asks the UI thread to stop, waits a bounded time, frees the handle. The handle is invalid afterwards whatever the result. Works on a poisoned shim. |

Not in the ABI yet, and not in the header: `raphp_event_fd` and `raphp_poll` (P3.2). The draft in `FRAMEWORK.md` 7.3 had `raphp_shutdown` returning `void` and `raphp_send` returning `int`; both return `RaphpStatus` now.

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

**3. What PHP sees.** The next call into the shim returns `POISONED`, and `raphp_last_error` says why. *Wired in P3.2:* at the moment of poisoning the shim also queues a last event (`{"type":"shim.failed",...}`) and wakes the event fd, so a master that is only waiting for events learns of it at once, not at its next call. Until P3.2 exists PHP learns on its next call.

**4. What the master does (proposed, D47; built in P3.5 and checked in P4.5).** A poisoned shim means the window is gone and cannot come back. The PHP `Shim` class turns `POISONED` into one `ShimFailedException`. Unless the application has asked to carry on headless, the master treats it as a fatal error of the UI: it logs the cause, stops itself with the kernel's error exit code through the normal shutdown, and its workers receive `bye`. They are not killed by a crash and no abort is involved.

**5. The shutdown order (proposed, D47).** The kernel runs shutdown hooks last-registered-first. The shim's hook is registered **after** the supervisor's, so it runs **first**: the window closes at once when the application quits, and the workers drain afterwards with nothing left to show. `raphp_shutdown` on a poisoned shim returns immediately, because its UI thread has already ended.

**6. Shutdown is bounded.** `raphp_shutdown` waits up to a time limit (3 s, my number) for the UI thread. If a native call keeps it from stopping, the thread is **detached**, `TIMED_OUT` is returned, and the master carries on to exit; a detached thread does not keep a process alive. Tested with a thread that ignores the stop request.

**What cannot be contained.** A call of `exit()` or `abort()`, or a fatal signal (a segmentation fault inside a native library the window system uses), ends the whole process, because the UI lives in the master's process (D5). Nothing in-process can catch that. The shim itself never calls `exit` or `abort`. This is the price of the in-process design; the worker isolation protects the opposite direction (a worker crash cannot close the window).

**Not covered here.** What runs when `exit()` is called inside a PHP Fiber belongs to the PHP kernel and is checked in P4.5.

## The glibc floor (R23, D21)

`make shim` builds the release library and prints the highest `GLIBC_x.y` symbol version it needs, and records it in `shim/target/release/glibc-floor.txt`. On this machine (glibc 2.43) it is **GLIBC_2.34**, the same as the Phase 0 headless figure. The mechanism for building against an older glibc (D21: `cargo-zigbuild` or a container) is **not chosen yet**: choosing it needs a download I did not make, and the plan verifies it in P6.1.

## The header

cbindgen writes C that PHP's `FFI::cdef()` cannot read: an include guard, and a C23 `#if __STDC_VERSION__ ...` branch around the status enum's underlying type. `cargo run -p xtask -- header` runs cbindgen and then a filter that keeps the pre-C23 branch and drops the directives; any other preprocessor line is an error, because FFI could not read it either. The filter has its own tests, and `ShimAbiTest` checks that the committed header has no preprocessor line and loads. `make header` regenerates it; `make rust` fails if the committed file differs from what the sources produce.

`FFI::cdef` resolves every declared function in the library at load time, so a header that names a function the library does not export fails to load. A null pointer returned from C arrives in PHP as `null`.
