//! Input and focus (plan P3.9): what the user does to a window, and what the shim makes of it.
//!
//! Real windows feed this from winit; the headless backend is fed through the command `input.simulate`; the rules
//! are the same, so everything here is tested without a screen. The rules are in `docs/protocol/input.md`.
//!
//! Hit testing uses the layout (`layout.rs`) and the scroll offsets, and respects the clip of a scroll view: a point
//! outside a scroll view cannot hit what it holds, even where that overflows. Hover, pressed and focus are state of
//! the shim, not of PHP: PHP hears of clicks, keys, focus changes and scrolling as events and describes the result
//! (declaratively) as properties. Every event about a widget goes through `Model::emit_widget_event`, so none is
//! queued about a widget that is gone (R15).

use std::collections::HashMap;
use std::time::Duration;

use serde_json::{Map, Value, json};

use crate::clipboard::{Clipboard, MemoryClipboard};
use crate::draw::{ScrollOffsets, scroll_shift};
use crate::events::{ContinuousKind, Event};
use crate::layout::{Layout, Rect, TextMetrics, layout_overlay, layout_window, overlay_origin};
use crate::model::{EventSink, Model, Request, WidgetKind};

/// How long a widget takes to fade into its hovered look and out of it (my number).
pub(crate) const HOVER_FADE: Duration = Duration::from_millis(120);

mod editing;

pub(crate) use editing::Cursor;

/// How many logical pixels one line of a mouse wheel scrolls (my number).
pub(crate) const WHEEL_LINE: f32 = 40.0;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum Button {
    Left,
    Middle,
    Right,
    Other,
}

impl Button {
    fn name(self) -> &'static str {
        match self {
            Self::Left => "left",
            Self::Middle => "middle",
            Self::Right => "right",
            Self::Other => "other",
        }
    }

    fn from_name(name: &str) -> Option<Self> {
        Some(match name {
            "left" => Self::Left,
            "middle" => Self::Middle,
            "right" => Self::Right,
            "other" => Self::Other,
            _ => return None,
        })
    }
}

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub(crate) struct Modifiers {
    pub shift: bool,
    pub ctrl: bool,
    pub alt: bool,
    pub meta: bool,
}

/// One thing the user did, in logical pixels from the top left of the window's content.
#[derive(Clone, Debug, PartialEq)]
pub(crate) enum InputEvent {
    PointerMove {
        x: f32,
        y: f32,
    },
    PointerLeave,
    PointerDown {
        x: f32,
        y: f32,
        button: Button,
    },
    PointerUp {
        x: f32,
        y: f32,
        button: Button,
    },
    Scroll {
        x: f32,
        y: f32,
        dx: f32,
        dy: f32,
    },
    Key(KeyPress),
    /// Text typed, or committed by an input method: what goes into a text input that has the focus.
    Text(String),
    /// Text an input method is composing and has not committed: shown, underlined, at the caret, and not yet part of
    /// the text. Empty text ends the composition. `cursor` is where the input method's own caret is in it (bytes).
    Preedit {
        text: String,
        cursor: Option<(usize, usize)>,
    },
    Modifiers(Modifiers),
}

/// A key going down or coming up: its logical name ("a", "Enter", "ArrowLeft"), its physical code ("KeyA"), and whether
/// it is the keyboard repeating.
#[derive(Clone, Debug, PartialEq)]
pub(crate) struct KeyPress {
    pub key: String,
    pub code: String,
    pub down: bool,
    pub repeat: bool,
}

fn number(map: &Map<String, Value>, name: &str) -> Result<f32, String> {
    match map.get(name) {
        Some(Value::Number(number)) => number
            .as_f64()
            .filter(|value| value.is_finite())
            .map(|value| value as f32)
            .ok_or_else(|| format!("\"{name}\" must be a finite number")),
        _ => Err(format!("\"{name}\" must be a number")),
    }
}

fn text(map: &Map<String, Value>, name: &str) -> Result<String, String> {
    match map.get(name) {
        Some(Value::String(text)) if text.len() <= 64 => Ok(text.clone()),
        _ => Err(format!("\"{name}\" must be a string of at most 64 bytes")),
    }
}

fn flag(map: &Map<String, Value>, name: &str) -> Result<bool, String> {
    match map.get(name) {
        None => Ok(false),
        Some(Value::Bool(value)) => Ok(*value),
        Some(_) => Err(format!("\"{name}\" must be true or false")),
    }
}

