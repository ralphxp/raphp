//! What the interaction state does for a text input that has the focus (plan P3.11b): keys that move the caret and
//! change the text, typed text, a click that places the caret, a drag that selects, and the clipboard keys.
//!
//! The text is the model's (`props.text`); the caret, the selection and how far the text is scrolled sideways are the
//! interaction state's, kept for the focused input only. The user's change is applied **in the shim, at once**, with
//! no round trip: `Model::user_set_text` makes the change and counts it (the text revision), and PHP is told with
//! `text.changed`, which carries the whole text, the revision and the caret and anchor as byte offsets. A value PHP
//! sends later with the revision it saw before is refused if the user has typed since (R15 rule 6).

use unicode_segmentation::UnicodeSegmentation;

use super::{
    Event, EventSink, Interaction, KeyPress, Model, Reaction, Rect, TextMetrics, Value, WidgetKind,
    is_enabled, json, kind_of,
};
use crate::draw::theme;
use crate::edit::Editor;
use crate::protocol::MAX_TEXT_BYTES;

/// Where the caret and the selection of the focused text input are, and how far its text is scrolled sideways.
#[derive(Clone, Debug, Default, PartialEq)]
pub(crate) struct Cursor {
    /// Byte offsets into the text, between grapheme clusters.
    pub caret: usize,
    pub anchor: usize,
    /// How far the text is shifted left so that the caret is in view, in logical pixels.
    pub scroll: f32,
    /// Where the text starts on the window, from the last press (a drag measures from it).
    text_left: f32,
    /// The left button went down on this input and is still held: moving the pointer extends the selection.
    dragging: bool,
    /// Text an input method is composing at the caret, and where its own caret is in it.
    pub preedit: Option<(String, Option<(usize, usize)>)>,
}

/// A second press this soon after one, near it and on the same widget, is a double click (my number).
const DOUBLE_CLICK_MILLIS: u128 = 400;

/// The byte offset of the grapheme boundary nearest to a horizontal position measured from the start of the text.
fn index_at(text: &str, x: f32, ruler: &dyn TextMetrics) -> usize {
    if x <= 0.0 || text.is_empty() {
        return 0;
    }

    let bounds: Vec<usize> = text
        .grapheme_indices(true)
        .map(|(index, _)| index)
        .chain(std::iter::once(text.len()))
        .collect();
    let width = |at: usize| ruler.text_size(&text[..bounds[at]]).0;

    // The last boundary whose text fits before the position, found by halving: the widths only grow.
    let (mut low, mut high) = (0, bounds.len() - 1);
    while low < high {
        let middle = (low + high).div_ceil(2);
        if width(middle) <= x {
            low = middle;
        } else {
            high = middle - 1;
        }
    }
    if low + 1 < bounds.len() && x - width(low) > width(low + 1) - x {
        low += 1; // the next boundary is nearer
    }

    bounds[low]
}

impl Interaction {
    /// The caret and selection of a text input that has them (the focused one).
    pub fn cursor(&self, widget: u64) -> Option<&Cursor> {
        self.cursors.get(&widget)
    }

    /// The focused widget of a window, if it is an enabled text input.
    fn editing_widget(&self, window: u64, model: &Model) -> Option<u64> {
        let widget = self.focus(window)?;

        (kind_of(model, widget) == Some(WidgetKind::TextInput) && is_enabled(model, widget))
            .then_some(widget)
    }

    /// The text of an input with the caret and selection it has, made valid for the text as it is now.
    fn editor(&self, model: &Model, widget: u64) -> Option<Editor> {
        let view = model.widget_view(widget)?;
        let text = match view.props.get("text") {
            Some(Value::String(text)) => text.as_str(),
            _ => "",
        };
        let (caret, anchor) = self
            .cursors
            .get(&widget)
            .map_or((text.len(), text.len()), |cursor| {
                (cursor.caret, cursor.anchor)
            });
        let mut editor = Editor {
            text: text.to_owned(),
            caret,
            anchor,
        };
        editor.clamp();

        Some(editor)
    }

