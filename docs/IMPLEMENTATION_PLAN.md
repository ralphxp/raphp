# Raphp Implementation Plan

**Status:** Draft v0.2 for review, 2026-11-06. Revised after the Phase 0 audit: the spike results P0.0 to P0.7 and findings F1 to F19 are folded into the steps below. Anything marked *(added after the Phase 0 audit)* is new since v0.1.
**Source of truth for design:** `FRAMEWORK.md` (current version in its own status line). This plan says *in what order* and *how we know each step is done*. It does not redesign anything. If a step reveals that the framework doc is wrong, we fix the doc first, then continue.
**Where results live:** the results of P0.0 to P0.7 are recorded in `PROGRESS.md` and consolidated and finalised in `SPIKE_REPORT.md` at P0.8. This plan carries only short status tags and the corrected instructions, not a status tracker.
**IDs:** `R` ids are the risk register (`FRAMEWORK.md` section 16), `D` ids the decision log (section 15), `O` ids the numbered questions (section 17). There is no O6. Ids are never renumbered or reused. `F` ids are the Phase 0 findings. F1 to F10 are defined in `PROGRESS.md` section 4, and F1 to F19 are all defined in `docs/SPIKE_REPORT.md` section 4, which is the full list.

---

## 0. Working agreement

These rules exist because the earlier spikes ran ahead of you. They bind me.

1. **I stop at every checkpoint and wait for your approval.** No work starts on the next phase until you say so.
2. **Within a phase, I do one step at a time** and report after each: what I did, the exact acceptance output, and anything that surprised me.
3. **No scope growth.** Anything not in this plan goes to the "Parked ideas" list (section 12) and waits for you.
4. **Honest results.** If an acceptance test fails or is skipped, I say so with the output. A step is not "done" on my say-so, it is done when its acceptance check passes and I have shown the output.
5. **Docs stay true.** A step that changes a design decision updates `FRAMEWORK.md` (and its decision log) in the same step.
6. **Anything that needs `sudo`, a download of unknown size, or changes outside the project folder is asked for first.**
7. **Throwaway versus kept code.** Phase 0 code lives in `spikes/` and is throwaway. From Phase 1 on, everything is production code with tests.
8. **`PROGRESS.md` is updated at the end of every step** and whenever something is decided or found. It is the record of what has actually been achieved.

### Definition of done (every step from Phase 1)
- Code follows PER-CS and passes PHPStan at the configured strict level (PHP) and `clippy` plus `rustfmt` (Rust).
- Tests for the new behavior exist and pass.
- `make check` (defined in P1.3) passes.
- Relevant docs updated.
- Acceptance command output shown to you.
- No invariant below is broken.

### Project-wide invariants *(added after the Phase 0 audit)*
These come from Phase 0 findings. Each has a named step that tests it, and a later step must not weaken it.

| Invariant | Source | Tested in |
|---|---|---|
| Every file descriptor Raphp creates is close-on-exec. Worker channels are created only through the P2.9 channel factory (`socket_create_pair` with `SOCK_CLOEXEC` plus `socket_export_stream`), never `stream_socket_pair`. `ext-sockets` is a hard requirement. | F1 | P2.9, P1.3 (forbidden-calls rule) |
| Workers are spawned with raw `proc_open` and an ARRAY command, never a string and never `amphp/process` (source-checked: its source was read, not run in a spike). The "never a string" part is derived from how `PDEATHSIG` works (a string command makes `/bin/sh` the parent), not separately tested in Phase 0. P2.9 check 4 is its first test. | F2, F18, D18 | P2.9 |
| Revolt watchers are cancelled before the stream they watch is closed. | F5 | P2.5 |
| PHP code uses the instance form `$ffi->new(...)`, and tests run with `error_reporting = E_ALL` and fail on deprecations. | F11, D20 | P1.3, P3.5 |
| Redraws are paced to the compositor and driven by dirty state, never polled. | F8 | P3.6, P3.8, P3.10 |
| The shim's glibc floor is recorded and never silently raised above the declared minimum. | F14, R23, D21 | P3.1, P6.1, P6.7, P6.8 |

### Step size labels
**S** = a focused session, **M** = a few sessions, **L** = a long piece of work that may be split further when we reach it. These are relative sizes, not time estimates.

---

## 1. Overview

| Phase | Name | Outcome | Gate to start next phase |
|---|---|---|---|
| **0** | Spikes and verification | Evidence for every "Unverified" claim in `FRAMEWORK.md`. Go/no-go per risk. | Checkpoint 0 |
| **1** | Repository foundation | Clean repo, tooling, `make check`, composition root | Checkpoint 1 |
| **2** | Runtime core and IPC | Headless master/worker apps with RPC, cancel, supervision | Checkpoint 2 |
| **2B** | Tier 1 standard library | Filesystem, process, streams, signals services | Checkpoint 2B |
| **3** | `libraphp` shim MVP | A native window driven from PHP over FFI | Checkpoint 3 |
| **4** | UI framework | `hello-gui` example, responsive UI over a worker | Checkpoint 4 |
| **5** | `rlm` | `init`, `doctor`, `add`, `install`, `run`, `dev` | Checkpoint 5 |
| **6** | Standalone packaging | `hello-gui` as a single binary | Checkpoint 6 |
| **7** | Runtime tier 2 | Sockets, HTTP client and server, WebSocket | Checkpoint 7 |
| **8** | Tier 3 and hardening | TLS, crypto, zlib, DNS, security review, v0.1 release | Checkpoint 8 |

Phases 2 and 3 are independent of each other after Phase 1 (no GUI needed for IPC, no IPC needed for the shim). I will still run them in order, so that you review one thing at a time.

### Risk gates (from `FRAMEWORK.md` section 16)

| Risk | Resolved by | If it fails | Phase 0 outcome |
|---|---|---|---|
| R1 winit loop off the main thread | P0.1 | Fall back to pumping the toolkit from the master thread. Needs a design update to FRAMEWORK 7.2. | **Verified** on Wayland and X11 (the X11 run is inferred from `WAYLAND_DISPLAY` being unset plus creation time; the backend was not logged). Keyboard and click events not yet tested. |
| R2 native chrome versus winit | P0.4 | Use the GTK4 window path. Needs `gtk4-devel`. | **Decided** (user): option (a), GNOME first. Dialogs and tray not yet exercised, see P3.12a to P3.12c. Fallback (b) untested. |
| R3 glibc static PHP with FFI | P0.5 | Ship a bundle directory (binary plus libs) instead of a single file. | **Verified, with a condition:** the shim's glibc floor is higher than PHP's (R23). |
| R4 self re-exec in micro | P0.6 | Spawn workers through a separate copy of the runtime beside the app. | **Verified.** Use `readlink('/proc/self/exe')`, because `PHP_BINARY` is empty in micro (D19). |
| O1 socket-pair via `proc_open` | P0.2 | Use path sockets plus token (the original plan). | **Verified, with a condition:** `SOCK_CLOEXEC` and `ext-sockets` are mandatory. Tested with one worker only. |

**Hard gate:** R1 and R3 must pass before Phase 3 and Phase 6 respectively. R1 has passed. R3 has passed with the R23 condition, which P3.1, P6.1, P6.7 and P6.8 must close. R2 is decided (option (a)), so P3.12a to P3.12c proceed on that basis.

### Open design gaps *(added after the Phase 0 audit)*
These are gaps in the design, found by the Phase 0 audit. They are **Open**. This plan does not choose a solution for any of them, except D21 for R23, which you approved on 2026-11-06 (its build recipe is still to be chosen and verified). For R14 to R21 the named step's acceptance must include a written contract in `FRAMEWORK.md` (and a decision-log row) plus a test of it, and that step is not done until it is. R22 has no building step yet (P8.4 reviews it). R23 is closed by the P6.1 to P6.8 recipe and tests, not by a contract. All are rows in `FRAMEWORK.md` section 16.

| ID | Gap | Why it matters | Step whose acceptance must resolve it |
|---|---|---|---|
| R14 | A panic or exit on the shim's own UI thread is not covered. The panic rule covers only calls into the shim, and the shim's exit has no defined place in the shutdown order. | Depending on how the crate is built, a UI-thread panic could take down the whole master (and, through `PDEATHSIG`, every worker) or silently kill only the window. | P3.1 (panic behavior). The shutdown-order part is checked again at P4.5. |
| R15 | No ordering contract between the command queue and the event queue. | PHP may receive events for widgets it has already removed. A command error cannot be tied to the batch that caused it. A re-render may race with text the user is typing. | P3.3. P4.2, P4.3 and P4.6 test whatever P3.3 defines. |
| R16 | An event larger than the poll buffer can stall all UI input. | The event at the head of the queue blocks every later event, including clicks and key presses, and the `eventfd` is already cleared. | P3.2. |
| R17 | `proc_open` forks while a live multi-threaded shim exists in the master. Never tested. | Every worker start, restart and hot reload does this. Descriptors owned by the window-system libraries are outside the F1 rule and could leak into workers. | P3.5 (first step where PHP drives a live shim). Repeat with a real window in P3.6. |
| R18 | No rule for reaping workers or draining their stdout and stderr. | An unreaped or still-running child can block the master loop, exit status can be lost, and a chatty worker can stall on a full pipe and be killed for it. | P2.9. P2.10 relies on the result. |
| R19 | Ping, pong and cancel can be starved behind a very large message on the single ordered stream. | A healthy worker could be declared dead, and a cancel could arrive late. | P2.7. P2.3 covers the decoder side of oversize lines. |
| R20 | The master cannot be recycled, yet it holds the most long-lived state (all view code). | A slow leak in the master has no remedy except closing the app. | P2.13 (baseline measurement and budget). P4.8 (UI-event soak). |
| R21 | Hot reload covers worker code only, while most edits land in master code. | The reload promise does not hold for the common case. How a reload restart is counted by the supervisor is also open. | P5.6. |
| R22 | `SO_PEERCRED` is not exposed by PHP's sockets extension here, so the secondary path-socket authentication is unspecified. | The secondary IPC path cannot be built as the framework doc describes it. | No step builds the secondary path in this plan. It must be resolved before one is added, and P8.4 reviews it. |
| R23 | The shim's glibc floor (2.34 headless, 2.43 windowed) is higher than PHP's (2.17). The shim was built only on Fedora 44. | The distributable floor is set by how the shim is built. Loading the shim on the build host proves nothing about older distributions. | P3.1 (record), P6.1 (recipe), P6.7 (old-glibc test), P6.8 (declared minimum). D21 (build against an old glibc) is **Decided** (approved 2026-11-06); the recipe is chosen and verified in P3.1 and P6.1. |

---

## 2. Things I need from you along the way

