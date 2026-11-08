# Changelog

All notable changes are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/) once a first release exists.

## [Unreleased]

### Fixed
- The heartbeat no longer counts the time before a peer's `hello` as silence, so a worker that is slow to start on a loaded machine is no longer declared crashed.

### Changed
- Checks while working: `make changed` (`tools/test-changed.py`) runs only the tests and checks for what changed since the last commit; the whole suite (`make check`) is for a phase checkpoint or on request. The mutation tool for the shim runs only the test modules that belong to the changed file.
- Renamed the project from the code name niphy to Raphp (namespace `Raphp\`, `libraphp`, `raphp.json`, `RAPHP_*` variables, CLI `rlm`). Spike code under `spikes/` keeps the old names.

### Added
- Canvas for the shim (step P3.13): a `canvas` widget that draws a list of rectangles, lines, paths, circles and text sent in its `ops` property, checked as a whole when set; golden pictures of a chart at two scales.
- Menus for the shim (step P3.12a): `menu_item` and `menu_separator` widgets, an `anchor` on `overlay.show` that opens a menu under a widget (above it when there is no room below), a menu that closes itself when an item is chosen (`overlay.dismissed` with `activated`), and arrow-key navigation; golden picture and tests, window-check example and checklist rows.
- Text input for the shim (step P3.11): a text field the user can edit with caret, selection, word jumps, mouse and keyboard, clipboard (the system one on Wayland), input-method composition, scrolling of long text, and events that tell PHP what was typed with a revision so that a later re-render cannot overwrite it.
- Core widget looks, shim-side animation and an overlay layer for the shim (step P3.10): buttons, checkboxes and text inputs react to the pointer and keyboard with a fade, a pressed look and a focus ring; a progress bar can sweep by itself with the shim asking for each frame; and a layer for menus (`overlay.show`, `overlay.hide`) that is drawn above everything, hit first, kept inside the window and dismissed by an outside click or Escape.
- Input and focus for the shim (step P3.9): pointer, keyboard and wheel events with hit testing, keyboard focus (click, Tab, Enter and Space), shim-side scrolling, and two commands, `widget.focus` and `input.simulate`, the latter driving a window as if the user had, which is how the interaction is tested without a screen.
- Text and drawing for the shim (step P3.8): widgets are painted on the CPU with real fonts in both the headless and the window backend, a scroll view clips and scrolls, and golden-picture tests with a written tolerance check the look.
- Layout for the shim (step P3.7): widgets are placed by a flexbox subset (Taffy) in logical pixels, with a new `grow` property, exact rectangles tested for a table of cases, and HiDPI rounding that keeps touching widgets touching.
- Window backend for the shim (step P3.6): real windows on Wayland and X11 through winit, drawn only when the window system asks, with events for close requests, focus, resizing and scale; opt-in tests that open a real window, and a checklist for the part only a person can check.
- PHP binding for the shim (step P3.5): a loader that checks FFI, the files and the ABI version and says what to do when something is wrong; a `Shim` that sends batches, watches the shim's wake-up descriptor in the event loop and delivers its events in order; and a rule that a started worker inherits only the descriptors it is given.
- Shim headless backend (step P3.4): the shim's loop, commands and events run with no window system, on a clock that moves only when told to, with in-memory pictures of the windows, frame pacing and a memory budget, so that the whole path from PHP can be tested without a display.
- Shim command model (step P3.3): a strict command protocol for windows and widgets with a tree that the shim owns, errors reported as events tied to their batch, a read-only diagnostic call, golden test files and a written ordering contract.
- Shim event queue (step P3.2): events from the shim to PHP through a queue and a wake-up descriptor, with coalescing of continuous events, back-pressure, a contract for oversized events, and a last `shim.failed` event when the shim fails.
- Shim workspace and C ABI (step P3.1): `libraphp` with five functions, a committed C header, a contract for panics and for failure of the shim's UI thread, and a glibc report; a Rust mutation-testing tool.
- Blocking-calls guide (step P2B.5) and the `examples/large-file` demonstration: which PHP calls stop the event loop, how long they stalled it, and what to use instead.
- Optional accelerators (step P2B.4): a detector for ext-uv, ext-ev, ext-event and ext-eio and the running event-loop driver; the test suite was run under ext-uv and ext-ev.
- Stream helpers (step P2B.3): a line splitter with a limit on line length (for chunks and for streams, also as a pipeline) and a stream wrapper that reports how many bytes have been read.
- Process runner (step P2B.2): `ProcessRunner` runs application commands without a shell, captures or streams their output, and stops a command together with everything it started on cancellation, timeout or output limit.
- Filesystem service (step P2B.1): a `Filesystem` interface with a cooperative default that reads, writes, lists and streams files in chunks while the event loop keeps running, and a benchmark of how long the loop stalls.
- Soak and performance baseline (step P2.13): `tools/bench/bench.php` (`make bench`) and `docs/BENCHMARKS.md`, with a soak of 50,000 round trips, a memory tolerance and plateau check, payloads up to the 8 MiB limit, and start-up times.
- Headless example (step P2.12): `examples/headless-worker`, a command-line master calling a worker with progress, Ctrl-C cancellation and recovery from a crashed worker, with tests that run it for real.
- Worker entry (step P2.11): a strict worker command line and a runtime that protects the worker from outliving its master, leaves Ctrl-C to the master, serves calls and ends with a meaningful exit code.
- Supervisor (step P2.10): declared workers and pools, restart with growing waits and a degraded state, killing and restarting a worker that stops answering pings, routing (round-robin, least-busy), recycling by call count or memory, ordered graceful shutdown, and PSR-14 events for every change.
- Process spawner (step P2.9): starts worker processes with a close-on-exec channel, drains and logs their output, collects their exit status without leaving zombies, and makes sure they cannot outlive their master.
- Attribute handlers (step P2.8): `#[RpcMethod]` and `#[Idempotent]`, a method catalog read from the attributes, handlers registered from a container with parameters filled by name and type, and a retry middleware that repeats only idempotent methods.
- Handshake and heartbeat (step P2.7): `hello` exchange with a protocol version check, `bye` with an error code, a graceful `shutdown()`, and a heartbeat that declares a silent peer dead without blaming one that is busy sending a large message.
- RPC peer (step P2.6): `RpcPeer` for calling and answering over a connection, with progress events, cancellation, timeouts, limits that bound memory, a handler registry and middleware (timing, trace id, error mapping).
- IPC transport (step P2.5): a `Transport` interface and `StreamTransport` over a stream socket, with back-pressure, an `end()` that waits for pending writes, and an immediate `close()` that cancels event-loop watchers first.
- IPC messages (step P2.4): a `Kind` enum, an `Envelope` interface, eight immutable message classes and `EnvelopeReader`, which turns decoded lines into typed messages or an `E_PROTOCOL` error.
- IPC codec (step P2.3): `Codec` and `Decoder` interfaces, `JsonLineCodec` (compact ASCII NDJSON, size and depth limits) and a pull-based incremental decoder that recovers from bad lines and stops on an oversize line, plus golden wire-format files in `docs/protocol/golden/`.
- Background task runner (step P2.2): `BackgroundTasks` runs fire-and-forget work, logs failures and supports cancelling all tasks.
- Kernel (step P2.1): starts the application inside the event loop, handles SIGINT and SIGTERM, runs ordered shutdown hooks within a time budget and returns a defined exit code.
- Error model (step P1.6): an `ErrorCode` enum of the nine IPC error codes, an abstract `RaphpException` base, one exception per code, `ApplicationException` for `E_APP_*` codes, and conversion to and from the wire's `error` object.
- Composition root (step P1.5): typed configuration loaded from `raphp.json` plus environment overrides, a PSR-20 UTC clock, a PSR-3 Monolog logger with an enum log level, and a PSR-11 PHP-DI container.
- Design document, implementation plan, Phase 0 spike report and progress tracker under `docs/`.
- Repository scaffold: `LICENSE` (MIT), `README.md`, `CONTRIBUTING.md`, `.editorconfig`, `composer.json` and the directory layout.