    /// A text input has just been given the focus by the keyboard or by PHP: all its text is selected, as in most
    /// toolkits (a click puts the caret where it landed instead).
    pub(super) fn begin_edit(&mut self, widget: u64, model: &Model) {
        if kind_of(model, widget) != Some(WidgetKind::TextInput) {
            return;
        }
        if let Some(mut editor) = self.editor(model, widget) {
            editor.select_all();
            self.cursors.insert(
                widget,
                Cursor {
                    caret: editor.caret,
                    anchor: editor.anchor,
                    ..Cursor::default()
                },
            );
        }
    }

    /// The rectangle of a widget in its own layout (the window's or its overlay's), whether or not it is on view.
    fn widget_rect(
        &mut self,
        model: &Model,
        window: u64,
        ruler: &dyn TextMetrics,
        widget: u64,
    ) -> Option<Rect> {
        if let Some(rect) = self
            .layout(model, window, ruler)
            .and_then(|layout| layout.rect(widget))
        {
            return Some(rect);
        }
        let top = model.top_of(widget)?;

        self.overlay_layout(model, top, ruler)?.rect(widget)
    }

    /// How far the text of an input must be scrolled for the caret to be in view.
    fn scroll_to_show(
        &mut self,
        model: &Model,
        window: u64,
        widget: u64,
        editor: &Editor,
        current: f32,
        ruler: &dyn TextMetrics,
    ) -> f32 {
        let Some(rect) = self.widget_rect(model, window, ruler, widget) else {
            return current;
        };
        let room = (rect.width - 2.0 * theme::INPUT_PADDING).max(0.0);
        let total = ruler.text_size(&editor.text).0;
        if total <= room {
            return 0.0;
        }

        let furthest = total - room;
        let mut scroll = current.clamp(0.0, furthest);
        let caret = ruler.text_size(&editor.text[..editor.caret]).0;
        if caret - scroll > room {
            scroll = caret - room;
        } else if caret < scroll {
            scroll = caret;
        }

        scroll.clamp(0.0, furthest)
    }

    /// Stores the editor's caret and selection for the input; if the text changed, has the model make the change and
    /// tells PHP.
    fn commit(
        &mut self,
        target: (u64, u64),
        editor: &Editor,
        changed: bool,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let (window, widget) = target;
        let revision = if changed {
            let Some(revision) = model.user_set_text(widget, &editor.text) else {
                return Reaction::default();
            };

            Some(revision)
        } else {
            None
        };

        let before = self.cursors.get(&widget).cloned();
        let mut cursor = before.clone().unwrap_or_default();
        cursor.caret = editor.caret;
        cursor.anchor = editor.anchor;
        cursor.scroll = self.scroll_to_show(model, window, widget, editor, cursor.scroll, ruler);
        let moved = before.as_ref() != Some(&cursor);
        self.cursors.insert(widget, cursor);

        if let Some(revision) = revision {
            model.emit_widget_event(
                widget,
                Event::discrete(
                    json!({
                        "type": "text.changed", "window": window, "widget": widget, "text": editor.text,
                        "revision": revision, "caret": editor.caret, "anchor": editor.anchor,
                    })
                    .to_string(),
                ),
                sink,
            );
        }

        Reaction {
            redraw: changed || moved,
        }
    }