| When | What | Why |
|---|---|---|
| P0.4 | Nothing now. `sudo dnf install gtk4-devel` is needed only if the GTK4 fallback (b) is ever pursued. Option (a) was chosen. | Not installed on this machine |
| P0.5 | Done. Permission was given. `spc doctor --no-interaction` attempts `sudo` installs on its own, so ask before re-running it. | Rule 6 |
| Checkpoint 0 | *Answered:* D21 (build the shim against an old glibc) was approved on 2026-11-06 | Decides how P3.1 and P6.1 build the shim |
| P1.1 | *Answered 2026-11-06:* PHP floor, container, formatter, copyright holder (D23 to D26). Still open: the Composer vendor name, which stays a placeholder (`raphp/raphp`; the name was a code name when this was decided, D22, and the rename is D28), and the exact tool versions (fixed in P1.3) | Binding for the whole project |
| P6.1 | Permission before the first `spc` run. It downloads sources and installs Zig 0.17.1 and `pkg-config` into its own work directory. `spc doctor --no-interaction` may attempt `sudo` installs on its own. The P0.5 build took well over 30 minutes of compute. | Rule 6 |
| P6.7 | Permission before pulling container images (`podman`, including an old-glibc image) | A download of unknown size, so working-agreement rule 6 applies |
| P1.2 | Copyright holder name for the MIT `LICENSE`, and whether to `git init`. The project folder is on a root-owned mount with 777 permissions, where git reports "dubious ownership", so a `safe.directory` entry may be needed (F19). | Not in the conversation yet |
| P1.4 | Git hosting (GitHub, GitLab, other) | Decides the CI file format |
| P3.12c | You enable the GNOME AppIndicator extension (installed, currently disabled) | The tray can only be tested with it enabled |
| P5.5 | Where prebuilt `libraphp` binaries will be published | Decides the native lib fetch source |

---

## 3. Phase 0: Spikes and verification

**Goal:** turn every **[Unverified]** tag in `FRAMEWORK.md` into **[Verified]** or a design change, before production code exists. The one exception is IME and text input (R8), which is resolved by P3.11a.
**Rules:** throwaway code in `spikes/<NN-name>/`. Each spike ends with a short written result in `docs/PROGRESS.md`; P0.8 consolidates them into `docs/SPIKE_REPORT.md`. Time-box each spike. If it is not converging, I stop and ask you.
**Status tags:** the steps below keep their original **Do** and **Done when** criteria. A **Result** line records the outcome in one or two sentences, with details in `PROGRESS.md` section 3. Where a Phase 0 finding corrected a step's instructions, the corrected text replaces the old one.

### P0.0 Spike 1 stage A: queue + eventfd shim round trip. **Done**
Headless Rust shim with a UI thread, in-memory event queue and `eventfd`, driven from PHP via FFI and watched by Revolt.
- Results are in `FRAMEWORK.md` Appendix A. Pipe-as-buffer lost 88% of events under a PHP stall. Queue plus `eventfd` lost none.
- Code: `spikes/01-shim-roundtrip/{shim,spike01.php}`.

### P0.1 Spike 1 stage B: real window on a non-main thread (R1). Size S. **Done**
Code: `shim-win/`, `spike01b.php`.
**Result:** passes R1. The winit loop runs on a non-main thread (`with_any_thread`) under Wayland and X11 (the X11 run is inferred from `WAYLAND_DISPLAY` being unset plus creation time; the backend was not logged). 1000 round trips p50 65 to 114 us. The UI thread kept redrawing while PHP was blocked. Pointer events arrived and were coalesced. You confirmed a window appeared. Not yet tested: keyboard and click events (see P3.9). The redraw counts were unthrottled CPU fills, not a rendering benchmark (see P3.6, P3.10). Also verified under the glibc PHP build.
- **Do:** run `spike01b.php`. A window will appear for about 4 seconds on your Wayland session.
- **Done when:** window created on the shim thread; 1000 ping round trips measured through the winit proxy; `set_title` and `fill` commands visibly work; the 500 ms animation completes at a sane frame rate **while PHP is blocked**; shutdown joins cleanly. Optional manual part: mouse and key events arrive, and mouse moves coalesce.
- **Also check:** the same code under X11 (`WAYLAND_DISPLAY` unset, via XWayland) if feasible.
- **Output:** pass/fail notes for R1, including numbers.

### P0.2 Socket-pair inheritance via `proc_open` (O1). Size S. **Done, with a condition**
- **Do:** parent creates `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, $pair)` and exports each end with `socket_export_stream`. It passes one end as a numbered descriptor in `proc_open`'s descriptor spec (the command must be an ARRAY, not a string; this is derived from how `PDEATHSIG` works, because a string command makes `/bin/sh` the parent, and was not separately tested in Phase 0), closes its own copy of the child's end after spawning, and the child reads `php://fd/3`. Both sides under Revolt. `stream_socket_pair` is not usable here: it leaks the parent's end (and other fds) into the child, so the child never sees EOF.
- **Done when:** NDJSON flows both ways without blocking the loop; child sees EOF when the parent closes; parent sees EOF when the child exits; writes larger than the socket buffer back-pressure correctly; the child still sees EOF while the parent's own end is open elsewhere in the parent (shows the parent's end is not inherited; the rule for every Raphp fd is derived from this and tested in P2.9).
- **Fallback if it fails:** path socket plus token (FRAMEWORK 6.2 secondary).
- **Result:** passes, with a mandatory condition: `SOCK_CLOEXEC`, which makes `ext-sockets` a hard requirement. 2000 round trips p50 about 106 to 265 us. A 16 MiB write to a stalled child back-pressured correctly: the loop kept ticking (max gap about 19 ms) and all 16,777,217 bytes arrived. Tested with ONE worker only, so the two-worker case is a P2.9 check.

### P0.3 Orphan prevention via `prctl(PR_SET_PDEATHSIG)` (5.6). Size S. **Done**
- **Do:** child calls `prctl(PR_SET_PDEATHSIG, SIGTERM)` through libc FFI right after start, then checks `getppid()`.
- **Done when:** `SIGKILL` of the parent terminates the child promptly. Document the race (parent dying before the call) and the EOF-based backup. The worker's SIGTERM handling must actually terminate it.
- **Result:** passes. The child was killed 5 to 9 ms after a master `SIGKILL`. Without `prctl` (control test) the child was still alive at the end of the 3 s observation window. The startup race is handled by the `getppid()` guard.

### P0.4 Spike 2: native chrome on Linux with a drawn window (R2). Size M. **Decided**
**Decided by you:** option (a), GNOME first, improve later. This is a decision, not a hands-on pass. Findings are in `PROGRESS.md`. A later chrome experiment verified a real portal file dialog end to end (requested from PHP through the shim, result returned as an event, dialog not attached to the window) and the title bar's close button. The tray, menus, dialog parenting and responsiveness during a dialog were NOT exercised, and are deferred to P3.12a to P3.12c.
- **Do:** evaluate, in this order, and stop at the first acceptable one:
  - **(a)** winit window + `rfd`/portal file dialog + custom in-window menus + tray via StatusNotifier.
  - **(b)** GTK4 window with a GL area hosting the renderer (needs `gtk4-devel`, asked for first).
- **Done when:** a decision note covers menus, file dialog, tray, clipboard, and window decorations on GNOME/Wayland, with what works and what does not.
- **Output:** the chosen approach and the fallback, written into `FRAMEWORK.md` 7.7.
- **Findings that shape later steps (F6, F7):** GNOME 50.5 Wayland offers no server-side decorations, so winit draws client-side ones. winit 0.30.13 has no `xdg_popup` support, so menus are drawn inside the window. There is no StatusNotifierWatcher unless the AppIndicator extension is enabled (installed, disabled here). The clipboard must use `smithay-clipboard`, not `arboard` (no data-control protocol). The FileChooser portal is available. `zwp_text_input_manager_v3` is offered.

### P0.5 Spike 3: static-php-cli glibc build with FFI (R3). Size M. **Done, with a condition**
- **Do:** with your permission, fetch `spc`, build a glibc PHP with extensions `ffi, pcntl, posix, sockets, mbstring, openssl, zlib, sodium, phar` (and optionally `uv`, `eio`), plus `micro`.
- **Done when:** the built PHP runs `FFI::cdef` and loads the spike shim; a phar can be appended to `micro.sfx` and runs; binary size is recorded. *(Corrected after the audit: the original criterion "`ffi.enable` can be baked into the build's ini" is not needed. `ffi.enable` defaults to `preload`, which already allows `FFI::cdef` in plain CLI and in micro, see D20.)* *(Added after the Phase 0 audit:)* for the built `php`, `micro.sfx` and the spike shim `.so`, record the highest `GLIBC_x.y` each requires (`objdump -T FILE | grep -o "GLIBC_[0-9.]*" | sort -Vu | tail -1`) and the Zig version used. Loading the shim on this Fedora 44 host does not prove a floor, because the host glibc is newer.
- **Output:** a working, documented build recipe, or a documented reason it cannot work and the fallback layout.
- **Result:** passes R3 with a condition. static-php-cli 2.8.6 with `SPC_TARGET=native-native-gnu.2.17` built PHP 8.5.12 CLI (21.0 MB) and `micro.sfx` (18.9 MB), dynamically linked against glibc only, with `ffi, pcntl, posix, sockets, mbstring, openssl, zlib, sodium, phar` (plus opcache). `php`, `micro.sfx` and the app need glibc up to 2.17. The Rust shim needs up to 2.34 (headless) and 2.43 (windowed), so the distributable floor is set by how the shim is built (R23, D21). Not yet run on any other distro. The PHP configure duration is unknown.

### P0.6 Spike 4: self re-exec inside a micro binary (R4). Size S. **Done**
Depends on P0.5.
- **Done when:** the built binary spawns itself with `--role=worker`, and the child receives the inherited socket and answers. Record what `PHP_BINARY` and `/proc/self/exe` return.
- **Result:** passes R4. Inside micro `PHP_BINARY` is EMPTY, and `readlink('/proc/self/exe')` returns the binary itself, even when launched via `PATH` from another directory (D19). A phar appended to `micro.sfx` runs. The master re-executed itself as a worker with fd 3 inherited, role dispatch worked, and the worker exited 0 on EOF. The single-file app is 19.5 MB.

### P0.7 Spike: embedded shim via `memfd_create` (10.2). Size S. **Done, headless only**
Depends on P0.5.
- **Done when:** the shim `.so` stored inside the phar is written to a `memfd`, loaded through `FFI::cdef(..., "/proc/self/fd/N")`, and works. Otherwise, document the sidecar fallback.
- **Result:** passes for the headless shim inside micro. The windowed shim was verified under the glibc CLI PHP but NOT through `memfd` inside micro. P6.5 must close that gap.

### P0.8 Phase 0 report. Size S
- **Do:** write `docs/SPIKE_REPORT.md`, update `FRAMEWORK.md` (tags, decision log D18 to D21, risk register R14 to R23), and list any design changes. Confirm that this plan's acceptance criteria still match the framework doc after those updates, including the R15 owner (P3.3 defines the command/event ordering contract, P4.2, P4.3 and P4.6 test against it) and the P3.11b / P3.12 restructure.
- **Checkpoint 0:** you review the report. We decide go/no-go for Phase 1 and for each gated risk. D21 was approved on 2026-11-06.

---

## 4. Phase 1: Repository foundation

**Goal:** a clean, checked, empty-but-wired project that every later step builds on.

