# Phase 0 Spike Report

**Date:** 2026-11-06
**Scope:** plan steps P0.0 to P0.7. The design-doc corrections that follow from this report are step P0.8.
**Evidence rule:** every number here came from code I ran on this machine. Where a number is noisy, the range is given. Where something was *not* tested, it says so.

## 1. Environment

| Item | Value |
|---|---|
| OS / desktop | Fedora 44, GNOME 50.5 (Classic) on Wayland, XWayland available |
| CPU | 4 cores (load was often above 4 during these runs: the static build and the audit workflow ran at the same time) |
| PHP (system) | 8.5.12 NTS, `php-ffi` installed, `display_errors` off, `error_reporting` excludes `E_DEPRECATED` |
| Rust / Composer | 1.95.0 / 2.10.3 |
| Revolt / Amp | `revolt/event-loop` 1.0.9, `amphp/amp` 3.1.3 |
| glibc (host) | 2.43 |
| Timing caveat | The PC was hibernated overnight during the static build, so log timestamps contain a fake gap of several hours. Build times below that span the hibernation are marked unknown. |

All spike code is throwaway and lives under `spikes/`.

## 2. Verdicts

| Item | Question | Verdict | Condition or caveat |
|---|---|---|---|
| **Design D6** | Can events cross from a native thread to PHP without loss and without C-to-PHP callbacks? | **Pass** | In-memory queue plus `eventfd`, never a pipe as the buffer |
| **R1** | Does a winit loop work off the main thread? | **Pass** on Wayland and X11 | Clicks and key presses untested. Redraw numbers are not a rendering benchmark |
| **O1 / D10** | Does a socket pair handed to `proc_open` work as the IPC channel? | **Pass, with a mandatory condition** | Must use `SOCK_CLOEXEC` (needs `ext-sockets`) |
| **Orphans** | Can a worker die with its master? | **Pass** | `prctl(PDEATHSIG)` plus a `getppid()` race guard |
| **R2** | Native chrome on GNOME/Wayland | **Decided by you: option (a), GNOME first** | File dialog verified end to end (unparented). Tray, menus, and how things look not yet checked. Deferred to P3.12 |
| **R3** | glibc PHP with FFI, single-file binary | **Pass, with a condition** | The shim's glibc floor (2.34 / 2.43) is higher than PHP's (2.17) |
| **R4** | Self re-exec inside a micro binary | **Pass** | `PHP_BINARY` is empty. Use `/proc/self/exe` |
| **Embedded shim** | Load the shim from inside the phar | **Pass** (headless shim) | Windowed shim through `memfd` inside micro not tested |

## 3. Spike results

### P0.0 Event queue, `eventfd`, FFI cost (headless shim, system PHP)

Rust `cdylib` with a UI thread. PHP sends JSON command batches through FFI. Events come back through an in-memory queue and an `eventfd` that Revolt watches.

| Measurement | Result |
|---|---|
| Round trip ping to pong, 5000 sequential | p50 **14 to 56 µs** over four runs, p99 37 µs to 1.7 ms (noisy: cross-thread wake-ups) |
| 20,000 events while PHP is blocked 300 ms | **Pipe-as-buffer design: 2,416 received, 17,584 dropped (88%).** Queue plus `eventfd`: **20,000 received, 0 lost**, peak queue 540,000 bytes |
| FFI call cost (`abs()`, no syscall) | about **125 to 240 ns** |
| `niphy_send("[]")` | about 1.2 to 1.8 µs (includes allocation, channel send, waking the UI thread) |
| `json_encode` of a 200-command batch | about 36 to 71 µs |
| UI-originated events waking Revolt (20 ms ticks) | 49 to 50 ticks per second, max gap 20.2 to 21.7 ms |

Two of my own measurement errors were found and corrected along the way: a "0 dropped" result that was a race with the reader, and a "1.2 µs FFI call" that was actually a `getpid()` syscall.

### P0.1 Real window on a non-main thread (R1)

winit 0.30 with `with_any_thread(true)` inside the shim, softbuffer CPU fill.

