//! The widget arena and the commands that change it (plan P3.3). The model is plain data with no window
//! system in it: the UI thread owns one, applies batches to it in order, and reports what happened as events.
//! The rules, with their reasons, are in `docs/protocol/shim-commands.md`.

use std::collections::{BTreeMap, BTreeSet, HashMap};
use std::sync::Arc;

use serde_json::{Map, Value, json};

use crate::backend::Backend;
use crate::diagnostics::Diagnostics;
use crate::events::Event;
use crate::protocol::{
    Batch, Command, CommandError, ErrorCode, MAX_TEXT_BYTES, WindowSettings, parse_command,
};

/// The most widgets alive at once, the deepest nesting, and the most windows (my numbers).
pub(crate) const MAX_WIDGETS: usize = 100_000;
pub(crate) const MAX_DEPTH: usize = 64;
pub(crate) const MAX_WINDOWS: usize = 64;

type Result<T> = std::result::Result<T, CommandError>;

/// Where the model's events go.
pub(crate) trait EventSink {
    fn emit(&mut self, event: Event);
}

impl EventSink for Vec<Event> {
    fn emit(&mut self, event: Event) {
        self.push(event);
    }
}

#[derive(Clone, Copy, Debug, PartialEq)]
pub(crate) enum PropType {
    Text,
    Bool,
    Number { min: f64, max: f64 },
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum WidgetKind {
    Label,
    Button,
    ProgressBar,
    Column,
    Row,
    ScrollView,
    Checkbox,
    TextInput,
}

const GAP: (&str, PropType) = (
    "gap",
    PropType::Number {
        min: 0.0,
        max: 10_000.0,
    },
);
const PADDING: (&str, PropType) = (
    "padding",
    PropType::Number {
        min: 0.0,
        max: 10_000.0,
    },
);
const ENABLED: (&str, PropType) = ("enabled", PropType::Bool);

impl WidgetKind {
    pub fn from_name(name: &str) -> Option<Self> {
        Some(match name {
            "label" => Self::Label,
            "button" => Self::Button,
            "progress_bar" => Self::ProgressBar,
            "column" => Self::Column,
            "row" => Self::Row,
            "scroll_view" => Self::ScrollView,
            "checkbox" => Self::Checkbox,
            "text_input" => Self::TextInput,
            _ => return None,
        })
    }

    pub fn is_container(self) -> bool {
        matches!(self, Self::Column | Self::Row | Self::ScrollView)
    }

    /// A scroll view holds one child; the layout containers any number.
    fn max_children(self) -> Option<usize> {
        match self {
            Self::ScrollView => Some(1),
            _ => None,
        }
    }

    /// The properties of the widget, with the type each takes. `null` resets a property to its default.
    pub fn props(self) -> &'static [(&'static str, PropType)] {
        match self {
            Self::Label => &[("text", PropType::Text)],
            Self::Button => &[("label", PropType::Text), ENABLED],
            Self::ProgressBar => &[("value", PropType::Number { min: 0.0, max: 1.0 })],
            Self::Column | Self::Row => &[GAP, PADDING],
            Self::ScrollView => &[],
            Self::Checkbox => &[
                ("label", PropType::Text),
                ("checked", PropType::Bool),
                ENABLED,
            ],
            Self::TextInput => &[
                ("text", PropType::Text),
                ("placeholder", PropType::Text),
                ENABLED,
            ],
        }
    }
}

fn check_prop(kind: WidgetKind, name: &str, value: &Value) -> Result<()> {
    let Some((_, expected)) = kind.props().iter().find(|(prop, _)| *prop == name) else {
        let shown: String = name.chars().take(64).collect();

        return Err(CommandError::new(
            ErrorCode::UnknownProp,
            format!("a widget of this type has no property \"{shown}\""),
        ));
    };
    if value.is_null() {
        return Ok(()); // reset to the default
    }

    let fits = match (expected, value) {
        (PropType::Text, Value::String(text)) => text.len() <= MAX_TEXT_BYTES,
        (PropType::Bool, Value::Bool(_)) => true,
        (PropType::Number { min, max }, Value::Number(number)) => number
            .as_f64()
            .is_some_and(|n| n.is_finite() && n >= *min && n <= *max),
        _ => false,
    };
    if fits {
        Ok(())
    } else {
        Err(CommandError::new(
            ErrorCode::BadProp,
            format!(
                "the value of \"{name}\" does not fit: {}",
                describe(*expected)
            ),
        ))
    }
}