### P1.1 Project decisions. Size S
Already decided, not reopened here (D13): PER-CS, PSRs, strict types, PHPStan strict, PHPUnit.
**Decided by you on 2026-11-06:**
- **PHP floor: 8.5 or newer** (D23), confirmed by you. CI runs on 8.5 for now. PHPUnit 13 needs PHP 8.5.12 or newer, which this floor allows.
- **Container: PHP-DI 7** (D24). The core depends only on `psr/container`.
- **Formatter: PHP-CS-Fixer** with the `@PER-CS3.0` rule set (D25), pinned so a tool update cannot change the rules.
- **Copyright holder: Codizium** (D26).
- **Composer vendor and package name:** the framework name was then a code name (D22), so a placeholder was used, now `raphp/raphp` (D28), and nothing is published under it.

**Still to do in this step:** the exact versions of PHPUnit, PHP-CS-Fixer and PHPStan, and the strictest PHPStan configuration that passes cleanly on an empty project (never lowered afterwards). They are fixed in P1.3, when the tools are installed.
**Done when:** the decisions are recorded in the `FRAMEWORK.md` decision log. *(Done 2026-11-06, D23 to D26. The tool versions close in P1.3.)*

### P1.2 Repository scaffold. Size S
- `LICENSE` (MIT, with your copyright holder name), `README.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, `.gitignore`, `.editorconfig`.
- `composer.json` with PSR-4 `Raphp\` → `php/src/`, dev autoload for tests, scripts. The required extensions from FRAMEWORK 10 are declared, with `ext-sockets` as a hard requirement (F1).
- Directory layout from FRAMEWORK/plan: `php/`, `shim/`, `rlm/`, `examples/`, `docs/`, `spikes/`.
- `git init` was done by you. The project mount is root-owned with 777 permissions and git reports "dubious ownership" there (F19). Git ignores `safe.directory` in a repository's own config, so the fix is a global entry (you already have one for your other projects on this mount) or `git -c safe.directory=...` per command.
**Done when:** `composer validate` passes and a clean checkout installs. Composer's symlink and permission behavior on this mount is untested, so the install is checked here.
**Result (2026-11-06):** passes. `composer validate --strict` reports the file valid; `composer check-platform-reqs` passes for PHP 8.5.12 and `ffi`, `json`, `pcntl`, `posix`, `sockets`; a clean install works; and a package with an executable (`phpstan`) installs on this mount as a regular proxy file and runs. Composer prints a "dubious ownership" warning and defaults the root version because it probes git, which needs the global `safe.directory` entry (or `COMPOSER_ROOT_VERSION`). Nothing is committed yet.

### P1.3 Tooling and `make check`. Size S
- `phpstan.neon`, the formatter configuration (`.php-cs-fixer.php`, PHP-CS-Fixer with `@PER-CS3.0`, D25), `phpunit.xml`, `rustfmt.toml`, clippy settings.
- A top-level `Makefile` (or `justfile`) with `check`, `test`, `stan`, `cs`, `fix`, `shim`, `header`.
- `make check` runs PHP formatting check, PHPStan, PHPUnit, `cargo fmt --check`, `cargo clippy -D warnings`, `cargo test`.
- PHP tests run with `error_reporting = E_ALL` and fail on deprecations (F11: the PHP 8.5 `FFI::new()` static-call deprecation was invisible under the system ini). *(added after the Phase 0 audit)*
- A forbidden-calls rule in static analysis (for example a PHPStan disallowed-calls extension; the package choice, and whether it can tell a string `proc_open` argument from an array one, are unverified, so the rule bans by function name) that bans `proc_open` outside the process spawner and `stream_socket_pair` outside tests. The allow-list entries are filled in by P2.9, when those locations exist. *(added after the Phase 0 audit, F1, F2)*
**Done when:** `make check` passes on the empty project, and fails if I introduce a deliberate style error or a deliberate forbidden call (I show all three).
**Result (2026-11-06):** passes.
- **Versions fixed:** PHPUnit 13.4.6, PHPStan 2.3.5 with strict-rules 2.1.1, deprecation-rules 2.0.5 and `spaze/phpstan-disallowed-calls` 4.15.0, PHP-CS-Fixer 3.95.28 (`@PER-CS3.0` and `@PER-CS3.0:risky`). PHPStan runs at `level: max`.
- **Baseline:** `make check` exits 0 (formatting clean, PHPStan clean, 7 PHPUnit tests pass). The Rust step is **skipped** because `shim/Cargo.toml` does not exist until P3.1. It prints that, and confirms `rustfmt` 1.9.0 and `clippy` 0.1.95 are present. So `cargo fmt`, `clippy` and `cargo test` have **not actually run** yet.
- **Deliberate failures shown:** a badly formatted file fails `make check` at the formatter with the diff; `proc_open()` in `php/src` fails PHPStan with the spawner message; `stream_socket_pair()` fails in `php/src` and is allowed in `php/tests`; a real PHP 8.5 deprecation (`FFI::new()` called statically) fails PHPUnit.
- **Finding F22:** PHPUnit 13 narrows `error_reporting()` inside tests to 245 on purpose, so a test cannot assert the value. The test proves the behaviour instead: a deprecation raised in a test reaches PHPUnit. An expected deprecation still counts as an issue under `failOnDeprecation` unless the test also carries `#[IgnoreDeprecations]`.
- The `proc_open` allow-list entry is added in P2.9, when the spawner exists.

### P1.4 Continuous integration. Size S
Depends on your hosting answer. Runs `make check` on PHP 8.5 (the floor, D23), plus stable Rust, with `ffi`, `pcntl`, `posix` and `sockets` enabled.
**Done when:** the pipeline file exists and (if hosting is set up) is green.
**Result (2026-11-06):** the pipeline file is written, **not yet proven green**. GitHub is the host (`github.com/ralphxp/raphp`), so it is `.github/workflows/ci.yml`: one job that runs `make check` on PHP 8.5 with `ffi`, `pcntl`, `posix` and `sockets`, plus stable Rust with rustfmt and clippy, on pushes to `master` and on pull requests. Actions used: `actions/checkout@v7`, `shivammathur/setup-php@v2`, `dtolnay/rust-toolchain@stable`, all pinned by tag, not by commit hash. The YAML parses. It has never run: it needs a push, after which the result is read with `gh run list`. The Rust steps are skipped by `make check` until P3.1, so the Rust toolchain setup is unused for now.

### P1.5 Composition root skeleton. Size M
- PSR-11 container bootstrapping, typed `Config` loaded from `raphp.json` plus environment, PSR-3 logger (Monolog), PSR-20 clock.
- No global helpers, no static facades.
**Done when:** unit tests cover config loading (valid, invalid, env override) and container wiring.
**Result (2026-11-06):** passes. `make check` exits 0 with **302 tests and 481 assertions**; PHPStan runs at `level: max`.
- **Built** (all under `php/src/Core`): `Config\{Config, ConfigLoader, InvalidConfigException, Problems, WorkerConfig, UiConfig, PhpConfig, BuildConfig}`, `Logging\{LogLevel, LoggerFactory, LineLogFormatter}`, `Clock\SystemClock`, `Container\ContainerFactory`. Rules and environment overrides are in FRAMEWORK 9.2 (D29, needs your approval). `InvalidConfigException` extends the framework's `RaphpException` (P1.6).
- **Verified by attack and by mutation.** I probed the loader with hostile input and fixed what it found (a PCRE `$` trailing-newline hole, weak path checks, `null` sections treated as absent, an unbounded pool, a crash on a non-string environment value, control characters in error output). An independent review (three lenses, two skeptics per finding) then confirmed 20 more points, all applied: realpath and `file://`, digit-only keys becoming integer keys, unbounded and unescaped error output, the log level as an enum (D30), the logger taking its time from the PSR-20 clock in UTC, and the Fiber limit of PHP-DI (R24). Finally **60 deliberate mutations of the source were applied one at a time: 58 were killed by the tests, and the 2 survivors are equivalent** (a planted no-op, and a leading-slash check that the empty-segment rule also covers).
- **Not covered:** the logger's default stream (`php://stderr`) is not captured by a test; a manifest that repeats a JSON key keeps the last value (R25); the container is not exercised under real concurrency.

### P1.6 Error model. Size S
- `Raphp\Error\*` exception hierarchy and an `ErrorCode` enum matching FRAMEWORK 6.6.
**Done when:** tests prove every code round-trips to and from its string form.
**Result (2026-11-06):** passes. `make check` exits 0 with **415 tests and 810 assertions**, no skips.
- **Built** (`php/src/Error`): the string-backed `ErrorCode` enum (the nine codes of FRAMEWORK 6.6), the abstract `RaphpException`, the abstract `RpcException` (`wireCode()`, `toWire()`, `fromWire()`), nine final exceptions (one per code), and `ApplicationException` for `E_APP_[A-Z0-9_]+`. `InvalidConfig` became `InvalidConfigException` and extends `RaphpException`. Rules and the table of codes to classes are in FRAMEWORK 6.6 (D32, needs your approval).
- **Round trips:** every code round-trips through its string, and every exception through real `json_encode` and `json_decode` back to the same class, message and data, including falsy data (`false`, `0`, `''`).
- **Edge cases covered:** a malformed error object, an unknown code, an `E_APP_*` code in the wrong shape, extra keys from a newer peer, a missing `data`, and the previous exception staying local.
- **Mutation testing:** 56 deliberate bugs (every wire string, every code-to-class mapping, every validation branch, every wire field, the `final` and `abstract` modifiers). **All 56 were killed, none survived.**
- **A lesson:** PHPStan rejected two of my first tests as tautologies it could prove statically, so I replaced them with a test that exercises real behaviour. That a module exception is not an IPC error is enforced by the type system.

**Checkpoint 1:** `make check` is green on a clean clone. You review structure and tooling.
**Checkpoint 1 status (2026-11-06):** a clean copy of the working tree (no `vendor/`, no caches), installed from `composer.lock` (72 packages), passes `make check` with 415 tests and 810 assertions. CI on GitHub has run green on commits `f221905` and `aa1e919` but not yet on the final version, which is not committed. You review structure and tooling.

---

## 5. Phase 2: Runtime core and IPC (no GUI)

**Goal:** headless master and workers that talk reliably, cancel work, and survive crashes.

### P2.1 Kernel: loop bootstrap, signals, shutdown, error policy. Size M
- Revolt error handler implementing O12 (master: log, graceful shutdown, non-zero exit). SIGINT/SIGTERM handling. Ordered shutdown hooks.
**Done when:** integration tests (child process) show correct exit codes and hook order for a thrown exception, SIGINT and SIGTERM.

**Result (2026-11-06):** passes. `Raphp\Core\Kernel`; exit codes 0, 1, 130, 143 or the code given to `stop()`; hooks run last-registered first within one shared 5 s budget; a failing or overrunning hook is logged and makes the exit non-zero; a second signal ends the process at once. 11 integration tests against a real child process, stable over 8 repeated runs. FRAMEWORK 4.5.

### P2.2 Supervised background task runner. Size S
- Fire-and-forget tasks that log failures (PSR-3), never silently swallowed, never an unhandled `UnhandledFutureError`.
**Done when:** a test proves a failing background task is logged and the loop keeps running.

**Result (2026-11-06):** passes. `Raphp\Async\BackgroundTasks` with `run()`, `count()`, `awaitAll()` and `cancelAll()`; 12 tests, including proof that no future is left unhandled. Amp starts a task on the next tick, so a task is visible in `count()` right after `run()` but has not yet started.