| Measurement | Wayland | X11 (XWayland) |
|---|---|---|
| Window created on the shim thread | 98 to 141 ms | 3 ms |
| 1000 round trips through the winit loop | p50 65 to 103 µs | p50 114 µs |
| Real redraws while PHP blocked 700 ms | 347 in 511 ms, later 62 in 500 ms under load | 128 in 501 ms |
| Shutdown | 1 to 8 ms | 1.6 ms |

- **You confirmed a window appeared** and closed when each run ended (about 4 s and 14 s).
- A 14-second hold delivered **38 real pointer-move events with coordinates inside the window**, with coalescing visible (`coalesced_from` 1 to 2).
- **Not tested:** clicks, key presses. The X11 backend is inferred from `WAYLAND_DISPLAY` being unset plus the faster creation time.
- **Redraw counts are unthrottled CPU fills that burn a core.** They prove the UI thread runs independently of PHP, not rendering performance.
- The windowed shim also ran under the new glibc PHP build (window created in 141 ms).

### P0.2 Socket pair through `proc_open` (O1)

| Check | Result |
|---|---|
| Child opens the inherited socket as `php://fd/3` | works, 2000 NDJSON round trips, p50 about 106 to 265 µs |
| Parent closes its end, child must see EOF | **only with `SOCK_CLOEXEC`**: child exited 12 to 37 ms later, code 0 |
| Child exits, parent must see EOF | yes |
| 16 MiB written to a child that does not read for 1 s | write returned at once with 16.5 MB queued, 36 to 73 `EAGAIN` stalls, **all 16,777,217 bytes arrived**, loop kept ticking (max gap about 19 ms) |

**The bug that mattered.** With `stream_socket_pair` the child also inherited the *parent's* end (fd 7 in the child), so EOF never came and `proc_close()` hung. `fdcheck.php` shows it directly. `socket_create_pair(AF_UNIX, SOCK_STREAM | SOCK_CLOEXEC, ...)` plus `socket_export_stream` fixes it. The parent also leaks its own script file descriptor (fd 4) into the child, which is harmless for EOF. **Only one worker was tested**, so cross-worker leakage is a rule derived from the mechanism, not a measured result.

A second harness bug: closing a stream while Revolt still watched it crashed `stream_select`. Watchers must be cancelled first.

### P0.3 Parent-death signal

| Case | Result |
|---|---|
| Control: no `prctl`, master SIGKILLed | child **still alive after 3 s** (orphaned) |
| `prctl(PR_SET_PDEATHSIG, SIGTERM)` | child gone **5 to 9 ms** after master SIGKILL |
| Parent dies before `prctl` runs (race) | child exited itself via the `getppid()` guard |

`proc_open` must receive an array command. A string makes `/bin/sh` the parent, which defeats the signal (derived from how `PDEATHSIG` works, not separately tested). The worker's SIGTERM handling must actually terminate it.

### P0.4 Native chrome on GNOME 50.5 / Wayland (R2)

| Capability | Finding |
|---|---|
| File dialogs | `org.freedesktop.portal.FileChooser` available (portal 1.22.1, gnome and gtk backends). **Exercised end to end** by the chrome experiment (below) |
| Clipboard | `wl_data_device_manager` offered, **no `data-control`**, so `arboard` Wayland would not work. Use `smithay-clipboard` (0.7.3) |
| IME | `zwp_text_input_manager_v3` offered (to be tested in P3.11a) |
| Decorations | **No server-side decorations offered**. winit 0.30.13 draws client-side ones (`wayland-csd-adwaita`) |
| Popup menus | **winit 0.30.13 has no `xdg_popup` support**. Menus are drawn inside the window |
| Tray | **No `StatusNotifierWatcher`**. The AppIndicator extension is installed but disabled. `ksni` 0.3.7 is a candidate crate |

**Your decision:** option (a), GNOME first, improve later if needed.

**Chrome experiment** (`spikes/01-shim-roundtrip/chrome.php`, run by you, output pasted):

