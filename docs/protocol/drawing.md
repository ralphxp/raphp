# Drawing and text (step P3.8)

How the shim paints a window. PHP never draws: it describes widgets (`shim-commands.md`), layout places them (`layout.md`), and this is what puts pixels on them. The code is `shim/raphp/src/draw.rs` (the painter), `text.rs` (fonts), and the two backends that put the picture where it goes (`backend.rs` for headless, `window.rs` for a real window).

## What it uses

- **tiny-skia 0.11** rasterizes on the CPU: filled and stroked paths with antialiasing, clipping by a mask. No GPU (FRAMEWORK 7.6: CPU first, GPU later).
- **cosmic-text 0.19** shapes and draws text (harfrust for shaping, swash for glyphs).
- **The picture** is RGBA, opaque. A real window gets it as softbuffer's `0x00RRGGBB` words.

## Fonts (D54)

| Backend | Font | Why |
|---|---|---|
| Headless | **Liberation Sans Regular alone**, compiled into the library (`shim/raphp/assets/fonts`, 410 KB, SIL Open Font License, the license file beside it) | A headless picture is the same on every machine, which golden pictures need; and no scan of the system's fonts holds up the UI thread. In a debug build such a scan took many seconds and made tests time out |
| Window | The machine's fonts, "sans-serif" by the system's own choice | A real window should look like the system. The scan is started by a background thread when the window loop starts, and the first frame waits for it if it is not done |

Consequences: text in a headless picture is Liberation Sans whatever fonts PHP's machine has; a window's text depends on the machine. The size of text is 14 logical pixels on a 20 high line, with no wrapping (a line is as wide as it is); both are my numbers until a theme exists.

## The base look

Light only. Hover, pressed and focus states, the caret and animations come with P3.9 to P3.11.

| Widget | Drawn as |
|---|---|
| `label` | its text at the top left of its place, near-black (`#202020`) |
| `button` | a rounded rectangle (radius 4, 1 px border `#B4B4B4`, fill `#E6E6E6`) with its label in the middle; grey text when `enabled` is false |
| `progress_bar` | a pill-shaped track (`#DCDCDC`) with the filled part in the accent (`#2D6CDF`) up to `value` |
| `checkbox` | a 16 px rounded box (white, or the accent with a white check mark when `checked`) and the label to its right, vertically centered |
| `text_input` | a rounded white box with a border, the text (or the `placeholder`, grey) 8 px in, vertically centered and cut off at the edge |
| `menu_item` | its label, 28 px in, with the shortcut (grey) at the right edge and a check mark in the left margin when `checked`; a rounded fill of the accent at 20 % when it has the focus or the pointer is over it (fading in as hover does; never when disabled); grey text when disabled |
| `menu_separator` | a 1 px line in the border color (`#B4B4B4`) across the middle of its place, 8 px in from each side |
| `canvas` | its draw list, clipped to its own rectangle (`canvas.md`) |
| `column`, `row` | nothing: they only place their children |
| `scroll_view` | nothing of its own; it **clips** what it holds to its own rectangle and draws it shifted by its scroll offset, which cannot be more than the content beyond what the view shows, nor less than 0 |

Everything is drawn at the window's scale: sizes and rectangles in logical pixels are multiplied by it and each edge rounded on its own (`layout.md`), and text is shaped at the physical size, so a scale-2 picture is as sharp as one drawn for that screen, not a stretched scale-1 one.

## When a frame is drawn

Only when the window changed (F8): the model marks windows dirty, the backend draws them (at the headless clock's pace, or when the window system asks). Drawing builds the layout again from the model each time (`layout.md`, "no caching"). A window with no root is plain white and needs no fonts.

## The golden pictures and their tolerance

`shim/raphp/tests/golden/*.png` hold the expected pictures of the widgets alone and together (label, buttons, progress bars, checkboxes, text inputs, a form, the form at scale 2, a scroll view at the top and scrolled). `draw_tests.rs` draws the same scenes with the bundled font and compares:

- **A pixel matches** if each of its four channels is within **3** of the golden pixel.
- **A picture matches** if it has the same size and at most **0.05%** of its pixels do not match.

Shapes, borders and fills are exact; what can differ between machines is the antialiasing of text edges, by a few levels (different SIMD paths in the rasterizer), which is what the tolerance is for. A failing picture is written to `target/golden-failures/<name>.actual.png`.

**To change a golden picture on purpose:** run the tests with `RAPHP_UPDATE_GOLDEN=1`, **look at the new pictures** (open the PNG files), and commit them with the change that caused them. Never regenerate to make a test pass without looking.

Besides the pictures, tests check what they do not say: the filled part and the track of a progress bar by pixel, that a scroll view paints nothing outside itself (the title above it is the same with or without scrolling), that a scroll offset is limited to what the content allows, and that drawing through the headless backend puts the label's text in the window's picture.
