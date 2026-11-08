# The real-window checklist (plan P3.6)

This is the part of P3.6 that only you can do: a person looking at a real window on a real session. It takes about five minutes. Everything else about the window backend is tested by `make check` (with no window) and `make window-test` (which opens windows briefly, each test in its own process).

## Run it

```
make shim                      # once, if the library is not built
php examples/window-check/main.php
```

A window titled **Raphp window check** opens, blank white (drawing arrives in P3.8). The script prints every event the window system sends to the shim and writes them to a trace file (`/tmp/raphp-window-check-trace.jsonl`, or `--trace=PATH`). It ends when you click the window's close button, or after 180 seconds (`--seconds=N`).

## What to do, and what to write down

Tick each line; "yes/no" and what you saw is enough.

| # | Do | Expected | Result |
|---|---|---|---|
| 1 | Look at the window | It appeared, with the title "Raphp window check", about 480 by 320, blank white |y |
| 2 | **Leave it alone for the first 9 seconds** | The script prints `PASS: no frame was drawn or asked for while idle` and a CPU figure. This is the hard criterion (F8). The CPU figure is recorded, not judged |ms of CPU in 5000 ms |
| 3 | Drag the title bar to move the window | It moves. (No event is expected: moving is not reported yet) | no even|
| 4 | Drag an edge or corner to resize | It resizes. `window.resized` lines print, the width and height in logical pixels | rsized|
| 5 | Maximize it, then restore it | Both work; `window.resized` events print for each |done |
| 6 | Click another window, then click this one again | `window.focus` prints `"focused":false`, then `true` | done|
| 7 | Minimize it (or hide it) for about 10 seconds, then bring it back | Nothing breaks. Record whether the counters in the trace (`kind":"counters"`) went up while it was hidden: this is **recorded, not pass or fail** (it depends on the compositor) | one|
| 8 | Move it to a monitor with a different scale, if you have one | `window.scale` prints | not yet |
| 9 | Click the window's **close button** | `window.close_requested` prints, the window closes, the script prints a summary and ends |done |
| 10 | The title bar: does it allow move, resize, maximize and close, and what does it look like? (GNOME/Wayland uses winit's own title bar.) A screenshot is welcome as evidence donly; its look is already accepted (D31) | | |

## What to send back

- The ticked table (a line each is enough).
- The trace file.
- The compositor and session type (`echo $XDG_CURRENT_DESKTOP $XDG_SESSION_TYPE`) and, if you can, a run under the other one (X11 or Wayland): the backend supports both, but only one has been seen so far.
- Anything that looked wrong, even if you are not sure it is.

## What this does not check

The window shows nothing yet but white: layout and drawing are P3.7 and P3.8, and input events (mouse and keyboard) are P3.9. `make window-test` covers the shim with a real window open (first frame, an idle window, a change drawing a frame, a title change drawing none, a resize reported); the worker-spawn check (R17) with a real window open is `php/tests/Ui/Ffi/ShimWindowTest.php`, also run by `make window-test`.

## Result so far (your run of 2026-11-08, read from the trace and one screenshot)

GNOME on Wayland (the session this was run on; no X11 run yet). A run of 17 seconds.

| # | What the trace and screenshot show | Status |
|---|---|---|
| 1 | The window opened at 480 by 320, scale 1, with the title "Raphp window check", blank white (screenshot) | seen |
| 2 | Idle for 5.001 s: (requested, drawn) stayed (2, 2), **1 ms of CPU** (the shim's own check measured 6 and 15 ms in two other runs) | **PASS** (the hard criterion) |
| 3 | Move | not evidenced (moving sends no event, so a trace cannot show it) |
| 4, 5 | Resize by dragging, maximize and restore | **not evidenced**: the only `window.resized` in the trace is the first, 480 by 320 |
| 6 | Focus: lost at 3.2 s, regained at 10.7 s, lost at 11.9 s, regained at 15.4 s | seen |
| 7 | Minimize or hide for 10 seconds | not evidenced |
| 8 | A monitor with another scale | not applicable (one `window.scale`, 1) |
| 9 | Close button: `window.close_requested` arrived, the shim destroyed the window, the script ended | seen |
| 10 | The title bar has minimize, maximize and close buttons and looks as accepted in D31 (screenshot) | seen |

**One observation to record:** `frames_drawn` went from 2 to 3 at about 12 s and to 4 at about 16 s while `redraws_requested` stayed 2. Those are frames the window system asked for itself (they came next to focus changes, which repaint the window's title bar), not frames the shim asked for. So the counters can show more frames drawn than requested after the user touches the window; the idle criterion is about an undisturbed window, and that passed. Whether they are also what a minimized window gets is item 7, still open.

**Still to do from you (about a minute):** run it again and, within the first 20 seconds after the idle part, drag an edge to resize it, maximize and restore it, and minimize it for ten seconds. That checks the path from the user's drag to the `window.resized` event, which the automated tests cannot exercise (they ask for a size from PHP; the drag is the window system's own event). A run under X11, if you have one, would answer the other session type.
