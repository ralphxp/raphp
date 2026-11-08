# The real-window checklist (plan P3.6)

This is the part of P3.6 that only you can do: a person looking at a real window on a real session. It takes about five minutes. Everything else about the window backend is tested by `make check` (with no window) and `make window-test` (which opens windows briefly, each test in its own process).

## Run it

```
make shim                      # once, if the library is not built
php examples/window-check/main.php
```

A window titled **Raphp window check** opens, showing a small form (a title, a text field with a Save button, a ticked checkbox, a progress bar at 60% and a greyed-out button). It does not react to the mouse yet: input is P3.9 and P3.10. The script prints every event the window system sends to the shim and writes them to a trace file (`/tmp/raphp-window-check-trace.jsonl`, or `--trace=PATH`). It ends when you click the window's close button, or after 180 seconds (`--seconds=N`).

## What to do, and what to write down

Tick each line; "yes/no" and what you saw is enough.

| # | Do | Expected | Result |
|---|---|---|---|
| 1 | Look at the window | It appeared, with the title "Raphp window check", about 480 by 320, showing the form described above. **Tell me if anything looks wrong**: text cut off, misaligned, blurry, or the wrong colors | y |
| 2 | **Leave it alone** until the script prints `>> idle measurement ended` (it waits for the window to settle, then measures 5 seconds; do not run builds or other heavy work meanwhile) | `PASS: no frame was drawn or asked for while idle` and a CPU figure. This is the hard criterion (F8). The CPU figure is recorded, not judged | ms of CPU in 5000 ms |
| 3 | Drag the title bar to move the window | It moves. (No event is expected: moving is not reported yet) | no even|
| 4 | Drag an edge or corner to resize | It resizes. `window.resized` lines print, the width and height in logical pixels | rsized|
| 5 | Maximize it, then restore it | Both work; `window.resized` events print for each |done |
| 6 | Click another window, then click this one again | `window.focus` prints `"focused":false`, then `true` | done|
| 7 | Minimize it (or hide it) for about 10 seconds, then bring it back | Nothing breaks. Record whether the counters in the trace (`kind":"counters"`) went up while it was hidden: this is **recorded, not pass or fail** (it depends on the compositor) | one|
| 8 | Move it to a monitor with a different scale, if you have one | `window.scale` prints | not yet |
| 9 | Click the window's **close button** | `window.close_requested` prints, the window closes, the script prints a summary and ends |done |
| 10 | The title bar: does it allow move, resize, maximize and close, and what does it look like? (GNOME/Wayland uses winit's own title bar.) A screenshot is welcome as evidence donly; its look is already accepted (D31) | | |

## Input (step P3.9): mouse, keyboard, scroll

After the window check above, or in the same run, try these. The window does not draw hover, pressed or focus states yet (that is P3.10), so most of what you do shows up **only as lines the script prints**; the list at the bottom does move when you turn the wheel.

| # | Do | Expected |
|---|---|---|
| I1 | Move the pointer over the Save button and over the text field | `pointer.move` lines (a few a second) with `target` the id of the widget under it: 5 for Save, 4 for the field, 6 for the checkbox, 8 for the disabled button |
| I2 | Click Save | `pointer.down`, `focus.changed` (widget 5), `pointer.up`, `click` (widget 5, button left) |
| I3 | Click the disabled button (`Disabled`) | `pointer.down` and `pointer.up` only: no `click`, no `focus.changed` |
| I4 | Right-click Save | `pointer.down`, `pointer.up`, `click` with button `right`, and no `focus.changed` |
| I5 | Press Tab a few times, then Shift+Tab | `focus.changed` only (no key line for Tab), visiting 4, 5, 6 and wrapping; Shift+Tab goes back |
| I6 | With Save focused, press Enter; then Space | `key.down`, `click` (button `keyboard`) for each; holding a key down clicks once |
| I7 | Type letters while the text field has the focus | `key.down` and `key.up` lines with `key` and `code`, `target` 4. (No text appears in the field yet: that is P3.11) |
| I8 | Turn the mouse wheel over the list at the bottom | The list moves. `scroll` lines with `view` 9 and `offset` growing to a limit and no further, going back when you turn the other way. **Tell me which way is "down"**: turning the wheel away from you should show earlier items |
| I9 | Turn the wheel over the title or the form (not the list) | `scroll` lines with `view` null and nothing moves |
| I10 | Close the window | as before |

Report what differs from "expected", and any line that surprised you.

## Core widgets, animation and the overlay (step P3.10)

The window check now also measures an animation, and the window draws how widgets react. Things to look at and do:

| # | Do | Expected |
|---|---|---|
| W1 | Move the pointer slowly over the Save button, off it, and over the checkbox and the text field | the button darkens gently (about a tenth of a second) and lightens when you leave; the checkbox border and the field border change a little. **Tell me if it is too much, too little, or flickers** |
| W2 | Press and hold the left button on Save, then let go | the button is darker while held |
| W3 | Press Tab until the text field and the buttons are focused in turn | a blue ring around the focused one (the field also has a blue border) |
| W4 | After the idle measurement, leave the window alone for the animation: the progress bar sweeps for 7 seconds | the script prints `animation ended: N frames in S s = R frames a second`. **R should be about your monitor's refresh rate and never above it.** Tell me R and your monitor's rate, and the CPU figure |
| W5 | **Right-click** anywhere in the window | a small menu appears where you clicked, above the form, with a shadow: "Menu item one" with a shortcut at the right, "Menu item two" with a check mark, a thin line, and a grey "Disabled item"; near an edge it moves in so that it stays inside the window |
| W6 | With the menu open, move over the form behind it and click on it | nothing behind reacts (no hover); the click closes the menu and does nothing else; the script prints `overlay.dismissed` with `outside_click` |
| W7 | Open the menu and press Escape | it closes; `overlay.dismissed` with `escape` |
| W8 | Open the menu, press Tab, then the Down and Up arrows | the focus moves between the two enabled items only (the grey one is skipped), by Tab and by the arrows |
| W9 | Open the menu, move over the items, then click "Menu item one" | the item under the pointer is lit and the grey one is not; the click prints `click` and then `overlay.dismissed` with `activated`, and the menu closes |
| W10 | Open the menu, focus an item with the arrow and press **Enter** (then again with Space) | same as W9: `click` with `button: keyboard`, then `overlay.dismissed` `activated` |
| W11 | Click the **Save** button | a dropdown ("Save  Ctrl+S", "Save as...", a line, "Quit  Ctrl+Q") opens directly under the button, at its left edge. Make the window short (drag its bottom edge up) so that the button is near the bottom and click again: the menu should open **above** the button instead |

## Text input and the input method (step P3.11)

The text field of the window ("Ada Lovelace") can now be edited. The script prints `text.changed` and `text.submitted` lines. This is also the spike for input methods on Wayland (P3.11a, R8), which only you can run: I need to know **which** input method you used and **what** happened.

| # | Do | Expected |
|---|---|---|
| T1 | Click in the text field and type a few letters, including one with an accent (`é`) | the letters appear at the caret; a `text.changed` line for each, with the whole `text`, a growing `revision`, and `caret` and `anchor` (bytes: `é` is two) |
| T2 | Use the arrow keys, Home, End; hold Shift with them; hold Ctrl with Left and Right; Backspace and Delete (also with Ctrl) | the caret moves; a blue selection appears with Shift; Ctrl jumps by word; Backspace removes the selection or one character (an accented letter or an emoji goes in one press) |
| T3 | Click and drag across the text; double-click a word | the dragged part is selected, also when the pointer leaves the field; the double-clicked word is selected |
| T4 | Select text, press Ctrl+C, then paste it in **another application** (an editor or the terminal); then copy something in that application and press Ctrl+V in the field | **Wayland: the text should travel between the applications.** Tell me if it does not |
| T5 | Type more than the field shows | the text slides left so that the caret stays in view, and back when you press Home |
| T6 | Press Enter in the field | a `text.submitted` line |
| T7 | Press Tab until the field is focused | all its text is selected |
| T8 | **Input method:** switch to an input method (IBus on GNOME: for example Japanese or Chinese, or a Compose or dead-key sequence such as the dead acute then `e`) and type | what you are composing appears at the caret **underlined**, the candidate list appears **next to the caret**, and committing puts the text in the field with a `text.changed`. **Tell me the input method, what you typed, and anything wrong (candidate list in the wrong place, nothing appearing, a crash)** |

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

## Second run (2026-11-08, read from the console output you pasted; its trace file was not where I could read it)

| # | What the output shows | Status |
|---|---|---|
| 2 | **Not a valid measurement.** The idle measurement started at counters `[0,0]` and the window's first events (`batch.done`, `window.scale`, `window.resized`, `window.focus`) arrived after it had started: the window took more than 4 seconds to appear, probably because a build I was running was using the machine (83 ms of CPU against 1 ms in the first run). The script assumed 4 seconds, so it measured the window opening, and printed FAIL. The script now waits for the window to settle first. The first run, on a quiet machine, passed | **to be repeated** |
| 4 | Dragging the bottom edge gave 24 `window.resized` events, the height going 320, 324 ... 382 and back to 312, the width staying 480: the reported size follows the drag step by step | **seen** (vertical drag only) |
| 5 | Maximize reported 1920 by 1016 (a 1920 by 1080 screen less the bars); restore reported 480 by 312, the last size before maximizing | **seen** |
| 6 | 14 focus events, alternating | seen |
| 4, 7 | Dragging **horizontally**, the **smallest size** the window allows, and **minimize**: not in the output (the counters during a minimize were in the trace, which I could not read) | not evidenced |
| end | 25 redraws requested (1 first frame + 24 resizes), 37 frames drawn: 12 frames came from the window system itself, next to focus changes | as expected |

Still wanted: one more run on a quiet machine, with a horizontal drag, the window made as small as it goes, and a minimize of ten seconds. If the trace file is somewhere else (the script prints its path at the end), tell me where.
