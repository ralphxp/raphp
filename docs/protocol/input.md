# Input and focus (step P3.9)

What the user does to a window, and what the shim tells PHP about it. The code is `shim/raphp/src/input.rs`; real windows feed it from winit (`window.rs`), the headless backend from the command `input.simulate`. The rules are the same for both, so they are tested without a screen (`input_tests.rs`).

PHP **describes** (properties) and the shim **owns the interaction**: where the pointer is, what is under it, what is held down, what has the keyboard focus, how far each scroll view is scrolled. PHP hears of what happens as events, and answers by changing properties (a checkbox's `checked`), never by drawing.

## Coordinates and hit testing

All positions are in **logical pixels** from the top left of the window's content. The widget under a point is the **deepest** one whose rectangle holds it, **last painted first** (what is on top wins). A rectangle includes its left and top edge and not its right and bottom edge. A scroll view **clips** what it holds: a point outside the scroll view cannot hit what is inside it, even where that is scrolled out over something else, and what it holds is hit where it is *shown*, shifted by the scroll offset. The layout used is kept between events and worked out again only when the model changes (`Model::revision`).

## Events from the shim

All carry `window`. Every event about a widget goes through the same check as any other (R15): none is queued about a widget that is gone.

| Event | Fields | Kind | When |
|---|---|---|---|
| `pointer.move` | `x`, `y`, `target` (widget id or null) | **continuous** (only the latest per window is kept) | the pointer moved over the window |
| `pointer.leave` | | discrete | the pointer left the window |
| `pointer.down`, `pointer.up` | `x`, `y`, `button` (`left`, `middle`, `right`, `other`), `target` | discrete | a button went down or up |
| `click` | `widget`, `button`, `x`, `y` (a keyboard click has `button` `keyboard`, no position) | discrete | a button went down and up on the **same enabled** widget (a button, checkbox, menu item or text input), or Enter or Space was pressed on a focused button, checkbox or menu item (a menu item also closes its menu: `overlay.md`) |
| `scroll` | `x`, `y`, `dx`, `dy`, `target`, `view`, `offset` | **continuous** | the wheel turned; `dy` is how far the content should move up (positive shows what is below), in logical pixels (a wheel line is 40). `view` is the innermost scroll view under the pointer and `offset` where it is now, `[x, y]`, whether or not this turn moved it (continuous events carry **state**: a newer one replaces an older queued one, so `dx` and `dy` are lost when events merge and the offset is not); both are null when no scroll view is under the pointer |
| `key.down`, `key.up` | `key`, `code`, `repeat`, `shift`, `ctrl`, `alt`, `meta`, `target` (the focused widget or null) | discrete | a key; `key` is what it types ("a") or its name ("Enter", "ArrowLeft"), `code` the physical key ("KeyA"). **Tab is not reported**: it moves the focus; **ArrowUp and ArrowDown are not reported while an overlay is open** (unless a text input has the focus): they move the focus between the overlay's enabled items |
| `focus.changed` | `widget` (id or null) | discrete | the focus moved, or went nowhere (the focused widget was destroyed, disabled or detached) |
| `text.changed`, `text.submitted` | see `text-input.md` | discrete | the user changed the text of a text input; Enter was pressed in one |

## Focus

- **Which widgets can take it:** `button`, `checkbox` and `text_input` that are not `enabled: false`.
- **Tab** moves it to the next such widget in tree order (depth first from the root), wrapping; **Shift+Tab** goes the other way (from nothing, backwards starts at the last). A focused widget that is destroyed, disabled or removed from the window loses the focus, and PHP is told (`focus.changed` with a null widget).
- **A left click** on one takes the focus. A right or middle click does not. A click on nothing changes nothing.
- **PHP** can ask: `widget.focus` with `id` of a widget in a window's tree, or with `id` null and a `window` to take it away there. A widget that cannot take the focus, or has no window, leaves things as they were (it is not an error: the widget exists).
- **Hover and pressed state** are kept (the widget under the pointer if it can be used, and the widget a button is held down on) and drawn from P3.10.

## What each loop does with them

- A change of state that needs a new picture (a hover change, a press, a focus change, a scroll that moved something) **asks for a frame, and the request is counted before the events that follow from it are queued** (F57: shared state first, then the event). An input that changes nothing visible (the pointer moving inside one button) asks for no frame (F8).
- A scroll view scrolls **in the shim**, with no round trip to PHP: the wheel changes its offset (limited to what its content holds beyond what it shows, in both directions, in the innermost scroll view under the pointer that has somewhere to go), and the next frame shows it.

## Commands (shim-commands.md)

- `widget.focus`: `{"op": "widget.focus", "id": 5}` or `{"op": "widget.focus", "id": null, "window": 1}`.
- `input.simulate`: `{"op": "input.simulate", "window": 1, "event": {...}}` hands a window an input event as if the user had made it. The `event` is `{"kind": "pointer.move", "x": 10, "y": 20}`, `pointer.leave`, `pointer.down` / `pointer.up` (with `x`, `y` and an optional `button`, left by default), `scroll` (`x`, `y`, `dx`, `dy`), `key.down` / `key.up` (`key`, optional `code` and `repeat`), or `modifiers` (`shift`, `ctrl`, `alt`, `meta`, each optional), `text` (`text`) or `preedit` (`text`), which are typed text and input-method composition (`text-input.md`). It works on **both** backends (on a real window it acts as if the user had done it). It is how PHP tests and tools drive the shim without a screen. The simulated inputs of a batch are handled **after** the batch's commands, in order, and their events follow the batch's `batch.done` (so a simulated click on a widget the batch created finds it).

## Real windows (winit)

Mouse and keyboard events of a window are turned into the same input events: a cursor position (physical) to logical pixels by the window's scale factor; the mouse buttons to `left`, `middle`, `right`, `other`; a wheel to `dx` and `dy` (a line is 40 logical pixels; a pixel delta is converted by the scale; the sign is reversed because a wheel turned away shows what is above); the modifiers from winit's `ModifiersChanged`; `key` from the logical key (a character, or a name like "Enter") and `code` from the physical key. What has not been verified is that real keys and clicks arrive on a real session: that is the manual check of P3.9 (`docs/WINDOW_CHECKLIST.md`).

## What is not here yet

Touch and gestures are not planned for the MVP.