    /// A key going down while a text input has the focus: moves, deletes, selects, copies, cuts, pastes, submits.
    pub(super) fn edit_key(
        &mut self,
        window: u64,
        press: &KeyPress,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        if !press.down {
            return Reaction::default();
        }
        let Some(widget) = self.editing_widget(window, model) else {
            return Reaction::default();
        };
        let Some(mut editor) = self.editor(model, widget) else {
            return Reaction::default();
        };

        let (shift, ctrl) = (self.modifiers.shift, self.modifiers.ctrl);
        let letter = |wanted: char| {
            ctrl && (press.key.chars().eq(std::iter::once(wanted))
                || press
                    .key
                    .chars()
                    .eq(std::iter::once(wanted.to_ascii_uppercase())))
        };
        let mut changed = false;
        match press.key.as_str() {
            "ArrowLeft" => editor.move_left(shift, ctrl),
            "ArrowRight" => editor.move_right(shift, ctrl),
            "Home" => editor.home(shift),
            "End" => editor.end(shift),
            "Backspace" => changed = editor.backspace(ctrl),
            "Delete" => changed = editor.delete(ctrl),
            "Enter" => {
                if !press.repeat {
                    let revision = model
                        .widget_view(widget)
                        .map_or(0, |view| view.text_revision);
                    model.emit_widget_event(
                        widget,
                        Event::discrete(
                            json!({
                                "type": "text.submitted", "window": window, "widget": widget,
                                "text": editor.text, "revision": revision,
                            })
                            .to_string(),
                        ),
                        sink,
                    );
                }

                return Reaction::default();
            }
            _ if letter('a') => editor.select_all(),
            _ if letter('c') => {
                if editor.has_selection() {
                    self.clipboard.set(editor.selected());
                }

                return Reaction::default();
            }
            _ if letter('x') => {
                if let Some(cut) = editor.cut() {
                    self.clipboard.set(&cut);
                    changed = true;
                }
            }
            _ if letter('v') => {
                if let Some(pasted) = self.clipboard.get() {
                    changed = editor.insert(&pasted, MAX_TEXT_BYTES);
                }
            }
            _ => return Reaction::default(),
        }

        self.commit((window, widget), &editor, changed, model, ruler, sink)
    }

    /// An input method is composing text (or has stopped): it is kept to be drawn at the caret, underlined, and is not
    /// part of the text until it is committed.
    pub(super) fn compose(
        &mut self,
        window: u64,
        text: &str,
        cursor: Option<(usize, usize)>,
        model: &Model,
    ) -> Reaction {
        let Some(widget) = self.editing_widget(window, model) else {
            return Reaction::default();
        };
        let entry = self.cursors.entry(widget).or_default();
        let preedit = (!text.is_empty()).then(|| (text.to_owned(), cursor));
        let changed = entry.preedit != preedit;
        entry.preedit = preedit;

        Reaction { redraw: changed }
    }

    /// Whether the focused widget of a window takes text, so that the window system should run an input method for it.
    pub fn wants_text(&self, window: u64, model: &Model) -> bool {
        self.editing_widget(window, model).is_some()
    }

    /// Where the caret of the focused text input is on its window, for the input method to place its candidate list
    /// next to it: `(x, y, height)` in logical pixels.
    pub fn caret_area(
        &mut self,
        window: u64,
        model: &Model,
        ruler: &dyn TextMetrics,
    ) -> Option<(f32, f32, f32)> {
        let widget = self.editing_widget(window, model)?;
        let shown = self.shown_rect(model, window, ruler, widget)?;
        let editor = self.editor(model, widget)?;
        let cursor = self.cursors.get(&widget).cloned().unwrap_or_default();
        let before = ruler.text_size(&editor.text[..editor.caret]).0;
        let x = shown.x + theme::INPUT_PADDING + before - cursor.scroll;

        Some((x, shown.y, shown.height))
    }

    /// Replaces the clipboard text inputs use (the window backend puts the system's there where it can).
    pub fn set_clipboard(&mut self, clipboard: Box<dyn crate::clipboard::Clipboard>) {
        self.clipboard = clipboard;
    }