### P2.3 NDJSON codec. Size M
- `Codec` interface and `JsonLineCodec`: no pretty-print, strict UTF-8, incremental decoder for partial and multi-message chunks, max line size → `E_PAYLOAD_TOO_LARGE`.
- Shared **golden files** in `docs/protocol/golden/` that the Rust side will reuse.
**Done when:** tests cover split-anywhere chunking (every byte boundary), multiple messages per chunk, oversized lines, invalid UTF-8, and invalid JSON.

**Result (2026-11-06):** passes. The decoder is pull-based and reads in linear time. A bad line is an `E_PROTOCOL` item and decoding continues. An oversize line (complete, or unfinished past the limit) is one `E_PAYLOAD_TOO_LARGE`, after which the decoder stops and discards input, so memory stays bounded. This is the decoder half of R19; the transport half (a large message ahead of a ping) is still P2.7. Golden files are in `docs/protocol/golden/`. Mutation testing: 26 of 29 killed, 3 equivalent. A JSON object with digit keys decodes to a PHP list (F32) and is still an object: the envelope check in P2.4 judges its keys. FRAMEWORK 6.3.

### P2.4 Message types. Size S
- Immutable `Envelope` and typed messages (`Request`, `Response`, `Event`, `Cancel`, `Hello`, `Bye`, `Ping`, `Pong`), a `Kind` enum, validation that maps to `E_PROTOCOL`.
**Done when:** tests cover valid messages, missing fields, wrong types, unknown `kind`.

**Result (2026-11-06):** passes. `Kind` enum, `Envelope` interface and eight final readonly messages; `EnvelopeReader` checks field types and maps every refusal to `E_PROTOCOL`, the constructors check values. Every golden message reads into the right type and writes back to the golden bytes. About 70 refusal cases are tested for their reason. Mutation testing: 55 of 55 killed. The spec example gave a progress `evt` an `id`; removed (F36). FRAMEWORK 6.4.

### P2.5 Transport and stream transport. Size M
- `Transport` interface; `StreamTransport` over a stream resource (Amp byte streams), with write back-pressure and EOF/close handling.
**Done when:** tests over an in-process `stream_socket_pair` show ordered delivery, back-pressure on large writes, and clean close on both sides. Using `stream_socket_pair` here is acceptable ONLY because no child process is spawned, so the descriptors can never be inherited. It does not contradict the close-on-exec rule. Closing a transport while read or write watchers are registered cancels the watchers first and never crashes `stream_select` (F5). Any test or code path that crosses `proc_open` must use the P2.9 channel factory, never `stream_socket_pair` (F1). *(added after the Phase 0 audit)*

**Result (2026-11-06):** passes. `Transport` (bytes only) and `StreamTransport` over a stream socket. Tested over an in-process socket pair: ordered delivery, simultaneous two-way traffic, a 16 MiB write that waits for a slow reader while the loop keeps ticking, half-close, close with a read or a write waiting, 50 close cycles with pending reads (no stray descriptors, no loop errors), cancellation, concurrent writers that never interleave. **Two findings shaped the code:** an AF_UNIX socket takes only about 280 small writes before a write waits (F38), and Amp's `end()` loses queued writes (F39), so `StreamTransport::end()` waits for pending writes itself. FRAMEWORK 6.1 and 6.8. **For P2.6:** a write can wait after a few hundred small messages, so the peer's sending path must be designed for that (a queue, or a rule on who may block).

### P2.6 RPC peer and middleware. Size L
- `RpcPeer` used on both sides: `call()`, handler registry, `id` correlation with `DeferredFuture`, progress events (`evt` with `ref`), cancellation (`cancel` ↔ `DeferredCancellation`), timeouts, in-flight cap.
- `RpcMiddleware` contract (FRAMEWORK 4.8), `MiddlewareStack`, built-ins: timing/logging, error mapping, trace id.
**Done when:** tests cover out-of-order responses, concurrent calls, progress then result, cancellation, timeout, handler exception → `E_INTERNAL` mapping, peer closing mid-call → `E_WORKER_CRASHED`, middleware ordering and short-circuit.

**Result (2026-11-07):** passes, after an independent review whose 28 confirmed findings are fixed (PROGRESS.md section 3B). `RpcPeer` (one reader fiber that never waits on a write, one writer fiber), `RpcPeerOptions`, `HandlerRegistry`, `MiddlewareStack`, `TimingMiddleware`, `TraceIdMiddleware`, `ErrorMappingMiddleware`. Tested: out-of-order answers, concurrent calls, progress then result, cancellation, timeout, handler exception to `E_INTERNAL`, peer vanishing to `E_WORKER_CRASHED`, middleware order and short circuit (inbound and outbound, through a live peer), in-flight and waiting limits, two peers flooding each other, a far end that stops reading (memory stays bounded, the connection is cut). 843 tests in the suite. FRAMEWORK 4.8, 6.3, 6.4, 6.8, D37. Defaults (64 / 1024 / 256 / 256) are not measured: P2.13.

### P2.7 Handshake, versioning, heartbeat. Size M
- `hello` exchange with `proto` check, `ping`/`pong` liveness, configurable thresholds.
**Done when:** tests cover version mismatch (`bye` + `E_PROTOCOL`), missed pings declaring a peer dead, and no false positives under normal load.
**Open gap to close here: R19.** Ping, pong and cancel can be starved behind a very large message on the single ordered stream. This step's acceptance must include a written contract in `FRAMEWORK.md` and a test, for example a large in-flight message with a delayed ping. The decoder side of oversize lines (P2.3) is part of the same gap. *(added after the Phase 0 audit)*

**Result (2026-11-07):** passes. `RpcPeer` takes an optional `Hello`: it sends it first, requires the other side's before anything but `ping`, `pong` and `bye`, and refuses a different `proto`, a second `hello`, a message sent too early and a missing `hello` with `bye` + `E_PROTOCOL`, then closes. `remote()`, `awaitHandshake()` and a graceful `shutdown(reason)` that flushes a last `bye` within a timeout are new; protocol violations now also say why with a `bye` before the connection closes. Liveness is judged by **bytes heard, not by `pong`**: a `ping` after one silent interval, the peer declared dead after `missedPings` silent intervals, and no blame when this process itself stalled. **R19 is resolved** by a written contract (FRAMEWORK 6.8): a message in flight delays what is behind it by at most its transfer, a slowly arriving 3 MB message keeps its sender alive, a `cancel` cannot overtake the message ahead of it, and the size cap ends with `bye E_PAYLOAD_TOO_LARGE`. The ping latency numbers are P2.13's. About 60 tests added. FRAMEWORK 6.5, 6.8, D38.

### P2.8 Attribute-based handlers. Size S
- `#[RpcMethod('files.scan')]` and `#[Idempotent]` attributes, a registry built from the container.
**Done when:** tests prove discovery, duplicate-name detection, and that only `#[Idempotent]` methods are eligible for automatic retry.

**Result (2026-11-07):** passes. `#[RpcMethod]` and `#[Idempotent]` attributes, `MethodCatalog` (discovery from classes, no objects needed), `AttributeHandlers` (registers the methods from a PSR-11 container at boot) and `RetryMiddleware`. Tested: discovery, duplicate-name detection (both places named), every misuse refused at boot (not public, static, idempotent without rpc, empty or long name, unsupported parameter types), parameters filled by name and type through a live peer with a real PHP-DI container (about 30 wrong-type and missing-parameter cases), and that **only `#[Idempotent]` methods are retried**, for transient failures only, including over a real connection. FRAMEWORK 4.8, D39.

### P2.9 Process spawner. Size M
- **Channel factory:** `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, 0, $raw)` plus `socket_export_stream` on both ends, so every descriptor Raphp creates is close-on-exec (F1, P0.2). It fails with a clear typed error if `ext-sockets` or `SOCK_CLOEXEC` is missing. The parent closes its copy of the child's end right after `proc_open`.
- **Spawn:** raw `proc_open` with an ARRAY command, never a string (F2), so the master is the child's parent and not `/bin/sh`. Never `amphp/process` (F18, D18): it wraps every command in a `/bin/sh` script and uses fd 3 for its own pid pipe (source read, not run in a spike). The self re-exec path comes from `readlink('/proc/self/exe')` (D19). Role flags, stdout/stderr capture, `WorkerHandle`.
- **Orphan protection (per P0.3):** in the child, right after start, `prctl(PR_SET_PDEATHSIG, SIGTERM)` through libc FFI, then apply the spike's guard: compare `posix_getppid()` with the expected parent pid (the spike passed it as an argument) and exit if it differs. The exact argument convention is decided in this step and reconciled with the worker command line in FRAMEWORK 5.1 (path, `--role=worker`, `--name=<worker>`) and 5.6. The worker's SIGTERM handler must really terminate the process. **[Proposed]** The `prctl` wrapper lives in a separate runtime-side libc FFI namespace, chosen in this step, that does not depend on the UI package, so headless mode does not load the UI package.
- Add the allow-list entries to the P1.3 forbidden-calls rule (the spawner may call `proc_open`).
**Done when** (keep the existing hello, call and stdout/stderr capture test, and add) *(added after the Phase 0 audit)*:
1. `/proc/<worker pid>/fd` holds no socket for the worker's own channel's parent end. Extra descriptors such as the parent's script fd (F4) are tolerated and not asserted on.
2. Spawn worker A, then worker B, with `prctl` disabled by a test-only option. Closing A's parent end gives A EOF while B is still alive (proves no cross-inheritance). `SIGKILL` of the master then gives both workers EOF.
3. With `prctl` enabled, `SIGKILL` of the master terminates a worker within a stated bound. The spike measured 5 to 9 ms, so the CI bound should be generous, for example 1 s.
4. The worker's parent pid, from `/proc/<pid>/stat`, equals the master pid, not `/bin/sh`. The `/bin/sh`-as-parent effect of a string command was derived, not tested, in Phase 0, so this check is its first test.
5. `SIGTERM` sent to a worker terminates it. A worker started with an expected-parent pid that does not match exits (the startup-race guard). Also record whether a worker whose loop is busy, with a handler installed, still terminates on `SIGTERM`; that case is untested (FRAMEWORK 5.6).
6. This integration test is part of `make check`, so the PHP 8.5 leg of P1.4 runs it. The spike evidence covers PHP 8.5.12 only.
**Open gaps to close here: R18 and R17.** R18: the contract for reaping workers (collecting exit status) and for draining worker stdout and stderr is not designed yet. This step's acceptance must include the written contract and a test. R17: the interaction with a live multi-threaded shim is resolved in P3.5, and the spawner must not assume the answer before then.

**Result (2026-11-07):** passes, with real worker processes started by the real spawner, all inside `make check` (about 70 tests). `ChannelFactory`, `ProcessSpawner`, `WorkerSpec`, `WorkerHandle`, `OutputCapture`, `ExitStatus`, `SelfCommand`, `OrphanGuard`, `InheritedChannel`, and `Raphp\Runtime\Libc\ParentDeathSignal` in a runtime-side namespace that does not load the UI package. The six acceptance checks: (1) the worker holds no socket the master holds; (2) closing worker A's end gives A end of input while B runs on, and a master killed with SIGKILL ends all workers by end of input alone; (3) with the kernel signal alone a worker that ignores end of input still ends within the one-second bound, and with both safeguards off it survives (the control); (4) the worker's parent is the master, not `/bin/sh`; (5) SIGTERM ends an idle worker, a wrong parent pid makes it exit with code 4, and **a busy worker with a loop-bound SIGTERM handler does not end on SIGTERM** (recorded, FRAMEWORK 5.6); (6) all of it runs in `make check`. **R18 is resolved** (FRAMEWORK 5.1: output always drained, one owner reaps, no zombie). **R17 is not**: it stays with P3.5, and the spawner makes no assumption about it. D40.