fn describe(expected: PropType) -> String {
    match expected {
        PropType::Text => format!("a string of at most {MAX_TEXT_BYTES} bytes"),
        PropType::Bool => "true or false".to_owned(),
        PropType::Number { min, max } => format!("a number from {min} to {max}"),
    }
}

struct Widget {
    kind: WidgetKind,
    props: BTreeMap<String, Value>,
    parent: Option<u64>,
    children: Vec<u64>,
    /// The window this widget is the root of, if it is one.
    root_of: Option<u64>,
    /// How many times the user has changed the text since PHP last set it (text inputs).
    text_revision: u64,
}

struct Window {
    settings: WindowSettings,
    root: Option<u64>,
}

/// What applying a batch led to.
#[derive(Debug, PartialEq, Eq)]
pub(crate) struct Outcome {
    /// `app.quit` was applied: the UI loop must end.
    pub quit: bool,
    /// The windows whose picture is now out of date and need a frame, each once however many commands touched it.
    pub dirty: Vec<u64>,
}

pub(crate) struct Model {
    widgets: HashMap<u64, Widget>,
    windows: BTreeMap<u64, Window>,
    last_widget_id: u64,
    last_window_id: u64,
    /// Windows changed since the last batch ended (see [`Outcome::dirty`]).
    dirty: BTreeSet<u64>,
    diagnostics: Arc<Diagnostics>,
}

impl Model {
    pub fn new(diagnostics: Arc<Diagnostics>) -> Self {
        Self {
            widgets: HashMap::new(),
            windows: BTreeMap::new(),
            last_widget_id: 0,
            last_window_id: 0,
            dirty: BTreeSet::new(),
            diagnostics,
        }
    }

    /// Applies the commands of a batch in order. A command that fails changes nothing and is reported as an
    /// `error` event naming the batch and the command's place in it; the next command is still applied.
    /// An object-form batch ends with a `batch.done` event, which marks the moment its commands took effect.
    pub fn apply_batch(
        &mut self,
        batch: Batch,
        sink: &mut dyn EventSink,
        backend: &mut dyn Backend,
    ) -> Outcome {
        let mut applied: u64 = 0;
        let mut failed: u64 = 0;
        let mut quit = false;
        let batch_id = batch.id.map_or(Value::Null, Value::from);

        for (index, value) in batch.commands.iter().enumerate() {
            let result = parse_command(value)
                .and_then(|command| self.execute(command, &mut quit, sink, backend));
            match result {
                Ok(()) => applied += 1,
                Err(error) => {
                    failed += 1;
                    let op = match value.get("op") {
                        Some(Value::String(op)) => {
                            Value::from(op.chars().take(64).collect::<String>())
                        }
                        _ => Value::Null,
                    };
                    sink.emit(Event::discrete(
                        json!({
                            "type": "error",
                            "batch": batch_id,
                            "index": index,
                            "op": op,
                            "code": error.code.as_str(),
                            "message": error.message,
                            "target": error.target,
                        })
                        .to_string(),
                    ));
                }
            }

            if quit {
                break;
            }
        }

        // The counters first: once PHP has read `batch.done`, the diagnostics already include the batch and the
        // redraws it asked for. (The frames are drawn later, at the backend's pace.)
        let dirty: Vec<u64> = std::mem::take(&mut self.dirty).into_iter().collect();
        for window in &dirty {
            self.diagnostics.redraw_requested(*window);
        }
        self.diagnostics.batch_done(applied, failed);
        self.diagnostics.set_widgets(self.widgets.len());

        if batch.id.is_some() {
            sink.emit(Event::discrete(
                json!({"type": "batch.done", "batch": batch_id, "applied": applied, "failed": failed}).to_string(),
            ));
        }

        Outcome { quit, dirty }
    }