/// Reads the `event` object of `input.simulate`: `{"kind": "pointer.move", "x": 1, "y": 2}` and the like.
pub(crate) fn parse_input(value: &Value) -> Result<InputEvent, String> {
    let Value::Object(map) = value else {
        return Err("\"event\" must be an object".to_owned());
    };
    let Some(Value::String(kind)) = map.get("kind") else {
        return Err("\"event\" needs a \"kind\"".to_owned());
    };
    let button = |map: &Map<String, Value>| -> Result<Button, String> {
        match map.get("button") {
            None => Ok(Button::Left),
            Some(Value::String(name)) => Button::from_name(name)
                .ok_or_else(|| "\"button\" must be left, middle, right or other".to_owned()),
            Some(_) => Err("\"button\" must be a string".to_owned()),
        }
    };

    Ok(match kind.as_str() {
        "pointer.move" => InputEvent::PointerMove {
            x: number(map, "x")?,
            y: number(map, "y")?,
        },
        "pointer.leave" => InputEvent::PointerLeave,
        "pointer.down" => InputEvent::PointerDown {
            x: number(map, "x")?,
            y: number(map, "y")?,
            button: button(map)?,
        },
        "pointer.up" => InputEvent::PointerUp {
            x: number(map, "x")?,
            y: number(map, "y")?,
            button: button(map)?,
        },
        "scroll" => InputEvent::Scroll {
            x: number(map, "x")?,
            y: number(map, "y")?,
            dx: number(map, "dx")?,
            dy: number(map, "dy")?,
        },
        "key.down" | "key.up" => InputEvent::Key(KeyPress {
            key: text(map, "key")?,
            code: map
                .get("code")
                .map_or(Ok(String::new()), |_| text(map, "code"))?,
            down: kind == "key.down",
            repeat: flag(map, "repeat")?,
        }),
        "text" => InputEvent::Text(match map.get("text") {
            Some(Value::String(text)) if text.len() <= 64 * 1024 => text.clone(),
            _ => return Err("\"text\" must be a string of at most 64 KiB".to_owned()),
        }),
        "preedit" => InputEvent::Preedit {
            text: match map.get("text") {
                Some(Value::String(text)) if text.len() <= 1024 => text.clone(),
                _ => return Err("\"text\" must be a string of at most 1 KiB".to_owned()),
            },
            cursor: None,
        },
        "modifiers" => InputEvent::Modifiers(Modifiers {
            shift: flag(map, "shift")?,
            ctrl: flag(map, "ctrl")?,
            alt: flag(map, "alt")?,
            meta: flag(map, "meta")?,
        }),
        other => {
            let shown: String = other.chars().take(64).collect();

            return Err(format!("there is no input event \"{shown}\""));
        }
    })
}

/// What handling an input event asks of the loop.
#[derive(Debug, Default, PartialEq, Eq)]
pub(crate) struct Reaction {
    /// The window's picture is now out of date: the loop asks for a frame (and counts the request).
    pub redraw: bool,
}

impl Reaction {
    /// Asks for a frame if either does.
    fn or(self, other: Self) -> Self {
        Self {
            redraw: self.redraw || other.redraw,
        }
    }
}

/// A widget's hover amount moving from one value to another since a moment, for the fade.
#[derive(Clone, Copy)]
struct Fade {
    from: f32,
    to: f32,
    start: Duration,
}

impl Fade {
    fn amount(self, now: Duration) -> f32 {
        let t = (now.saturating_sub(self.start).as_secs_f32() / HOVER_FADE.as_secs_f32())
            .clamp(0.0, 1.0);
        let eased = t * t * (3.0 - 2.0 * t); // smoothstep: gentle at both ends

        self.from + (self.to - self.from) * eased
    }

    fn finished(self, now: Duration) -> bool {
        now.saturating_sub(self.start) >= HOVER_FADE
    }
}

/// A widget tree shown above a window's content, where PHP asked for it (kept inside the window when it is drawn).
#[derive(Clone, Copy, Debug, PartialEq)]
pub(crate) struct Overlay {
    pub widget: u64,
    pub at: (f32, f32),
    /// A widget of the window the overlay is put under (a menu under its button), if it was asked for that way.
    pub anchor: Option<u64>,
}

#[derive(Default)]
struct WindowState {
    /// The button went down outside every overlay and dismissed one: its release must not be a click.
    swallow_up: bool,
    pointer: Option<(f32, f32)>,
    hover: Option<u64>,
    pressed: Option<(u64, Button)>,
    focus: Option<u64>,
}

/// The state of the user's interaction with the windows: where the pointer is, what is under it, what is held down,
/// what has the keyboard focus and how far each scroll view is scrolled.
pub(crate) struct Interaction {
    /// How far each scroll view is scrolled (drawing reads it).
    pub scroll: ScrollOffsets,
    windows: HashMap<u64, WindowState>,
    /// The time of the frame being worked on, set by the loop: animations are a function of it.
    now: Duration,
    /// Widgets whose hovered look is changing or is full.
    fades: HashMap<u64, Fade>,
    /// The overlays of each window, the one shown last on top.
    overlays: HashMap<u64, Vec<Overlay>>,
    /// The layout of each overlay's tree, by its widget, as of a revision of the model.
    overlay_layouts: HashMap<u64, (u64, Layout)>,
    /// The layout of each window as of a revision of the model, so that moving the pointer does not lay out again.
    layouts: HashMap<u64, (u64, Layout)>,
    modifiers: Modifiers,
    /// The caret and selection of the text input that has the focus.
    cursors: HashMap<u64, Cursor>,
    /// What text inputs copy to and paste from.
    clipboard: Box<dyn Clipboard>,
    /// The widget the last hit test found, and its rectangle as shown (shifted by scrolling, moved by an overlay).
    last_hit: Option<(u64, Rect)>,
    /// When and where the last click on a text input was, for the double click.
    last_click: Option<(Duration, (f32, f32), u64)>,
}

