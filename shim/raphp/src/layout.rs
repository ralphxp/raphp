//! Layout (plan P3.7): where each widget of a window goes, in logical pixels, by the flexbox subset that Taffy
//! implements. The rules are written down in `docs/protocol/layout.md`.
//!
//! Layout reads the model and changes nothing in it. It is meant to be run when a window is drawn (P3.8), and
//! only for a window that has something to draw: a tree is built from the model each time, which costs time in
//! proportion to the number of widgets (measured in the tests).
//!
//! Text has no size until fonts exist (P3.8), so widgets that hold text ask a [`TextMetrics`]; [`PlaceholderMetrics`]
//! is a fixed-size stand-in with which the tests have exact numbers.

use std::collections::BTreeMap;

use serde_json::Value;
use taffy::{
    AvailableSpace, Dimension, FlexDirection, LengthPercentage, LengthPercentageAuto, NodeId,
    Overflow, Point, Rect as EdgeRect, Size, Style, TaffyTree,
};

use crate::model::{Model, WidgetKind, WidgetView};

/// A rectangle in logical pixels, from the top left corner of the window's content.
#[derive(Clone, Copy, Debug, PartialEq)]
pub(crate) struct Rect {
    pub x: f32,
    pub y: f32,
    pub width: f32,
    pub height: f32,
}

/// A rectangle in physical pixels: whole numbers, so that it can be drawn.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) struct PixelRect {
    pub x: i64,
    pub y: i64,
    pub width: u64,
    pub height: u64,
}

impl Rect {
    /// The rectangle on the physical pixel grid of a window with this scale factor. Each edge is rounded on its
    /// own, so two widgets that touch in logical pixels still touch in physical ones, with no gap and no overlap,
    /// and a rectangle's size is the distance between its rounded edges.
    pub fn snapped(self, scale: f64) -> PixelRect {
        let edge = |logical: f32| (f64::from(logical) * scale).round();
        let (left, top) = (edge(self.x), edge(self.y));
        let (right, bottom) = (edge(self.x + self.width), edge(self.y + self.height));

        PixelRect {
            x: left as i64,
            y: top as i64,
            width: (right - left).max(0.0) as u64,
            height: (bottom - top).max(0.0) as u64,
        }
    }
}

/// How big text is. P3.8 replaces the stand-in with real font metrics.
pub(crate) trait TextMetrics {
    /// The width and height in logical pixels of text without wrapping; lines are separated by `\n`.
    fn text_size(&self, text: &str) -> (f32, f32);
}

/// Every character 8 pixels wide and every line 16 high, whatever the character: not a font, a ruler for the tests.
pub(crate) struct PlaceholderMetrics;

impl PlaceholderMetrics {
    pub(crate) const CHAR_WIDTH: f32 = 8.0;
    pub(crate) const LINE_HEIGHT: f32 = 16.0;
}

impl TextMetrics for PlaceholderMetrics {
    fn text_size(&self, text: &str) -> (f32, f32) {
        let lines = text.split('\n');
        let widest = lines
            .clone()
            .map(|line| line.chars().count())
            .max()
            .unwrap_or(0);

        (
            widest as f32 * Self::CHAR_WIDTH,
            lines.count() as f32 * Self::LINE_HEIGHT,
        )
    }
}

/// Sizes of the parts of widgets that are not text, in logical pixels (my numbers, until a theme exists).
pub(crate) mod metrics {
    pub(crate) const BUTTON_PADDING_X: f32 = 12.0;
    pub(crate) const BUTTON_PADDING_Y: f32 = 6.0;
    pub(crate) const BUTTON_MIN_HEIGHT: f32 = 28.0;
    pub(crate) const CHECKBOX_BOX: f32 = 16.0;
    pub(crate) const CHECKBOX_GAP: f32 = 8.0;
    pub(crate) const TEXT_INPUT_MIN_WIDTH: f32 = 160.0;
    pub(crate) const TEXT_INPUT_HEIGHT: f32 = 28.0;
    /// A canvas that was given no size.
    pub(crate) const CANVAS_WIDTH: f32 = 200.0;
    pub(crate) const CANVAS_HEIGHT: f32 = 150.0;
    pub(crate) const PROGRESS_MIN_WIDTH: f32 = 160.0;
    pub(crate) const PROGRESS_HEIGHT: f32 = 8.0;
    /// A menu item: room for a check mark at the left, a gap before its shortcut, and a margin at the right.
    pub(crate) const MENU_ITEM_PAD_LEFT: f32 = 28.0;
    pub(crate) const MENU_ITEM_PAD_RIGHT: f32 = 12.0;
    pub(crate) const MENU_ITEM_GAP: f32 = 24.0;
    pub(crate) const MENU_ITEM_HEIGHT: f32 = 24.0;
    pub(crate) const MENU_SEPARATOR_HEIGHT: f32 = 9.0;
}

/// The result for one window.
#[derive(Clone, Debug, Default, PartialEq)]
pub(crate) struct Layout {
    rects: BTreeMap<u64, Rect>,
    contents: BTreeMap<u64, (f32, f32)>,
}