| Check | Result |
|---|---|
| Window created, `resized` event (480x320) | arrived in PHP at 0.1 s |
| Real portal file dialog requested from PHP (`open_file` command, `rfd` 0.17.2, helper thread, **not attached to the window**) | opened; you picked `/mnt/C62A254E2A253CB5/cart_tmp.txt`; the path arrived back in PHP as a `dialog_result` event at 12.5 s (8.5 s after the request, mostly you choosing) |
| Title bar close button | produced a `close_requested` event in PHP at 27.1 s |
| Pointer moves seen | 69 |
| No `click` or `key` events | nobody clicked inside the window or typed (clicks on the title bar are handled by winit's decorations, not delivered as clicks) |

**Your impression (2026-11-06):** you like how the window looks: rounded corners and shadows. **Not shown by this run:** how the dialog looks, whether the dialog appeared over the window, and whether the window stayed responsive while the dialog was open (not measured). The dialog's file path is a plain host path, which suggests an unsandboxed app.

### P0.5 glibc PHP with FFI (R3)

`static-php-cli` 2.8.6, `SPC_TARGET=native-native-gnu.2.17`, PHP 8.5.12, extensions `ffi, pcntl, posix, sockets, mbstring, openssl, zlib, sodium, phar` (plus opcache).

| Item | Result |
|---|---|
| Artifacts | `php` CLI **21.0 MB**, `micro.sfx` **18.9 MB**, both **dynamically linked against glibc only** (libc, libm, libdl, libpthread, librt, libutil, libresolv) |
| Docs claim | musl static builds cannot use `ffi`, so a glibc target is required (confirmed from the `spc` documentation) |
| Extensions | all nine required ones present |
| FFI | libc call works. The Rust shim loads and delivers its first event |
| Spikes 1, 2, 3 rerun on the new PHP | all pass (spike 2 spawns `PHP_BINARY`, which is the new PHP, and still works) |
| `ffi.enable` | defaults to `preload`, and **`FFI::cdef` is allowed in plain CLI and in micro with that default** |
| Ini baking | `spc micro:combine -I "ffi.enable=1"` works (`ini_get` shows `'1'`), but is not needed for FFI |
| Build times | sources 5.5 min; `libffi` 7.3 min (one-time Zig cache warm-up), `zlib` 10 s, `openssl` 9.8 min, `libsodium` 2.7 min; PHP `configure` **unknown** (hibernation); compile of 616 objects about 34 objects/min |
| Toolchain | `spc doctor` installed Zig 0.17.1 and `pkg-config` into the work directory. It also **tried `sudo dnf install` on its own** when run non-interactively |

**The glibc floor (`objdump -T`, highest `GLIBC_` symbol version required):**

| Binary | Needs up to |
|---|---|
| `php`, `micro.sfx`, `app` | **2.17** |
| Rust shim (headless) | **2.34** |
| Rust shim (windowed) | **2.43** |

PHP honours the 2.17 target, but the shim, built by Rust against the host glibc, does not. The distributable floor is therefore set by the shim, not by PHP. **Nothing has been run on another distro yet.**

### P0.6 Self re-exec in a micro binary (R4)

A phar appended to `micro.sfx` (19.5 MB total, including the shim `.so`).

| Check | Result |
|---|---|
| SAPI | `micro` |
| `PHP_BINARY` | **empty string** |
| `readlink('/proc/self/exe')` | the binary itself, also when launched via `PATH` from another directory |
| Master spawns a worker by re-executing itself with fd 3 | **same binary, different pid**, role dispatch works |
| Worker's `prctl(PDEATHSIG)` | returned 0 |
| Master closes its end | worker saw EOF, exited 0 |

### P0.7 Embedded shim

The shim `.so` stored inside the phar was read, written to `memfd_create`, and loaded with `dlopen("/proc/self/fd/4")`. The UI thread started, the first event arrived, and shutdown was clean. **Only the headless shim was tested this way.** The windowed shim was verified under the CLI PHP but not through `memfd` inside micro.

## 4. Findings

| ID | Finding | Source |
|---|---|---|
| F1 | Socket pairs must use `SOCK_CLOEXEC`. `ext-sockets` is a hard requirement. Every Raphp descriptor must be close-on-exec. | P0.2 |
| F2 | `proc_open` must get an array command. | P0.3 (derived) |
| F3 | The worker's SIGTERM handling must terminate it. | P0.3 |
| F4 | The parent leaks its script fd into children (harmless for EOF). | P0.2 |
| F5 | Cancel Revolt watchers before closing a stream. | P0.2 |
| F6 | winit has no Wayland popup support; GNOME has no server-side decorations; the tray needs an extension. | P0.4 |
| F7 | Clipboard needs `smithay-clipboard`, not `arboard`. | P0.4 |
| F8 | Redraws must be dirty-driven and paced to the compositor. | P0.1 |
| F9 | musl static builds cannot use `ffi`; a glibc target is required. | P0.5 |
| F10 | `spc doctor --no-interaction` attempts fixes (including `sudo`) on its own. | P0.5 |
| F11 | In PHP 8.5, `FFI::new()` called statically is deprecated; use `$ffi->new()`. Hidden on the system PHP by its error settings. | P0.5 |
| F12 | **Correction:** `ffi.enable=preload` does **not** block FFI in CLI or micro. My earlier claim was wrong. | P0.5 |
| F13 | `PHP_BINARY` is empty in micro. Use `/proc/self/exe`. | P0.6 |
| F14 | The shim's glibc floor (2.34 / 2.43) is higher than PHP's (2.17). | P0.5 |
| F15 | `spc micro:combine -I` bakes ini. Sizes: micro 18.9 MB, php 21 MB, app 19.5 MB. | P0.5 |
| F16 | Shim loads from inside the phar via `memfd_create` plus `dlopen` (headless shim). | P0.7 |
| F17 | Zig 0.17.1 and `pkg-config` are installed locally by `spc doctor`. | P0.5 |
| F18 | `amphp/process` wraps commands in `/bin/sh` with a signal trap and uses fd 3 itself, so it cannot spawn Raphp workers. Verified by reading its source. | audit + source check |
| F19 | The project directory is on a root-owned 777 mount. Git warns "dubious ownership" there, and so does Composer when it probes git for the project version. Composer itself works on the mount (checked in P1.2: executables are installed as regular proxy files and run). | P0.5, P1.2 |

## 5. What was not tested

- Clicks and key presses in the window.
- A tray icon and a native menu; the dialog's look; attaching the dialog to the window (it was unparented); window responsiveness while the dialog is open.
- IME and text input (planned for P3.11a).
- Anything on another distro, another glibc, another architecture, or inside a container (planned for P6.7).
- More than one worker at a time.
- `proc_open` while the multi-threaded shim is alive in the same process (the audit flagged this as a risk).
- The windowed shim loaded through `memfd` inside a micro binary.
- How long PHP's `configure` really takes, and the total clean-build time.

## 6. Doc audit (multi-agent, read-only)

Five independent auditors reviewed the docs against these findings. Every finding then faced two skeptics trying to refute it.

| Result | Count |
|---|---|
| Candidate findings | 40 |
| Confirmed | **33** (12 high, 14 medium, 7 low) |
| Rejected | 7 |
| Lost to a connection reset | 2 verifier calls, so one finding about FRAMEWORK 4.1 was dropped unverified |

Full result: `spikes/phase0-doc-audit-result.json`. The red-team lens found eight design gaps no spike had looked at (a panic on the shim's own thread, command/event ordering, an oversized event stalling input, `proc_open` forking a multi-threaded process, reaping and stdio draining, head-of-line blocking of pings, master memory growth, master-code hot reload), plus the `SO_PEERCRED` gap. These are recorded as **Open** risks R14 to R22 in `FRAMEWORK.md`, with the plan step that must resolve each. None has a designed solution yet.

## 7. Reproducing the build

```
cd spikes/04-static-php
./build.sh                    # downloads sources, builds libs, PHP CLI and micro
./progress.sh --watch         # live progress view
work/buildroot/bin/php -n -m  # check the extensions
```
`build.sh` sets `SPC_TARGET=native-native-gnu.2.17` and builds `ffi,pcntl,posix,sockets,mbstring,openssl,zlib,sodium,phar` with `--build-micro --build-cli`. Prerequisites installed on this machine: `perl`, `re2c` (via `dnf`). Everything else is fetched by `spc` into `work/`.