impl Default for Interaction {
    fn default() -> Self {
        Self {
            scroll: ScrollOffsets::new(),
            windows: HashMap::new(),
            now: Duration::ZERO,
            fades: HashMap::new(),
            overlays: HashMap::new(),
            overlay_layouts: HashMap::new(),
            layouts: HashMap::new(),
            modifiers: Modifiers::default(),
            cursors: HashMap::new(),
            clipboard: Box::new(MemoryClipboard::default()),
            last_hit: None,
            last_click: None,
        }
    }
}

fn is_enabled(model: &Model, id: u64) -> bool {
    model
        .widget_view(id)
        .is_some_and(|view| !matches!(view.props.get("enabled"), Some(Value::Bool(false))))
}

fn kind_of(model: &Model, id: u64) -> Option<WidgetKind> {
    model.widget_view(id).map(|view| view.kind)
}

/// Widgets that can be clicked and take the keyboard focus.
fn is_focusable(model: &Model, id: u64) -> bool {
    matches!(
        kind_of(model, id),
        Some(
            WidgetKind::Button
                | WidgetKind::Checkbox
                | WidgetKind::TextInput
                | WidgetKind::MenuItem
        )
    ) && is_enabled(model, id)
}

impl Interaction {
    pub fn new() -> Self {
        Self::default()
    }

    /// Tells the state what time it is (the loop does this before it handles input or draws a frame), so that
    /// fades start and run against the same clock the frames are drawn by.
    pub fn set_time(&mut self, now: Duration) {
        self.now = now;
        self.fades
            .retain(|_, fade| !(fade.to == 0.0 && fade.finished(now)));
    }

    /// The time of the frame being worked on.
    pub fn now(&self) -> Duration {
        self.now
    }

    /// How hovered a widget looks now: 0 for not at all, 1 for fully, fading between over [`HOVER_FADE`].
    pub fn hover_amount(&self, widget: u64) -> f32 {
        self.fades
            .get(&widget)
            .map_or(0.0, |fade| fade.amount(self.now))
    }

    /// Whether the window will look different at a later time with nothing else happening, so that the loop
    /// must ask for another frame: a hover that is still fading, or an indeterminate progress bar.
    pub fn animating(&self, model: &Model, window: u64) -> bool {
        let fading = self.fades.iter().any(|(widget, fade)| {
            !fade.finished(self.now) && model.window_of_widget(*widget) == Some(window)
        });

        fading || has_indeterminate(model, window)
    }

    /// Moves the hover of a window to a widget (or none), starting the fades. True if it changed.
    fn set_hover(&mut self, window: u64, hover: Option<u64>) -> bool {
        let state = self.state(window);
        let before = state.hover;
        if before == hover {
            return false;
        }
        state.hover = hover;

        let now = self.now;
        for (widget, to) in before
            .map(|id| (id, 0.0))
            .into_iter()
            .chain(hover.map(|id| (id, 1.0)))
        {
            let from = self.hover_amount(widget);
            self.fades.insert(
                widget,
                Fade {
                    from,
                    to,
                    start: now,
                },
            );
        }

        true
    }

    #[cfg(test)]
    pub fn hover(&self, window: u64) -> Option<u64> {
        self.windows.get(&window).and_then(|state| state.hover)
    }

    pub fn pressed(&self, window: u64) -> Option<u64> {
        self.windows
            .get(&window)
            .and_then(|state| state.pressed.map(|(widget, _)| widget))
    }

    pub fn focus(&self, window: u64) -> Option<u64> {
        self.windows.get(&window).and_then(|state| state.focus)
    }

    /// The overlays of a window, bottom to top.
    pub fn overlays(&self, window: u64) -> &[Overlay] {
        self.overlays.get(&window).map_or(&[], Vec::as_slice)
    }

    /// The window an overlay's tree is shown in, or a window's own tree: the window a widget belongs to, if any.
    fn window_of(&self, model: &Model, widget: u64) -> Option<u64> {
        model.window_of_widget(widget).or_else(|| {
            let top = model.top_of(widget)?;

            self.overlays
                .iter()
                .find(|(_, stack)| stack.iter().any(|overlay| overlay.widget == top))
                .map(|(window, _)| *window)
        })
    }

    /// The layout of an overlay's tree, worked out again only when the model has changed.
    fn overlay_layout(
        &mut self,
        model: &Model,
        widget: u64,
        ruler: &dyn TextMetrics,
    ) -> Option<&Layout> {
        let revision = model.revision();
        let stale = self
            .overlay_layouts
            .get(&widget)
            .is_none_or(|(known, _)| *known != revision);
        if stale {
            let layout = layout_overlay(model, widget, ruler)?;
            self.overlay_layouts.insert(widget, (revision, layout));
        }

        self.overlay_layouts.get(&widget).map(|(_, layout)| layout)
    }

    fn state(&mut self, window: u64) -> &mut WindowState {
        self.windows.entry(window).or_default()
    }

    fn layout(&mut self, model: &Model, window: u64, ruler: &dyn TextMetrics) -> Option<&Layout> {
        let revision = model.revision();
        let stale = self
            .layouts
            .get(&window)
            .is_none_or(|(known, _)| *known != revision);
        if stale {
            let layout = layout_window(model, window, ruler)?;
            self.layouts.insert(window, (revision, layout));
        }

        self.layouts.get(&window).map(|(_, layout)| layout)
    }