### P2.10 Supervisor. Size L
- *(added after the Phase 0 audit)* Before `amphp/parallel` is relied on for CPU pools (FRAMEWORK 4.4), test whether its child processes die with the master. Until then that claim stays Unverified.
- Lifecycle states, restart with exponential backoff, `degraded` after K failures in T seconds, graceful shutdown (`bye` → `SIGTERM` → `SIGKILL`), worker pools with routing (round-robin and least-busy), recycling by request count or memory.
**Done when:** integration tests prove: `SIGKILL` of a worker → in-flight calls fail with `E_WORKER_CRASHED` and the worker restarts; repeated crashes → `degraded` and an app event; graceful shutdown order; pool routing; recycling drains in-flight work first.

**Result (2026-11-07):** passes, with real workers, real crashes and real signals. `Raphp\Supervisor\{Supervisor, WorkerDefinition, WorkerState, WorkerEvent, WorkerEventType, RestartPolicy, RecyclePolicy, Routing}` and the internal `Member`, `Pool` and `ProcessMemory`. Tested: SIGKILL of a worker fails every call in flight with `E_WORKER_CRASHED` and the worker restarts as a new process; restart waits double (50, 100, 200, 400 ms, then 400 ms); repeated crashes make it `degraded` with an event, no further start, and calls fail at once; crashes older than the window do not count; a call during a restart waits and succeeds, or fails with `E_WORKER_UNAVAILABLE` after the ready timeout; a worker that stops answering pings is killed and restarted; round-robin and least-busy routing; recycling after a call count (no call fails, no crash counted) and after memory growth, draining in-flight calls first; stop order: calls in flight finish, `bye`, SIGTERM, SIGKILL, with an event at each stage and a check that SIGTERM was really received. **The `amphp/parallel` check is done: its children do NOT die with the master** (they run under `sh -c` and survived a SIGKILL of the master), so Raphp builds CPU pools from supervised Raphp workers instead (FRAMEWORK 4.4). D41.

### P2.11 Worker runtime entry. Size S
- The `--role=worker` bootstrap: container, handler loading, loop, exit on EOF.
**Done when:** a worker exits promptly when the master dies, even without `prctl`: EOF-only, verified with two workers running (see P2.9 check 2). This relies on every Raphp descriptor being close-on-exec.

