//! Drawing (plan P3.8): paints the widgets of a window into a picture, on the CPU, from the model and its layout.
//!
//! The look is the base look only (no hover or pressed states: P3.10). Everything is drawn from `layout.rs`'s rectangles,
//! snapped to the physical pixel grid, and from the theme below. A scroll view clips what it holds to itself and
//! shows it shifted by its scroll offset.

use std::collections::HashMap;

use serde_json::Value;
use tiny_skia::{
    Color, FillRule, LineCap, LineJoin, Mask, Paint, Path, PathBuilder, Pixmap, Stroke, Transform,
};

use crate::canvas::{self, Op, Point, Rgba};
use crate::input::{Interaction, overlay_place};
use crate::layout::TextMetrics;
use crate::layout::{Layout, PixelRect, Rect, layout_overlay, layout_window, metrics};
use crate::model::{Model, WidgetKind, WidgetView};
use crate::text::{LINE_HEIGHT, TextRenderer, TextStyle};

/// The colors and sizes of the base look, light only (my choices, until a theme exists).
pub(crate) mod theme {
    use tiny_skia::Color;

    pub fn background() -> Color {
        Color::from_rgba8(0xFF, 0xFF, 0xFF, 0xFF)
    }

    pub fn text() -> Color {
        Color::from_rgba8(0x20, 0x20, 0x20, 0xFF)
    }

    pub fn text_disabled() -> Color {
        Color::from_rgba8(0x9A, 0x9A, 0x9A, 0xFF)
    }

    pub fn placeholder() -> Color {
        Color::from_rgba8(0x8A, 0x8A, 0x8A, 0xFF)
    }

    pub fn button() -> Color {
        Color::from_rgba8(0xE6, 0xE6, 0xE6, 0xFF)
    }

    pub fn border() -> Color {
        Color::from_rgba8(0xB4, 0xB4, 0xB4, 0xFF)
    }

    pub fn accent() -> Color {
        Color::from_rgba8(0x2D, 0x6C, 0xDF, 0xFF)
    }

    pub fn button_hover() -> Color {
        Color::from_rgba8(0xD8, 0xD8, 0xD8, 0xFF)
    }

    pub fn button_pressed() -> Color {
        Color::from_rgba8(0xC4, 0xC4, 0xC4, 0xFF)
    }

    pub fn border_hover() -> Color {
        Color::from_rgba8(0x80, 0x80, 0x80, 0xFF)
    }

    pub fn track() -> Color {
        Color::from_rgba8(0xDC, 0xDC, 0xDC, 0xFF)
    }

    /// Corner radius and border width in logical pixels.
    pub(crate) const RADIUS: f32 = 4.0;
    pub(crate) const BORDER: f32 = 1.0;
    pub(crate) const INPUT_PADDING: f32 = 8.0;
}

/// How long the sweep of an indeterminate progress bar takes to cross the bar (my number).
const INDETERMINATE_PERIOD: std::time::Duration = std::time::Duration::from_millis(1400);

/// How far each scroll view is scrolled, in logical pixels, by the scroll view's widget id.
pub(crate) type ScrollOffsets = HashMap<u64, (f32, f32)>;

