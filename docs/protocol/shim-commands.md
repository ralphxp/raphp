# The shim's command protocol (PHP to shim), ABI version 0.4

Step P3.3. This is how PHP describes windows and widgets to the shim, what the shim answers with, and the written contract for the order of things (R15). The functions that carry it are in `shim-abi.md`. The golden cases that pin it are in `golden/shim-commands.json`; a test sends each case through the real C functions and compares every event.

PHP **describes**; the shim owns the widget tree. Nothing here draws: drawing, layout and the real window arrive in P3.4 to P3.8. What exists now is the model: the tree, its rules, and the events that report what happened.

## Sending

`raphp_send(shim, batch, len)` takes one **batch**, UTF-8 JSON, in one of two forms:

```json
{"batch": 7, "commands": [ {"op": "widget.create", "id": 1, "type": "label"}, ... ]}
```
```json
[ {"op": "widget.create", "id": 1, "type": "label"} ]
```

The object form is the one PHP uses: `batch` is a number PHP chooses (0 to 2^53-1; it should go up, but the shim only echoes it). The array form is for tests and tools; it has no id.

`raphp_send` returns at once. It checks only that the text is a batch, and refuses one that is not, with `RAPHP_STATUS_INVALID_ARGUMENT` and the reason in `raphp_last_error`: not valid JSON, not an array or an object, no `batch` or `commands`, an unknown field in the batch object, more than **8 MiB** or **100,000 commands**. Such a batch is not applied at all. Everything inside the commands is checked later, by the UI thread, one command at a time, and reported as events.

The UI thread applies batches **in the order they were sent**, and the commands of a batch in order. If the shim has stopped taking commands (after `app.quit`, or when its UI thread has ended) `raphp_send` returns `RAPHP_STATUS_CLOSED`.

## What a command is

An object with `"op"` and the fields the operation takes. **A field the operation does not know is an error** (`unknown_field`), not ignored, so a misspelling is found at once.

**Ids** are integers from 1 to 2^53-1, chosen by PHP, in two separate series (windows, widgets). **They only go up and are never reused**: a new window or widget id must be higher than every id of that kind created before, even one that was destroyed. This is what makes a stale event harmless (see the ordering contract): an id in an event never means a different widget than when the event was made. A PHP counter does it.

| Operation | Fields | What it does |
|---|---|---|
| `window.create` | `id`, `title`?, `width`?, `height`? | Creates a window (defaults: title empty, 800 by 600). Refused with `limit` if its picture would not fit the headless budget. It asks for its first frame. |
| `window.set` | `id`, at least one of `title`, `width`, `height` | Changes only what is named. A new size makes a new picture (refused with `limit`, changing nothing, if it would not fit the budget) and asks for a frame; a new title does not. |
| `window.set_root` | `id`, `widget` (an id, or `null`) | Makes a widget the window's root. The widget must have no parent and not be a root already. The window must have no root: clear it first with `null`, which leaves the widget alive and parentless. |
| `window.destroy` | `id` | Destroys the window **and its tree**. |
| `widget.create` | `id`, `type`, `props`? | Creates a widget with no parent. Every property is checked first: if one is bad, nothing is created. |
| `widget.set` | `id`, `props`, `base_rev`? | Sets properties; `null` resets one to its default. All are checked first: if one is bad, none is applied. |
| `widget.append` | `parent`, `child` | Adds the child at the end of the parent's children. |
| `widget.insert` | `parent`, `child`, `index` | Adds it at `index` (0 to the number of children; the end is allowed, beyond is `bad_index`). |
| `widget.remove` | `id` | Detaches the widget from its parent. It stays alive, parentless. |
| `widget.destroy` | `id` | Destroys the widget and **everything below it**, and detaches it from its parent and its window. |
| `app.quit` | none | Ends the UI loop. The commands after it in the batch are not applied. |

For `append` and `insert` the child must exist, have no parent and not be a window's root; the parent must be a container; the child must not be the parent or one of its ancestors (`cycle`); a scroll view takes one child; and the tree may not become deeper than 64 levels counting the child's own subtree (`limit`).

### Widget types and their properties

The set the MVP needs (P3.10 draws them; P3.11 and P3.13 add text editing and the canvas). A property not in the table is `unknown_prop`.

| Type | Container | Properties |
|---|---|---|
| `label` | no | `text` (string) |
| `button` | no | `label` (string), `enabled` (bool) |
| `progress_bar` | no | `value` (number, 0 to 1) |
| `column`, `row` | yes | `gap`, `padding` (numbers, 0 to 10,000) |
| `scroll_view` | yes, one child | none yet |
| `checkbox` | no | `label` (string), `checked`, `enabled` (bool) |
| `text_input` | no | `text`, `placeholder` (strings), `enabled` (bool) |

A string is at most 64 KiB; a window title at most 1 KiB; a window size is above 0 and at most 100,000.

## What the shim answers with: events

Everything comes back through the event queue (`shim-abi.md`), as discrete events, in the order things happened.

**`error`**: one for each command that was refused. The command changed nothing, and **the next command is still applied**.

```json
{"type": "error", "batch": 7, "index": 2, "op": "widget.set", "code": "unknown_widget", "message": "...", "target": 99}
```

`batch` is the batch's id (`null` for an array batch), `index` is the command's place in it (from 0), `op` is its operation if it had one (cut at 64 characters), `target` is the id the error is about when there is one, and `message` is for people and may be reworded. The `code` is stable:

