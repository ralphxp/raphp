//! Layout (plan P3.7): a table of cases with the exact rectangles expected, through the model and no window. Text
//! is measured by the placeholder ruler (8 by 16 per character and line), so every number below is exact.

use std::sync::Arc;
use std::time::Instant;

use serde_json::{Value, json};

use crate::backend::HeadlessBackend;
use crate::config::Config;
use crate::diagnostics::Diagnostics;
use crate::events::Event;
use crate::layout::{
    Layout, PixelRect, PlaceholderMetrics, Rect, TextMetrics, layout_window, metrics,
};
use crate::model::Model;
use crate::protocol::parse_batch;

fn rect(x: f32, y: f32, width: f32, height: f32) -> Rect {
    Rect {
        x,
        y,
        width,
        height,
    }
}

/// A smaller ruler (5 by 10), so that sizes that do not depend on the text can be seen: with the usual 16-high lines
/// a button is 28 high whatever its minimum.
struct SmallRuler;

impl TextMetrics for SmallRuler {
    fn text_size(&self, text: &str) -> (f32, f32) {
        let lines = text.split('\n');
        let widest = lines
            .clone()
            .map(|line| line.chars().count())
            .max()
            .unwrap_or(0);

        (widest as f32 * 5.0, lines.count() as f32 * 10.0)
    }
}

/// Builds a model from the commands, with no errors allowed, and lays out window 1.
fn laid_out(commands: &Value) -> (Model, Layout) {
    laid_out_with(commands, &PlaceholderMetrics)
}

fn laid_out_with(commands: &Value, ruler: &dyn TextMetrics) -> (Model, Layout) {
    let text = json!({"batch": 1, "commands": commands}).to_string();
    let mut model = Model::new(Arc::new(Diagnostics::default()));
    let mut events: Vec<Event> = Vec::new();
    let mut backend = HeadlessBackend::new(&Config::default(), Arc::default());
    model.apply_batch(parse_batch(&text).unwrap(), &mut events, &mut backend);
    let errors: Vec<&str> = events
        .iter()
        .map(|event| event.json.as_str())
        .filter(|json| json.contains("\"error\""))
        .collect();
    assert!(errors.is_empty(), "the setup failed: {errors:?}");

    let layout = layout_window(&model, 1, ruler).expect("window 1 exists");

    (model, layout)
}

fn window(width: u32, height: u32) -> Value {
    json!({"op": "window.create", "id": 1, "width": width, "height": height})
}

fn widget(id: u64, kind: &str, props: &Value) -> Value {
    json!({"op": "widget.create", "id": id, "type": kind, "props": props})
}

fn append(parent: u64, child: u64) -> Value {
    json!({"op": "widget.append", "parent": parent, "child": child})
}

fn root(id: u64) -> Value {
    json!({"op": "window.set_root", "id": 1, "widget": id})
}

// ---------------------------------------------------------------- the table

#[test]
fn the_root_fills_the_window_whatever_it_holds() {
    let (_, layout) = laid_out(&json!([
        window(400, 300),
        widget(1, "label", &json!({"text": "hi"})),
        root(1),
    ]));

    assert_eq!(layout.rect(1), Some(rect(0.0, 0.0, 400.0, 300.0)));
}

#[test]
fn a_window_without_a_root_has_an_empty_layout_and_an_unknown_window_has_none() {
    let (model, layout) = laid_out(&json!([window(400, 300)]));

    assert_eq!(layout, Layout::default());
    assert_eq!(layout.len(), 0);
    assert!(layout_window(&model, 9, &PlaceholderMetrics).is_none());
}