    /// The path from the root to the widget under the point, if any.
    fn path_at(
        &mut self,
        model: &Model,
        window: u64,
        ruler: &dyn TextMetrics,
        point: (f32, f32),
    ) -> Vec<u64> {
        let scroll = self.scroll.clone();
        self.last_hit = None;

        // While a window has overlays they have all the pointer: the topmost one the point is in wins, and a point
        // in none of them is on nothing (the content below is out of reach).
        let stack: Vec<Overlay> = self.overlays(window).to_vec();
        if !stack.is_empty() {
            // An overlay anchored to a widget needs where the widget is.
            let window_root = model.window_content(window).and_then(|(_, _, root)| root);
            let window_layout = if stack.iter().any(|overlay| overlay.anchor.is_some()) {
                self.layout(model, window, ruler).cloned()
            } else {
                None
            };
            let size = model
                .window_content(window)
                .map_or((0.0, 0.0), |(w, h, _)| (w as f32, h as f32));
            for overlay in stack.iter().rev() {
                let Some(layout) = self.overlay_layout(model, overlay.widget, ruler) else {
                    continue;
                };
                let Some(root) = layout.rect(overlay.widget) else {
                    continue;
                };
                let origin = overlay_place(
                    model,
                    window_layout.as_ref(),
                    window_root,
                    &scroll,
                    size,
                    overlay,
                    (root.width, root.height),
                );
                let mut path = Vec::new();
                let hitter = Hitter {
                    model,
                    layout,
                    scroll: &scroll,
                    point,
                    found: std::cell::Cell::new(None),
                };
                if hitter.find(overlay.widget, origin, None, &mut path) {
                    self.last_hit = path.last().copied().zip(hitter.found.get());

                    return path;
                }
            }

            return Vec::new();
        }

        let Some(layout) = self.layout(model, window, ruler) else {
            return Vec::new();
        };
        let Some((_, _, Some(root))) = model.window_content(window) else {
            return Vec::new();
        };

        let mut path = Vec::new();
        let hitter = Hitter {
            model,
            layout,
            scroll: &scroll,
            point,
            found: std::cell::Cell::new(None),
        };
        hitter.find(root, (0.0, 0.0), None, &mut path);
        self.last_hit = path.last().copied().zip(hitter.found.get());

        path
    }

    /// What is on the clipboard (the tests look at it).
    #[cfg(test)]
    pub fn clipboard_text(&mut self) -> Option<String> {
        self.clipboard.get()
    }

    /// The last place the pointer was in a window, if it is in it.
    pub fn pointer(&self, window: u64) -> Option<(f32, f32)> {
        self.windows.get(&window).and_then(|state| state.pointer)
    }

    /// Handles one input event of a window: updates the state, queues the events for PHP, and tells `redraw` the
    /// window if it needs a new frame. **`redraw` is called before the events are queued**, so that whoever reads an
    /// event finds the redraw it led to already counted (the rule of F57: shared state first, then the event).
    pub fn input(
        &mut self,
        window: u64,
        event: InputEvent,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
        redraw: &mut dyn FnMut(u64),
    ) {
        let mut buffered: Vec<Event> = Vec::new();
        let reaction = self.handle(window, event, model, ruler, &mut buffered);
        if reaction.redraw {
            redraw(window);
        }
        for event in buffered {
            sink.emit(event);
        }
    }

    /// Does what a command asked of the interaction state, with the same order of redraw and events as [`Self::input`].
    pub fn request(
        &mut self,
        request: Request,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
        redraw: &mut dyn FnMut(u64),
    ) {
        match request {
            Request::Input { window, event } => {
                self.input(window, event, model, ruler, sink, redraw)
            }
            Request::OverlayShow {
                window,
                widget,
                x,
                y,
                anchor,
            } => {
                let stack = self.overlays.entry(window).or_default();
                stack.retain(|overlay| overlay.widget != widget);
                stack.push(Overlay {
                    widget,
                    at: (x, y),
                    anchor,
                });
                redraw(window);
            }
            Request::OverlayHide { widget } => {
                for (window, stack) in &mut self.overlays {
                    let before = stack.len();
                    stack.retain(|overlay| overlay.widget != widget);
                    if stack.len() != before {
                        redraw(*window);
                    }
                }
            }
            Request::Focus { widget, window } => {
                let mut buffered: Vec<Event> = Vec::new();
                let reaction = self.request_focus(window, widget, model, &mut buffered);
                let affected = widget.and_then(|id| self.window_of(model, id)).or(window);
                if let (true, Some(window)) = (reaction.redraw, affected) {
                    redraw(window);
                }
                for event in buffered {
                    sink.emit(event);
                }
            }
        }
    }

