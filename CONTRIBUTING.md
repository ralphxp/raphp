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
