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

The commands that run all of these (`make check`) are added in step P1.3. Until then there is nothing to run.

## Names

`niphy` is a code name. Avoid spreading it into new places (file names, public strings, protocol fields) beyond what the docs already list. A later rename should be a scripted search and replace.
