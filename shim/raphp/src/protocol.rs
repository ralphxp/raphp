//! The command protocol (PHP to shim): how a batch of commands is read and checked (FRAMEWORK 7.4,
//! `docs/protocol/shim-commands.md`).
//!
//! A batch is a JSON array of commands, or an object `{"batch": <id>, "commands": [...]}`. The object form
//! gives the batch an id, which the shim repeats in every error it reports and in the `batch.done` event, so
//! PHP can tie them to the batch (R15). The checks are strict: an unknown field is an error, not ignored.

use serde_json::{Map, Value};

/// The largest batch, in bytes (my number: 200 commands encode to about 40 µs, so this is a very large batch).
pub(crate) const MAX_BATCH_BYTES: usize = 8 << 20;

/// The most commands in one batch (my number).
pub(crate) const MAX_COMMANDS_PER_BATCH: usize = 100_000;

/// Ids are integers from 1 to this, which a JSON number holds exactly in PHP and in JavaScript-like decoders.
pub(crate) const MAX_ID: u64 = (1 << 53) - 1;

/// The longest text in a widget property, and the longest window title (my numbers).
pub(crate) const MAX_TEXT_BYTES: usize = 1 << 16;
pub(crate) const MAX_TITLE_BYTES: usize = 1024;

/// Window sizes are positive and at most this many logical pixels.
pub(crate) const MAX_WINDOW_SIZE: f64 = 100_000.0;

/// Why a command was refused. Each has a stable name that goes into the error event.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum ErrorCode {
    UnknownOp,
    UnknownField,
    MissingField,
    BadField,
    UnknownWindow,
    UnknownWidget,
    IdReused,
    UnknownType,
    UnknownProp,
    BadProp,
    Cycle,
    NotAContainer,
    HasParent,
    NoParent,
    BadIndex,
    WindowHasRoot,
    AlreadyRoot,
    Limit,
    StaleText,
}

impl ErrorCode {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::UnknownOp => "unknown_op",
            Self::UnknownField => "unknown_field",
            Self::MissingField => "missing_field",
            Self::BadField => "bad_field",
            Self::UnknownWindow => "unknown_window",
            Self::UnknownWidget => "unknown_widget",
            Self::IdReused => "id_reused",
            Self::UnknownType => "unknown_type",
            Self::UnknownProp => "unknown_prop",
            Self::BadProp => "bad_prop",
            Self::Cycle => "cycle",
            Self::NotAContainer => "not_a_container",
            Self::HasParent => "has_parent",
            Self::NoParent => "no_parent",
            Self::BadIndex => "bad_index",
            Self::WindowHasRoot => "window_has_root",
            Self::AlreadyRoot => "already_root",
            Self::Limit => "limit",
            Self::StaleText => "stale_text",
        }
    }
}

/// A refused command: the reason, a message for people, and the id it concerns when there is one.
#[derive(Debug, PartialEq)]
pub(crate) struct CommandError {
    pub code: ErrorCode,
    pub message: String,
    pub target: Option<u64>,
}

impl CommandError {
    pub fn new(code: ErrorCode, message: impl Into<String>) -> Self {
        Self {
            code,
            message: message.into(),
            target: None,
        }
    }

    pub fn about(mut self, id: u64) -> Self {
        self.target = Some(id);
        self
    }
}

type Result<T> = std::result::Result<T, CommandError>;

/// A batch as it arrives: its id (object form only) and the commands, not yet looked at one by one.
#[derive(Debug)]
pub(crate) struct Batch {
    pub id: Option<u64>,
    pub commands: Vec<Value>,
}

/// Reads the shape of a batch. A batch that is not even a batch is refused as a whole (the caller turns the
/// message into `RAPHP_STATUS_INVALID_ARGUMENT`); the commands inside are checked one at a time later, so one
/// bad command does not hide the others.
pub(crate) fn parse_batch(text: &str) -> std::result::Result<Batch, String> {
    if text.len() > MAX_BATCH_BYTES {
        return Err(format!(
            "the batch is {} bytes, more than the limit of {MAX_BATCH_BYTES}",
            text.len()
        ));
    }

    let value: Value = serde_json::from_str(text)
        .map_err(|error| format!("the batch is not valid JSON: {error}"))?;

    let (id, commands) = match value {
        Value::Array(commands) => (None, commands),
        Value::Object(mut map) => {
            let id = match map.remove("batch") {
                Some(Value::Number(number)) => match number.as_u64() {
                    Some(id) if id <= MAX_ID => id,
                    _ => return Err(format!("\"batch\" must be an integer from 0 to {MAX_ID}")),
                },
                Some(_) => return Err("\"batch\" must be an integer".to_owned()),
                None => return Err("a batch object needs a \"batch\" id".to_owned()),
            };
            let commands = match map.remove("commands") {
                Some(Value::Array(commands)) => commands,
                Some(_) => return Err("\"commands\" must be an array".to_owned()),
                None => return Err("a batch object needs a \"commands\" array".to_owned()),
            };
            if let Some(extra) = map.keys().next() {
                return Err(format!("a batch object has no field \"{extra}\""));
            }

            (Some(id), commands)
        }
        _ => {
            return Err(
                "a batch is a JSON array of commands, or an object with \"batch\" and \"commands\""
                    .to_owned(),
            );
        }
    };

    if commands.len() > MAX_COMMANDS_PER_BATCH {
        return Err(format!(
            "the batch has {} commands, more than the limit of {MAX_COMMANDS_PER_BATCH}",
            commands.len()
        ));
    }

    Ok(Batch { id, commands })
}

