# Raphp (code name: niphy)

> **Raphp** (Ralph PHP, pronounced "raf-pee") is the public name. **niphy** is the code name still used in the code and package names. Nothing is published yet.

An async PHP runtime with an optional native GUI.

- **Runtime:** a long-running, event-driven runtime for PHP built on Revolt and Amp, with workers, IPC and a standard library.
- **GUI (optional):** a native desktop UI driven from PHP through FFI and a small Rust library, with no browser engine.
- **Standalone apps:** an application ships as a single binary that needs no PHP installed.

## Status

**Early development. Nothing here is usable yet.** Phase 0 (spikes that tested the risky assumptions) is finished. Phase 1 (repository foundation) is in progress. There is no production code yet.

## Documents

| File | What it is |
|---|---|
| [`docs/FRAMEWORK.md`](docs/FRAMEWORK.md) | The design: architecture, protocols, decisions, risks |
| [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) | The step-by-step plan and acceptance checks |
| [`docs/SPIKE_REPORT.md`](docs/SPIKE_REPORT.md) | The Phase 0 evidence |
| [`docs/PROGRESS.md`](docs/PROGRESS.md) | What has actually been done so far |

## Requirements

- Linux (the first supported platform)
- PHP 8.5 with the `ffi`, `sockets`, `pcntl` and `posix` extensions
- Composer

## License

[MIT](LICENSE), Copyright (c) 2026 Codizium.
