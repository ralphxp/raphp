//! The draw list of a `canvas` widget (plan P3.13): a list of shapes and text that PHP sends in the `ops` property and
//! the shim rasterizes with the rest of the window. This module only reads and checks the list; `draw.rs` paints it.
//!
//! Coordinates are logical pixels from the canvas's top left corner. Nothing is drawn outside the canvas.

use serde_json::{Map, Value};

/// How many operations a draw list may hold.
pub(crate) const MAX_OPS: usize = 4096;
/// How many points a path may have, and how many all the paths of a list may have together.
pub(crate) const MAX_PATH_POINTS: usize = 4096;
pub(crate) const MAX_TOTAL_POINTS: usize = 65_536;
/// The longest text an operation may draw, in bytes.
pub(crate) const MAX_TEXT_BYTES: usize = 4096;
/// The largest coordinate or length accepted: a number beyond it is a mistake, not a drawing.
const LIMIT: f64 = 1_000_000.0;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct Rgba {
    pub red: u8,
    pub green: u8,
    pub blue: u8,
    pub alpha: u8,
}

#[derive(Clone, Copy, Debug, PartialEq)]
pub(crate) struct Stroke {
    pub color: Rgba,
    pub width: f32,
}

pub(crate) type Point = (f32, f32);

#[derive(Clone, Debug, PartialEq)]
pub(crate) enum Op {
    Rect {
        at: Point,
        size: (f32, f32),
        radius: f32,
        fill: Option<Rgba>,
        stroke: Option<Stroke>,
    },
    Line {
        from: Point,
        to: Point,
        stroke: Stroke,
    },
    /// Straight segments through the points; filled (as if closed) and/or stroked.
    Path {
        points: Vec<Point>,
        closed: bool,
        fill: Option<Rgba>,
        stroke: Option<Stroke>,
    },
    Circle {
        center: Point,
        radius: f32,
        fill: Option<Rgba>,
        stroke: Option<Stroke>,
    },
    Text {
        at: Point,
        text: String,
        color: Option<Rgba>,
        size: f32,
    },
}

/// `#RRGGBB` or `#RRGGBBAA`.
fn color(text: &str) -> Option<Rgba> {
    let hex = text.strip_prefix('#')?;
    if !(hex.len() == 6 || hex.len() == 8) || !hex.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return None;
    }
    let byte = |at: usize| u8::from_str_radix(&hex[at..at + 2], 16).ok();

    Some(Rgba {
        red: byte(0)?,
        green: byte(2)?,
        blue: byte(4)?,
        alpha: if hex.len() == 8 { byte(6)? } else { 255 },
    })
}

/// Reads a draw list. The error says which operation and what is wrong with it.
pub(crate) fn parse(list: &Value) -> Result<Vec<Op>, String> {
    let Value::Array(items) = list else {
        return Err("a draw list is an array of operations".to_owned());
    };
    if items.len() > MAX_OPS {
        return Err(format!(
            "a draw list has at most {MAX_OPS} operations, this one {}",
            items.len()
        ));
    }

    let mut points = 0;
    let mut ops = Vec::with_capacity(items.len());
    for (index, item) in items.iter().enumerate() {
        let op = operation(item, &mut points).map_err(|why| format!("ops[{index}]: {why}"))?;
        ops.push(op);
    }

    Ok(ops)
}

fn operation(item: &Value, points: &mut usize) -> Result<Op, String> {
    let Value::Object(fields) = item else {
        return Err("an operation is an object".to_owned());
    };
    let name = match fields.get("op") {
        Some(Value::String(name)) => name.as_str(),
        _ => return Err("missing \"op\" (rect, line, path, circle or text)".to_owned()),
    };
    let fields = Fields { name, map: fields };

    let (allowed, op): (&[&str], Op) = match name {
        "rect" => (
            &[
                "op",
                "x",
                "y",
                "w",
                "h",
                "radius",
                "fill",
                "stroke",
                "line_width",
            ],
            Op::Rect {
                at: (fields.coordinate("x")?, fields.coordinate("y")?),
                size: (fields.length("w")?, fields.length("h")?),
                radius: fields.optional_length("radius")?.unwrap_or(0.0),
                fill: fields.optional_color("fill")?,
                stroke: fields.stroke()?,
            },
        ),
        "line" => (
            &["op", "x1", "y1", "x2", "y2", "stroke", "line_width"],
            Op::Line {
                from: (fields.coordinate("x1")?, fields.coordinate("y1")?),
                to: (fields.coordinate("x2")?, fields.coordinate("y2")?),
                stroke: fields
                    .stroke()?
                    .ok_or_else(|| "line needs \"stroke\"".to_owned())?,
            },
        ),
        "path" => {
            let list = fields.points(points)?;
            let fill = fields.optional_color("fill")?;
            let stroke = fields.stroke()?;
            if fill.is_none() && stroke.is_none() {
                return Err(
                    "path: it needs a \"fill\" or a \"stroke\" or nothing is drawn".to_owned(),
                );
            }

            (
                &["op", "points", "closed", "fill", "stroke", "line_width"],
                Op::Path {
                    points: list,
                    closed: fields.optional_bool("closed")?.unwrap_or(false),
                    fill,
                    stroke,
                },
            )
        }
        "circle" => (
            &["op", "x", "y", "r", "fill", "stroke", "line_width"],
            Op::Circle {
                center: (fields.coordinate("x")?, fields.coordinate("y")?),
                radius: fields.length("r")?,
                fill: fields.optional_color("fill")?,
                stroke: fields.stroke()?,
            },
        ),
        "text" => (
            &["op", "x", "y", "text", "fill", "size"],
            Op::Text {
                at: (fields.coordinate("x")?, fields.coordinate("y")?),
                text: fields.text("text")?,
                color: fields.optional_color("fill")?,
                size: fields.size()?,
            },
        ),
        other => {
            let shown: String = other.chars().take(32).collect();

            return Err(format!(
                "unknown operation \"{shown}\" (rect, line, path, circle or text)"
            ));
        }
    };

    if let Some(extra) = fields
        .map
        .keys()
        .find(|key| !allowed.contains(&key.as_str()))
    {
        let shown: String = extra.chars().take(32).collect();

        return Err(format!("{name} has no field \"{shown}\""));
    }

    Ok(op)
}

