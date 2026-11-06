# Raphp Progress Tracker

**Last updated:** 2026-11-06
**Current position:** **Phase 1 in progress.** P1.1 to P1.3 are done. P1.4 (CI) is written but **not yet proven green**. The project is now named **Raphp** in code and docs. Your `initial commit` (`83c8a97`) is on GitHub at `ralphxp/raphp`; the rename, the `rlm/` directory and the CI file are not committed yet. Phase 0 was accepted when you started Phase 1.
**How this file works:** I update it at the end of every step and whenever something is decided or found. The plan lives in `IMPLEMENTATION_PLAN.md`, the design in `FRAMEWORK.md`, the evidence in `SPIKE_REPORT.md`. This file only records what has actually happened.

Legend: ✅ done and verified · 🔄 in progress · ⏳ not started · ⛔ blocked · ⚠️ done with caveats

---

## 1. Status at a glance

| Phase | Name | Status | Steps done |
|---|---|---|---|
| 0 | Spikes and verification | ✅ finished (accepted when you started Phase 1) | 9 of 9 (P0.0 to P0.8) |
| 1 | Repository foundation | 🔄 in progress | 3 of 6 (P1.1 to P1.3); P1.4 written, unproven |
| 2 | Runtime core and IPC | ⏳ not started | 0 |
| 2B | Tier 1 standard library | ⏳ not started | 0 |
| 3 | `libraphp` shim MVP | ⏳ not started | 0 |
| 4 | UI framework | ⏳ not started | 0 |
| 5 | `rlm` | ⏳ not started | 0 |
| 6 | Standalone packaging | ⏳ not started | 0 |
| 7 | Runtime tier 2 | ⏳ not started | 0 |
| 8 | Tier 3 and hardening | ⏳ not started | 0 |

**No production code exists yet.** Everything built so far is throwaway spike code under `spikes/`, documentation, and the Phase 1 repository scaffold.

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
Clicks and key presses; a tray icon or menu; how the title bar and dialog look; attaching the dialog to the window; responsiveness while the dialog is open; IME; any other distro, glibc or architecture; more than one worker at a time; `proc_open` while the multi-threaded shim is alive; the windowed shim through `memfd` inside micro; the real duration of PHP's `configure`.

---

## 3A. Phase 1 progress

| Step | What | Status | Result |
|---|---|---|---|
| **P1.1** | Project decisions | ✅ | **PHP floor 8.5 or newer** (confirmed by you). **PHP-DI 7** as the container. **PHP-CS-Fixer** with `@PER-CS3.0`. **Copyright holder Codizium.** Composer vendor stays a placeholder (`raphp/raphp` after the rename, D28). Recorded as D22 to D26. Exact tool versions close in P1.3 |
| **P1.2** | Repository scaffold | ✅ | `LICENSE` (MIT), `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `.editorconfig`, `composer.json`, `.gitignore` (your `/spikes` line kept), and the layout (`php/src`, `php/tests`, `shim`, `rlm`, `examples`). `composer validate --strict` valid; PHP 8.5.12 and all five extensions pass the platform check; a clean install works; an executable (`phpstan`) installs on the mount and runs. Nothing is committed |
| **P1.3** | Tooling and `make check` | ✅ | `make check` exits 0: formatter clean, PHPStan (`level: max`) clean, 7 PHPUnit tests pass. **Versions:** PHPUnit 13.4.6, PHPStan 2.3.5 (+ strict-rules, deprecation-rules, disallowed-calls 4.15.0), PHP-CS-Fixer 3.95.28 (`@PER-CS3.0`). **Failures shown on purpose:** bad formatting is caught with a diff; `proc_open()` in `php/src` is rejected; `stream_socket_pair()` is rejected in `php/src` and allowed in `php/tests`; a real PHP 8.5 deprecation fails PHPUnit. **Caveat:** the Rust step is *skipped* (no `shim/Cargo.toml` until P3.1), so `cargo fmt`, `clippy` and `cargo test` have not actually run. Only their presence is confirmed |
| — | **Rename to Raphp** (D28) | ✅ | Code and config by me (`raphp/raphp`, `Raphp\` namespace, `rlm/`); docs by three editors and two reviewers. Both reviewers passed and I fixed their two low notes. `make check` still exits 0. The old name remains only in the history rows D22, D27 and D28, in the spike code and its measurement rows, and in the former-name notes in README, CONTRIBUTING and CHANGELOG |
| **P1.4** | Continuous integration | ⚠️ | `.github/workflows/ci.yml` written: `make check` on PHP 8.5 (`ffi`, `pcntl`, `posix`, `sockets`) plus stable Rust with rustfmt and clippy, on pushes to `master` and pull requests. Actions `checkout@v7`, `setup-php@v2`, `rust-toolchain@stable`, pinned by tag, not commit. YAML parses. **Not yet run:** it needs your push, then I read the result with `gh`. Rust steps are skipped by `make check` until P3.1 |
| **P1.5** | Composition root skeleton | ⏳ | |
| **P1.6** | Error model | ⏳ | |

**Phase 1 findings**
- **F20:** Composer shells out to git to detect the project version, so the mount's "dubious ownership" problem affects Composer too (a warning plus a defaulted version), not only git. Git ignores `safe.directory` in a repository's own config, so the fix is a **global** entry (you already have one for tinypay, comanager and jobAI) or `git -c safe.directory=...` per command. I correct my earlier offer of a "repo-local" entry, which was never possible.
- **F20 resolved:** you ran the global `safe.directory` command. Git and Composer now work without the workaround, which I confirmed.
- **F21:** on this mount Composer installs executables as regular proxy files instead of symlinks, and they run. F19's open question is closed.
- **F22:** PHPUnit 13 narrows `error_reporting()` inside tests to 245 on purpose, so a test cannot assert the value (my first version of the test failed for this reason). The test now proves the behaviour instead. An expected deprecation still counts as an issue under `failOnDeprecation` unless the test also carries `#[IgnoreDeprecations]`.