/// The settings of a window; absent means "leave as it is" (or the default, for a new one).
#[derive(Debug, Default, PartialEq)]
pub(crate) struct WindowSettings {
    pub title: Option<String>,
    pub width: Option<f64>,
    pub height: Option<f64>,
}

#[derive(Debug, PartialEq)]
pub(crate) enum Command {
    WindowCreate {
        id: u64,
        settings: WindowSettings,
    },
    WindowSet {
        id: u64,
        settings: WindowSettings,
    },
    WindowSetRoot {
        id: u64,
        widget: Option<u64>,
    },
    WindowDestroy {
        id: u64,
    },
    WidgetCreate {
        id: u64,
        kind: String,
        props: Map<String, Value>,
    },
    WidgetSet {
        id: u64,
        props: Map<String, Value>,
        base_rev: Option<u64>,
    },
    WidgetAppend {
        parent: u64,
        child: u64,
    },
    WidgetInsert {
        parent: u64,
        child: u64,
        index: usize,
    },
    WidgetRemove {
        id: u64,
    },
    WidgetDestroy {
        id: u64,
    },
    AppQuit,
    /// Exists only in test builds: panics inside the model, to prove that a bug there is contained.
    #[cfg(any(test, feature = "test-hooks"))]
    TestPanic,
}

/// The fields of one command, checked off as they are read so that a field nobody asked for is an error.
struct Fields<'a> {
    map: &'a Map<String, Value>,
    used: Vec<&'static str>,
}

impl<'a> Fields<'a> {
    fn new(map: &'a Map<String, Value>) -> Self {
        Self {
            map,
            used: vec!["op"],
        }
    }

