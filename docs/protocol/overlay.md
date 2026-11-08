# Overlays, states and animation (step P3.10)

Widgets that react to the pointer and keyboard, a shim-side animation, and the **overlay layer** that menus and context menus are drawn on (FRAMEWORK 7.6; winit 0.30 has no `xdg_popup`, so a popup cannot be a window of its own and is drawn inside the window, F6).

## Looks that follow the user

Drawn from the interaction state of `input.md`, with no call from PHP:

| State | Look |
|---|---|
| **Hover** (a button, checkbox or text input under the pointer) | fades in over **120 ms** (smoothstep) and out again: a button's fill goes from `#E6E6E6` to `#D8D8D8` and its border darkens; a checkbox's border turns toward the accent; a text input's border darkens |
| **Pressed** (the left button held on a button or checkbox) | a button's fill is `#C4C4C4`; an unchecked checkbox's too (no fade: a press is immediate) |
| **Focus** | a 2 px accent ring just outside the widget (the box of a checkbox); a focused text input also has an accent border |
| **Disabled** | grey text and no hover or press, as before |

## Animation, in the shim

- **Time.** The loop tells the interaction state the time of the frame before it handles input or draws. A fade and the sweep below are functions of that time alone, so a test that sets the time gets exact pictures.
- **Indeterminate progress bar:** `progress_bar` takes `indeterminate: true` (new in P3.10; `value` is then ignored). A segment a third of the bar's width sweeps across it, entering at the left and leaving at the right, once every **1.4 s**, clipped to the bar.
- **Asking for frames.** After a frame is drawn, if something on the window is still changing by itself (a fade that is not finished, an indeterminate bar on the window), **the loop asks for the next frame**, and counts that request like any other: in a real window it is `request_redraw`, so the frame comes when the window system is ready for it, which is at most once per refresh of the monitor; in the headless backend it is the next frame interval of the clock. PHP is not called for any of it. A window with nothing animated asks for nothing (F8).
- **Measured on a real window** (the manual check): frames drawn per second while a bar sweeps, against the monitor's refresh rate, and the CPU used. See `docs/WINDOW_CHECKLIST.md`.

## The overlay layer

An overlay is a widget tree that hangs from nothing: PHP creates a widget (a `column` with buttons, say) with no parent, and shows it.

- **`overlay.show`** `{window, widget, x, y}` or `{window, widget, anchor}`: the widget must exist, have no parent and not be a window's root (`has_parent`, `already_root`, `unknown_widget`, `unknown_window` otherwise). Shown again, it moves. `(x, y)` is where its top left corner is asked to be, in logical pixels. With `anchor` (the id of a widget on the window's content, P3.12a) the overlay opens **under** that widget, at its left edge, wherever the widget is on the screen at that moment (scroll offsets included); the place is worked out again each frame, so it follows the widget. If it would not fit between the widget and the bottom of the window but fits above it, it opens **above** the widget instead. An `anchor` that is not a widget is `unknown_widget`.
- **`overlay.hide`** `{widget}`: takes it away; the widget stays alive and can be shown again. PHP is not told (it did it).
- **Size and place.** The overlay is as large as what it holds. It is **clipped to the window by being kept inside it**: asked for beyond an edge, it is moved in by as little as needed; an overlay larger than the window is put at its top left.
- **Drawn last**, above all content, on a soft shadow and a bordered panel; of several the one shown last is on top.
- **Hit-tested first.** While a window has overlays they have all the pointer: the topmost one the point is in wins, and a point in none of them is on nothing (the content below is out of reach: no hover, no click).
- **Dismissed** by a press outside every overlay or by **Escape**: the topmost overlay is taken away, and PHP gets `overlay.dismissed` `{window, widget, reason}` with the reason `outside_click` or `escape`. The press that dismisses is **consumed** (no `pointer.down`, and its release does nothing: it is not a click on what was under it); Escape is not a key event for anyone. The widgets stay alive for PHP to destroy or show again.
- **The focus stays inside** the topmost overlay: Tab goes round its widgets and never reaches the content behind.
- **Cleanup.** An overlay whose widget is destroyed or attached to something, or whose window is gone, is removed without an event.

## Menus (step P3.12a)

A menu is an overlay whose widgets are `menu_item`s and `menu_separator`s in a `column`. Nothing new is needed for it beyond the widgets and the `anchor`; the rules below are what makes it behave like a menu.

- **Menu bar.** Not a widget: a `row` of buttons in the window whose clicks show a dropdown with `overlay.show` and `anchor`. (Opening the neighbouring dropdown by moving over the bar while one is open is not built.)
- **Dropdown and context menu:** `anchor` for the first, `x` and `y` (the pointer's place, from `pointer.down`) for the second. `examples/window-check` has both.
- **Choosing.** A click on an enabled `menu_item`, or Enter or Space on a focused one, sends `click` as for any widget (`button: keyboard` for the keys), **then the menu is closed by the shim**: the overlay is taken away and PHP gets `overlay.dismissed` `{window, widget, reason: "activated"}` after the click. PHP has nothing to hide. A disabled item and a separator cannot be focused and do nothing when clicked; clicking one leaves the menu open.
- **Keys.** **ArrowDown** and **ArrowUp** go to the next and previous enabled item of the top overlay, wrapping (as Tab and Shift+Tab do); the arrow is consumed. A text input that has the focus in an overlay keeps its arrows for the caret.
- **Accelerators.** The `shortcut` is only drawn. Which keys do what is the application's (it has `key.down` with the modifiers); making `Ctrl+S` work is not the shim's job.

## What is not here

Submenus placed beside their parent, hover-to-open, a menu bar that opens the next dropdown when the pointer moves along it, typing a letter to jump to an item, and mnemonics are not built; nor are tooltips and combo boxes. An overlay does not follow a window resize except by being kept inside it. Menus are drawn inside the window (winit 0.30 has no popup surface, F6), so **a menu cannot extend beyond the window**: it is kept inside, or opened above its anchor, and a window too small for it clips nothing but shows it from its top left.