| Code | Meaning |
|---|---|
| `unknown_op`, `unknown_field` | No such operation; a field the operation does not take. |
| `missing_field`, `bad_field` | A required field is absent; a field has the wrong type or range (an id that is not an integer from 1, a size out of range). |
| `unknown_window`, `unknown_widget` | No window or widget has that id (never created, or destroyed). |
| `id_reused` | The id is not higher than every id of its kind so far. |
| `unknown_type`, `unknown_prop`, `bad_prop` | No such widget type; no such property for the type; the value does not fit (type, range, length). |
| `cycle` | The widget would become its own ancestor. |
| `not_a_container`, `has_parent`, `no_parent`, `already_root`, `window_has_root` | The structure rules above. |
| `bad_index` | `insert` beyond the end. |
| `limit` | Too many widgets (100,000), windows (64), nesting (64), or children of a scroll view (1), or (headless) pictures over the memory budget. |
| `backend` | The window system could not do it (the window backend could not make a window). |
| `stale_text` | A text set built on an old revision (see below). |

**`batch.done`**: one at the end of every **object-form** batch.

```json
{"type": "batch.done", "batch": 7, "applied": 9, "failed": 1}
```

It marks the moment the batch's commands took effect (see the contract).

**`app.quit`**: `{"type": "app.quit"}`, when the command was applied. The UI loop then ends in order (this is not a failure), and later `raphp_send` calls return `CLOSED`. Events can still be read.

**Window events** (window backend only, P3.6; details in `shim-abi.md`): `{"type":"window.close_requested","window":1}` (the user asked to close: nothing is closed until PHP sends `window.destroy`), `{"type":"window.focus","window":1,"focused":true}`, `{"type":"window.resized","window":1,"width":480.0,"height":320.0,"scale":1.0}` (logical pixels; continuous, so only the latest is kept) and `{"type":"window.scale","window":1,"scale":2.0}`. After `window.destroy` no event about that window is queued (rule 4 below).

## The ordering contract (R15)

The events PHP reads and the commands PHP sends race: the user clicks while PHP is sending a batch. These rules make the race harmless.

1. **Commands are applied in the order they were sent, and one at a time.** There is one UI thread; the batches come to it through one queue.
2. **Events are in the order they were produced.** The queue keeps that order; the only thing it ever merges is a continuous event with an earlier one of the same kind when no discrete event is between them (`shim-abi.md`), and `batch.done` is a discrete event, so no merge crosses it.
3. **`batch.done` marks the moment a batch took effect.** Every event before it in the queue was produced against the tree as it was before that batch, and every event after it against the tree after. So a click on a widget that the batch destroys arrives *before* the batch's `batch.done`, and PHP can tell it is about a widget that is now gone and ignore it. (Tested: a click queued before a destroy comes before the done event.) An array batch has no `batch.done`; PHP that cares about this order uses the object form.
4. **After `widget.destroy` the shim emits no event about that widget.** Every source of widget events asks the model first, on the UI thread (`Model::emit_widget_event`), so no event with that id is queued after the destroy. Events queued earlier stay queued, and rule 3 tells PHP where the destroy took effect. Because ids are never reused, an old event can never be mistaken for one about a newer widget. P3.9 uses this for the input events and tests it.
5. **A command error can always be tied to its batch.** The error event carries the batch id and the command's index, and the batch ends with `batch.done` with the counts. A failed command does not stop the batch: PHP learns which commands did not apply, and a later command that depended on one will fail too and say so.
6. **A re-render cannot overwrite what the user typed.** A text input's text can change under PHP's feet (the user types). The shim counts the user's changes in a revision number, which the text events will carry (built with typing in P3.11). A `widget.set` that sets `text` may carry `base_rev`, the revision of the last text event PHP has seen. If the user has typed since, the shim refuses the set with `stale_text` and PHP's older view does not replace the user's text. A set without `base_rev` always applies. The revision logic is built and tested now (with a stand-in for the user's typing); the events that carry it come with P3.11. PHP's own sets do not change the revision: PHP already knows them.

## The diagnostic call

`size_t raphp_diagnostics(const RaphpShim*, char* out, size_t cap)` copies one line of JSON into `out` (at most `cap` bytes, never cutting a character) and returns its **full** length, like `raphp_last_error`. It is read-only, works on a poisoned shim and never waits for the UI thread (the counters are shared). A null handle gives 0.

```json
{"batches": 3, "commands_applied": 41, "commands_failed": 2, "widgets": 12,
 "windows": {"1": {"redraws_requested": 0, "frames_drawn": 0}}}
```

`redraws_requested` counts the batches that left the window needing a frame, and `frames_drawn` the frames drawn (see "Frame pacing" in `shim-abi.md`); P3.6 and P3.10 use them to test that an idle window draws nothing (F8). `redraws_requested` is exact once a batch's `batch.done` has been read; `frames_drawn` needs the headless barrier first.

## Limits (all my numbers, unmeasured)

| | |
|---|---|
| Batch | 8 MiB, 100,000 commands |
| Widgets alive | 100,000 |
| Windows alive | 64 |
| Nesting depth | 64 levels |
| Text property | 64 KiB; window title 1 KiB |

## The golden files

`docs/protocol/golden/shim-commands.json` holds eight cases: an empty batch, a window with a small tree, errors reported one by one, ids that never go back, the structure rules, destroying widgets and windows, an anonymous batch, and `app.quit`. Each lists its batches, the exact events expected (without the wording of `message`) and the diagnostics afterwards. The test `the_golden_cases_produce_exactly_the_documented_events_and_counters` runs them against a live shim.