    fn handle(
        &mut self,
        window: u64,
        event: InputEvent,
        model: &mut Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        if model.window_content(window).is_none() {
            return Reaction::default();
        }

        match event {
            InputEvent::Modifiers(modifiers) => {
                self.modifiers = modifiers;

                Reaction::default()
            }
            InputEvent::PointerMove { x, y } => {
                let moved = self.pointer_move(window, (x, y), model, ruler, sink);
                let selected = self.text_pointer_move(window, (x, y), model, ruler, sink);

                moved.or(selected)
            }
            InputEvent::PointerLeave => {
                self.state(window).pointer = None;
                let changed = self.set_hover(window, None);
                sink.emit(Event::discrete(
                    json!({"type": "pointer.leave", "window": window}).to_string(),
                ));

                Reaction { redraw: changed }
            }
            InputEvent::PointerDown { x, y, button } => {
                let pressed = self.pointer_down(window, (x, y), button, model, ruler, sink);
                let placed = if button == Button::Left {
                    self.text_pointer_down(window, (x, y), model, ruler, sink)
                } else {
                    Reaction::default()
                };

                pressed.or(placed)
            }
            InputEvent::PointerUp { x, y, button } => {
                self.text_pointer_up(window);

                self.pointer_up(window, (x, y), button, model, ruler, sink)
            }
            InputEvent::Scroll { x, y, dx, dy } => {
                self.scrolled(window, (x, y), (dx, dy), model, ruler, sink)
            }
            InputEvent::Key(press) => {
                let keyed = self.key(window, &press, model, sink);
                let edited = self.edit_key(window, &press, model, ruler, sink);

                keyed.or(edited)
            }
            InputEvent::Text(text) => self.typed(window, &text, model, ruler, sink),
            InputEvent::Preedit { text, cursor } => self.compose(window, &text, cursor, model),
        }
    }

    fn pointer_move(
        &mut self,
        window: u64,
        point: (f32, f32),
        model: &Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let path = self.path_at(model, window, ruler, point);
        let target = path.last().copied();
        let hover = target.filter(|id| is_focusable(model, *id));
        self.state(window).pointer = Some(point);
        let changed = self.set_hover(window, hover);

        sink.emit(Event::continuous(
            window,
            ContinuousKind::PointerMove,
            json!({"type": "pointer.move", "window": window, "x": point.0, "y": point.1, "target": target})
                .to_string(),
        ));

        Reaction { redraw: changed }
    }

    fn pointer_down(
        &mut self,
        window: u64,
        point: (f32, f32),
        button: Button,
        model: &Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let path = self.path_at(model, window, ruler, point);
        let target = path.last().copied();
        if path.is_empty() && !self.overlays(window).is_empty() {
            // A press outside every overlay dismisses the top one and is not a press on anything else; its release
            // is swallowed too.
            self.dismiss_top(window, "outside_click", sink);
            self.state(window).swallow_up = true;

            return Reaction { redraw: true };
        }

        sink.emit(Event::discrete(
            json!({
                "type": "pointer.down", "window": window, "x": point.0, "y": point.1,
                "button": button.name(), "target": target,
            })
            .to_string(),
        ));

        let Some(target) = target.filter(|id| is_focusable(model, *id)) else {
            return Reaction::default();
        };
        self.state(window).pressed = Some((target, button));
        if button == Button::Left {
            self.focus_widget(window, Some(target), sink);
        }

        Reaction { redraw: true }
    }

    fn pointer_up(
        &mut self,
        window: u64,
        point: (f32, f32),
        button: Button,
        model: &Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        if std::mem::take(&mut self.state(window).swallow_up) {
            return Reaction::default();
        }

        let target = self.path_at(model, window, ruler, point).last().copied();
        sink.emit(Event::discrete(
            json!({
                "type": "pointer.up", "window": window, "x": point.0, "y": point.1,
                "button": button.name(), "target": target,
            })
            .to_string(),
        ));

        let pressed = self.state(window).pressed.take();
        let mut closed = false;
        if let Some((widget, held)) = pressed
            && held == button
            && target == Some(widget)
            && is_enabled(model, widget)
        {
            model.emit_widget_event(
                widget,
                Event::discrete(
                    json!({
                        "type": "click", "window": window, "widget": widget,
                        "button": button.name(), "x": point.0, "y": point.1,
                    })
                    .to_string(),
                ),
                sink,
            );
            closed = self.close_menu_of(window, widget, model, sink);
        }

        Reaction {
            redraw: pressed.is_some() || closed,
        }
    }