    fn execute(
        &mut self,
        command: Command,
        quit: &mut bool,
        sink: &mut dyn EventSink,
        backend: &mut dyn Backend,
    ) -> Result<()> {
        match command {
            Command::WindowCreate { id, settings } => self.window_create(id, settings, backend),
            Command::WindowSet { id, settings } => self.window_set(id, settings, backend),
            Command::WindowSetRoot { id, widget } => self.window_set_root(id, widget),
            Command::WindowDestroy { id } => self.window_destroy(id, backend),
            Command::WidgetCreate { id, kind, props } => self.widget_create(id, &kind, props),
            Command::WidgetSet {
                id,
                props,
                base_rev,
            } => self.widget_set(id, props, base_rev),
            Command::WidgetAppend { parent, child } => self.attach(parent, child, None),
            Command::WidgetInsert {
                parent,
                child,
                index,
            } => self.attach(parent, child, Some(index)),
            Command::WidgetRemove { id } => self.widget_remove(id),
            Command::WidgetDestroy { id } => self.widget_destroy(id),
            #[cfg(any(test, feature = "test-hooks"))]
            Command::TestPanic => panic!("the model panicked on purpose"),
            Command::AppQuit => {
                *quit = true;
                sink.emit(Event::discrete(r#"{"type":"app.quit"}"#));

                Ok(())
            }
        }
    }

    // ------------------------------------------------------------ windows

    fn window_create(
        &mut self,
        id: u64,
        settings: WindowSettings,
        backend: &mut dyn Backend,
    ) -> Result<()> {
        if id <= self.last_window_id {
            return Err(CommandError::new(
                ErrorCode::IdReused,
                format!(
                    "window ids only go up and are never reused: {id} is not above {}",
                    self.last_window_id
                ),
            )
            .about(id));
        }

        if self.windows.len() >= MAX_WINDOWS {
            return Err(CommandError::new(
                ErrorCode::Limit,
                format!("at most {MAX_WINDOWS} windows at once"),
            )
            .about(id));
        }

        let (width, height) = (
            settings.width.unwrap_or(800.0),
            settings.height.unwrap_or(600.0),
        );
        backend
            .create_window(id, width, height)
            .map_err(|reason| CommandError::new(ErrorCode::Limit, reason).about(id))?;

        self.last_window_id = id;
        self.windows.insert(
            id,
            Window {
                settings: WindowSettings {
                    title: Some(settings.title.unwrap_or_default()),
                    width: Some(width),
                    height: Some(height),
                },
                root: None,
            },
        );
        self.diagnostics.window_created(id);
        self.dirty.insert(id); // its first frame

        Ok(())
    }

    fn window_set(
        &mut self,
        id: u64,
        settings: WindowSettings,
        backend: &mut dyn Backend,
    ) -> Result<()> {
        let window = self.window_mut(id)?;
        let width = settings.width.or(window.settings.width).unwrap_or(800.0);
        let height = settings.height.or(window.settings.height).unwrap_or(600.0);
        let resized =
            Some(width) != window.settings.width || Some(height) != window.settings.height;

        if resized {
            // The new picture first: if it does not fit, nothing about the window changes.
            backend
                .resize_window(id, width, height)
                .map_err(|reason| CommandError::new(ErrorCode::Limit, reason).about(id))?;
        }

        let window = self.window_mut(id)?;
        if settings.title.is_some() {
            window.settings.title = settings.title;
        }
        window.settings.width = Some(width);
        window.settings.height = Some(height);
        if resized {
            self.dirty.insert(id); // a new size needs a new frame; a new title does not
        }

        Ok(())
    }

    fn window_set_root(&mut self, id: u64, widget: Option<u64>) -> Result<()> {
        let current = self.window_mut(id)?.root;
        let Some(widget_id) = widget else {
            // Clearing the root leaves the widget alive and parentless.
            if let Some(root) = current
                && let Some(old) = self.widgets.get_mut(&root)
            {
                old.root_of = None;
            }
            self.window_mut(id)?.root = None;
            self.dirty.insert(id);

            return Ok(());
        };

        if current.is_some() {
            return Err(CommandError::new(
                ErrorCode::WindowHasRoot,
                "the window has a root: clear it first with widget null",
            )
            .about(id));
        }

        let candidate = self.widget(widget_id)?;
        if candidate.parent.is_some() {
            return Err(CommandError::new(
                ErrorCode::HasParent,
                "a widget with a parent cannot be a window's root",
            )
            .about(widget_id));
        }
        if candidate.root_of.is_some() {
            return Err(CommandError::new(
                ErrorCode::AlreadyRoot,
                "the widget is already the root of a window",
            )
            .about(widget_id));
        }

        self.widget_mut(widget_id)?.root_of = Some(id);
        self.window_mut(id)?.root = Some(widget_id);
        self.dirty.insert(id);

        Ok(())
    }

    fn window_destroy(&mut self, id: u64, backend: &mut dyn Backend) -> Result<()> {
        let root = self.window_mut(id)?.root;
        if let Some(root) = root {
            self.remove_subtree(root);
        }

        self.windows.remove(&id);
        self.diagnostics.window_destroyed(id);
        backend.destroy_window(id);
        self.dirty.remove(&id);

        Ok(())
    }

    // ------------------------------------------------------------ widgets

    fn widget_create(&mut self, id: u64, kind: &str, props: Map<String, Value>) -> Result<()> {
        if id <= self.last_widget_id {
            return Err(CommandError::new(
                ErrorCode::IdReused,
                format!(
                    "widget ids only go up and are never reused: {id} is not above {}",
                    self.last_widget_id
                ),
            )
            .about(id));
        }

        let Some(kind) = WidgetKind::from_name(kind) else {
            let shown: String = kind.chars().take(64).collect();

            return Err(CommandError::new(
                ErrorCode::UnknownType,
                format!("there is no widget type \"{shown}\""),
            )
            .about(id));
        };

        if self.widgets.len() >= MAX_WIDGETS {
            return Err(CommandError::new(
                ErrorCode::Limit,
                format!("at most {MAX_WIDGETS} widgets at once"),
            )
            .about(id));
        }

        for (name, value) in &props {
            check_prop(kind, name, value).map_err(|error| error.about(id))?;
        }

        let props = props
            .into_iter()
            .filter(|(_, value)| !value.is_null())
            .collect();
        self.last_widget_id = id;
        self.widgets.insert(
            id,
            Widget {
                kind,
                props,
                parent: None,
                children: Vec::new(),
                root_of: None,
                text_revision: 0,
            },
        );

        Ok(())
    }

    fn widget_set(
        &mut self,
        id: u64,
        props: Map<String, Value>,
        base_rev: Option<u64>,
    ) -> Result<()> {
        let widget = self.widget(id)?;
        let kind = widget.kind;

        for (name, value) in &props {
            check_prop(kind, name, value).map_err(|error| error.about(id))?;
        }

        if let Some(base) = base_rev {
            // The user has typed since PHP last saw the text: PHP's value is stale and must not overwrite it.
            if props.contains_key("text") && base != widget.text_revision {
                return Err(CommandError::new(
                    ErrorCode::StaleText,
                    format!(
                        "the user changed the text after revision {base}; it is at revision {}",
                        widget.text_revision
                    ),
                )
                .about(id));
            }
        }

        let widget = self.widget_mut(id)?;
        let mut changed = false;
        for (name, value) in props {
            changed |= if value.is_null() {
                widget.props.remove(&name).is_some()
            } else {
                widget.props.insert(name, value.clone()).as_ref() != Some(&value)
            };
        }

        if changed {
            self.mark_dirty(id); // setting what is already there changes nothing and asks for no frame
        }

        Ok(())
    }

    /// `widget.append` (no index) and `widget.insert` (an index).
    fn attach(&mut self, parent_id: u64, child_id: u64, index: Option<usize>) -> Result<()> {
        let parent = self.widget(parent_id)?;
        if !parent.kind.is_container() {
            return Err(CommandError::new(
                ErrorCode::NotAContainer,
                "this widget cannot have children",
            )
            .about(parent_id));
        }

        let child = self.widget(child_id)?;
        if child.parent.is_some() {
            return Err(CommandError::new(
                ErrorCode::HasParent,
                "the widget already has a parent: remove it first",
            )
            .about(child_id));
        }
        if child.root_of.is_some() {
            return Err(CommandError::new(
                ErrorCode::AlreadyRoot,
                "the widget is the root of a window",
            )
            .about(child_id));
        }

        // A widget cannot go below itself or below its own descendants.
        let mut ancestor = Some(parent_id);
        while let Some(current) = ancestor {
            if current == child_id {
                return Err(CommandError::new(
                    ErrorCode::Cycle,
                    "the widget would become its own ancestor",
                )
                .about(child_id));
            }
            ancestor = self.widgets.get(&current).and_then(|widget| widget.parent);
        }

        if let Some(max) = parent.kind.max_children()
            && parent.children.len() >= max
        {
            return Err(CommandError::new(
                ErrorCode::Limit,
                format!("this widget holds at most {max} child"),
            )
            .about(parent_id));
        }

        if self.depth_of(parent_id) + 1 + self.height_of(child_id) > MAX_DEPTH {
            return Err(CommandError::new(
                ErrorCode::Limit,
                format!("widgets nest at most {MAX_DEPTH} deep"),
            )
            .about(child_id));
        }

        let position = match index {
            Some(index) if index > parent.children.len() => {
                return Err(CommandError::new(
                    ErrorCode::BadIndex,
                    format!(
                        "index {index} is beyond the {} children",
                        parent.children.len()
                    ),
                )
                .about(parent_id));
            }
            Some(index) => index,
            None => parent.children.len(),
        };

        self.widget_mut(parent_id)?
            .children
            .insert(position, child_id);
        self.widget_mut(child_id)?.parent = Some(parent_id);
        self.mark_dirty(parent_id);

        Ok(())
    }

    fn widget_remove(&mut self, id: u64) -> Result<()> {
        let widget = self.widget(id)?;
        let Some(parent) = widget.parent else {
            return Err(CommandError::new(
                ErrorCode::NoParent,
                "the widget has no parent to be removed from",
            )
            .about(id));
        };

        let window = self.window_of(id);
        self.detach(id, parent);
        self.dirty.extend(window);

        Ok(())
    }

    fn widget_destroy(&mut self, id: u64) -> Result<()> {
        self.widget(id)?;
        let window = self.window_of(id);
        self.remove_subtree(id);
        self.dirty.extend(window);

        Ok(())
    }

    // ------------------------------------------------------------ helpers

    pub fn has_window(&self, id: u64) -> bool {
        self.windows.contains_key(&id)
    }

    /// The window whose tree the widget is in, if any.
    fn window_of(&self, id: u64) -> Option<u64> {
        let mut current = id;
        loop {
            let widget = self.widgets.get(&current)?;
            match widget.parent {
                Some(parent) => current = parent,
                None => return widget.root_of,
            }
        }
    }

    /// Marks the window of this widget as needing a frame (a widget outside every window is on no screen).
    fn mark_dirty(&mut self, id: u64) {
        let window = self.window_of(id);
        self.dirty.extend(window);
    }

    fn detach(&mut self, id: u64, parent: u64) {
        if let Some(parent) = self.widgets.get_mut(&parent) {
            parent.children.retain(|child| *child != id);
        }
        if let Some(widget) = self.widgets.get_mut(&id) {
            widget.parent = None;
        }
    }

    /// Removes a widget and everything below it, from its parent and from its window.
    fn remove_subtree(&mut self, id: u64) {
        if let Some(parent) = self.widgets.get(&id).and_then(|widget| widget.parent) {
            self.detach(id, parent);
        }

        let mut stack = vec![id];
        while let Some(current) = stack.pop() {
            let Some(widget) = self.widgets.remove(&current) else {
                continue;
            };
            stack.extend(widget.children);
            if let Some(window) = widget.root_of
                && let Some(window) = self.windows.get_mut(&window)
            {
                window.root = None;
            }
        }
    }

    /// How many ancestors the widget has.
    fn depth_of(&self, id: u64) -> usize {
        let mut depth = 0;
        let mut current = self.widgets.get(&id).and_then(|widget| widget.parent);
        while let Some(parent) = current {
            depth += 1;
            current = self.widgets.get(&parent).and_then(|widget| widget.parent);
        }

        depth
    }

    /// The number of levels in the subtree under the widget, itself included.
    fn height_of(&self, id: u64) -> usize {
        let mut deepest = 0;
        let mut stack = vec![(id, 1_usize)];
        while let Some((current, level)) = stack.pop() {
            deepest = deepest.max(level);
            if let Some(widget) = self.widgets.get(&current) {
                stack.extend(widget.children.iter().map(|child| (*child, level + 1)));
            }
        }

        deepest
    }

    fn widget(&self, id: u64) -> Result<&Widget> {
        self.widgets.get(&id).ok_or_else(|| {
            CommandError::new(ErrorCode::UnknownWidget, "there is no widget with this id").about(id)
        })
    }

    fn widget_mut(&mut self, id: u64) -> Result<&mut Widget> {
        self.widgets.get_mut(&id).ok_or_else(|| {
            CommandError::new(ErrorCode::UnknownWidget, "there is no widget with this id").about(id)
        })
    }

    fn window_mut(&mut self, id: u64) -> Result<&mut Window> {
        self.windows.get_mut(&id).ok_or_else(|| {
            CommandError::new(ErrorCode::UnknownWindow, "there is no window with this id").about(id)
        })
    }

    /// What every source of widget events must use (from P3.9): an event about a widget is only emitted while
    /// the widget exists, so after `widget.destroy` no event with its id is ever queued. Events queued earlier
    /// stay queued, and `batch.done` tells PHP where the destroy took effect (R15).
    #[allow(dead_code, reason = "used by the input code of step P3.9; tested now")]
    pub fn emit_widget_event(&self, id: u64, event: Event, sink: &mut dyn EventSink) -> bool {
        if self.widgets.contains_key(&id) {
            sink.emit(event);

            true
        } else {
            false
        }
    }

    // ------------------------------------------------------------ what the tests look at

    #[cfg(test)]
    pub fn widget_count(&self) -> usize {
        self.widgets.len()
    }

    #[cfg(test)]
    pub fn children_of(&self, id: u64) -> Vec<u64> {
        self.widgets
            .get(&id)
            .map(|widget| widget.children.clone())
            .unwrap_or_default()
    }

    #[cfg(test)]
    pub fn parent_of(&self, id: u64) -> Option<u64> {
        self.widgets.get(&id).and_then(|widget| widget.parent)
    }

    #[cfg(test)]
    pub fn prop(&self, id: u64, name: &str) -> Option<Value> {
        self.widgets
            .get(&id)
            .and_then(|widget| widget.props.get(name).cloned())
    }

    #[cfg(test)]
    pub fn window_root(&self, id: u64) -> Option<u64> {
        self.windows.get(&id).and_then(|window| window.root)
    }

    #[cfg(test)]
    pub fn window_settings(&self, id: u64) -> Option<&WindowSettings> {
        self.windows.get(&id).map(|window| &window.settings)
    }

    /// What the user typing will do from P3.11: change the text and count it, so that a stale PHP value can be told.
    #[cfg(test)]
    pub fn simulate_user_edit(&mut self, id: u64, text: &str) {
        if let Some(widget) = self.widgets.get_mut(&id) {
            widget.props.insert("text".to_owned(), Value::from(text));
            widget.text_revision += 1;
        }
    }
}
