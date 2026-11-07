# Changelog

All notable changes are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/) once a first release exists.

## [Unreleased]

### Changed
- Renamed the project from the code name niphy to Raphp (namespace `Raphp\`, `libraphp`, `raphp.json`, `RAPHP_*` variables, CLI `rlm`). Spike code under `spikes/` keeps the old names.

### Added
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