    /// Where a widget is on its window as shown: in the window's layout shifted by the scroll views around it, or in
    /// the layout of the overlay it is in, at the place the overlay was put.
    fn shown_rect(
        &mut self,
        model: &Model,
        window: u64,
        ruler: &dyn TextMetrics,
        widget: u64,
    ) -> Option<Rect> {
        let scroll = self.scroll.clone();
        let top = model.top_of(widget)?;
        let size = model
            .window_content(window)
            .map_or((0.0, 0.0), |(w, h, _)| (w as f32, h as f32));
        if let Some((_, _, Some(root))) = model.window_content(window)
            && root == top
        {
            let layout = self.layout(model, window, ruler)?;

            return super::shown_in(model, layout, &scroll, root, widget, (0.0, 0.0));
        }

        let overlay = self
            .overlays(window)
            .iter()
            .find(|o| o.widget == top)
            .copied()?;
        let window_root = model.window_content(window).and_then(|(_, _, root)| root);
        let window_layout = if overlay.anchor.is_some() {
            self.layout(model, window, ruler).cloned()
        } else {
            None
        };
        let layout = self.overlay_layout(model, overlay.widget, ruler)?;
        let own = layout.rect(overlay.widget)?;
        let origin = super::overlay_place(
            model,
            window_layout.as_ref(),
            window_root,
            &scroll,
            size,
            &overlay,
            (own.width, own.height),
        );

        super::shown_in(model, layout, &scroll, overlay.widget, widget, origin)
    }

    /// Text typed (or committed by an input method) while a text input has the focus.
    pub(super) fn typed(
        &mut self,
        window: u64,
        text: &str,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let Some(widget) = self.editing_widget(window, model) else {
            return Reaction::default();
        };
        let Some(mut editor) = self.editor(model, widget) else {
            return Reaction::default();
        };
        let changed = editor.insert(text, MAX_TEXT_BYTES);
        // A commit ends the composition it came from.
        if let Some(cursor) = self.cursors.get_mut(&widget) {
            cursor.preedit = None;
        }

        self.commit((window, widget), &editor, changed, model, ruler, sink)
    }

    /// The left button went down: if it landed on the text input that now has the focus, the caret goes there (a
    /// shift extends the selection, a second press soon after selects the word).
    pub(super) fn text_pointer_down(
        &mut self,
        window: u64,
        point: (f32, f32),
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let Some(widget) = self.editing_widget(window, model) else {
            return Reaction::default();
        };
        let Some((hit, rect)) = self.last_hit else {
            return Reaction::default();
        };
        if hit != widget {
            return Reaction::default();
        }
        let Some(mut editor) = self.editor(model, widget) else {
            return Reaction::default();
        };

        let scroll = self
            .cursors
            .get(&widget)
            .map_or(0.0, |cursor| cursor.scroll);
        let left = rect.x + theme::INPUT_PADDING;
        let index = index_at(&editor.text, point.0 - left + scroll, ruler);
        let now = self.now;
        let double = self.last_click.is_some_and(|(when, at, clicked)| {
            clicked == widget
                && now.saturating_sub(when).as_millis() <= DOUBLE_CLICK_MILLIS
                && (at.0 - point.0).abs() <= 4.0
                && (at.1 - point.1).abs() <= 4.0
        });
        if double {
            editor.select_word_at(index);
            self.last_click = None;
        } else {
            editor.set_caret(index, self.modifiers.shift);
            self.last_click = Some((now, point, widget));
        }

        let reaction = self.commit((window, widget), &editor, false, model, ruler, sink);
        if let Some(cursor) = self.cursors.get_mut(&widget) {
            cursor.text_left = left;
            cursor.dragging = !double;
        }

        Reaction { redraw: true }.or(reaction)
    }

    /// The pointer moved while the left button is held on the focused text input: the selection follows it.
    pub(super) fn text_pointer_move(
        &mut self,
        window: u64,
        point: (f32, f32),
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let Some(widget) = self.editing_widget(window, model) else {
            return Reaction::default();
        };
        let Some(cursor) = self.cursors.get(&widget).cloned() else {
            return Reaction::default();
        };
        if !cursor.dragging {
            return Reaction::default();
        }
        let Some(mut editor) = self.editor(model, widget) else {
            return Reaction::default();
        };

        let index = index_at(
            &editor.text,
            point.0 - cursor.text_left + cursor.scroll,
            ruler,
        );
        editor.set_caret(index, true);

        self.commit((window, widget), &editor, false, model, ruler, sink)
    }

    /// The button came up: the drag is over.
    pub(super) fn text_pointer_up(&mut self, window: u64) {
        if let Some(widget) = self.focus(window)
            && let Some(cursor) = self.cursors.get_mut(&widget)
        {
            cursor.dragging = false;
        }
    }
}
