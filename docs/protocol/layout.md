# Layout (step P3.7)

How the shim decides where each widget goes. PHP never sees any of this: it describes widgets and their properties (`shim-commands.md`), and the shim places them. The code is `shim/raphp/src/layout.rs`; its tests are a table of cases with the exact rectangles expected (`layout_tests.rs`). Layout is done by Taffy 0.15 (D53), the flexbox part only.

## Units

- Everything in layout is in **logical pixels**, the unit of the window sizes in the commands. A window's scale factor does not change a layout: the same tree has the same rectangles at any scale.
- A rectangle is `x`, `y`, `width`, `height` from the top left of the window's content, **absolute** (not relative to the parent).
- Only when something is drawn are rectangles put on the **physical** pixel grid (`Rect::snapped(scale)`): each edge is multiplied by the scale and rounded to a whole pixel on its own, and the size is the distance between the rounded edges. So two widgets that touch in logical pixels still touch in physical ones, with no gap and no overlap, at any scale (tested at 1, 1.5 and 2).

## The rules

1. **The root widget fills the window's content.** A window with no root has nothing to lay out.
2. **`column` and `row`** are flex containers: a column stacks its children top to bottom, a row puts them left to right. `padding` is the same on all four sides, `gap` is the space between neighbours (both in logical pixels, 0 by default).
3. **Across the container** (a column's width, a row's height) children **stretch** to fill it. Along it they take their own size.
4. **Every widget has a size of its own and is never made smaller than it.** If the children do not fit, they overflow past the container's edge (nothing is squeezed). The sizes of a widget's own are:

   | Widget | Its own size |
   |---|---|
   | `label` | the size of its text |
   | `button` | the size of its label, plus 12 on the left and right and 6 above and below, and at least 28 high |
   | `checkbox` | a 16 box, an 8 gap, then the label; at least 16 high |
   | `text_input` | 160 wide, 28 high (the text inside it does not change this) |
   | `menu_item` | its label, then (when there is a shortcut) a 24 gap and the shortcut, with 28 on the left (room for the check mark) and 12 on the right; 24 high at least |
   | `menu_separator` | 9 high |
   | `canvas` | its `width` and `height`, 200 by 150 when not set |
   | `progress_bar` | 160 wide, 8 high |
   | `column`, `row` | what its children and its padding add up to |

   The 12, 6, 28, 16, 8, 160 and 8 are my numbers, until a theme exists (Phase 4 or later).
5. **`grow`** (a number from 0 to 1000, 0 by default, on every widget) is how much of the space left over along the parent takes, in proportion to its siblings' `grow`. A text input with `grow` 1 in a row takes whatever the other widgets leave.
6. **`scroll_view`** shows part of what it holds. It may be smaller than its one child (it can shrink below it), and the child **is never squeezed** to fit: it keeps its own size, and the scroll view reports that size as its **content size**, which is what drawing will scroll over (P3.8). The child is stretched across the scroll view like any child of a column.
7. A widget that is not in a window's tree (created, but not appended to anything that is a root) has no rectangle.

## Text

Text has no size until fonts exist. Layout asks a `TextMetrics` for the size of a piece of text; until P3.8 the answer comes from a ruler that makes every character 8 wide and every line 16 high (lines are split at `\n`), which is what the tests use. **There is no wrapping yet**: a label is as wide as its longest line.

## What is not here (on purpose, for now)

- No `align`, `justify`, margins, borders, minimum or maximum sizes, percentages, or wrapping rows. The one property added to the protocol is `grow`; the others come when an example needs them.
- No grid, floats or `calc`: Taffy is built with the flexbox feature only.
- No caching: the layout of a window is built again from the model each time it is asked for, and it is asked for when a window is drawn, which only happens when the window changed (F8). Measured: **230 ms for 10,001 widgets in a debug build** (a release build is several times faster). That is the cost of redrawing a window of that size; it is not a threshold, and a cache is the answer if drawing needs it.