/// Draws a window into a picture of `size` physical pixels, at `scale` physical pixels per logical pixel. None
/// when there is no such window or the size is empty or too large to make.
pub(crate) fn render(
    model: &Model,
    window: u64,
    size: (u32, u32),
    scale: f64,
    text: &TextRenderer,
    view: &Interaction,
) -> Option<Pixmap> {
    let (width, height, root) = model.window_content(window)?;
    let mut pixmap = Pixmap::new(size.0, size.1)?;
    pixmap.fill(theme::background());

    let window_layout = match root {
        Some(_) => Some(layout_window(model, window, text)?),
        None => None,
    };
    if let (Some(root), Some(layout)) = (root, window_layout.as_ref()) {
        let painter = Painter {
            model,
            layout,
            scale: scale as f32,
            text,
            view,
            window,
        };
        painter.widget(&mut pixmap, root, (0.0, 0.0), None);
    }

    // The overlays last, so that they are above everything, the one shown last above the others, each kept inside
    // the window.
    for overlay in view.overlays(window) {
        let Some(layout) = layout_overlay(model, overlay.widget, text) else {
            continue;
        };
        let Some(own) = layout.rect(overlay.widget) else {
            continue;
        };
        let origin = overlay_place(
            model,
            window_layout.as_ref(),
            root,
            &view.scroll,
            (width as f32, height as f32),
            overlay,
            (own.width, own.height),
        );
        let painter = Painter {
            model,
            layout: &layout,
            scale: scale as f32,
            text,
            view,
            window,
        };
        painter.panel(
            &mut pixmap,
            Rect {
                x: origin.0 + own.x,
                y: origin.1 + own.y,
                ..own
            },
        );
        painter.widget(&mut pixmap, overlay.widget, origin, None);
    }

    Some(pixmap)
}

/// A clip for a picture of this size: the rectangle, cut down to `outer` when there already is a clip (a scroll view
/// inside a scroll view shows only what both show).
pub(crate) fn clip_mask(size: (u32, u32), area: PixelRect, outer: Option<&Mask>) -> Option<Mask> {
    let path = PathBuilder::from_rect(tiny_skia::Rect::from_xywh(
        area.x as f32,
        area.y as f32,
        area.width as f32,
        area.height as f32,
    )?);
    let Some(outer) = outer else {
        let mut mask = Mask::new(size.0, size.1)?;
        mask.fill_path(&path, FillRule::Winding, false, Transform::identity());

        return Some(mask);
    };

    let mut mask = outer.clone();
    mask.intersect_path(&path, FillRule::Winding, false, Transform::identity());

    Some(mask)
}

/// Where a widget is: its rectangle in the layout, how far its parents have shifted it, and the pixels that makes.
struct Place {
    rect: Rect,
    offset: (f32, f32),
    area: PixelRect,
}

/// How far a scroll view is really scrolled: what was asked for, kept between 0 and what its content holds beyond
/// what it shows.
pub(crate) fn scroll_shift(
    layout: &Layout,
    id: u64,
    rect: Rect,
    scroll: &ScrollOffsets,
) -> (f32, f32) {
    let (content_width, content_height) = layout.content_size(id).unwrap_or((0.0, 0.0));
    let (wanted_x, wanted_y) = scroll.get(&id).copied().unwrap_or((0.0, 0.0));

    (
        wanted_x.clamp(0.0, (content_width - rect.width).max(0.0)),
        wanted_y.clamp(0.0, (content_height - rect.height).max(0.0)),
    )
}

struct Painter<'a> {
    model: &'a Model,
    layout: &'a Layout,
    scale: f32,
    text: &'a TextRenderer,
    /// The user's interaction: scroll offsets, hover, pressed, focus, and the time of the frame.
    view: &'a Interaction,
    window: u64,
}

fn number(view: &WidgetView<'_>, name: &str) -> f32 {
    match view.props.get(name) {
        Some(Value::Number(number)) => number.as_f64().unwrap_or(0.0) as f32,
        _ => 0.0,
    }
}

fn string<'a>(view: &'a WidgetView<'_>, name: &str) -> &'a str {
    match view.props.get(name) {
        Some(Value::String(text)) => text,
        _ => "",
    }
}

/// A boolean property, with the value to use when it was never set.
fn flag(view: &WidgetView<'_>, name: &str, default: bool) -> bool {
    match view.props.get(name) {
        Some(Value::Bool(value)) => *value,
        _ => default,
    }
}