**Public name chosen by you: Raphp** (Ralph PHP, pronounced "raf-pee"), D27. `raphp` is free on crates.io, npm and Packagist; a GitHub *user* named `raphp` exists and is **not** yours: your account is `ralphxp` and the repository is `ralphxp/raphp`. That blocks nothing, because Composer vendor names are independent of GitHub handles.

**Names I suggested earlier** (weak signals only, now superseded):
`axiphy` is free on GitHub, crates.io, npm and Packagist. `deltrix`, `lemnis`, `nablix`, `orthon`, `phasora`, `quadrix`, `sigmora` and `tessar` are free on crates.io, npm and Packagist but their GitHub handles exist. `kernix`, `orbix` and `vectrix` are taken in several places. Every ordinary dictionary word tried (lemma, axiom, vertex, radix, torus and others) was taken on GitHub, npm and crates.io.

---

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
| R23 | The shim's glibc floor is higher than PHP's (proposed fix D21 needs your approval) |

---

## 5. Waiting on you

| # | Need | Status |
|---|---|---|
| 1 | **Push the rename and the CI file** (they are uncommitted). Then I check the workflow with `gh run list`. P1.4 is not proven until it is green | **Open** |
| 2 | **D21 (Proposed):** build the Rust shim against an old glibc so the shim does not set the distributable floor | **Open, needs your approval** |
| 3 | **Your impressions of the chrome experiment** (title bar look, dialog look, placement, anything odd) | **Partly answered** |
| 4 | **Packagist vendor `raphp`:** availability is unchecked (Packagist timed out). Check when you are ready to publish | Later |

Already answered: PHP 8.5 or newer, PHP-DI, PHP-CS-Fixer, copyright holder Codizium, public name Raphp, rename now (done, D28), CLI renamed to `rlm`, Git host GitHub, global `safe.directory` (done), commits are yours (initial commit made and pushed), native chrome option (a), plan approval. `master` is kept as the branch name.

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
- **Phase 1 tooling (D23 to D26):** PHP 8.5 or newer, PHP-DI 7, PHP-CS-Fixer with `@PER-CS3.0`, copyright holder Codizium.
- **Errors:** master logs and shuts down non-zero; worker exits non-zero and is restarted.
- **Middleware:** Amp-native for HTTP, one PSR-15-shaped contract for Raphp RPC.
- **New from Phase 0:** D18 raw `proc_open` with an array command, never `amphp/process` (source-checked); D19 self re-exec via `/proc/self/exe` (verified); D20 `$ffi->new()` and no `ffi.enable` baking needed (verified).
- **Deferred:** minimum glibc (Phase 6), accessibility, Windows and macOS.

---

## 7. Files created so far

**Project documents** (`docs/`): `FRAMEWORK.md`, `IMPLEMENTATION_PLAN.md`, `PROGRESS.md`, `SPIKE_REPORT.md`

**Repository root (Phase 1):** `LICENSE`, `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `.editorconfig`, `.gitignore`, `composer.json` (the author is your own name, edited by you), `composer.lock`, `Makefile`, `phpstan.neon`, `.php-cs-fixer.php`, `phpunit.xml`, `php/tests/EnvironmentTest.php`, `shim/rustfmt.toml`, `shim/clippy.toml`, placeholders in `php/src`, `php/tests`, `shim`, `rlm`, `examples`, and from P1.4 `.github/workflows/ci.yml`. **Committed:** everything up to P1.3, in your `initial commit` `83c8a97` (pushed to `ralphxp/raphp`). **Not committed:** the rename, the `nlm/` to `rlm/` directory move, the CI file, and the doc updates after that commit.

**Throwaway spikes** (`spikes/`, about 1.8 GB in total, most of it `04-static-php/work` at 1.4 GB and `shim-win/target` at 318 MB; both must be ignored by git):
- `01-shim-roundtrip/`: Rust shim stage A (`shim/`), windowed shim with file dialog (`shim-win/`), `spike01.php`, `spike01b.php`, `hold.php`, `chrome.php`
- `02-socketpair/`: `parent.php`, `child.php`, `fdcheck.php`
- `03-pdeathsig/`: `runner.php`, `middle.php`, `child.php`
- `04-static-php/`: `build.sh`, `progress.sh`, `bin/spc` (+ checksum), `work/` (sources, libraries, the built `php` and `micro.sfx`), `app/`, `app2/`, `make_phar.php`, `out/` (the combined binaries)
- `phase0-doc-audit-result.json`: the full audit result

**Outside the project:** the early planning file `~/.claude/plans/before-we-continue-this-twinkling-plum.md` (superseded), backups of the docs and workflow scratch files under the session scratchpad, and `perl` / `re2c` installed on your system.

**Not created yet:** any production source, the shim crate, the `rlm` CLI.

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