    fn scrolled(
        &mut self,
        window: u64,
        point: (f32, f32),
        delta: (f32, f32),
        model: &Model,
        ruler: &dyn TextMetrics,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let path = self.path_at(model, window, ruler, point);
        let target = path.last().copied();

        // The innermost scroll view under the pointer that has somewhere to go in the direction asked.
        let views: Vec<(u64, Rect, (f32, f32))> = match self.layout(model, window, ruler) {
            Some(layout) => path
                .iter()
                .rev()
                .filter(|id| kind_of(model, **id) == Some(WidgetKind::ScrollView))
                .filter_map(|id| Some((*id, layout.rect(*id)?, layout.content_size(*id)?)))
                .collect(),
            None => Vec::new(),
        };
        let mut moved: Option<(u64, (f32, f32))> = None;
        for (id, rect, content) in &views {
            let (id, rect, content) = (*id, *rect, *content);
            let now = scroll_limited(
                rect,
                content,
                self.scroll.get(&id).copied().unwrap_or((0.0, 0.0)),
            );
            let wanted = scroll_limited(rect, content, (now.0 + delta.0, now.1 + delta.1));
            if wanted != now {
                self.scroll.insert(id, wanted);
                moved = Some((id, wanted));

                break;
            }
        }

        // A continuous event carries state, because a newer one replaces an older queued one: it names the scroll
        // view under the pointer and where that is now, whether or not this turn of the wheel moved it. (`dx` and
        // `dy` are what this turn asked for; they are lost when events are merged, the offset is not.)
        let reported = moved.or_else(|| {
            views.first().map(|(id, rect, content)| {
                let now = self.scroll.get(id).copied().unwrap_or((0.0, 0.0));

                (*id, scroll_limited(*rect, *content, now))
            })
        });
        sink.emit(Event::continuous(
            window,
            ContinuousKind::Scroll,
            json!({
                "type": "scroll", "window": window, "x": point.0, "y": point.1,
                "dx": delta.0, "dy": delta.1, "target": target,
                "view": reported.map(|(id, _)| id),
                "offset": reported.map(|(_, offset)| [offset.0, offset.1]),
            })
            .to_string(),
        ));

        Reaction {
            redraw: moved.is_some(),
        }
    }

    fn key(
        &mut self,
        window: u64,
        press: &KeyPress,
        model: &Model,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let KeyPress {
            key,
            code,
            down,
            repeat,
        } = press;
        let (down, repeat) = (*down, *repeat);
        if key == "Escape" && down && !self.overlays(window).is_empty() {
            self.dismiss_top(window, "escape", sink);

            return Reaction { redraw: true };
        }

        // In a menu the arrows go from item to item, as Tab does; a text input in the overlay keeps them for the caret.
        if matches!(key.as_str(), "ArrowDown" | "ArrowUp")
            && down
            && !self.overlays(window).is_empty()
            && self
                .focus(window)
                .is_none_or(|focused| kind_of(model, focused) != Some(WidgetKind::TextInput))
        {
            return self.move_focus(window, key == "ArrowUp", model, sink);
        }

        if key == "Tab" {
            if down {
                let backwards = self.modifiers.shift;

                return self.move_focus(window, backwards, model, sink);
            }

            return Reaction::default(); // Tab belongs to the focus: PHP is told of the change, not of the key
        }

        let focus = self.focus(window);
        let modifiers = self.modifiers;
        let kind = if down { "key.down" } else { "key.up" };
        let event = Event::discrete(
            json!({
                "type": kind, "window": window, "key": key, "code": code, "repeat": repeat,
                "shift": modifiers.shift, "ctrl": modifiers.ctrl, "alt": modifiers.alt, "meta": modifiers.meta,
                "target": focus,
            })
            .to_string(),
        );
        match focus {
            Some(widget) => {
                model.emit_widget_event(widget, event, sink);
            }
            None => sink.emit(event),
        }

        // Enter or Space on a button or checkbox is a click, as with the mouse.
        let activates = matches!(key.as_str(), "Enter" | " " | "Space");
        if down
            && !repeat
            && activates
            && let Some(widget) = focus
            && matches!(
                kind_of(model, widget),
                Some(WidgetKind::Button | WidgetKind::Checkbox | WidgetKind::MenuItem)
            )
            && is_enabled(model, widget)
        {
            model.emit_widget_event(
                widget,
                Event::discrete(
                    json!({"type": "click", "window": window, "widget": widget, "button": "keyboard"}).to_string(),
                ),
                sink,
            );

            return Reaction {
                redraw: self.close_menu_of(window, widget, model, sink),
            };
        }

        Reaction::default()
    }

    /// Choosing a menu item closes the menu it is in: the overlay is taken away and PHP is told why (after the click).
    /// True if an overlay was closed.
    fn close_menu_of(
        &mut self,
        window: u64,
        widget: u64,
        model: &Model,
        sink: &mut dyn EventSink,
    ) -> bool {
        if kind_of(model, widget) != Some(WidgetKind::MenuItem) {
            return false;
        }
        let Some(top) = model.top_of(widget) else {
            return false;
        };
        let Some(stack) = self.overlays.get_mut(&window) else {
            return false;
        };
        let Some(at) = stack.iter().position(|overlay| overlay.widget == top) else {
            return false;
        };

        let closed = stack.remove(at);
        sink.emit(Event::discrete(
            json!({"type": "overlay.dismissed", "window": window, "widget": closed.widget, "reason": "activated"})
                .to_string(),
        ));

        true
    }

    /// The widgets that can take the focus, in the order Tab visits them: the tree from the root, depth first.
    fn focus_order(&self, model: &Model, window: u64) -> Vec<u64> {
        fn walk(model: &Model, id: u64, order: &mut Vec<u64>) {
            if is_focusable(model, id) {
                order.push(id);
            }
            if let Some(view) = model.widget_view(id) {
                for child in view.children {
                    walk(model, *child, order);
                }
            }
        }

        let mut order = Vec::new();
        // The focus stays in the topmost overlay while there is one.
        if let Some(top) = self.overlays(window).last() {
            walk(model, top.widget, &mut order);
        } else if let Some((_, _, Some(root))) = model.window_content(window) {
            walk(model, root, &mut order);
        }

        order
    }

