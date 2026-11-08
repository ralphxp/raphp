# Canvas (step P3.13)

A `canvas` is a widget whose picture PHP draws: charts, editors, games, anything the widgets do not cover (FRAMEWORK 8.9). PHP sends a **draw list**, a list of shapes and text, in the widget's `ops` property; the shim rasterizes it with the rest of the window, at the window's scale, on the CPU. It is an escape hatch: the shim keeps the list and redraws it whenever the window is redrawn, so a drawing that does not change costs PHP nothing. **Animation belongs in the shim**; sending a new list for every frame is possible and is not what this is for.

## The widget

| Property | Type | |
|---|---|---|
| `width`, `height` | numbers, 0 to 10,000 (logical pixels) | The size of its own: 200 by 150 when not set. As for every widget, it is a *minimum* (`layout.md`): in a column it is stretched across, in a row along only by `grow`. Set both to 0 with `grow` to fill what is left |
| `ops` | a draw list | What to draw. `null` (or not set) is an empty canvas |
| `grow` | number | As for every widget |

The canvas is hit-tested like any widget: `pointer.*`, `click` and `scroll` events name it as their target, and carry the position in the **window** (subtract the canvas's own position, which PHP knows from the layout it asked for or from the widgets around it, to get canvas coordinates). A canvas does not take the keyboard focus.

## The draw list

An array of at most **4,096 operations**, drawn in order, later ones over earlier ones. Coordinates and lengths are **logical pixels from the canvas's top left corner** (fractions allowed, from -1,000,000 to 1,000,000). **Nothing is drawn outside the canvas**: a shape that sticks out is cut at its edge. Colors are `"#RRGGBB"` or `"#RRGGBBAA"` (the last pair is the opacity, `FF` solid); shapes are anti-aliased and blended with what is under them.

| `op` | Fields | |
|---|---|---|
| `rect` | `x`, `y`, `w`, `h` (w and h not negative); optional `radius`, `fill`, `stroke`, `line_width` | A rectangle, with rounded corners when `radius` is set |
| `line` | `x1`, `y1`, `x2`, `y2`, `stroke` (required); optional `line_width` | One segment with flat ends |
| `path` | `points` (2 to 4,096 pairs `[x, y]`); optional `closed`, `fill`, `stroke`, `line_width` | Straight segments through the points. A `fill` fills the area as if the path were closed; the `stroke` closes it only if `closed` is true. A path with neither `fill` nor `stroke` is refused (it would draw nothing) |
| `circle` | `x`, `y` (the center), `r` (not negative); optional `fill`, `stroke`, `line_width` | |
| `text` | `x`, `y` (the top left of the text), `text`; optional `fill` (the color, near-black by default), `size` | One line of text up to 4,096 bytes, in the window's font; `size` is its height in logical pixels (4 to 200; 14 is the size the widgets use). It is cut at the canvas's edge, never wrapped |

`stroke` is a color and `line_width` its width in logical pixels (1 by default; more than 0 and at most 1000); a stroke is centered on the edge of the shape. A `fill` or `stroke` that is not given draws nothing for that part, so a `rect` with neither draws nothing.

## What is refused

The list is **checked when it is set** (`widget.create` or `widget.set`), and a list with anything wrong in it is refused as a whole with `bad_prop` and a message that names the operation and the problem (`ops[3]: circle needs "r"`); the canvas keeps its old drawing (and the rest of a `widget.set` is not applied either, as for every property). Refused: a value that is not an array, more than 4,096 operations, more than 65,536 path points in all, an operation that is not an object or has no or an unknown `op`, **a field the operation does not have** (a typo in a name is an error, not a silent no-op), a missing or non-number coordinate, a number beyond ±1,000,000, a negative length, a bad color, a bad `line_width`, `size` or `points`.

## Limits and what is not here

No images or gradients, no clipping or transform operations, no text wrapping or alignment, no curves (a path of many short segments does for a chart), no dashes, and no per-operation ids (replace the list). The list is kept as sent and parsed again at each draw (the cost of that, at the limits, has not been measured). Pictures are tested in golden files (`canvas_chart`, `canvas_chart_x2`).