impl Layout {
    /// Where a widget goes, from the top left of the window's content; none for a widget that is not in the
    /// window's tree.
    pub fn rect(&self, widget: u64) -> Option<Rect> {
        self.rects.get(&widget).copied()
    }

    /// The size of what a scroll view holds, counted from its top left to the farthest edge of anything inside it, which
    /// may be more than the scroll view shows (P3.8 scrolls it).
    pub fn content_size(&self, scroll_view: u64) -> Option<(f32, f32)> {
        self.contents.get(&scroll_view).copied()
    }

    pub fn len(&self) -> usize {
        self.rects.len()
    }
}

/// Where an overlay of this size, asked for at `at`, is put in a window of this size: as asked if it fits, and moved
/// in as little as needed if it would stick out (an overlay is never drawn outside its window).
pub(crate) fn overlay_origin(window: (f32, f32), size: (f32, f32), at: (f32, f32)) -> (f32, f32) {
    (
        at.0.clamp(0.0, (window.0 - size.0).max(0.0)),
        at.1.clamp(0.0, (window.1 - size.1).max(0.0)),
    )
}

/// Lays out the tree of an overlay: the root is as large as what it holds, from the origin `(0, 0)` of its own.
pub(crate) fn layout_overlay(model: &Model, root: u64, text: &dyn TextMetrics) -> Option<Layout> {
    let mut tree: TaffyTree<()> = TaffyTree::new();
    tree.disable_rounding();

    let node = build(&mut tree, model, root, text)?;
    tree.compute_layout(
        node,
        Size {
            width: AvailableSpace::MaxContent,
            height: AvailableSpace::MaxContent,
        },
    )
    .ok()?;

    let mut layout = Layout::default();
    collect(&tree, model, root, node, (0.0, 0.0), &mut layout);

    Some(layout)
}

/// Lays out the widgets of a window. None when there is no such window; a window with no root has an empty layout.
///
/// The root widget fills the window's content. Everything else follows from the rules in `docs/protocol/layout.md`.
pub(crate) fn layout_window(model: &Model, window: u64, text: &dyn TextMetrics) -> Option<Layout> {
    let (width, height, root) = model.window_content(window)?;
    let Some(root) = root else {
        return Some(Layout::default());
    };

    let mut tree: TaffyTree<()> = TaffyTree::new();
    tree.disable_rounding(); // the rounding that matters is to physical pixels, and is done on the edges (`Rect::snapped`)

    let (width, height) = (width as f32, height as f32);
    let node = build(&mut tree, model, root, text)?;
    let mut style = tree.style(node).ok()?.clone();
    style.size = Size {
        width: Dimension::length(width),
        height: Dimension::length(height),
    };
    tree.set_style(node, style).ok()?;
    tree.compute_layout(
        node,
        Size {
            width: AvailableSpace::Definite(width),
            height: AvailableSpace::Definite(height),
        },
    )
    .ok()?;

    let mut layout = Layout::default();
    collect(&tree, model, root, node, (0.0, 0.0), &mut layout);

    Some(layout)
}

fn number(view: &WidgetView<'_>, name: &str) -> f32 {
    match view.props.get(name) {
        Some(Value::Number(number)) => number.as_f64().unwrap_or(0.0) as f32,
        _ => 0.0,
    }
}

/// A number property, or this when it was never set.
fn size_or(view: &WidgetView<'_>, name: &str, default: f32) -> f32 {
    match view.props.get(name) {
        Some(Value::Number(number)) => number.as_f64().unwrap_or(0.0) as f32,
        _ => default,
    }
}

fn string<'a>(view: &'a WidgetView<'_>, name: &str) -> &'a str {
    match view.props.get(name) {
        Some(Value::String(text)) => text,
        _ => "",
    }
}

fn length(value: f32) -> LengthPercentage {
    LengthPercentage::length(value)
}

/// A widget that has a size of its own and no children: its size is a minimum, so it is never made smaller (the
/// flexbox rule for a shrinking item scales by its base size, which is 0 here, so a minimum is all that is needed),
/// and it may be made larger (stretched across its parent, or grown along it).
fn leaf(width: f32, height: f32, grow: f32) -> Style {
    Style {
        min_size: Size {
            width: LengthPercentageAuto::length(width),
            height: LengthPercentageAuto::length(height),
        },
        flex_grow: grow,
        ..Style::default()
    }
}

