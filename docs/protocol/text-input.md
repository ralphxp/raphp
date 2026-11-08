# Text input (step P3.11)

How a `text_input` is edited. The shim does the editing **itself, at once**: typing, the caret, the selection and the clipboard keys need no round trip to PHP. PHP is told what changed and answers, as everywhere, by describing properties (`text`). The code is `shim/raphp/src/edit.rs` (the editor, pure), `input/editing.rs` (what the interaction state does for a focused input), `clipboard.rs`, and the drawing in `draw.rs`.

## The editor (`edit.rs`)

One line of text with a **caret** and an **anchor** (the other end of the selection; equal when nothing is selected), both byte offsets that always stand **between grapheme clusters**: what a person sees as one character. An accented letter written as `e` and a combining mark, a flag, a family of emoji are one step for the caret and one press of Backspace or Delete. The text stays valid UTF-8 whatever is done to it.

| Key | Does |
|---|---|
| Left, Right | one cluster; with Shift extends the selection; with a selection and no Shift, goes to its near end |
| Ctrl+Left, Ctrl+Right | one word (a word is letters, digits and `_`); skips what is between words first |
| Home, End | start and end; with Shift selects to there |
| Backspace, Delete | the selection, else one cluster before or after the caret; with Ctrl one word |
| Ctrl+A | select all |
| Ctrl+C, Ctrl+X, Ctrl+V | copy, cut, paste (see the clipboard below) |
| Enter | submits (see `text.submitted`); not a character |
| a character typed | replaces the selection, or goes in at the caret |

**One line.** Line breaks and other control characters are dropped from what is typed or pasted. **At most 64 KiB** of text: what does not fit is cut off at a character. There is no undo and no redo yet.

**Focus.** An input given the focus by Tab, or by PHP (`widget.focus`), has **all its text selected**; a click puts the caret where it landed instead. Shift-click extends the selection. A drag with the left button selects from where it began to where the pointer is, even outside the input. A second click on the same place within 0.4 s selects the word.

**The caret** is a solid line (it does not blink: a blinking caret would redraw an idle window for ever, F8). When the text is longer than the input, it is shifted sideways so that the caret is in view; it shifts back when the caret goes home.

## Events to PHP

Both are about the widget and go through the usual check, so none is queued for a destroyed widget.

- **`text.changed`** `{window, widget, text, revision, caret, anchor}`: the user changed the text. `text` is the whole text; `revision` counts the user's changes since PHP last set it; `caret` and `anchor` are **byte offsets** into `text`. A change of the caret or selection alone is not reported (PHP has no use for it; `text.changed` says where they are after a change).
- **`text.submitted`** `{window, widget, text, revision}`: Enter was pressed (once, not when held).

Every key also still arrives as `key.down` and `key.up` (`input.md`), before the `text.changed` it caused.

## The revision (R15, rule 6)

PHP describes the UI from state, and the user types in between. So that a re-render built from older state cannot overwrite what was typed, PHP sends `widget.set` with `text` and **`base_rev`**, the revision of the last `text.changed` (or `text.submitted`) it has seen. If the user has changed the text since, the shim refuses with the error `stale_text` and keeps the user's text. A set without `base_rev` always applies. After PHP replaces the text, the caret stays where it was if that is a valid place in the new text, and is moved to the nearest one if not.

## The clipboard

Copy and cut put the selection on the clipboard; paste takes text from it (one line, within the limit).

| System | Clipboard |
|---|---|
| Wayland | the **system** clipboard, through `smithay-clipboard` on winit's own connection to the compositor (`arboard` does not work on GNOME, which offers no data-control protocol: F7). Built; **needs your check** with another application |
| X11 | **inside the shim only**: copy and paste work between the text inputs of the application and not with other applications. A system route for X11 (a crate such as `x11-clipboard`) is a decision for later; it was not tested in Phase 0 either |
| headless | in the shim's memory (a test reads it) |

## Input methods (IME)

While a text input has the focus in a real window, the shim asks winit to run an input method (`set_ime_allowed`) and tells it where the caret is (`set_ime_cursor_area`), so that the candidate list comes up next to it. What the input method is composing (`Ime::Preedit`) is **drawn inline at the caret, underlined, as if it were part of the text, and is not part of the text**: PHP hears nothing until the input method commits (`Ime::Commit`), which is then typed text and gives a `text.changed`. Dead keys and the compose key reach the shim as already composed characters.

**Known limits, to be written down after the on-screen check (P3.11a, R8):** whether it works with every input method on GNOME/Wayland (IBus, Fcitx) has not been verified; the input method's own caret and selection inside the composition are drawn only as a caret; text is not re-converted. X11 input methods go through winit's XIM support and have not been tried.

## Commands for tests

`input.simulate` takes two more kinds: `{"kind": "text", "text": "é"}` (typed or committed text) and `{"kind": "preedit", "text": "ni"}` (composing; empty text ends it). The editing keys are the usual `key.down` events.