#[test]
fn a_column_stacks_its_children_with_padding_and_gap_and_stretches_them_across() {
    // Labels "ab", "abcd", "a" are 16 high; the column has padding 10 and gap 5 in a 400 by 300 window.
    let (_, layout) = laid_out(&json!([
        window(400, 300),
        widget(1, "column", &json!({"padding": 10, "gap": 5})),
        widget(2, "label", &json!({"text": "ab"})),
        widget(3, "label", &json!({"text": "abcd"})),
        widget(4, "label", &json!({"text": "a"})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    assert_eq!(layout.rect(1), Some(rect(0.0, 0.0, 400.0, 300.0)));
    assert_eq!(layout.rect(2), Some(rect(10.0, 10.0, 380.0, 16.0)));
    assert_eq!(layout.rect(3), Some(rect(10.0, 31.0, 380.0, 16.0)));
    assert_eq!(layout.rect(4), Some(rect(10.0, 52.0, 380.0, 16.0)));
}

#[test]
fn a_row_puts_its_children_side_by_side_at_their_own_widths_and_stretches_them_down() {
    // A button "OK" is 16 + 24 = 40 wide and 28 high; a label "xyz" 24 by 16; the gap is 8.
    let (_, layout) = laid_out(&json!([
        window(400, 100),
        widget(1, "row", &json!({"gap": 8})),
        widget(2, "button", &json!({"label": "OK"})),
        widget(3, "label", &json!({"text": "xyz"})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 40.0, 100.0)));
    assert_eq!(layout.rect(3), Some(rect(48.0, 0.0, 24.0, 100.0)));
}

#[test]
fn grow_shares_what_is_left_in_proportion() {
    // Two empty columns in a row 300 wide: they have no size of their own, so the whole width is shared 1 to 2.
    let (_, layout) = laid_out(&json!([
        window(300, 80),
        widget(1, "row", &json!({})),
        widget(2, "column", &json!({"grow": 1})),
        widget(3, "column", &json!({"grow": 2})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 100.0, 80.0)));
    assert_eq!(layout.rect(3), Some(rect(100.0, 0.0, 200.0, 80.0)));
}

#[test]
fn a_text_input_that_grows_takes_the_rest_of_the_row() {
    // A label "Name:" (40 wide), a gap of 8, a text input that grows, a button "Go" (16 + 24 = 40): 400 - 40 - 8 - 8 - 40 = 304.
    let (_, layout) = laid_out(&json!([
        window(400, 60),
        widget(1, "row", &json!({"gap": 8})),
        widget(2, "label", &json!({"text": "Name:"})),
        widget(3, "text_input", &json!({"grow": 1})),
        widget(4, "button", &json!({"label": "Go"})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 40.0, 60.0)));
    assert_eq!(layout.rect(3), Some(rect(48.0, 0.0, 304.0, 60.0)));
    assert_eq!(layout.rect(4), Some(rect(360.0, 0.0, 40.0, 60.0)));
}

#[test]
fn a_nested_column_that_grows_fills_the_height_left_by_its_sibling() {
    let (_, layout) = laid_out(&json!([
        window(200, 300),
        widget(1, "column", &json!({})),
        widget(2, "label", &json!({"text": "top"})),
        widget(3, "column", &json!({"grow": 1, "padding": 4})),
        widget(4, "label", &json!({"text": "in"})),
        append(1, 2),
        append(1, 3),
        append(3, 4),
        root(1),
    ]));

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 200.0, 16.0)));
    assert_eq!(layout.rect(3), Some(rect(0.0, 16.0, 200.0, 284.0)));
    assert_eq!(
        layout.rect(4),
        Some(rect(4.0, 20.0, 192.0, 16.0)),
        "positions are from the window, not from the parent"
    );
}

#[test]
fn widgets_are_never_made_smaller_than_their_own_size_and_overflow_instead() {
    // Three text inputs (160 each) in a row 400 wide: they keep 160 and run past the edge.
    let (_, layout) = laid_out(&json!([
        window(400, 40),
        widget(1, "row", &json!({})),
        widget(2, "text_input", &json!({})),
        widget(3, "text_input", &json!({})),
        widget(4, "text_input", &json!({})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 160.0, 40.0)));
    assert_eq!(layout.rect(3), Some(rect(160.0, 0.0, 160.0, 40.0)));
    assert_eq!(layout.rect(4), Some(rect(320.0, 0.0, 160.0, 40.0)));
}

#[test]
fn what_a_scroll_view_holds_keeps_its_size_and_the_scroll_view_reports_it() {
    // Ten labels of 16 high are 160 high in a scroll view that shows 100.
    let mut commands = vec![
        window(200, 100),
        json!({"op": "widget.create", "id": 1, "type": "scroll_view"}),
        widget(2, "column", &json!({})),
        append(1, 2),
        root(1),
    ];
    for index in 0..10_u64 {
        commands.push(widget(10 + index, "label", &json!({"text": "row"})));
        commands.push(append(2, 10 + index));
    }
    let (_, layout) = laid_out(&Value::Array(commands));

    assert_eq!(layout.rect(1), Some(rect(0.0, 0.0, 200.0, 100.0)));
    assert_eq!(
        layout.rect(2),
        Some(rect(0.0, 0.0, 200.0, 160.0)),
        "the column keeps its 160, it is not squeezed into the 100"
    );
    assert_eq!(layout.content_size(1), Some((200.0, 160.0)));
    assert_eq!(layout.rect(19), Some(rect(0.0, 144.0, 200.0, 16.0)));
    assert_eq!(
        layout.content_size(2),
        None,
        "only a scroll view has content to scroll"
    );
}

#[test]
fn a_scroll_view_reports_what_overflows_it_sideways_as_well() {
    // Three text inputs (160 each) in a row inside a scroll view 200 wide: the row is stretched to 200, but what is
    // in it reaches 480, and the scroll view can be scrolled that far.
    let (_, layout) = laid_out(&json!([
        window(200, 100),
        json!({"op": "widget.create", "id": 1, "type": "scroll_view"}),
        widget(2, "row", &json!({})),
        widget(3, "text_input", &json!({})),
        widget(4, "text_input", &json!({})),
        widget(5, "text_input", &json!({})),
        append(1, 2),
        append(2, 3),
        append(2, 4),
        append(2, 5),
        root(1),
    ]));

    // 480 wide; only 28 high (the row's own height), even though the view shows 100.
    assert_eq!(layout.content_size(1), Some((480.0, 28.0)));
}

#[test]
fn checkboxes_progress_bars_and_buttons_have_their_sizes_and_the_rest_is_stretch() {
    let (_, layout) = laid_out(&json!([
        window(300, 200),
        widget(1, "column", &json!({"gap": 2})),
        widget(2, "checkbox", &json!({"label": "Agree"})),
        widget(3, "progress_bar", &json!({"value": 0.5})),
        widget(4, "button", &json!({"label": "Two\nlines"})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    // 16 box + 8 gap + 40 text; height max(16, 16) = 16
    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 300.0, 16.0)));
    assert_eq!(
        layout.rect(3),
        Some(rect(0.0, 18.0, 300.0, metrics::PROGRESS_HEIGHT))
    );
    // Two lines: 32 + 12 = 44 high, wider line "lines" = 40 + 24
    assert_eq!(layout.rect(4), Some(rect(0.0, 28.0, 300.0, 44.0)));
}

#[test]
fn along_a_row_each_widget_has_its_own_width() {
    // With the small ruler "ab" is 10 wide: a checkbox is 16 + 8 + 10, a button 10 + 24, a progress bar and a
    // text input 160; a gap of 1 between them.
    let (_, layout) = laid_out_with(
        &json!([
            window(600, 100),
            widget(1, "row", &json!({"gap": 1})),
            widget(2, "checkbox", &json!({"label": "ab"})),
            widget(3, "button", &json!({"label": "ab"})),
            widget(4, "progress_bar", &json!({})),
            widget(5, "text_input", &json!({})),
            append(1, 2),
            append(1, 3),
            append(1, 4),
            append(1, 5),
            root(1),
        ]),
        &SmallRuler,
    );

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 34.0, 100.0)));
    assert_eq!(layout.rect(3), Some(rect(35.0, 0.0, 34.0, 100.0)));
    assert_eq!(layout.rect(4), Some(rect(70.0, 0.0, 160.0, 100.0)));
    assert_eq!(layout.rect(5), Some(rect(231.0, 0.0, 160.0, 100.0)));
}

#[test]
fn down_a_column_each_widget_has_its_own_height() {
    // With the small ruler a line is 10 high: a one-line button is 10 + 12 = 22, which is below its minimum of 28;
    // a checkbox is 10, below its box of 16; a text input is 28; a progress bar 8; a label 10.
    let (_, layout) = laid_out_with(
        &json!([
            window(300, 200),
            widget(1, "column", &json!({})),
            widget(2, "button", &json!({"label": "ab"})),
            widget(3, "checkbox", &json!({"label": "ab"})),
            widget(4, "text_input", &json!({})),
            widget(5, "progress_bar", &json!({})),
            widget(6, "label", &json!({"text": "ab"})),
            append(1, 2),
            append(1, 3),
            append(1, 4),
            append(1, 5),
            append(1, 6),
            root(1),
        ]),
        &SmallRuler,
    );

    let heights: Vec<f32> = (2..=6).map(|id| layout.rect(id).unwrap().height).collect();
    assert_eq!(heights, [28.0, 16.0, 28.0, 8.0, 10.0]);
    assert_eq!(layout.rect(6).unwrap().y, 28.0 + 16.0 + 28.0 + 8.0);
}

#[test]
fn a_container_that_does_not_stretch_is_its_children_plus_its_padding_on_every_side() {
    // A column (not stretched: it is in a row, so its width is its own) with padding 4 holding "ab" (16 wide by 16
    // high) is 24 by 24; the row stretches its height, so look at a row inside a column for the other direction.
    let (_, layout) = laid_out(&json!([
        window(300, 200),
        widget(1, "row", &json!({"gap": 3})),
        widget(2, "column", &json!({"padding": 4})),
        widget(3, "label", &json!({"text": "ab"})),
        widget(4, "label", &json!({"text": "c"})),
        append(1, 2),
        append(2, 3),
        append(1, 4),
        root(1),
    ]));
    assert_eq!(
        layout.rect(2),
        Some(rect(0.0, 0.0, 24.0, 200.0)),
        "16 + 4 + 4 wide"
    );
    assert_eq!(layout.rect(4), Some(rect(27.0, 0.0, 8.0, 200.0)));

    let (_, layout) = laid_out(&json!([
        window(300, 200),
        widget(1, "column", &json!({"gap": 3})),
        widget(2, "row", &json!({"padding": 4})),
        widget(3, "label", &json!({"text": "ab"})),
        widget(4, "label", &json!({"text": "c"})),
        append(1, 2),
        append(2, 3),
        append(1, 4),
        root(1),
    ]));
    assert_eq!(
        layout.rect(2),
        Some(rect(0.0, 0.0, 300.0, 24.0)),
        "16 + 4 + 4 high"
    );
    assert_eq!(layout.rect(4), Some(rect(0.0, 27.0, 300.0, 16.0)));
}

#[test]
fn a_scroll_view_that_shares_a_column_takes_what_is_left_and_not_the_size_of_its_content() {
    // A label (16) above a scroll view that grows, in a window 100 high: the scroll view gets the 84 left, and the
    // 160 of ten labels it holds stay 160.
    let mut commands = vec![
        window(200, 100),
        widget(1, "column", &json!({})),
        widget(2, "label", &json!({"text": "title"})),
        json!({"op": "widget.create", "id": 3, "type": "scroll_view", "props": {"grow": 1}}),
        widget(4, "column", &json!({})),
        append(1, 2),
        append(1, 3),
        append(3, 4),
        root(1),
    ];
    for index in 0..10_u64 {
        commands.push(widget(10 + index, "label", &json!({"text": "row"})));
        commands.push(append(4, 10 + index));
    }
    let (_, layout) = laid_out(&Value::Array(commands));

    assert_eq!(layout.rect(3), Some(rect(0.0, 16.0, 200.0, 84.0)));
    assert_eq!(layout.rect(4), Some(rect(0.0, 16.0, 200.0, 160.0)));
    assert_eq!(layout.content_size(3), Some((200.0, 160.0)));
}

#[test]
fn a_widget_that_is_not_in_the_tree_has_no_rectangle() {
    let (_, layout) = laid_out(&json!([
        window(100, 100),
        widget(1, "label", &json!({"text": "in"})),
        widget(2, "label", &json!({"text": "loose"})),
        root(1),
    ]));

    assert!(layout.rect(1).is_some());
    assert_eq!(layout.rect(2), None);
}

#[test]
fn a_property_that_was_reset_goes_back_to_its_default_in_the_layout() {
    let (mut model, _) = laid_out(&json!([
        window(100, 100),
        widget(1, "column", &json!({"padding": 10})),
        widget(2, "label", &json!({"text": "x"})),
        append(1, 2),
        root(1),
    ]));
    let reset = parse_batch(r#"[{"op":"widget.set","id":1,"props":{"padding":null}}]"#).unwrap();
    model.apply_batch(
        reset,
        &mut Vec::<Event>::new(),
        &mut HeadlessBackend::new(&Config::default(), Arc::default()),
    );

    let layout = layout_window(&model, 1, &PlaceholderMetrics).unwrap();

    assert_eq!(layout.rect(2), Some(rect(0.0, 0.0, 100.0, 16.0)));
}

#[test]
fn positions_are_not_rounded_by_layout_because_only_the_physical_grid_matters() {
    // 100 wide shared 1 to 2: a third and two thirds, not 33 and 67.
    let (_, layout) = laid_out(&json!([
        window(100, 50),
        widget(1, "row", &json!({})),
        widget(2, "column", &json!({"grow": 1})),
        widget(3, "column", &json!({"grow": 2})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    let (first, second) = (layout.rect(2).unwrap(), layout.rect(3).unwrap());
    assert!((first.width - 33.333_33).abs() < 0.001, "{first:?}");
    assert!((second.x - 33.333_33).abs() < 0.001, "{second:?}");
    assert!((second.width - 66.666_66).abs() < 0.001, "{second:?}");
}

#[test]
fn grow_takes_numbers_from_zero_to_a_thousand_and_nothing_else() {
    for (grow, accepted) in [
        (json!(0), true),
        (json!(1000), true),
        (json!(1000.5), false),
        (json!(-1), false),
        (json!("1"), false),
    ] {
        let text = json!({"batch": 1, "commands": [widget(1, "label", &json!({"grow": grow}))]})
            .to_string();
        let mut events: Vec<Event> = Vec::new();
        Model::new(Arc::new(Diagnostics::default())).apply_batch(
            parse_batch(&text).unwrap(),
            &mut events,
            &mut HeadlessBackend::new(&Config::default(), Arc::default()),
        );

        assert_eq!(
            events
                .iter()
                .any(|event| event.json.contains("\"bad_prop\"")),
            !accepted,
            "{grow}"
        );
    }
}

// ---------------------------------------------------------------- units and HiDPI

#[test]
fn the_same_tree_at_scale_two_is_the_same_layout_twice_as_large_in_pixels() {
    let (_, layout) = laid_out(&json!([
        window(400, 300),
        widget(1, "column", &json!({"padding": 10, "gap": 5})),
        widget(2, "label", &json!({"text": "ab"})),
        widget(3, "label", &json!({"text": "abcd"})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    assert_eq!(
        layout.rect(3).unwrap().snapped(1.0),
        PixelRect {
            x: 10,
            y: 31,
            width: 380,
            height: 16
        }
    );
    assert_eq!(
        layout.rect(3).unwrap().snapped(2.0),
        PixelRect {
            x: 20,
            y: 62,
            width: 760,
            height: 32
        }
    );
}

#[test]
fn a_fractional_scale_rounds_each_edge_so_that_touching_widgets_still_touch() {
    // Logical 0..10 and 10..20 at scale 1.5 are physical 0..15 and 15..30; 0..5.5 and 5.5..11 are 0..8 and 8..17
    // (edges at 8.25 and 16.5 round to 8 and 17), sharing the edge at 8.
    let first = rect(0.0, 0.0, 5.5, 4.0).snapped(1.5);
    let second = rect(5.5, 0.0, 5.5, 4.0).snapped(1.5);

    assert_eq!(
        first,
        PixelRect {
            x: 0,
            y: 0,
            width: 8,
            height: 6
        }
    );
    assert_eq!(
        second,
        PixelRect {
            x: 8,
            y: 0,
            width: 9,
            height: 6
        }
    );
    assert_eq!(
        first.x + first.width as i64,
        second.x,
        "no gap and no overlap"
    );

    // A size is never negative or lost to rounding to nothing by accident: 0.2 wide at scale 1 is 0 pixels.
    assert_eq!(rect(3.0, 3.0, 0.2, 0.2).snapped(1.0).width, 0);
    assert_eq!(
        rect(0.5, 0.0, 1.0, 1.0).snapped(1.0),
        PixelRect {
            x: 1,
            y: 0,
            width: 1,
            height: 1
        }
    );
}

#[test]
fn the_text_ruler_counts_the_widest_line_and_every_line() {
    let ruler = PlaceholderMetrics;

    assert_eq!(ruler.text_size(""), (0.0, 16.0));
    assert_eq!(ruler.text_size("abc"), (24.0, 16.0));
    assert_eq!(ruler.text_size("ab\nabcde\n"), (40.0, 48.0));
    assert_eq!(
        ruler.text_size("é€"),
        (16.0, 16.0),
        "a character, not a byte"
    );
}

// ---------------------------------------------------------------- how long it takes

#[test]
fn ten_thousand_widgets_are_laid_out_in_a_time_that_is_recorded() {
    let mut commands = vec![
        window(800, 600),
        widget(1, "column", &json!({"gap": 1})),
        root(1),
    ];
    for index in 0..10_000_u64 {
        commands.push(widget(10 + index, "label", &json!({"text": "item"})));
        commands.push(append(1, 10 + index));
    }
    let (model, _) = laid_out(&Value::Array(commands));

    let started = Instant::now();
    let layout = layout_window(&model, 1, &PlaceholderMetrics).unwrap();
    let elapsed = started.elapsed();

    println!("LAYOUT OF 10,001 WIDGETS: {elapsed:?}");
    assert_eq!(layout.len(), 10_001);
    assert!(
        elapsed.as_secs() < 5,
        "layout took {elapsed:?}: something is quadratic"
    );
}

#[test]
fn a_canvas_is_200_by_150_unless_told_and_its_size_is_a_minimum_like_any_widget_has() {
    let (_, layout) = laid_out(&json!([
        window(400, 300),
        widget(1, "column", &json!({})),
        widget(2, "canvas", &json!({})),
        widget(3, "canvas", &json!({"width": 120, "height": 40})),
        widget(4, "canvas", &json!({"width": 0, "height": 0, "grow": 1})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    assert_eq!(
        layout.rect(2),
        Some(rect(0.0, 0.0, 400.0, 150.0)),
        "stretched across the column, 150 high"
    );
    assert_eq!(layout.rect(3), Some(rect(0.0, 150.0, 400.0, 40.0)));
    assert_eq!(
        layout.rect(4),
        Some(rect(0.0, 190.0, 400.0, 110.0)),
        "of size 0 with grow, it takes what is left"
    );

    let (_, row) = laid_out(&json!([
        window(400, 300),
        widget(1, "row", &json!({})),
        widget(2, "canvas", &json!({})),
        widget(3, "canvas", &json!({"width": 50, "height": 60})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));
    assert_eq!(
        row.rect(2).map(|r| (r.width, r.height)),
        Some((200.0, 300.0))
    );
    assert_eq!(row.rect(3).map(|r| r.x), Some(200.0));
}