    fn raw(&mut self, name: &'static str) -> Option<&'a Value> {
        self.used.push(name);
        self.map.get(name)
    }

    fn id(&mut self, name: &'static str) -> Result<u64> {
        match self.raw(name) {
            None => Err(CommandError::new(
                ErrorCode::MissingField,
                format!("\"{name}\" is missing"),
            )),
            Some(value) => id_value(name, value),
        }
    }

    /// An id, or null (or absent) for "none".
    fn nullable_id(&mut self, name: &'static str) -> Result<Option<u64>> {
        match self.raw(name) {
            None => Err(CommandError::new(
                ErrorCode::MissingField,
                format!("\"{name}\" is missing (use null for none)"),
            )),
            Some(Value::Null) => Ok(None),
            Some(value) => id_value(name, value).map(Some),
        }
    }

    fn optional_u64(&mut self, name: &'static str) -> Result<Option<u64>> {
        match self.raw(name) {
            None | Some(Value::Null) => Ok(None),
            Some(value) => value
                .as_u64()
                .filter(|number| *number <= MAX_ID)
                .map(Some)
                .ok_or_else(|| {
                    CommandError::new(
                        ErrorCode::BadField,
                        format!("\"{name}\" must be a whole number"),
                    )
                }),
        }
    }

    fn index(&mut self, name: &'static str) -> Result<usize> {
        match self.raw(name) {
            None => Err(CommandError::new(
                ErrorCode::MissingField,
                format!("\"{name}\" is missing"),
            )),
            Some(value) => value
                .as_u64()
                .and_then(|number| usize::try_from(number).ok())
                .ok_or_else(|| {
                    CommandError::new(
                        ErrorCode::BadField,
                        format!("\"{name}\" must be a whole number from 0"),
                    )
                }),
        }
    }

    fn string(&mut self, name: &'static str, limit: usize) -> Result<Option<String>> {
        match self.raw(name) {
            None => Ok(None),
            Some(Value::String(text)) if text.len() <= limit => Ok(Some(text.clone())),
            Some(Value::String(_)) => Err(CommandError::new(
                ErrorCode::BadField,
                format!("\"{name}\" is longer than {limit} bytes"),
            )),
            Some(_) => Err(CommandError::new(
                ErrorCode::BadField,
                format!("\"{name}\" must be a string"),
            )),
        }
    }

    fn size(&mut self, name: &'static str) -> Result<Option<f64>> {
        match self.raw(name) {
            None => Ok(None),
            Some(value) => match value.as_f64() {
                Some(number) if number.is_finite() && number > 0.0 && number <= MAX_WINDOW_SIZE => {
                    Ok(Some(number))
                }
                _ => Err(CommandError::new(
                    ErrorCode::BadField,
                    format!("\"{name}\" must be a number above 0 and at most {MAX_WINDOW_SIZE}"),
                )),
            },
        }
    }

    fn object(&mut self, name: &'static str) -> Result<Map<String, Value>> {
        match self.raw(name) {
            None => Err(CommandError::new(
                ErrorCode::MissingField,
                format!("\"{name}\" is missing"),
            )),
            Some(Value::Object(map)) => Ok(map.clone()),
            Some(_) => Err(CommandError::new(
                ErrorCode::BadField,
                format!("\"{name}\" must be an object"),
            )),
        }
    }

    fn optional_object(&mut self, name: &'static str) -> Result<Map<String, Value>> {
        match self.raw(name) {
            None => Ok(Map::new()),
            Some(Value::Object(map)) => Ok(map.clone()),
            Some(_) => Err(CommandError::new(
                ErrorCode::BadField,
                format!("\"{name}\" must be an object"),
            )),
        }
    }

    fn required_string(&mut self, name: &'static str, limit: usize) -> Result<String> {
        self.string(name, limit)?.ok_or_else(|| {
            CommandError::new(ErrorCode::MissingField, format!("\"{name}\" is missing"))
        })
    }

    /// Fails if the command has a field that was not read.
    fn finish(self) -> Result<()> {
        for key in self.map.keys() {
            if !self.used.contains(&key.as_str()) {
                let shown: String = key.chars().take(64).collect();

                return Err(CommandError::new(
                    ErrorCode::UnknownField,
                    format!("this command has no field \"{shown}\""),
                ));
            }
        }

        Ok(())
    }
}

fn id_value(name: &str, value: &Value) -> Result<u64> {
    match value.as_u64() {
        Some(id) if (1..=MAX_ID).contains(&id) => Ok(id),
        _ => Err(CommandError::new(
            ErrorCode::BadField,
            format!("\"{name}\" must be an integer from 1 to {MAX_ID}"),
        )),
    }
}

fn window_settings(fields: &mut Fields<'_>) -> Result<WindowSettings> {
    Ok(WindowSettings {
        title: fields.string("title", MAX_TITLE_BYTES)?,
        width: fields.size("width")?,
        height: fields.size("height")?,
    })
}

/// Reads one command of a batch.
pub(crate) fn parse_command(value: &Value) -> Result<Command> {
    let Value::Object(map) = value else {
        return Err(CommandError::new(
            ErrorCode::BadField,
            "a command must be an object",
        ));
    };
    let op = match map.get("op") {
        Some(Value::String(op)) => op.as_str(),
        Some(_) => {
            return Err(CommandError::new(
                ErrorCode::BadField,
                "\"op\" must be a string",
            ));
        }
        None => {
            return Err(CommandError::new(
                ErrorCode::MissingField,
                "\"op\" is missing",
            ));
        }
    };
    let mut fields = Fields::new(map);

    let command = match op {
        "window.create" => Command::WindowCreate {
            id: fields.id("id")?,
            settings: window_settings(&mut fields)?,
        },
        "window.set" => {
            let id = fields.id("id")?;
            let settings = window_settings(&mut fields)?;
            if settings == WindowSettings::default() {
                return Err(CommandError::new(
                    ErrorCode::MissingField,
                    "window.set needs at least one of title, width, height",
                )
                .about(id));
            }

            Command::WindowSet { id, settings }
        }
        "window.set_root" => Command::WindowSetRoot {
            id: fields.id("id")?,
            widget: fields.nullable_id("widget")?,
        },
        "window.destroy" => Command::WindowDestroy {
            id: fields.id("id")?,
        },
        "widget.create" => Command::WidgetCreate {
            id: fields.id("id")?,
            kind: fields.required_string("type", 64)?,
            props: fields.optional_object("props")?,
        },
        "widget.set" => Command::WidgetSet {
            id: fields.id("id")?,
            props: fields.object("props")?,
            base_rev: fields.optional_u64("base_rev")?,
        },
        "widget.append" => Command::WidgetAppend {
            parent: fields.id("parent")?,
            child: fields.id("child")?,
        },
        "widget.insert" => Command::WidgetInsert {
            parent: fields.id("parent")?,
            child: fields.id("child")?,
            index: fields.index("index")?,
        },
        "widget.remove" => Command::WidgetRemove {
            id: fields.id("id")?,
        },
        "widget.destroy" => Command::WidgetDestroy {
            id: fields.id("id")?,
        },
        "app.quit" => Command::AppQuit,
        #[cfg(any(test, feature = "test-hooks"))]
        "test.panic" => Command::TestPanic,
        other => {
            let shown: String = other.chars().take(64).collect();

            return Err(CommandError::new(
                ErrorCode::UnknownOp,
                format!("there is no command \"{shown}\""),
            ));
        }
    };
    fields.finish()?;

    Ok(command)
}