**Result (2026-11-07):** passes. `WorkerArguments` (a strict parse of the worker command line, with a message fit to print) and `WorkerRuntime` (guard, ignore SIGINT, no SIGTERM handler, channel, hello, serve, exit codes); the worker fixture used by every process and supervisor test now runs on the real runtime, so those tests exercise it. **Done-when check:** with two workers running and the death signal off, a master killed with SIGKILL ends both by end of input alone (`ProcessOrphanTest`). A real finding: descriptor 3 can be something other than a socket (PHP's own handle on the script file) in a process that no master started, so `InheritedChannel` now checks that it is a socket. FRAMEWORK 3.4, D42.

### P2.12 Headless example. Size S
- `examples/headless-worker`: a CLI app calling a worker with progress and cancellation (Ctrl-C cancels).
**Done when:** I run it live and show the output, including a cancellation and a deliberate worker crash with recovery.

**Result (2026-11-07):** passes, run live. `examples/headless-worker` (`main.php` with `demo`, `job` and `crash`; `worker.php`; `src/{Application, ConsoleEvents, ReportService}.php`; a README) uses only public framework pieces. Live output: a 10-step job with progress; a job cancelled half way (the worker's own log shows it stopped during step 11); a worker that kills itself with SIGKILL during a call (`E_WORKER_CRASHED`, `crashed ... killed by signal 9`, restart after 0.2 s, a new pid answers); a job on the new worker; ordered stop. A real Ctrl-C (SIGINT to the whole process group) cancelled the job, the worker ignored it and left on `bye`, and the master exited with 130 and no stray process. Running it found one bug of mine in the example (fixed). `HeadlessWorkerExampleTest` (4 tests, slow group) runs it for real; breaking the Ctrl-C wiring makes it fail. `make check`: 1,125 tests. No mutation run, as there is no new framework code.

### P2.13 Soak and performance baseline. Size S
- Script that runs 10k+ round trips and a large-payload test, recording RSS (from `/proc/self/statm`) and `memory_get_usage(true)` on both sides. Results go in `docs/BENCHMARKS.md`. This step covers the headless master only.
**Done when:** no memory growth beyond a stated tolerance, and numbers are recorded with the machine description.
**Open gap to close here (first part): R20.** The master cannot be recycled and holds the most long-lived state. This step's acceptance must record a baseline for the master and state the budget and growth tolerance that later steps are held to. The UI-event workload is measured in P4.8. *(added after the Phase 0 audit)*

**Result (2026-11-07):** passes; the record is `docs/BENCHMARKS.md`. `tools/bench/bench.php` (with `make bench`, a quick mode and a smoke test) runs a real master and worker through the supervisor. **Soak:** 50,000 sequential round trips (about 5,900 calls/s, p50 0.16 ms); after warm-up the master grew +144 KiB resident and +7 KiB `memory_get_usage()`, the worker +0 and +16 B, against a tolerance fixed beforehand of 4 MiB and 256 KiB. **Bursts:** six concurrency levels up to 1,000 in flight and payloads up to 8 MiB minus 4 KiB; repeating the bursts adds nothing (plateau check passes). **R19 numbers:** a small call behind a 7 MiB message waits about 150 ms. **R20:** the headless master's baseline (56 MiB resident, 15.5 MiB private) and a proposed tolerance are recorded (D43). **Three findings:** F48 (every queue bounds messages, not bytes; master memory grows linearly with large calls in flight, risk R26), F49 (a message needs about 6 times its size in PHP memory), F50 (the recycling threshold reads `VmRSS`, which counts shared pages). My unmeasured numbers were checked: none needed changing; the 64 KiB chunk was the fastest of five. `make check`: 1,126 tests. Speeds are from a loaded laptop and vary by about 30% between runs; memory numbers repeat.

**Checkpoint 2:** live demo of `examples/headless-worker`, test summary, `docs/BENCHMARKS.md`.

---

## 6. Phase 2B: Tier 1 standard library

**Goal:** the PHP-idiomatic services that application code uses instead of blocking built-ins.

### P2B.1 Filesystem service. Size M
`Raphp\Filesystem` interface and Amp-backed implementation: read, write, stat, directory listing, watch (if supported), all taking `?Cancellation`.
**Done when:** tests pass, and a "blocking call detector" test shows the loop keeps ticking during large reads.

**Decision (2026-11-07, yours):** an own cooperative driver, not `amphp/file`. This machine has neither ext-eio nor ext-uv, and `amphp/file` then falls back to child processes that survive their master (F46) and bypass the spawner (D18). Recorded as D44.

**Result (2026-11-07):** passes. `Raphp\Filesystem\{Filesystem, CooperativeFilesystem, CooperativeReadStream, FileStat, FileType, FilesystemException, FileNotFoundException}` and the internal `TimeSlice`: `read`, `write` (replace or append), `stat`, `list` (sorted), `openRead` (a byte stream, one chunk in memory), all with `?Cancellation`; chunks of 64 KiB, and the loop gets a turn after each 2 ms slice. Registered in the container (`Filesystem::class`). Watching is not provided (needs inotify, eio or uv). **Blocking-call detector** (`BlockingCallDetectorTest`): a 40 MiB file streamed with the longest gap between 1 ms ticks about 2 ms; the built-in `file_get_contents()` stalled the loop for 35 to 40 ms (the control proves the detector can see a stall). **Measured, not assumed** (`docs/BENCHMARKS.md`): the stall is about one slice, 2 ms costs no speed against larger slices, and `read()` of a large file is three to six times slower than the built-in and still stalls 50 to 90 ms at the end (one unavoidable copy), so it is documented as for small files and `openRead()` is the way for large ones (F52). **A flaky test led to a real bug (F51):** `SupervisorTest::testAWorkerThatStopsAnsweringPingsIsKilledAndRestarted` failed about one run in six under load; the cause was the heartbeat counting a worker's boot time as silence before its `hello` (fixed, with a test that failed before). 33 new tests for the filesystem, 1 for the container binding and 1 for the heartbeat; the suite is 1,161 tests (run in full twice). **Mutation testing:** filesystem 25 mutants, 24 killed (the survivor, a zero-byte-write guard, cannot be provoked on a plain file); heartbeat fix 3 mutants, 2 killed, 1 equivalent.

### P2B.2 Process runner. Size S
`Raphp\Process\ProcessRunner` over `amphp/process`: run a command, capture output, stream output, cancel. This is for application commands only. Raphp workers are never spawned through `amphp/process` (P2.9, D18).

**Result (2026-11-07):** passes. `Raphp\Process\{ProcessRunner, ProcessResult, OutputStream, CommandException, CommandTimeoutException, CommandOutputLimitException}` and the internal `ExecutableFinder` and `RunningCommand`; `amphp/process` 2.1.2 added. One method, `run()`: a command as a list (no shell), working directory, environment added to the inherited one, input, timeout, cancellation, capture and/or a streaming callback; plus `stopAll()` for shutdown hooks and `runningCount()`. **Measured before building (F53):** `amphp/process` signals only the command's own pid, so a command that starts children (`sh -c 'sleep 31; sleep 32'`) left them running as orphans after SIGTERM and after `kill()`. So each command runs under `setsid`, which executes in place (the pid is the group id, checked), and stopping signals the whole group: SIGTERM, a 2 s grace while the group empties, then SIGKILL. PHPStan now forbids `Process::start()` anywhere but `ProcessRunner` (the guard was shown to fire). **35 tests, real processes:** output and exit codes, 400 KB on both streams at once, input of 200 KB, no shell interpretation of tricky arguments, directory and environment, no sockets inherited, streaming while the command still runs, a callback that throws, output limits, a timeout that kills a command and both its background children (pids checked), SIGTERM before SIGKILL, a command that ignores SIGTERM, a background process that holds the pipes, `stopAll()` with two commands and their children, 20 commands at once, missing or non-executable programs. **Mutation testing:** 25 mutants, 24 killed; one real gap found and fixed (a non-executable file in `PATH`), one equivalent (the early cancellation check: `amphp/process` honours a cancelled token at start anyway). Suite: 1,196 tests (run in full twice). One mutation-harness baseline run failed once and I could not reproduce it in 11 further runs of the process tests; the cause is unknown. **Limits:** commands are in their own session (Ctrl-C does not reach them) and are not stopped when the master is killed with SIGKILL; `stopAll()` from a shutdown hook is the orderly way (D45).

### P2B.3 Streams helpers. Size S
Line reading, piping, progress streams over `amphp/pipeline` and byte streams.

**Result (2026-11-07):** passes, with less new code than the plan's wording might suggest, on purpose: `amphp/byte-stream` already has `pipe()`, `buffer()`, `split()` and `parseLineDelimitedJson()`, and `amphp/pipeline` has the pipeline operators, so Raphp adds only what they lack. `Raphp\Stream\{LineBuffer, LineReader, ProgressStream, LineTooLongException}`: **`LineBuffer`** cuts chunks into lines (the output callback of `ProcessRunner`, a socket's data event) with a limit on line length, one `\r` dropped before `\n`, bytes not interpreted; **`LineReader`** does the same for a readable stream and can return the lines as an `amphp/pipeline` pipeline (`map`, `filter`, `take`, `concurrent`); **`ProgressStream`** wraps a stream and reports bytes read and the total, throttled by time, with an exact final report, for a worker's `ProgressReporter`. Piping is `Amp\ByteStream\pipe()`: nothing to add. **Why not Amp's `splitLines()` (F54):** it has no line limit, strips every trailing `\r` (`rtrim`), and re-scans the whole buffer for each chunk: 2.9 s against 46 ms for 16 MB without a newline (`docs/BENCHMARKS.md`). **33 tests** (one of them cuts a random text at 200 random places and at every byte and compares with one reference; another checks memory stays bounded and a long line in 4 KiB chunks is not quadratic), a file read through `Filesystem::openRead()` into `LineReader`, and `ProgressStream` with Amp's `pipe()`. **Mutation testing:** 22 mutants, 22 killed after fixing two gaps found (a last line ending in `\r`, and the throttle restart). Suite 1,230 tests. Not added: a way to write a file in chunks (`Filesystem::openWrite`), which a file copy with progress would need; say if you want it.

### P2B.4 Optional accelerators. Size S
Detect `ext-uv` and `ext-eio` and select drivers automatically. Everything must work without them.

**Decision (2026-11-07, yours):** detect and verify the loop drivers (not an eio filesystem).

**Result (2026-11-07):** passes, verified with the real extensions. `Raphp\Runtime\{Accelerator, Accelerators}`: which of ext-uv, ext-ev, ext-event and ext-eio are loaded, which Revolt driver is running, which loaded ones are not used, and a one-line summary (for `rlm doctor` and logs). **Raphp selects nothing itself:** Revolt picks a driver on first use (uv, else ev, else event, else `stream_select`) or the one in `REVOLT_DRIVER`; a test checks that the real choice matches that order. **Verified, not assumed:** after you installed `libuv-devel` and `libev-devel`, I built ext-uv 0.3.1 and ext-ev 1.2.3 from pecl in a scratch folder (they compile and load on PHP 8.5; not installed system-wide) and ran the whole suite with each loaded in the master **and** the workers (`PHP_INI_SCAN_DIR`): the first ext-uv run had **2 failures of 1,238**, and after the two causes below were fixed, both drivers pass all 1,238 tests (ext-uv twice, ext-ev once), as does the default driver. **F55, a real driver difference:** under ext-uv a plain `pcntl_signal()` handler runs only when something else wakes the loop (measured 319 ms instead of 214 ms, and unbounded if nothing happens), because libuv restarts the interrupted wait; ext-ev and `stream_select` run it at once, and `EventLoop::onSignal()` works on all three. Raphp's own code is unaffected (`Kernel` uses `onSignal`; the worker uses `pcntl_signal(SIGINT, SIG_IGN)`, which the kernel handles); one test fixture used a plain handler and now uses `onSignal`. **The other failure** was my blocking-call detector's control comparing single samples: one 458 ms stall caused by something else on the machine decided it; it now takes three samples of each. **Not done:** ext-eio is detected but unused (no eio filesystem, as decided), ext-event was not tested, and no speed with an accelerator was measured, only correctness. 8 detector tests; **mutation testing 10 of 10 killed**. `CONTRIBUTING.md` says how to run the suite under another driver.

### P2B.5 `rlm doctor` checks for blocking calls (documentation only). Size S
A doc section listing blocking PHP functions and their Raphp replacements.

**Result (2026-11-07):** passes. `docs/BLOCKING_CALLS.md`: a table of 14 blocking PHP calls with the longest stall of the event loop **measured** for each (`tools/bench/blocking.php`: `usleep` blocks the whole 50 ms, `exec` 56 to 59 ms, `file_get_contents` of 32 MiB 63 to 124 ms, `glob` of 20,000 entries 225 ms, `password_hash` 500 to 690 ms, `sort` of a million numbers 900 ms), what to use instead, and **whether it exists today**; the replacements are measured too (`Amp\delay` 1 to 2 ms, `Filesystem::openRead` 2 to 7 ms, `ProcessRunner` 5 to 11 ms per later call but 25 to 54 ms the first time, `Filesystem::list` 27 to 33 ms). Honest gaps stated in the page: no chunked write (`copy()` has no replacement), no delete, rename or mkdir, network and DNS replacements are Phase 7 and 8. Two recipes, both checked: a ten-line stall detector, and a `phpstan-disallowed-calls` configuration (run against sample code: it flags `src/` and honours `allowIn`). Also checked while writing it: stdin through `getStdin()` and `LineReader` keeps the loop ticking, and starting a command stalls about 5 ms for a 55 or 260 MiB master and 18 ms for an 870 MiB one. **Nothing in the plan's "rlm doctor" itself is built:** it is documentation only, as planned.

**Checkpoint 2B:** tests green, short demo reading a large file while a timer keeps ticking.
**Checkpoint 2B status (2026-11-07):** `examples/large-file` (`php examples/large-file/main.php`, with `--blocking` for the comparison, tested by `LargeFileExampleTest`). Run live with a 512 MiB file: streaming with `Filesystem::openRead()` took 0.38 s, the 1 ms timer ticked 183 times and the longest gap was 14.0 ms (14 to 35 ms over three runs on this loaded laptop); the plain `fread()` loop took 0.35 s, the timer ticked 10 times and the longest gap was 346.8 ms. The streaming read costs about 10% in speed (1,360 against 1,477 MiB/s).

---

## 7. Phase 3: `libraphp` shim MVP (Rust)

**Goal:** a native window and widgets driven from PHP over FFI, with no C-to-PHP callbacks.
**Gate:** P0.1 (R1) passed, and P0.4 (R2) decided (option (a), GNOME first). Open gaps R14 to R17 are resolved in the steps named in section 1.

### P3.1 Shim workspace and ABI. Size M
- `shim/` Cargo workspace, `cbindgen` configuration, committed header, `raphp_abi_version`, `raphp_last_error`, `catch_unwind` wrapper for every `extern "C"` function.
- A `make` target that reports the highest `GLIBC_x.y` the built shim requires (`objdump -T`), and records it. *(added after the Phase 0 audit, R23)* Building the shim against an old glibc is D21 (**Decided**, approved 2026-11-06). The report records the figure for each build, and the P0.5 figures (2.34 headless, 2.43 windowed) are the baseline for a build on the host glibc.
**Done when:** `make header` produces no diff; a Rust test proves a panic inside a command becomes an error and leaves the shim usable or cleanly poisoned; the shim's glibc requirement is printed by the `make` target.
**Open gap to close here: R14.** A panic or exit on the shim's own UI thread is not covered by the rule above. This step's acceptance must include a written contract for what happens in that case, and a test. The place of the shim's exit in the master's shutdown order is checked again at P4.5. *(added after the Phase 0 audit)*

**Result (2026-11-08):** passes. `shim/` is now a Cargo workspace (`raphp`, which builds `libraphp.so`, and `xtask`, which generates the header; edition 2024, Rust 1.95, no dependencies in the shim itself). **The ABI so far** (`docs/protocol/shim-abi.md`, version 0.1): `raphp_abi_version`, `raphp_init`, `raphp_send`, `raphp_last_error`, `raphp_shutdown`; `raphp_send` and `raphp_shutdown` return a `RaphpStatus` (the draft had `int` and `void`); the event functions are P3.2. Every exported function runs under `catch_unwind`, and a test reads the source and fails if one does not. **A panic inside a command** (done-when): through the real FFI from PHP it comes back as status -3 and the PHP process carries on; the shim is then permanently poisoned, later calls return -2, the first cause stays readable, shutdown still works. **`make header`** writes `shim/include/raphp.h` and `make rust` fails if the committed file differs; the generated C needed a filter (an include guard and a C23 `#if` branch that `FFI::cdef` cannot read), which has its own tests, and a PHP test checks the committed header has no preprocessor line and loads. **`make shim`** builds the release library and prints the glibc requirement: **GLIBC_2.34** (host glibc 2.43), the same as the Phase 0 headless figure; the older-glibc build mechanism (D21) is not chosen yet. **R14, closed as a written contract with tests:** no `panic = abort` anywhere (a compile-time check and a manifest check), the UI thread's body under `catch_unwind` (a panic, or the thread ending by itself, poisons the shim), shutdown waits a bounded 3 s and detaches a stuck thread, a fatal signal or `exit()` in native code cannot be contained in-process (said plainly). The master's reaction to a poisoned shim and the shutdown order (the shim's hook first) are D47, proposed. **Tests:** 23 Rust tests (panics from several threads at once, UI thread panic, UI thread that ends by itself, a UI thread that ignores the stop request, error text cut on a character boundary at every size, and more) plus 4 for the header filter, and 7 PHP tests through the real FFI. **Mutation testing of the Rust code** with a new tool, `tools/mutation/mutate_rust.py`: 22 mutants, all killed after four missing tests were added (a length above `isize::MAX`, a null output buffer with a capacity, white space around a batch, a formatted panic message). `phpstan.neon` lets only the shim-facing test directory use the dynamic `raphp_*` functions. **Needs your approval:** D47. **Downloaded:** cbindgen 0.29 and its dependencies, as a dependency of `xtask` inside the workspace (nothing installed globally).

### P3.2 Event queue, eventfd, poll, coalescing. Size M
- Promote the spike 1 stage A design, add the coalescing framework (pointer-move, scroll, resize: latest wins per window per turn) and the O7 overflow policy.
**Done when:** Rust stress tests prove no loss of discrete events under concurrent producers and a slow consumer, and coalescing keeps only the latest continuous event.
**Open gap to close here: R16.** An event larger than the poll buffer can stall all UI input. This step's acceptance must include a written contract for that case and a test that a large event does not block later events. *(added after the Phase 0 audit)*

**Result (2026-11-08):** passes. `shim/raphp/src/events.rs` promotes the spike 1 design: an in-memory queue of one-line JSON events with an `eventfd` as the wake-up only. **Four new ABI functions** (version 0.2): `raphp_event_fd`, `raphp_poll`, `raphp_next_event_size`, `raphp_max_event_bytes`, and a new status `BUFFER_TOO_SMALL` (-6). **Coalescing:** continuous events (pointer move, scroll, resize) carry state and the latest replaces an earlier queued one with the same window and kind, unless a discrete event was queued in between, so a discrete event is never overtaken. **Overflow (O7):** past 1 MiB queued an older continuous event is removed even across a discrete one and superseded ones are swept; past 4 MiB the producer waits for PHP; shutdown and a failure wake any waiting producer; nothing discrete is ever dropped (the limits are my numbers). **R16, the written contract:** a single event is at most 1 MiB (larger ones become a small `event.too_large` event); `raphp_poll` never skips or splits, and a too-small buffer leaves the event at the head, raises the wake-up again and `raphp_next_event_size` gives the size; PHP grows its buffer and continues. **R14 item 3 is built:** a poisoned shim queues one last `shim.failed` event and wakes the descriptor. **Stress test** (the done-when): 8 concurrent producers (32,000 discrete events of about 1.8 KB and as many continuous ones) against a slow consumer using the real descriptor lost no discrete event, delivered each producer's events once and in order, delivered the latest continuous value of every key and never one out of order, was held back by back-pressure (checked: it waited), and never exceeded the 4 MiB limit. **Tests:** 52 Rust tests in all (29 new for the queue) and 4 more PHP tests through the real FFI (a wake-up with `stream_select`, a 100 KB event with a 1 KB buffer, the failure event, the limit); the descriptor is close-on-exec (checked). **Mutation testing:** 26 mutants, all killed after one test gap was found and fixed (`push_or_report`'s result). **Clippy** then found six collapsible `if let` chains in my code; fixed mechanically, 52 tests unchanged. **Needs your approval:** D48 (the queue policy and its numbers). `FRAMEWORK.md` 7.3 and 7.5 and the R16 row are updated.

### P3.3 Command model and parser. Size M
- Widget arena with PHP-assigned ids; command schema (`widget.create|set|append|insert|remove|destroy`, `window.*`, `app.quit`); validation errors become `error` events.
- Command schema documented in `docs/protocol/shim-commands.md` with golden files.
- A read-only diagnostic call that returns per-window counters (redraws requested, frames drawn), also documented in `shim-commands.md`. P3.6 and P3.10 use it to test redraw pacing (F8). The counters are filled in once drawing exists. *(added after the Phase 0 audit)*
**Done when:** golden-file tests pass; invalid ids, cycles and unknown props produce errors without crashing.
**Open gap to close here: R15.** There is no ordering contract between the command queue and the event queue (events for removed widgets, command errors that cannot be tied to their batch, re-renders racing typed text). This step's acceptance must include a written contract and tests. P4.2, P4.3 and P4.6 test against it. *(added after the Phase 0 audit)*

**Result (2026-11-08):** passes. `shim/raphp/src/{protocol,model,diagnostics}.rs`: how a batch is read (an array, or `{"batch": id, "commands": [...]}`), the widget arena with PHP-assigned ids, the commands (`window.create|set|set_root|destroy`, `widget.create|set|append|insert|remove|destroy`, `app.quit`), the registry of widget types and properties (label, button, progress bar, column, row, scroll view, checkbox, text input), and the UI thread's command loop. Commands are strict (an unknown field is an error) and a failure changes nothing and does not stop the batch; each is reported as an `error` event naming the batch and the command's index, and an object batch ends with `batch.done`. **ABI 0.3:** `raphp_diagnostics` (the read-only counters, with per-window `redraws_requested` and `frames_drawn` at 0 until drawing exists) and the status `CLOSED`. **Golden files:** `docs/protocol/golden/shim-commands.json`, eight cases sent through the real C functions and compared event by event (empty batch, a small tree, errors one by one, ids that never go back, structure rules, destroying, an anonymous batch, `app.quit`). **R15, the written contract** (`docs/protocol/shim-commands.md`), each rule tested: commands in order; events in order; `batch.done` marks when a batch took effect (a click queued before a destroy arrives before its done event); no event about a destroyed widget is queued afterwards; ids never reused, so a stale event cannot name a newer widget; every error tied to its batch; a text set can carry `base_rev` and is refused (`stale_text`) if the user has typed since (the revision logic is built and tested now with a stand-in for typing; the text events come with P3.11). **A race found by testing:** the model emitted `batch.done` before it updated the counters, so reading the diagnostics right after the done event could show old numbers (the golden test passed by luck); it now updates them first, and a test reads them after each of 300 done events. **Containment:** a bug in the model (`test.panic`, a command that exists only in test builds) poisons the shim through the UI thread's guard and PHP receives `shim.failed`. **Tests:** 98 Rust tests in all (46 new); 3 more PHP tests through the real FFI (a batch with an error and its done event, the diagnostics, `app.quit` then `CLOSED`). **Mutation testing:** 43 mutants on the protocol, model, diagnostics and loop, all killed after fixing one gap that I created myself (I had dropped two tests when rewriting the file; one of them had a wrong expectation, which the code showed). **Needs your approval:** D49 (the command protocol and ordering contract; the limits and the strictness are my choices). **Not done:** drawing, layout, the real window (P3.4 onward).

### P3.4 Headless backend. Size M
- A backend with no window system: same loop, deterministic clock, software raster to an in-memory buffer.
**Done when:** the PHP integration tests in P3.5 run in an environment with no display.

**Result (2026-11-08):** passes. The same UI loop, model and commands now run on a backend with no window system, chosen by the new `raphp_init` configuration (strict JSON: `backend`, `refresh_hz`, `scale`, `surface_budget_mib`; `"window"` is refused until P3.6). New modules `backend.rs` (a `Backend` trait the loop owns, and `HeadlessBackend`), `clock.rs` (a real clock for P3.6 and a **manual clock that moves only through `raphp_headless_advance`**), `surface.rs` (an RGBA picture, blank white until P3.8 draws) and `config.rs`. **ABI 0.4:** `raphp_headless_advance` (moves the clock and waits until the UI thread has applied everything sent before and drawn the frames then due; with 0 it is a barrier, and it is the one call that waits for the UI thread, giving up after 10 s) and `raphp_headless_frame` (copies a window's picture and reports its size). **Pictures** are the window's size times `scale`, rounded up, in a memory budget (default 256 MiB): a `window.create` or resize that would exceed it is a `limit` command error that changes nothing. **Frame pacing (F8):** a batch asks for a frame only for a real change (setting a property to the value it already has, an empty batch, a refused command, a widget on no window and a title change all ask for nothing); a window gets one frame per interval at the most, counted from its previous frame, the first at once, each window at its own pace; a destroyed window draws no more. **Tested through the real C functions and PHP's FFI** with the manual clock, so the pace is exact and nothing sleeps (e.g. 9 ms after a frame: still waiting; 10 ms: drawn). **Done-when:** the PHP integration tests of P3.5 can run with no display. **Found by testing:** the redraw counter was updated after `batch.done` (the F57 race again): it is now updated before, and a test reads it after 60 done events; and `Surface::bytes_for` could overflow for absurd sizes (unreachable through the protocol, now saturating). **Tests:** 132 Rust tests in all (34 new for the backend, the loop and the configuration) and 2 more PHP FFI tests. **Mutation testing:** 32 mutants, 31 killed; the survivor is equivalent (the lower clamp of a picture size is redundant because the protocol refuses sizes of zero or less, kept as defence in depth). **Needs your approval:** D50 (headless as the default backend until P3.6, the manual clock and barrier, the budget, the pacing rules; the 60 Hz, 256 MiB and 10 s are my numbers). `FRAMEWORK.md` 7.8 and `docs/protocol/shim-abi.md` are updated.

### P3.5 PHP FFI binding. Size M
- `Raphp\Ui\Ffi\Loader` (header loading, ABI check, clear diagnostics when `ffi.enable` is wrong), `Shim` class (send batch, watch eventfd under Revolt, drain events). Use the instance form `$ffi->new(...)`, never the static `FFI::new()` (deprecated in PHP 8.5, D20). `ffi.enable=preload`, the default, already permits `FFI::cdef` in CLI and micro, so the loader does not require a changed ini.
**Done when:** PHP integration tests drive the headless shim and receive events; the "wrong ABI version" and "FFI disabled" errors are clear; the tests run with `error_reporting = E_ALL` and raise no deprecation.
**Open gap to close here: R17.** `proc_open` forks while a live multi-threaded shim exists in the master, which no spike has tested. This step is the first where PHP drives a live shim, so its acceptance must include a test that spawns a worker while the shim is running (headless first, repeated with a real window in P3.6), a written contract for what the worker may inherit, and a check of the worker's descriptor list. *(added after the Phase 0 audit)*

### P3.6 Window backend. Size L
- winit window per P0.1: create, title, size, close, focus, scale factor, resize events; Wayland and X11.
**Done when:** a manual checklist passes on your session, and screenshots plus event traces are attached. *(added after the Phase 0 audit:)*
- On GNOME/Wayland the winit client-side title bar allows move, resize, maximize and close, and a screenshot records its look. Its look is accepted (D31, from the chrome experiment); the screenshot is recorded as evidence only, not as a review gate.
- Redraw pacing (F8), with static content and no animation or caret blink: an idle window (no commands, no input) draws zero frames over 5 seconds after its initial frame, read from the P3.3 redraw counter, and its CPU use is recorded as a measured figure, not a threshold. Separately, minimize or hide the window and record whether the counter advances, per compositor as observed. This is a recorded result, not a pass or fail condition, because occlusion reporting is compositor-dependent and unverified. The idle-window zero-frames check above is the hard criterion.
- The P3.5 worker-spawn test (R17) is repeated with the real window open.

### P3.7 Layout. Size M
- Taffy integration: flex subset, units, HiDPI.
**Done when:** layout tests (headless) match expected rectangles for a table of cases.

### P3.8 Text and drawing. Size L
- CPU rasterization, rectangles, clipping, text via cosmic-text and fontconfig, scrolling.
**Done when:** golden-image tests in the headless backend pass, with tolerance rules documented. A headless test asserts that a redraw is requested only when a widget is marked dirty, and that a no-op command batch causes no draw call (F8). *(added after the Phase 0 audit)*

### P3.9 Input and focus. Size M
- Pointer, keyboard, scroll events; hit testing; focus management; coalescing applied.
**Done when:** simulated-input tests in the headless backend pass. A manual check on your session confirms that real keyboard and click events arrive, because Phase 0 verified pointer moves only. *(added after the Phase 0 audit)*

### P3.10 Core widgets. Size L
- Label, Button, ProgressBar, Column/Row, ScrollView, Checkbox, with hover and pressed states and shim-side animation.
- Overlay layer *(added after the Phase 0 audit, F6)*: drawn above content, hit-tested first, dismissed on outside click or Escape, clipped to the window. It uses layout (P3.7), clipping (P3.8) and hit testing and focus (P3.9). winit 0.30.13 has no `xdg_popup` support, so menus and context menus (P3.12a) are drawn on this layer.
**Done when:** a widget gallery renders correctly in headless golden tests and looks right on screen, and the overlay layer passes headless tests for z-order, hit-test priority, dismissal and clipping. A continuous shim-side animation on screen redraws no faster than the monitor refresh rate and uses no PHP calls per frame (record the measured redraw rate, the refresh rate and the CPU use). *(added after the Phase 0 audit)*

### P3.11 Text input. Size L (split when reached)
- **P3.11a** spike: IME and text-input on Wayland through winit (R8). Output: go or limits.
- **P3.11b** editing, selection, caret, clipboard. The clipboard goes through `smithay-clipboard` on winit's Wayland connection (`arboard` does not work on GNOME, because no data-control protocol is offered, F7). The X11 clipboard route is a decision to take here, because Phase 0 tested Wayland only. Clipboard is built here and is not part of P3.12. *(restructured after the Phase 0 audit: ordering conflict between P3.11b and P3.12)*
- **P3.11c** IME composition, key repeat.
**Done when:** typing, selection, copy and paste work; IME behavior is documented with its known limits.

### P3.12 Native chrome, split into three steps *(restructured after the Phase 0 audit: ordering conflict between P3.11b and P3.12)*
Per the P0.4 decision (option (a), GNOME first). Clipboard is in P3.11b. Sizes are my relative estimates for the split.

#### P3.12a Menus. Size M
- In-window menu bar and context menu on the P3.10 overlay layer.
**Done when:** it works on your GNOME/Wayland session, and the limits are listed (no popup can extend beyond the window).

#### P3.12b File dialogs. Size M
- FileChooser portal dialogs. They send a result or cancel event and do not block the UI thread.
**Done when:** open and save dialogs work on your GNOME session. Record how the portal parent window is handled, which is unverified in Phase 0.

#### P3.12c Tray. Size S
- Optional feature. It reports "unavailable" when no StatusNotifierWatcher exists, and does not fail.
**Done when:** it works with the AppIndicator extension enabled (you enable it, because it is installed but disabled here) and reports "unavailable" with it disabled. Both cases are recorded.

### P3.13 Canvas widget. Size M
- Draw-list operations for custom-drawn content.
**Done when:** a sample chart renders in headless golden tests.

**Checkpoint 3:** demo of a window with widgets driven directly from PHP (no framework layer yet), screenshots, frame timings, idle CPU, redraw counter readings, animation redraw rate, and the FFI-calls-per-frame measurement.

---

## 8. Phase 4: UI framework (PHP)

**Goal:** the declarative PHP API from FRAMEWORK section 8, wired to workers.

### P4.1 Widget value objects and screens. Size M
Immutable widgets with stable ids, roles and labels (accessibility readiness), `Screen` and `ScreenState`.

### P4.2 Virtual tree and diff. Size L
Diff to a minimal command batch, child keys, ordering rules (as defined by the P3.3 resolution of R15).
**Done when:** pure PHP golden tests cover insert, remove, reorder, prop change, no-op, and the R15 scenario of a late event for a removed widget. This is the first of three R15 golden tests. The bad-id batch test is in P4.3 and the double-click-while-suspended test is in P4.4.

### P4.3 Command batcher. Size S
One batch per loop tick, with size limits and ordering guarantees (as defined by the P3.3 resolution of R15).
**Done when:** a bad-id batch produces an error that can be tied to its batch, if that is what the P3.3 contract specifies. This is the second of the three R15 golden tests (the others are in P4.2 and P4.4). *(added after the Phase 0 audit)*

### P4.4 Event dispatch. Size M
Shim events to widget handlers run in Fibers, an error boundary, PSR-14 integration, and a UI middleware pipeline.
**Done when:** a throwing handler is contained, logged, and does not kill the UI. A golden test also covers a second click (double-click) or other event on a widget whose handler is still suspended in its Fiber. It is handled according to the P3.3 / R15 contract and the handler-concurrency policy, which is written there and not chosen in this plan. This is the third of the three R15 golden tests. *(added after the Phase 0 audit)*

### P4.5 Application kernel. Size M
`Application` wiring master, shim, supervisor and lifecycle (start, run, close, shutdown).
**Done when:** the shutdown order, including where the shim's exit happens relative to worker shutdown, matches the contract written for R14 in P3.1, and is tested. *(added after the Phase 0 audit)*

### P4.6 State and worker integration. Size M
`WorkerClient` with progress callbacks, state updates, debounced text commits (FRAMEWORK 8.3).
**Done when:** a re-render triggered by a worker progress event does not overwrite text the user typed after the last commit. This is the R15 scenario, tested against the P3.3 contract. *(added after the Phase 0 audit)*

### P4.7 Theming. Size M
Theme tokens, light and dark following the system setting, fonts.

### P4.8 `examples/hello-gui`. Size M
The file-scanner screen from FRAMEWORK 8.2.
**Done when (live):** the UI stays responsive during a long worker task; progress updates; cancel works; killing the worker shows an error and the worker restarts while the window stays open.
**Also (R20):** a soak with a UI-event workload (widget create and destroy, a long progress stream) on the headless shim backend, recording RSS and `memory_get_usage(true)` of the master against the budget set in P2.13. *(added after the Phase 0 audit)*

### P4.9 UI guide draft. Size S
`docs/guide/ui.md` written against the working example.

**Checkpoint 4:** live demo of `hello-gui`, including the worker-crash scenario.

---

## 9. Phase 5: `rlm`

**Goal:** one CLI for project setup, dependencies, running, and (later) building.

### P5.1 CLI skeleton. Size M
Symfony Console, project discovery (`raphp.json`), consistent output and exit codes.

### P5.2 `rlm init`. Size S
Templates for a headless app and a GUI app.

### P5.3 `rlm doctor`. Size M
Checks: `ffi` and `ffi.enable`, required extensions (including `ext-sockets`), shim presence and ABI, display session, glibc version (compared against the declared minimum, a configurable placeholder until P6.8 wires in the final value and re-runs this check), PHP version.
**Done when:** each check has a failing and a passing test, and the messages say how to fix the problem.

### P5.4 `rlm add|remove|install|update`. Size M
Wrap Composer (decide: invoke the `composer` binary versus embed `composer.phar`; recommend the binary first).

### P5.5 Native lib store and lock file. Size L
`~/.local/share/rlm/libs/...`, `rlm.lock` with versions and sha256, `rlm lib list|pin|verify`. Source for downloads decided with you at this step.

### P5.6 `rlm run` and `rlm dev`. Size L
Run an app or script; `dev` watches files and restarts workers. The file watcher may use Amp file watching, or polling if no inotify binding is available.
**Done when:** editing a worker file restarts the worker while the window stays open.
**Open gap to close here: R21.** Hot reload covers worker code only, while most edits land in master code (views and handlers run in the master). This step's acceptance must include a written statement of what survives a reload for each kind of edit, and a decision on how a reload restart is counted by the supervisor. Neither is designed yet. *(added after the Phase 0 audit)*

### P5.7 Managed PHP provisioning (O3). Size M
Interface now, full implementation after Phase 6 produces the builds.

**Checkpoint 5:** `rlm init` → `rlm dev` → edit → hot reload demo.

---

## 10. Phase 6: Standalone packaging

**Goal:** `hello-gui` ships as a single binary that runs with no PHP installed.
**Gate:** P0.5 and P0.6 passed (both have, with the R23 condition on P0.5).

### P6.1 Reproducible PHP build recipe. Size M
`build/php/` scripts, extension list, glibc target. Pin the `spc` version. Measured in P0.5: `spc doctor` installs Zig 0.17.1 and `pkg-config` into its own work directory, and `spc doctor --no-interaction` may attempt `sudo` installs on its own, so ask before the first run (rule 6). A full build takes well over 30 minutes of compute (libffi 7.3 min, openssl 9.8 min, 616 objects at about 34 per minute). The PHP configure time is unknown. The recipe documents how a rebuild reuses the cache. *(added after the Phase 0 audit)*
Also define how the shim `.so` is built for the same glibc floor as PHP (R23, D21). Candidates are a Zig-based linker such as `cargo-zigbuild` with a `.2.17` target, or a build container with an old glibc. This is a recipe to choose and verify here. No spike has checked it, and D21 is approved (2026-11-06) but the recipe is not verified. *(added after the Phase 0 audit)*
**Done when:** the recipe builds `php`, `micro.sfx` and the shim, and `objdump -T` on each shows the highest `GLIBC_x.y` it requires.

### P6.2 Phar packer. Size M
Use a standard phar tool (Box is the common choice, confirmed here). Dependencies installed with `--no-dev`.

### P6.3 Micro assembly and baked ini. Size M
Binary = `micro.sfx` + phar, with ini defaults baked in (via `spc micro:combine -I`). `ffi.enable=1` works but is not needed: the default `preload` already allows `FFI::cdef` in micro (D20). Bake it only as defensive hardening.

### P6.4 Role dispatch in the built binary. Size S
Master and worker roles from the same executable, per P0.6. The executable path comes from `readlink('/proc/self/exe')`, because `PHP_BINARY` is empty in micro (D19).

### P6.5 Shim embedding. Size M
`memfd_create` loading per P0.7, with a sidecar fallback and checksum verification.
**Done when:** the windowed shim loads through `memfd` inside a micro binary and opens a window. P0.7 verified only the headless shim there. *(added after the Phase 0 audit)*

### P6.6 `rlm build`. Size M
End-to-end command and output layouts (single file or bundle directory).

### P6.7 Clean-container test. Size M
Run the built binary in a minimal image with `podman` (no PHP, no project files), headless mode, plus a manual GUI run on your host.
**Done when:** `ldd` is clean of missing libraries; master and worker both start; the file-scan example completes. *(added after the Phase 0 audit:)* The test image is glibc-based (not Alpine or musl). The binary and the shim also run on the oldest-glibc image that is practical to obtain (which image is practical to obtain is decided here), and the image and its glibc version are recorded. `ldd` and `objdump -T` of the binary and the shim show no missing libraries and no symbol version above the target.

### P6.8 Support matrix and minimum glibc (O5, R23). Size S
The declared minimum glibc is the higher of the PHP floor and the shim floor, based on the P6.7 results, and the images actually tested are stated. Documented in FRAMEWORK 11 and 7.9, and compared against the host glibc by `rlm doctor` (P5.3). *(corrected after the Phase 0 audit)*

**Checkpoint 6:** you run the single binary yourself.

---

## 11. Phases 7 and 8 (outline, detailed at Checkpoint 6)

### Phase 7: Runtime tier 2
- **P7.1** Network services (TCP, UDP, Unix sockets).
- **P7.2** HTTP client.
- **P7.3** HTTP server with Amp-native middleware (O13).
- **P7.4** WebSocket.
- **P7.5** `examples/http-server`.
- **P7.6** PSR-15 bridge decision (build only if there is demand).
- **Checkpoint 7.**

### Phase 8: Tier 3 and hardening
- **P8.1** TLS, crypto, zlib, DNS, os/path/url utilities.
- **P8.2** Permissions model design.
- **P8.3** Shared-memory lane for large payloads.
- **P8.4** Security review of IPC, FFI surface and `rlm` downloads. Includes the secondary path-socket authentication, where `SO_PEERCRED` is not exposed by PHP's sockets extension here (R22, open).
- **P8.5** Documentation set and API reference.
- **P8.6** Release process (semantic versioning, changelog, checksums), first tagged release.
- **P8.7** Accessibility design (AccessKit) and Windows/macOS design notes (documents, not code).
- **Checkpoint 8: v0.1.**

---

## 12. Parked ideas (not in scope until you pull them in)
- Windows named pipes and macOS host design.
- GPU rendering (Vello/wgpu).
- Binary encoding for shim commands (only if profiling demands it).
- Worker sandboxing (Landlock/seccomp).
- Auto-update for built apps.
- ReactPHP promise interop.
- A Raphp package registry.

---

## 13. How a step is reported

For every step, my report will contain:
1. What changed (files).
2. The exact acceptance command(s) and their real output.
3. Anything that deviated from the plan or the framework doc, and the doc change that fixes it.
4. What the next step is, and whether I need anything from you first.
