# Contributing

The project is in early development and the design is still moving. Read `docs/FRAMEWORK.md` (design) and `docs/IMPLEMENTATION_PLAN.md` (order of work) before proposing changes.

## Ground rules

- **The design docs are the source of truth.** A change that contradicts them updates the docs in the same change.
- **Work follows the plan.** Steps are small and each has an acceptance check. Anything outside the plan goes to its "Parked ideas" list first.
- **Honest results.** If a check fails or was skipped, say so with the output.

## Code standards (decided, D13, D23 to D25)

- PHP 8.5, `declare(strict_types=1);` in every file.
- PER Coding Style, enforced by PHP-CS-Fixer with the `@PER-CS3.0` rule set.
- PHPStan at the strictest level that passes, never lowered.
- Tests with PHPUnit.
- Constructor injection, no static facades, no fluent method chaining as the primary API, no promise chaining. Async code uses Amp's Fiber style.
- Rust code (the shim) is formatted with `rustfmt` and must pass `clippy` without warnings.

Run `make check` to run all of them. The Rust checks are skipped until the shim crate exists (step P3.1).

## Names

The project was renamed from the code name `niphy` to Raphp on 2026-11-06 (D28). Use the new names everywhere: namespace `Raphp\`, `libraphp`, `raphp.json`, `RAPHP_*` variables, C prefix `raphp_`, CLI `rlm`. The old name survives only in the spike code under `spikes/`, which is throwaway and is not renamed. Do not copy `niphy` names from there into new code.

## Checks: what to run, and when

Time and machine resources are limited, so each check runs at the point where it pays for itself.

| When | Run | Cost |
|---|---|---|
| While writing code | `make fast` (formatting, PHPStan, and the tests not tagged `slow`) | about 10 s |
| Before calling a step done | `make check` once, and a second time only if the step touches processes, timers or the heartbeat | about 40 s |
| After a step | mutation testing of the **new** code only, 15 to 25 well-chosen changes, with `tools/mutation/mutate.py` | a few minutes |
| At a checkpoint | an independent review, and a full mutation pass | longer; once per phase |

- The tests tagged `#[Group('slow')]` use real processes, timers or heartbeats. They are about 5 classes but 80% of the test time. CI runs everything.
- `tools/mutation/mutate.py` runs only the tests that belong to the file being changed and stops at the first failure. Add entries to its `FOCUS` table for new files. A mutant that survives is either a missing test or a change that cannot be observed (for example, an explicit `fclose` of a resource PHP closes anyway); say which.
- Do not repeat a full run "to be sure". Repeat only when a timing-sensitive test has failed once.

## Running the tests under another event-loop driver

Revolt picks ext-uv, ext-ev or ext-event by itself when one is loaded, and Raphp must work with each (D46). To check a change under one, build the extension (for example from pecl, with `phpize`, into a scratch folder: it needs the library's `-devel` package), put `extension=/path/to/uv.so` in a file in a folder of its own, and run:

```
PHP_INI_SCAN_DIR=":/path/to/that/folder" php vendor/bin/phpunit
```

The environment variable is inherited by the worker processes the tests start, so master and workers use the same driver. `REVOLT_DRIVER` forces a specific driver class. The rule that follows from the first such run (F55): handle signals only with `EventLoop::onSignal()`; a plain `pcntl_signal()` handler waits for the next event under ext-uv.
