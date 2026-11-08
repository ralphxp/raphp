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
| 1 | Look at the window | It appeared, with the title "Raphp window check", about 480 by 320, blank white | |
| 2 | **Leave it alone for the first 9 seconds** | The script prints `PASS: no frame was drawn or asked for while idle` and a CPU figure. This is the hard criterion (F8). The CPU figure is recorded, not judged | |
| 3 | Drag the title bar to move the window | It moves. (No event is expected: moving is not reported yet) | |
| 4 | Drag an edge or corner to resize | It resizes. `window.resized` lines print, the width and height in logical pixels | |
| 5 | Maximize it, then restore it | Both work; `window.resized` events print for each | |
| 6 | Click another window, then click this one again | `window.focus` prints `"focused":false`, then `true` | |
| 7 | Minimize it (or hide it) for about 10 seconds, then bring it back | Nothing breaks. Record whether the counters in the trace (`kind":"counters"`) went up while it was hidden: this is **recorded, not pass or fail** (it depends on the compositor) | |
| 8 | Move it to a monitor with a different scale, if you have one | `window.scale` prints | |
| 9 | Click the window's **close button** | `window.close_requested` prints, the window closes, the script prints a summary and ends | |
| 10 | The title bar: does it allow move, resize, maximize and close, and what does it look like? (GNOME/Wayland uses winit's own title bar.) A screenshot is welcome as evidence only; its look is already accepted (D31) | | |

## What to send back

- The ticked table (a line each is enough).
- The trace file.
- The compositor and session type (`echo $XDG_CURRENT_DESKTOP $XDG_SESSION_TYPE`) and, if you can, a run under the other one (X11 or Wayland): the backend supports both, but only one has been seen so far.
- Anything that looked wrong, even if you are not sure it is.

## What this does not check

The window shows nothing yet but white: layout and drawing are P3.7 and P3.8, and input events (mouse and keyboard) are P3.9. `make window-test` covers the shim with a real window open (first frame, an idle window, a change drawing a frame, a title change drawing none, a resize reported); the worker-spawn check (R17) with a real window open is `php/tests/Ui/Ffi/ShimWindowTest.php`, also run by `make window-test`.