    fn move_focus(
        &mut self,
        window: u64,
        backwards: bool,
        model: &Model,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let order = self.focus_order(model, window);
        if order.is_empty() {
            return Reaction::default();
        }

        let current = self
            .focus(window)
            .and_then(|focused| order.iter().position(|id| *id == focused));
        let next = match (current, backwards) {
            (None, false) => 0,
            (None, true) => order.len() - 1,
            (Some(at), false) => (at + 1) % order.len(),
            (Some(at), true) => (at + order.len() - 1) % order.len(),
        };

        let reaction = self.focus_widget(window, Some(order[next]), sink);
        self.begin_edit(order[next], model);

        reaction
    }

    /// Takes the topmost overlay of a window away, leaving its widgets alive for PHP, and tells PHP why.
    fn dismiss_top(&mut self, window: u64, reason: &str, sink: &mut dyn EventSink) {
        let Some(overlay) = self.overlays.get_mut(&window).and_then(Vec::pop) else {
            return;
        };

        sink.emit(Event::discrete(
            json!({"type": "overlay.dismissed", "window": window, "widget": overlay.widget, "reason": reason})
                .to_string(),
        ));
    }

    /// Gives the focus to a widget of the window (or takes it away), and tells PHP if it changed.
    fn focus_widget(
        &mut self,
        window: u64,
        widget: Option<u64>,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        let state = self.state(window);
        if state.focus == widget {
            return Reaction::default();
        }

        let previous = state.focus;
        state.focus = widget;
        if let Some(previous) = previous {
            self.cursors.remove(&previous);
        }
        sink.emit(Event::discrete(
            json!({"type": "focus.changed", "window": window, "widget": widget}).to_string(),
        ));

        Reaction { redraw: true }
    }

    /// PHP asks for a widget to have the focus (`widget.focus`), or for none to have it (null) in a window.
    /// The widget must be in a window's tree and be one that can take the focus; otherwise nothing changes.
    fn request_focus(
        &mut self,
        window: Option<u64>,
        widget: Option<u64>,
        model: &Model,
        sink: &mut dyn EventSink,
    ) -> Reaction {
        match (widget, window) {
            (Some(widget), _) => {
                let Some(window) = self.window_of(model, widget) else {
                    return Reaction::default();
                };
                if !is_focusable(model, widget) {
                    return Reaction::default();
                }

                let reaction = self.focus_widget(window, Some(widget), sink);
                self.begin_edit(widget, model);

                reaction
            }
            (None, Some(window)) => self.focus_widget(window, None, sink),
            (None, None) => Reaction::default(),
        }
    }

    /// Called after each batch: forgets what no longer exists. A focused widget that is gone, or has been disabled,
    /// loses the focus (and PHP hears that the focus is nowhere); hover and the held button just go. `redraw` is told
    /// the windows that need a new frame, before the events are queued.
    pub fn sync(&mut self, model: &Model, sink: &mut dyn EventSink, redraw: &mut dyn FnMut(u64)) {
        let mut buffered: Vec<Event> = Vec::new();
        for window in self.forget(model, &mut buffered) {
            redraw(window);
        }
        for event in buffered {
            sink.emit(event);
        }
    }

    fn forget(&mut self, model: &Model, sink: &mut dyn EventSink) -> Vec<u64> {
        self.windows
            .retain(|window, _| model.window_content(*window).is_some());
        self.layouts
            .retain(|window, _| model.window_content(*window).is_some());
        self.cursors
            .retain(|widget, _| model.widget_view(*widget).is_some());

        // An overlay whose window or widget is gone, or that has been attached to something, is no overlay.
        let mut redraw = Vec::new();
        self.overlays
            .retain(|window, _| model.window_content(*window).is_some());
        for (window, stack) in &mut self.overlays {
            let before = stack.len();
            stack.retain(|overlay| {
                model.widget_view(overlay.widget).is_some()
                    && model.top_of(overlay.widget) == Some(overlay.widget)
                    && model.window_of_widget(overlay.widget).is_none()
            });
            if stack.len() != before {
                redraw.push(*window);
            }
        }
        self.overlay_layouts
            .retain(|widget, _| model.widget_view(*widget).is_some());

        let ids: Vec<u64> = self.windows.keys().copied().collect();
        for window in ids {
            let stack = self.overlays(window).to_vec();
            let alive = |id: u64| {
                model.window_of_widget(id) == Some(window)
                    || model
                        .top_of(id)
                        .is_some_and(|top| stack.iter().any(|overlay| overlay.widget == top))
            };
            let state = self.state(window);
            let lost_focus = state
                .focus
                .is_some_and(|id| !alive(id) || !is_focusable(model, id));
            let lost_hover = state.hover.is_some_and(|id| !alive(id));
            if state.pressed.is_some_and(|(id, _)| !alive(id)) {
                state.pressed = None;
                redraw.push(window);
            }
            if lost_hover {
                self.set_hover(window, None);
                redraw.push(window);
            }
            if lost_focus {
                self.focus_widget(window, None, sink);
                redraw.push(window);
            }
        }

        redraw.sort_unstable();
        redraw.dedup();

        redraw
    }
}