struct Fields<'a> {
    name: &'a str,
    map: &'a Map<String, Value>,
}

impl Fields<'_> {
    fn number(&self, key: &str) -> Result<Option<f64>, String> {
        match self.map.get(key) {
            None | Some(Value::Null) => Ok(None),
            Some(Value::Number(number)) => number
                .as_f64()
                .filter(|value| value.is_finite() && value.abs() <= LIMIT)
                .map(Some)
                .ok_or_else(|| format!("\"{key}\" is a number between -{LIMIT} and {LIMIT}")),
            Some(_) => Err(format!("\"{key}\" is a number")),
        }
    }

    fn coordinate(&self, key: &str) -> Result<f32, String> {
        self.number(key)?
            .map(|value| value as f32)
            .ok_or_else(|| format!("{} needs \"{key}\"", self.name))
    }

    fn optional_length(&self, key: &str) -> Result<Option<f32>, String> {
        match self.number(key)? {
            Some(value) if value < 0.0 => Err(format!("\"{key}\" is not negative")),
            other => Ok(other.map(|value| value as f32)),
        }
    }

    fn length(&self, key: &str) -> Result<f32, String> {
        self.optional_length(key)?
            .ok_or_else(|| format!("{} needs \"{key}\"", self.name))
    }

    fn optional_bool(&self, key: &str) -> Result<Option<bool>, String> {
        match self.map.get(key) {
            None | Some(Value::Null) => Ok(None),
            Some(Value::Bool(value)) => Ok(Some(*value)),
            Some(_) => Err(format!("\"{key}\" is true or false")),
        }
    }

    fn optional_color(&self, key: &str) -> Result<Option<Rgba>, String> {
        match self.map.get(key) {
            None | Some(Value::Null) => Ok(None),
            Some(Value::String(text)) => color(text)
                .map(Some)
                .ok_or_else(|| format!("\"{key}\" is a color like \"#2D6CDF\" or \"#2D6CDF80\"")),
            Some(_) => Err(format!("\"{key}\" is a color string")),
        }
    }

    /// The `stroke` color with the `line_width` (1 by default, from just above 0 to 1000).
    fn stroke(&self) -> Result<Option<Stroke>, String> {
        let width = match self.number("line_width")? {
            None => 1.0,
            Some(width) if width > 0.0 && width <= 1000.0 => width as f32,
            Some(_) => return Err("\"line_width\" is more than 0 and at most 1000".to_owned()),
        };

        Ok(self
            .optional_color("stroke")?
            .map(|color| Stroke { color, width }))
    }

    fn text(&self, key: &str) -> Result<String, String> {
        match self.map.get(key) {
            Some(Value::String(text)) if text.len() <= MAX_TEXT_BYTES => Ok(text.clone()),
            Some(Value::String(_)) => Err(format!("\"{key}\" has at most {MAX_TEXT_BYTES} bytes")),
            _ => Err(format!("text needs \"{key}\", a string")),
        }
    }

    /// The text size in logical pixels: 4 to 200, 14 (the size of the widgets' text) by default.
    fn size(&self) -> Result<f32, String> {
        match self.number("size")? {
            None => Ok(crate::text::FONT_SIZE),
            Some(size) if (4.0..=200.0).contains(&size) => Ok(size as f32),
            Some(_) => Err("\"size\" is from 4 to 200".to_owned()),
        }
    }

    fn points(&self, total: &mut usize) -> Result<Vec<Point>, String> {
        let Some(Value::Array(list)) = self.map.get("points") else {
            return Err("path needs \"points\", a list of [x, y] pairs".to_owned());
        };
        if list.len() < 2 || list.len() > MAX_PATH_POINTS {
            return Err(format!(
                "\"points\" has from 2 to {MAX_PATH_POINTS} points, this one {}",
                list.len()
            ));
        }
        *total += list.len();
        if *total > MAX_TOTAL_POINTS {
            return Err(format!(
                "a draw list has at most {MAX_TOTAL_POINTS} path points"
            ));
        }

        list.iter()
            .enumerate()
            .map(|(at, point)| match point {
                Value::Array(pair) if pair.len() == 2 => {
                    let axis = |value: &Value| {
                        value
                            .as_f64()
                            .filter(|number| number.is_finite() && number.abs() <= LIMIT)
                            .map(|number| number as f32)
                    };
                    match (axis(&pair[0]), axis(&pair[1])) {
                        (Some(x), Some(y)) => Ok((x, y)),
                        _ => Err(format!(
                            "points[{at}] holds numbers between -{LIMIT} and {LIMIT}"
                        )),
                    }
                }
                _ => Err(format!("points[{at}] is a pair [x, y]")),
            })
            .collect()
    }
}