fn style_of(view: &WidgetView<'_>, text: &dyn TextMetrics) -> Style {
    let grow = number(view, "grow");

    match view.kind {
        WidgetKind::Label => {
            let (width, height) = text.text_size(string(view, "text"));

            leaf(width, height, grow)
        }
        WidgetKind::Button => {
            let (width, height) = text.text_size(string(view, "label"));

            leaf(
                width + 2.0 * metrics::BUTTON_PADDING_X,
                (height + 2.0 * metrics::BUTTON_PADDING_Y).max(metrics::BUTTON_MIN_HEIGHT),
                grow,
            )
        }
        WidgetKind::Checkbox => {
            let (width, height) = text.text_size(string(view, "label"));

            leaf(
                metrics::CHECKBOX_BOX + metrics::CHECKBOX_GAP + width,
                height.max(metrics::CHECKBOX_BOX),
                grow,
            )
        }
        WidgetKind::TextInput => leaf(
            metrics::TEXT_INPUT_MIN_WIDTH,
            metrics::TEXT_INPUT_HEIGHT,
            grow,
        ),
        WidgetKind::MenuItem => {
            let (label_width, label_height) = text.text_size(string(view, "label"));
            let shortcut = string(view, "shortcut");
            let shortcut_width = if shortcut.is_empty() {
                0.0
            } else {
                metrics::MENU_ITEM_GAP + text.text_size(shortcut).0
            };

            leaf(
                metrics::MENU_ITEM_PAD_LEFT
                    + label_width
                    + shortcut_width
                    + metrics::MENU_ITEM_PAD_RIGHT,
                (label_height + 8.0).max(metrics::MENU_ITEM_HEIGHT),
                grow,
            )
        }
        WidgetKind::MenuSeparator => leaf(0.0, metrics::MENU_SEPARATOR_HEIGHT, grow),
        WidgetKind::Canvas => leaf(
            size_or(view, "width", metrics::CANVAS_WIDTH),
            size_or(view, "height", metrics::CANVAS_HEIGHT),
            grow,
        ),
        WidgetKind::ProgressBar => {
            leaf(metrics::PROGRESS_MIN_WIDTH, metrics::PROGRESS_HEIGHT, grow)
        }
        WidgetKind::Column | WidgetKind::Row => {
            let gap = length(number(view, "gap"));
            let padding = length(number(view, "padding"));

            Style {
                flex_direction: if view.kind == WidgetKind::Column {
                    FlexDirection::Column
                } else {
                    FlexDirection::Row
                },
                gap: Size {
                    width: gap,
                    height: gap,
                },
                padding: EdgeRect {
                    left: padding,
                    right: padding,
                    top: padding,
                    bottom: padding,
                },
                flex_grow: grow,
                ..Style::default()
            }
        }
        // A scroll view shows part of what it holds: it may be smaller than its child (the overflow is scrollable,
        // which lets it shrink below its content). Its child is not squeezed to fit: a flex item's automatic minimum
        // size is its content, and a test shows it (a mutation that made the child shrinkable changed nothing).
        WidgetKind::ScrollView => Style {
            flex_direction: FlexDirection::Column,
            overflow: Point {
                x: Overflow::Scroll,
                y: Overflow::Scroll,
            },
            flex_grow: grow,
            ..Style::default()
        },
    }
}

fn build(
    tree: &mut TaffyTree<()>,
    model: &Model,
    widget: u64,
    text: &dyn TextMetrics,
) -> Option<NodeId> {
    let view = model.widget_view(widget)?;
    let style = style_of(&view, text);

    // One child that cannot be built fails the whole layout, so that the children and their nodes always line up.
    let children = view
        .children
        .iter()
        .map(|child| build(tree, model, *child, text))
        .collect::<Option<Vec<NodeId>>>()?;

    tree.new_with_children(style, &children).ok()
}

fn collect(
    tree: &TaffyTree<()>,
    model: &Model,
    widget: u64,
    node: NodeId,
    origin: (f32, f32),
    layout: &mut Layout,
) {
    let (Ok(own), Some(view)) = (tree.layout(node), model.widget_view(widget)) else {
        return;
    };
    let (x, y) = (origin.0 + own.location.x, origin.1 + own.location.y);
    layout.rects.insert(
        widget,
        Rect {
            x,
            y,
            width: own.size.width,
            height: own.size.height,
        },
    );

    let Ok(children) = tree.children(node) else {
        return;
    };
    for (child, child_node) in view.children.iter().zip(children) {
        collect(tree, model, *child, child_node, (x, y), layout);
    }

    if view.kind == WidgetKind::ScrollView {
        // What it holds is everything inside it, wherever it ends: a child can hold things wider or taller than
        // itself (a row of fields wider than its column), and all of it can be scrolled to.
        let (mut right, mut bottom) = (0.0_f32, 0.0_f32);
        extent(
            model,
            layout,
            view.children,
            (x, y),
            &mut right,
            &mut bottom,
        );
        layout.contents.insert(widget, (right, bottom));
    }
}

/// Grows `right` and `bottom` to the far edges of the widgets and everything below them, measured from `origin`.
fn extent(
    model: &Model,
    layout: &Layout,
    widgets: &[u64],
    origin: (f32, f32),
    right: &mut f32,
    bottom: &mut f32,
) {
    for id in widgets {
        if let Some(rect) = layout.rects.get(id) {
            *right = right.max(rect.x + rect.width - origin.0);
            *bottom = bottom.max(rect.y + rect.height - origin.1);
        }

        if let Some(view) = model.widget_view(*id) {
            extent(model, layout, view.children, origin, right, bottom);
        }
    }
}