fn rounded(x: f32, y: f32, width: f32, height: f32, radius: f32) -> Option<Path> {
    let r = radius.min(width / 2.0).min(height / 2.0).max(0.0);
    let mut path = PathBuilder::new();
    path.move_to(x + r, y);
    path.line_to(x + width - r, y);
    path.quad_to(x + width, y, x + width, y + r);
    path.line_to(x + width, y + height - r);
    path.quad_to(x + width, y + height, x + width - r, y + height);
    path.line_to(x + r, y + height);
    path.quad_to(x, y + height, x, y + height - r);
    path.line_to(x, y + r);
    path.quad_to(x, y, x + r, y);
    path.close();

    path.finish()
}

fn paint_of(color: Color) -> Paint<'static> {
    let mut paint = Paint::default();
    paint.set_color(color);
    paint.anti_alias = true;

    paint
}

impl Painter<'_> {
    fn px(&self, rect: Rect, offset: (f32, f32)) -> PixelRect {
        Rect {
            x: rect.x + offset.0,
            y: rect.y + offset.1,
            ..rect
        }
        .snapped(f64::from(self.scale))
    }

    fn widget(&self, pixmap: &mut Pixmap, id: u64, offset: (f32, f32), clip: Option<&Mask>) {
        let (Some(rect), Some(view)) = (self.layout.rect(id), self.model.widget_view(id)) else {
            return;
        };
        let area = self.px(rect, offset);

        match view.kind {
            WidgetKind::Label => self.label(pixmap, &view, area, clip),
            WidgetKind::Button => self.button(pixmap, id, &view, area, clip),
            WidgetKind::ProgressBar => self.progress(pixmap, &view, area, clip),
            WidgetKind::Checkbox => self.checkbox(pixmap, id, &view, area, clip),
            WidgetKind::TextInput => self.text_input(pixmap, id, &view, area, clip),
            WidgetKind::MenuItem => self.menu_item(pixmap, id, &view, area, clip),
            WidgetKind::MenuSeparator => self.separator(pixmap, area, clip),
            WidgetKind::Canvas => self.canvas(pixmap, &view, area, clip),
            WidgetKind::Column | WidgetKind::Row | WidgetKind::ScrollView => {}
        }

        if view.kind == WidgetKind::ScrollView {
            let place = Place { rect, offset, area };
            self.scroll_view(pixmap, id, &view, place, clip);

            return;
        }

        for child in view.children {
            self.widget(pixmap, *child, offset, clip);
        }
    }

    /// A scroll view clips its children to itself and shows them shifted by how far it is scrolled, which cannot
    /// be more than what they hold beyond what it shows.
    fn scroll_view(
        &self,
        pixmap: &mut Pixmap,
        id: u64,
        view: &WidgetView<'_>,
        place: Place,
        clip: Option<&Mask>,
    ) {
        let Place { rect, offset, area } = place;
        let shift = scroll_shift(self.layout, id, rect, &self.view.scroll);
        let Some(mask) = self.clipped(pixmap, area, clip) else {
            return;
        };

        for child in view.children {
            self.widget(
                pixmap,
                *child,
                (offset.0 - shift.0, offset.1 - shift.1),
                Some(&mask),
            );
        }
    }

    /// The clip of `outer` cut down to a rectangle.
    fn clipped(&self, pixmap: &Pixmap, area: PixelRect, outer: Option<&Mask>) -> Option<Mask> {
        clip_mask((pixmap.width(), pixmap.height()), area, outer)
    }

    fn fill(&self, pixmap: &mut Pixmap, path: &Path, color: Color, clip: Option<&Mask>) {
        pixmap.fill_path(
            path,
            &paint_of(color),
            FillRule::Winding,
            Transform::identity(),
            clip,
        );
    }

    /// A rounded rectangle with a border drawn inside its edge.
    fn boxed(
        &self,
        pixmap: &mut Pixmap,
        area: PixelRect,
        fill: Color,
        border: Color,
        clip: Option<&Mask>,
    ) {
        let (x, y) = (area.x as f32, area.y as f32);
        let (width, height) = (area.width as f32, area.height as f32);
        let radius = theme::RADIUS * self.scale;
        let line = (theme::BORDER * self.scale).max(1.0);

        if let Some(path) = rounded(x, y, width, height, radius) {
            self.fill(pixmap, &path, fill, clip);
        }

        if let Some(path) = rounded(
            x + line / 2.0,
            y + line / 2.0,
            width - line,
            height - line,
            radius - line / 2.0,
        ) {
            let stroke = Stroke {
                width: line,
                ..Stroke::default()
            };
            pixmap.stroke_path(
                &path,
                &paint_of(border),
                &stroke,
                Transform::identity(),
                clip,
            );
        }
    }

    /// The ring that shows which widget has the keyboard focus, just outside its edge.
    fn ring(&self, pixmap: &mut Pixmap, area: PixelRect, clip: Option<&Mask>) {
        let grow = self.scale;
        let line = 2.0 * self.scale;
        if let Some(path) = rounded(
            area.x as f32 - grow,
            area.y as f32 - grow,
            area.width as f32 + 2.0 * grow,
            area.height as f32 + 2.0 * grow,
            theme::RADIUS * self.scale + grow,
        ) {
            let stroke = Stroke {
                width: line,
                ..Stroke::default()
            };
            pixmap.stroke_path(
                &path,
                &paint_of(theme::accent()),
                &stroke,
                Transform::identity(),
                clip,
            );
        }
    }

    /// What an overlay stands on: a soft shadow below and to the sides, then a bordered white panel.
    fn panel(&self, pixmap: &mut Pixmap, rect: Rect) {
        let area = rect.snapped(f64::from(self.scale));
        let offset = 2.0 * self.scale;
        for (grow, alpha) in [(3.0_f32, 0.05_f32), (2.0, 0.07), (1.0, 0.09)] {
            let spread = grow * self.scale;
            if let Some(shadow) = rounded(
                area.x as f32 - spread,
                area.y as f32 - spread + offset,
                area.width as f32 + 2.0 * spread,
                area.height as f32 + 2.0 * spread,
                theme::RADIUS * self.scale + spread,
            ) {
                self.fill(
                    pixmap,
                    &shadow,
                    Color::from_rgba(0.0, 0.0, 0.0, alpha).unwrap_or(Color::BLACK),
                    None,
                );
            }
        }

        self.boxed(pixmap, area, theme::background(), theme::border(), None);
    }

    /// A rectangle `(x, y, width, height)` of whole pixels, filled without antialiasing (a caret, a selection): crisp,
    /// and clear of a failure of tiny-skia's hairline code on thin rectangles that sit between pixels.
    fn fill_snapped(
        &self,
        pixmap: &mut Pixmap,
        rect: (f32, f32, f32, f32),
        color: Color,
        clip: &Mask,
    ) {
        let Some(rect) = tiny_skia::Rect::from_xywh(rect.0, rect.1, rect.2, rect.3) else {
            return;
        };
        let mut paint = Paint::default();
        paint.set_color(color);
        paint.anti_alias = false;
        pixmap.fill_rect(rect, &paint, Transform::identity(), Some(clip));
    }

    fn focused(&self, id: u64) -> bool {
        self.view.focus(self.window) == Some(id)
    }

    /// Text with its top left at `(x, y)` physical pixels.
    fn write(
        &self,
        pixmap: &mut Pixmap,
        text: &str,
        x: f32,
        y: f32,
        color: Color,
        clip: Option<&Mask>,
    ) {
        let style = TextStyle {
            scale: self.scale,
            color,
        };
        self.text.paint(pixmap, text, (x, y), &style, clip);
    }

    /// The top of text of this many lines that is centered in a rectangle.
    fn centered_y(&self, area: PixelRect, text: &str) -> f32 {
        let lines = text.split('\n').count() as f32;

        area.y as f32 + (area.height as f32 - lines * LINE_HEIGHT * self.scale) / 2.0
    }

    fn label(
        &self,
        pixmap: &mut Pixmap,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        self.write(
            pixmap,
            string(view, "text"),
            area.x as f32,
            area.y as f32,
            theme::text(),
            clip,
        );
    }

    /// A menu entry: lit when the pointer is over it or the keyboard is on it, with a check mark at the left if it is
    /// checked, its label, and its shortcut grey at the right.
    fn menu_item(
        &self,
        pixmap: &mut Pixmap,
        id: u64,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let enabled = flag(view, "enabled", true);
        let lit = if !enabled {
            0.0
        } else if self.focused(id) {
            1.0
        } else {
            self.view.hover_amount(id)
        };
        if lit > 0.0
            && let Some(path) = rounded(
                area.x as f32,
                area.y as f32,
                area.width as f32,
                area.height as f32,
                theme::RADIUS * self.scale,
            )
        {
            self.fill(
                pixmap,
                &path,
                mix(theme::background(), theme::accent(), 0.20 * lit),
                clip,
            );
        }

        let color = if enabled {
            theme::text()
        } else {
            theme::text_disabled()
        };
        let label = string(view, "label");
        let left = area.x as f32 + metrics::MENU_ITEM_PAD_LEFT * self.scale;
        self.write(
            pixmap,
            label,
            left,
            self.centered_y(area, label),
            color,
            clip,
        );

        if flag(view, "checked", false) {
            let (x, y) = (
                area.x as f32 + 8.0 * self.scale,
                area.y as f32 + area.height as f32 / 2.0,
            );
            let mut mark = PathBuilder::new();
            mark.move_to(x, y);
            mark.line_to(x + 3.5 * self.scale, y + 3.5 * self.scale);
            mark.line_to(x + 10.0 * self.scale, y - 4.0 * self.scale);
            if let Some(path) = mark.finish() {
                let stroke = Stroke {
                    width: (1.8 * self.scale).max(1.0),
                    line_cap: LineCap::Round,
                    line_join: tiny_skia::LineJoin::Round,
                    ..Stroke::default()
                };
                pixmap.stroke_path(
                    &path,
                    &paint_of(color),
                    &stroke,
                    Transform::identity(),
                    clip,
                );
            }
        }

        let shortcut = string(view, "shortcut");
        if !shortcut.is_empty() {
            let (width, _) = self.text.text_size(shortcut);
            let x = area.x as f32 + area.width as f32
                - (metrics::MENU_ITEM_PAD_RIGHT + width) * self.scale;
            self.write(
                pixmap,
                shortcut,
                x,
                self.centered_y(area, shortcut),
                theme::text_disabled(),
                clip,
            );
        }
    }

    /// A thin line across a menu, between groups of entries.
    /// A canvas: its draw list, in logical pixels from its top left corner, clipped to the canvas. The list was checked
    /// when it was set, so one that no longer parses (it cannot) draws nothing.
    fn canvas(
        &self,
        pixmap: &mut Pixmap,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let Some(Ok(ops)) = view.props.get("ops").map(canvas::parse) else {
            return;
        };
        let Some(mask) = self.clipped(pixmap, area, clip) else {
            return;
        };
        let scale = self.scale;
        let place = |point: Point| {
            (
                area.x as f32 + point.0 * scale,
                area.y as f32 + point.1 * scale,
            )
        };
        let color = |c: Rgba| Color::from_rgba8(c.red, c.green, c.blue, c.alpha);
        let stroke = |pixmap: &mut Pixmap, path: &Path, line: canvas::Stroke| {
            let style = Stroke {
                width: line.width * scale,
                line_cap: LineCap::Butt,
                line_join: LineJoin::Round,
                ..Stroke::default()
            };
            pixmap.stroke_path(
                path,
                &paint_of(color(line.color)),
                &style,
                Transform::identity(),
                Some(&mask),
            );
        };

        for op in &ops {
            match op {
                Op::Rect {
                    at,
                    size,
                    radius,
                    fill,
                    stroke: line,
                } => {
                    let (x, y) = place(*at);
                    let Some(path) = rounded(x, y, size.0 * scale, size.1 * scale, radius * scale)
                    else {
                        continue;
                    };
                    if let Some(c) = fill {
                        self.fill(pixmap, &path, color(*c), Some(&mask));
                    }
                    if let Some(line) = line {
                        stroke(pixmap, &path, *line);
                    }
                }
                Op::Line {
                    from,
                    to,
                    stroke: line,
                } => {
                    let (from, to) = (place(*from), place(*to));
                    let mut path = PathBuilder::new();
                    path.move_to(from.0, from.1);
                    path.line_to(to.0, to.1);
                    if let Some(path) = path.finish() {
                        stroke(pixmap, &path, *line);
                    }
                }
                Op::Path {
                    points,
                    closed,
                    fill,
                    stroke: line,
                } => {
                    // Filled as if closed; stroked closed only when asked.
                    let build = |close: bool| {
                        let mut path = PathBuilder::new();
                        for (at, point) in points.iter().enumerate() {
                            let (x, y) = place(*point);
                            if at == 0 {
                                path.move_to(x, y);
                            } else {
                                path.line_to(x, y);
                            }
                        }
                        if close {
                            path.close();
                        }

                        path.finish()
                    };
                    if let Some(c) = fill
                        && let Some(path) = build(true)
                    {
                        self.fill(pixmap, &path, color(*c), Some(&mask));
                    }
                    if let Some(line) = line
                        && let Some(path) = build(*closed)
                    {
                        stroke(pixmap, &path, *line);
                    }
                }
                Op::Circle {
                    center,
                    radius,
                    fill,
                    stroke: line,
                } => {
                    let (x, y) = place(*center);
                    let Some(path) = PathBuilder::from_circle(x, y, radius * scale) else {
                        continue;
                    };
                    if let Some(c) = fill {
                        self.fill(pixmap, &path, color(*c), Some(&mask));
                    }
                    if let Some(line) = line {
                        stroke(pixmap, &path, *line);
                    }
                }
                Op::Text {
                    at,
                    text,
                    color: ink,
                    size,
                } => {
                    let (x, y) = place(*at);
                    let style = TextStyle {
                        scale: scale * size / crate::text::FONT_SIZE,
                        color: ink.map_or_else(theme::text, color),
                    };
                    self.text.paint(pixmap, text, (x, y), &style, Some(&mask));
                }
            }
        }
    }

    fn separator(&self, pixmap: &mut Pixmap, area: PixelRect, clip: Option<&Mask>) {
        let Some(mask) = self.clipped(pixmap, area, clip) else {
            return;
        };
        let thickness = self.scale.round().max(1.0);
        let margin = 8.0 * self.scale;
        self.fill_snapped(
            pixmap,
            (
                area.x as f32 + margin,
                (area.y as f32 + area.height as f32 / 2.0).round(),
                (area.width as f32 - 2.0 * margin).max(0.0),
                thickness,
            ),
            theme::border(),
            &mask,
        );
    }

    fn button(
        &self,
        pixmap: &mut Pixmap,
        id: u64,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let enabled = flag(view, "enabled", true);
        let pressed = self.view.pressed(self.window) == Some(id);
        let hover = self.view.hover_amount(id);
        let fill = if pressed {
            theme::button_pressed()
        } else {
            mix(theme::button(), theme::button_hover(), hover)
        };
        self.boxed(
            pixmap,
            area,
            fill,
            mix(theme::border(), theme::border_hover(), hover),
            clip,
        );
        if self.focused(id) {
            self.ring(pixmap, area, clip);
        }

        let label = string(view, "label");
        let (width, _) = self.text.text_size(label);
        let color = if enabled {
            theme::text()
        } else {
            theme::text_disabled()
        };
        let x = area.x as f32 + (area.width as f32 - width * self.scale) / 2.0;
        self.write(pixmap, label, x, self.centered_y(area, label), color, clip);
    }

    fn progress(
        &self,
        pixmap: &mut Pixmap,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let (x, y) = (area.x as f32, area.y as f32);
        let (width, height) = (area.width as f32, area.height as f32);
        if let Some(track) = rounded(x, y, width, height, height / 2.0) {
            self.fill(pixmap, &track, theme::track(), clip);
        }

        if flag(view, "indeterminate", false) {
            // A segment a third as wide as the bar sweeps across it, once every INDETERMINATE_PERIOD, entering at the
            // left edge and leaving at the right, clipped to the bar. It is a function of the time of the frame alone.
            let phase =
                (self.view.now().as_secs_f32() / INDETERMINATE_PERIOD.as_secs_f32()).fract();
            let segment = width / 3.0;
            let left = x - segment + (width + segment) * phase;
            let Some(mask) = self.clipped(pixmap, area, clip) else {
                return;
            };
            if let Some(bar) = rounded(left, y, segment, height, height / 2.0) {
                self.fill(pixmap, &bar, theme::accent(), Some(&mask));
            }

            return;
        }

        let filled = width * number(view, "value").clamp(0.0, 1.0);
        if filled >= 1.0
            && let Some(bar) = rounded(x, y, filled, height, height / 2.0)
        {
            self.fill(pixmap, &bar, theme::accent(), clip);
        }
    }

    fn checkbox(
        &self,
        pixmap: &mut Pixmap,
        id: u64,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let side = (metrics::CHECKBOX_BOX * self.scale).round();
        let top = area.y as f32 + ((area.height as f32 - side) / 2.0).round();
        let square = PixelRect {
            x: area.x,
            y: top as i64,
            width: side as u64,
            height: side as u64,
        };
        let checked = flag(view, "checked", false);
        let hover = self.view.hover_amount(id);
        let pressed = self.view.pressed(self.window) == Some(id);
        let fill = match (checked, pressed) {
            (true, _) => theme::accent(),
            (false, true) => theme::button_pressed(),
            (false, false) => theme::background(),
        };
        self.boxed(
            pixmap,
            square,
            fill,
            mix(theme::border(), theme::accent(), hover),
            clip,
        );
        if self.focused(id) {
            self.ring(pixmap, square, clip);
        }

        if checked {
            let (x, y) = (square.x as f32, square.y as f32);
            let mut mark = PathBuilder::new();
            mark.move_to(x + side * 0.25, y + side * 0.55);
            mark.line_to(x + side * 0.43, y + side * 0.72);
            mark.line_to(x + side * 0.78, y + side * 0.30);
            if let Some(path) = mark.finish() {
                let stroke = Stroke {
                    width: (2.0 * self.scale).max(1.0),
                    line_cap: LineCap::Round,
                    ..Stroke::default()
                };
                pixmap.stroke_path(
                    &path,
                    &paint_of(theme::background()),
                    &stroke,
                    Transform::identity(),
                    clip,
                );
            }
        }

        let label = string(view, "label");
        let x = area.x as f32 + (metrics::CHECKBOX_BOX + metrics::CHECKBOX_GAP) * self.scale;
        let color = if flag(view, "enabled", true) {
            theme::text()
        } else {
            theme::text_disabled()
        };
        self.write(pixmap, label, x, self.centered_y(area, label), color, clip);
    }

    fn text_input(
        &self,
        pixmap: &mut Pixmap,
        id: u64,
        view: &WidgetView<'_>,
        area: PixelRect,
        clip: Option<&Mask>,
    ) {
        let hover = self.view.hover_amount(id);
        let focused = self.focused(id);
        let border = if focused {
            theme::accent()
        } else {
            mix(theme::border(), theme::border_hover(), hover)
        };
        self.boxed(pixmap, area, theme::background(), border, clip);
        if focused {
            self.ring(pixmap, area, clip);
        }

        let text = string(view, "text");
        let (shown, color) = if text.is_empty() {
            (string(view, "placeholder"), theme::placeholder())
        } else if flag(view, "enabled", true) {
            (text, theme::text())
        } else {
            (text, theme::text_disabled())
        };
        let padding = theme::INPUT_PADDING * self.scale;
        // What does not fit is cut off at the edge, not drawn over what is next to the input.
        let inner = PixelRect {
            x: area.x + padding as i64,
            y: area.y,
            width: area.width.saturating_sub(2 * padding as u64),
            height: area.height,
        };
        let Some(mask) = self.clipped(pixmap, inner, clip) else {
            return;
        };

        // The caret and the selection belong to the focused input; the text is shifted so that the caret is in view.
        let state = if focused { self.view.cursor(id) } else { None };
        let scroll = state.map_or(0.0, |cursor| cursor.scroll) * self.scale;
        let left = inner.x as f32 - scroll;
        let line = LINE_HEIGHT * self.scale;
        let top = self.centered_y(area, shown);
        let caret = state.map_or(text.len(), |cursor| at_boundary(text, cursor.caret));

        // An input method that is composing has its text shown at the caret, underlined, as if it were part of the
        // text; it is not, until it is committed.
        let composing = state.and_then(|cursor| cursor.preedit.as_ref());
        let composed;
        let (shown, color) = match composing {
            Some((preedit, _)) if flag(view, "enabled", true) => {
                composed = format!("{}{}{}", &text[..caret], preedit, &text[caret..]);

                (composed.as_str(), theme::text())
            }
            _ => (shown, color),
        };
        let measure =
            |index: usize| self.text.text_size(&shown[..at_boundary(shown, index)]).0 * self.scale;

        if composing.is_none()
            && let Some(cursor) = state
            && cursor.caret != cursor.anchor
        {
            let (start, end) = (
                cursor.caret.min(cursor.anchor),
                cursor.caret.max(cursor.anchor),
            );
            let (x0, x1) = (
                (left + measure(start)).round(),
                (left + measure(end)).round(),
            );
            self.fill_snapped(
                pixmap,
                (x0, top.round(), x1 - x0, line.round()),
                mix(theme::background(), theme::accent(), 0.35),
                &mask,
            );
        }

        self.write(pixmap, shown, left, top, color, Some(&mask));

        let mut caret_at = caret;
        if let Some((preedit, own_cursor)) = composing {
            let (x0, x1) = (
                (left + measure(caret)).round(),
                (left + measure(caret + preedit.len())).round(),
            );
            let thickness = self.scale.round().max(1.0);
            self.fill_snapped(
                pixmap,
                (
                    x0,
                    (top + line).round() - 2.0 * thickness,
                    x1 - x0,
                    thickness,
                ),
                theme::text(),
                &mask,
            );
            caret_at =
                caret + own_cursor.map_or(preedit.len(), |(start, _)| start.min(preedit.len()));
        }

        if focused && flag(view, "enabled", true) {
            let x = (left + measure(caret_at)).round();
            self.fill_snapped(
                pixmap,
                (x, top.round(), self.scale.round().max(1.0), line.round()),
                theme::text(),
                &mask,
            );
        }
    }
}

/// The nearest place at or before an index that is a character boundary of the text.
fn at_boundary(text: &str, mut index: usize) -> usize {
    index = index.min(text.len());
    while !text.is_char_boundary(index) {
        index -= 1;
    }

    index
}

/// The color a fraction of the way from one to another, per channel.
fn mix(from: Color, to: Color, amount: f32) -> Color {
    let amount = amount.clamp(0.0, 1.0);
    let channel = |a: f32, b: f32| a + (b - a) * amount;

    Color::from_rgba(
        channel(from.red(), to.red()),
        channel(from.green(), to.green()),
        channel(from.blue(), to.blue()),
        channel(from.alpha(), to.alpha()),
    )
    .unwrap_or(from)
}