/// Whether a window shows a progress bar that is indeterminate, which is animated for as long as it is there.
fn has_indeterminate(model: &Model, window: u64) -> bool {
    fn walk(model: &Model, id: u64) -> bool {
        let Some(view) = model.widget_view(id) else {
            return false;
        };

        (view.kind == WidgetKind::ProgressBar
            && matches!(view.props.get("indeterminate"), Some(Value::Bool(true))))
            || view.children.iter().any(|child| walk(model, *child))
    }

    matches!(model.window_content(window), Some((_, _, Some(root))) if walk(model, root))
}

/// Keeps a scroll offset between 0 and what the content holds beyond what the view shows.
fn scroll_limited(rect: Rect, content: (f32, f32), wanted: (f32, f32)) -> (f32, f32) {
    (
        wanted.0.clamp(0.0, (content.0 - rect.width).max(0.0)),
        wanted.1.clamp(0.0, (content.1 - rect.height).max(0.0)),
    )
}

/// What hit testing looks at: the model, its layout, how far the scroll views are scrolled, and the point.
struct Hitter<'a> {
    model: &'a Model,
    layout: &'a Layout,
    scroll: &'a ScrollOffsets,
    point: (f32, f32),
    /// The rectangle, as shown, of the widget the search found.
    found: std::cell::Cell<Option<Rect>>,
}

impl Hitter<'_> {
    fn inside(&self, rect: Rect) -> bool {
        self.point.0 >= rect.x
            && self.point.0 < rect.x + rect.width
            && self.point.1 >= rect.y
            && self.point.1 < rect.y + rect.height
    }

    /// Finds the widget under the point and the path of widgets down to it, last painted first (what is on top
    /// wins), with a scroll view clipping what it holds and shifting it by its scroll offset. `offset` is how far
    /// the parents have shifted this widget and `clip` what they show of it. Returns whether it found one.
    fn find(&self, id: u64, offset: (f32, f32), clip: Option<Rect>, path: &mut Vec<u64>) -> bool {
        let (Some(rect), Some(view)) = (self.layout.rect(id), self.model.widget_view(id)) else {
            return false;
        };
        let shown = Rect {
            x: rect.x + offset.0,
            y: rect.y + offset.1,
            ..rect
        };

        path.push(id);
        let (child_offset, child_clip) = if view.kind == WidgetKind::ScrollView {
            let shift = scroll_shift(self.layout, id, rect, self.scroll);

            (
                (offset.0 - shift.0, offset.1 - shift.1),
                Some(intersect(clip, shown)),
            )
        } else {
            (offset, clip)
        };

        for child in view.children.iter().rev() {
            if self.find(*child, child_offset, child_clip, path) {
                return true;
            }
        }

        if clip.is_none_or(|visible| self.inside(visible)) && self.inside(shown) {
            self.found.set(Some(shown));

            return true;
        }

        path.pop();

        false
    }
}

/// Where a widget is on its window as shown: its place in the layout shifted by the scroll views around it (`offset` is
/// how far the widgets above `id` have shifted it already).
pub(crate) fn shown_in(
    model: &Model,
    layout: &Layout,
    scroll: &ScrollOffsets,
    id: u64,
    target: u64,
    offset: (f32, f32),
) -> Option<Rect> {
    let rect = layout.rect(id)?;
    if id == target {
        return Some(Rect {
            x: rect.x + offset.0,
            y: rect.y + offset.1,
            ..rect
        });
    }

    let view = model.widget_view(id)?;
    let inner = if view.kind == WidgetKind::ScrollView {
        let shift = scroll_shift(layout, id, rect, scroll);

        (offset.0 - shift.0, offset.1 - shift.1)
    } else {
        offset
    };

    view.children
        .iter()
        .find_map(|child| shown_in(model, layout, scroll, *child, target, inner))
}

/// Where an overlay of this size goes on its window: at the place it was asked for, or under the widget it is anchored to
/// (above it when there is no room below and there is above), and in either case kept inside the window.
pub(crate) fn overlay_place(
    model: &Model,
    window_layout: Option<&Layout>,
    root: Option<u64>,
    scroll: &ScrollOffsets,
    window: (f32, f32),
    overlay: &Overlay,
    size: (f32, f32),
) -> (f32, f32) {
    let anchored = overlay.anchor.and_then(|anchor| {
        let shown = shown_in(model, window_layout?, scroll, root?, anchor, (0.0, 0.0))?;
        let below = shown.y + shown.height;
        let y = if below + size.1 > window.1 && shown.y - size.1 >= 0.0 {
            shown.y - size.1
        } else {
            below
        };

        Some((shown.x, y))
    });

    overlay_origin(window, size, anchored.unwrap_or(overlay.at))
}

fn intersect(outer: Option<Rect>, inner: Rect) -> Rect {
    let Some(outer) = outer else {
        return inner;
    };
    let left = outer.x.max(inner.x);
    let top = outer.y.max(inner.y);
    let right = (outer.x + outer.width).min(inner.x + inner.width);
    let bottom = (outer.y + outer.height).min(inner.y + inner.height);

    Rect {
        x: left,
        y: top,
        width: (right - left).max(0.0),
        height: (bottom - top).max(0.0),
    }
}
