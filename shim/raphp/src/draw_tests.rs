//! Drawing (plan P3.8): golden pictures of widgets, and checks of what is drawn where that do not depend on a
//! picture file. Text is drawn with the bundled font (Liberation Sans Regular, SIL Open Font License, in
//! `assets/fonts`) and nothing else, so the pictures do not depend on the fonts of the machine.
//!
//! **The tolerance rule of the golden pictures** (`docs/protocol/drawing.md`): a pixel matches if every channel is
//! within 3 of the golden one; a picture matches if its size is the same and at most 0.05% of its pixels do not
//! match. Shapes are exact; the little that can differ between machines is the antialiasing of text edges. To
//! make or replace a golden picture run the tests with `RAPHP_UPDATE_GOLDEN=1`, look at the pictures, and commit them.
//! A failing picture is written to `target/golden-failures/` for looking at.

use std::path::PathBuf;
use std::sync::{Arc, LazyLock};

use serde_json::{Value, json};
use tiny_skia::{Pixmap, PremultipliedColorU8};

use crate::backend::HeadlessBackend;
use crate::config::Config;
use crate::diagnostics::Diagnostics;
use crate::draw::{ScrollOffsets, render};
use crate::events::Event;
use crate::input::Interaction;
use crate::layout::TextMetrics;
use crate::model::Model;
use crate::protocol::parse_batch;
use crate::text::{LINE_HEIGHT, TextRenderer};

static FONT: LazyLock<Arc<TextRenderer>> = LazyLock::new(TextRenderer::bundled);

fn model_of(commands: &Value) -> Model {
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

    model
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

/// Draws window 1 of the model at the scale, with the window's logical size as the picture's size times the scale.
fn picture(model: &Model, scale: f64, scroll: &ScrollOffsets) -> Pixmap {
    let mut view = Interaction::new();
    view.scroll = scroll.clone();

    picture_with(model, scale, &view)
}

/// The same with the whole interaction state given (hover, pressed, focus, the time of the frame).
fn picture_with(model: &Model, scale: f64, view: &Interaction) -> Pixmap {
    let (width, height, _) = model.window_content(1).unwrap();
    let size = (
        (width * scale).ceil() as u32,
        (height * scale).ceil() as u32,
    );

    render(model, 1, size, scale, &FONT, view).expect("a picture")
}

fn pixel(picture: &Pixmap, x: u32, y: u32) -> [u8; 4] {
    let color: PremultipliedColorU8 = picture.pixels()[(y * picture.width() + x) as usize];

    [color.red(), color.green(), color.blue(), color.alpha()]
}

const WHITE: [u8; 4] = [255, 255, 255, 255];

// ---------------------------------------------------------------- golden pictures

fn golden_path(name: &str) -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR"))
        .join("tests/golden")
        .join(format!("{name}.png"))
}

/// Compares the picture with `tests/golden/<name>.png` by the tolerance rule above.
fn check_golden(name: &str, actual: &Pixmap) {
    let path = golden_path(name);
    if std::env::var_os("RAPHP_UPDATE_GOLDEN").is_some() {
        actual.save_png(&path).unwrap();

        return;
    }

    let expected = Pixmap::load_png(&path).unwrap_or_else(|error| {
        panic!("the golden picture {} is missing ({error}): run with RAPHP_UPDATE_GOLDEN=1 and look at it", path.display())
    });
    let fail = |reason: String| -> ! {
        let failures = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../target/golden-failures");
        let _ = std::fs::create_dir_all(&failures);
        let _ = actual.save_png(failures.join(format!("{name}.actual.png")));

        panic!("{name}: {reason} (the picture is in target/golden-failures)");
    };

    if (expected.width(), expected.height()) != (actual.width(), actual.height()) {
        fail(format!(
            "the size is {}x{}, the golden one {}x{}",
            actual.width(),
            actual.height(),
            expected.width(),
            expected.height()
        ));
    }

    let wrong = expected
        .data()
        .chunks_exact(4)
        .zip(actual.data().chunks_exact(4))
        .filter(|(want, got)| want.iter().zip(got.iter()).any(|(a, b)| a.abs_diff(*b) > 3))
        .count();
    let allowed = expected.data().len() / 4 * 5 / 10_000; // 0.05%
    if wrong > allowed {
        fail(format!(
            "{wrong} pixels differ by more than 3 in a channel; {allowed} are allowed"
        ));
    }
}

fn form() -> Value {
    json!([
        window(260, 120),
        widget(1, "column", &json!({"padding": 12, "gap": 8})),
        widget(2, "label", &json!({"text": "Name"})),
        widget(3, "row", &json!({"gap": 8})),
        widget(4, "text_input", &json!({"text": "Ada Lovelace", "grow": 1})),
        widget(5, "button", &json!({"label": "Save"})),
        widget(
            6,
            "checkbox",
            &json!({"label": "Subscribe", "checked": true})
        ),
        append(1, 2),
        append(1, 3),
        append(3, 4),
        append(3, 5),
        append(1, 6),
        root(1),
    ])
}

#[test]
fn a_label_is_text_at_the_top_left_of_its_place() {
    let model = model_of(&json!([
        window(180, 40),
        widget(1, "label", &json!({"text": "Hello, Raphp"})),
        root(1)
    ]));

    check_golden("label", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn buttons_are_rounded_with_their_label_in_the_middle_and_a_disabled_one_is_grey() {
    let model = model_of(&json!([
        window(220, 48),
        widget(1, "row", &json!({"padding": 8, "gap": 8})),
        widget(2, "button", &json!({"label": "OK"})),
        widget(3, "button", &json!({"label": "Cancel", "enabled": false})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    check_golden("buttons", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn progress_bars_fill_in_proportion() {
    let model = model_of(&json!([
        window(200, 70),
        widget(1, "column", &json!({"padding": 10, "gap": 10})),
        widget(2, "progress_bar", &json!({"value": 0})),
        widget(3, "progress_bar", &json!({"value": 0.5})),
        widget(4, "progress_bar", &json!({"value": 1})),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    check_golden("progress", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn checkboxes_show_a_check_when_checked() {
    let model = model_of(&json!([
        window(200, 64),
        widget(1, "column", &json!({"padding": 10, "gap": 6})),
        widget(2, "checkbox", &json!({"label": "Unchecked"})),
        widget(3, "checkbox", &json!({"label": "Checked", "checked": true})),
        append(1, 2),
        append(1, 3),
        root(1),
    ]));

    check_golden("checkboxes", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn text_inputs_show_their_text_or_a_grey_placeholder() {
    let model = model_of(&json!([
        window(220, 130),
        widget(1, "column", &json!({"padding": 10, "gap": 8})),
        widget(2, "text_input", &json!({"text": "typed text"})),
        widget(3, "text_input", &json!({"placeholder": "Type here"})),
        widget(
            4,
            "text_input",
            &json!({"text": "disabled", "enabled": false})
        ),
        append(1, 2),
        append(1, 3),
        append(1, 4),
        root(1),
    ]));

    check_golden("text_inputs", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn a_form_is_the_widgets_laid_out_and_drawn_together() {
    let model = model_of(&form());

    check_golden("form", &picture(&model, 1.0, &ScrollOffsets::new()));
}

#[test]
fn the_same_form_at_scale_two_is_twice_the_pixels_and_as_sharp() {
    let model = model_of(&form());

    let drawn = picture(&model, 2.0, &ScrollOffsets::new());

    assert_eq!((drawn.width(), drawn.height()), (520, 240));
    check_golden("form_x2", &drawn);
}

fn scrolling_window() -> Value {
    let mut commands = vec![
        window(140, 70),
        widget(1, "column", &json!({})),
        widget(2, "label", &json!({"text": "Title"})),
        json!({"op": "widget.create", "id": 3, "type": "scroll_view", "props": {"grow": 1}}),
        widget(4, "column", &json!({})),
        append(1, 2),
        append(1, 3),
        append(3, 4),
        root(1),
    ];
    for index in 0..8_u64 {
        commands.push(widget(
            10 + index,
            "label",
            &json!({"text": format!("Item {}", index + 1)}),
        ));
        commands.push(append(4, 10 + index));
    }

    Value::Array(commands)
}

#[test]
fn a_scroll_view_clips_what_it_holds_and_shows_it_shifted_by_the_scroll() {
    let model = model_of(&scrolling_window());
    let none = ScrollOffsets::new();
    let scrolled = ScrollOffsets::from([(3, (0.0, 40.0))]);

    check_golden("scroll_top", &picture(&model, 1.0, &none));
    check_golden("scroll_down", &picture(&model, 1.0, &scrolled));
}

// ---------------------------------------------------------------- states and animation (P3.10)

use std::time::Duration;

use crate::input::{Button, HOVER_FADE, InputEvent};

/// Drives the interaction state of window 1 with input events, at the time given, and draws with it.
struct Hand {
    view: Interaction,
}

impl Hand {
    fn new() -> Self {
        Self {
            view: Interaction::new(),
        }
    }

    fn at(&mut self, millis: u64) {
        self.view.set_time(Duration::from_millis(millis));
    }

    fn input(&mut self, model: &mut Model, event: InputEvent) {
        self.view.input(
            1,
            event,
            model,
            &**FONT,
            &mut Vec::<Event>::new(),
            &mut |_| {},
        );
    }

    fn pointer(&mut self, model: &mut Model, x: f32, y: f32) {
        self.input(model, InputEvent::PointerMove { x, y });
    }

    fn draw(&self, model: &Model) -> Pixmap {
        picture_with(model, 1.0, &self.view)
    }
}

/// The Save button of `form()` is at the right of its row: a point just inside its left side, clear of its text.
const SAVE: (f32, f32) = (225.0, 54.0);

/// A pixel of the Save button's background, away from its border and its text.
fn save_fill(picture: &Pixmap) -> [u8; 4] {
    pixel(picture, 199, 54)
}

#[test]
fn a_button_is_darker_when_hovered_darker_still_when_pressed_and_goes_back() {
    let mut model = model_of(&form());
    let mut hand = Hand::new();
    assert_eq!(
        save_fill(&hand.draw(&model)),
        [0xE6, 0xE6, 0xE6, 255],
        "at rest"
    );

    hand.at(0);
    hand.pointer(&mut model, SAVE.0, SAVE.1);
    hand.at(HOVER_FADE.as_millis() as u64);
    assert_eq!(
        save_fill(&hand.draw(&model)),
        [0xD8, 0xD8, 0xD8, 255],
        "fully hovered"
    );

    hand.input(
        &mut model,
        InputEvent::PointerDown {
            x: SAVE.0,
            y: SAVE.1,
            button: Button::Left,
        },
    );
    assert_eq!(
        save_fill(&hand.draw(&model)),
        [0xC4, 0xC4, 0xC4, 255],
        "pressed"
    );

    hand.input(
        &mut model,
        InputEvent::PointerUp {
            x: SAVE.0,
            y: SAVE.1,
            button: Button::Left,
        },
    );
    hand.pointer(&mut model, 10.0, 110.0); // away
    hand.at(2 * HOVER_FADE.as_millis() as u64);
    assert_eq!(
        save_fill(&hand.draw(&model)),
        [0xE6, 0xE6, 0xE6, 255],
        "and back at rest"
    );
}

#[test]
fn hover_fades_in_and_out_over_the_fade_time_and_the_window_is_animating_meanwhile() {
    let mut model = model_of(&form());
    let mut hand = Hand::new();
    hand.at(1000);

    hand.pointer(&mut model, SAVE.0, SAVE.1);
    assert!(
        hand.view.animating(&model, 1),
        "a hover that has just begun is still fading"
    );
    assert_eq!(hand.view.hover_amount(5), 0.0);

    hand.at(1060);
    assert!(
        (hand.view.hover_amount(5) - 0.5).abs() < 0.001,
        "half way, half hovered"
    );
    assert!(hand.view.animating(&model, 1));

    hand.at(1120);
    assert_eq!(hand.view.hover_amount(5), 1.0);
    assert!(
        !hand.view.animating(&model, 1),
        "fully hovered and nothing else moving: nothing more to draw"
    );

    hand.pointer(&mut model, 10.0, 110.0);
    hand.at(1180);
    assert!(
        (hand.view.hover_amount(5) - 0.5).abs() < 0.001,
        "fading out from where it was"
    );
    hand.at(1240);
    assert_eq!(hand.view.hover_amount(5), 0.0);
    assert!(!hand.view.animating(&model, 1));
}

#[test]
fn the_focused_widget_has_a_ring_and_a_focused_text_input_an_accent_border() {
    let mut model = model_of(&form());
    let mut hand = Hand::new();
    let key = |hand: &mut Hand, model: &mut Model, name: &str| {
        let press = crate::input::KeyPress {
            key: name.to_owned(),
            code: String::new(),
            down: true,
            repeat: false,
        };
        hand.input(model, InputEvent::Key(press));
    };
    let plain = hand.draw(&model);

    key(&mut hand, &mut model, "Tab"); // the text input is the first widget that can take the focus
    let focused = hand.draw(&model);

    assert_ne!(plain.data(), focused.data());
    // The ring is drawn just outside the input's rectangle (12, 40) to (194, 68): a pixel on it is the accent.
    let accent = [0x2D, 0x6C, 0xDF, 255];
    assert_eq!(pixel(&focused, 100, 39), accent, "above the input");
    assert_ne!(pixel(&plain, 100, 39), accent);
    check_golden("form_focus", &focused);
}

#[test]
fn an_indeterminate_progress_bar_sweeps_with_the_time_and_comes_round_every_period() {
    let model = model_of(&json!([
        window(200, 30),
        widget(1, "column", &json!({"padding": 10})),
        widget(2, "progress_bar", &json!({"indeterminate": true})),
        append(1, 2),
        root(1),
    ]));
    let mut hand = Hand::new();

    hand.at(0);
    let start = hand.draw(&model);
    hand.at(700);
    let middle = hand.draw(&model);
    hand.at(1400);
    let again = hand.draw(&model);
    hand.at(2100);
    let next_round = hand.draw(&model);

    assert_ne!(start.data(), middle.data(), "the segment has moved");
    assert_eq!(
        start.data(),
        again.data(),
        "and one period later it is where it was"
    );
    assert_eq!(
        middle.data(),
        next_round.data(),
        "and a period and a half in is where half a period in was"
    );
    assert!(
        hand.view.animating(&model, 1),
        "an indeterminate bar is always animating"
    );
    // At half a period the segment, a third of the bar (180 wide), is centered in it: the middle of the bar is accent.
    assert_eq!(pixel(&middle, 100, 14), [0x2D, 0x6C, 0xDF, 255]);
    check_golden("progress_indeterminate", &middle);
}

fn one_input_window() -> Model {
    model_of(&json!([
        window(220, 48),
        widget(1, "column", &json!({"padding": 10})),
        widget(2, "text_input", &json!({})),
        append(1, 2),
        root(1),
    ]))
}

#[test]
fn a_long_text_is_scrolled_so_that_the_caret_is_in_view() {
    let mut model = one_input_window();
    let mut hand = Hand::new();
    hand.view.request(
        crate::model::Request::Focus {
            widget: Some(2),
            window: None,
        },
        &mut model,
        &**FONT,
        &mut Vec::<Event>::new(),
        &mut |_| {},
    );

    hand.input(
        &mut model,
        InputEvent::Text("The quick brown fox jumps over the lazy dog".to_owned()),
    );

    assert!(
        hand.view.cursor(2).unwrap().scroll > 0.0,
        "the text is longer than the input"
    );
    check_golden("text_scrolled", &hand.draw(&model));
}

#[test]
fn an_input_methods_text_is_drawn_underlined_at_the_caret() {
    let mut model = one_input_window();
    let mut hand = Hand::new();
    hand.view.request(
        crate::model::Request::Focus {
            widget: Some(2),
            window: None,
        },
        &mut model,
        &**FONT,
        &mut Vec::<Event>::new(),
        &mut |_| {},
    );
    hand.input(&mut model, InputEvent::Text("Hello ".to_owned()));
    let before = hand.draw(&model);

    hand.input(
        &mut model,
        InputEvent::Preedit {
            text: "wor".to_owned(),
            cursor: Some((3, 3)),
        },
    );
    let composing = hand.draw(&model);

    assert_ne!(before.data(), composing.data());
    check_golden("text_preedit", &composing);
}

#[test]
fn an_unchecked_checkbox_is_darker_while_it_is_pressed_and_a_checked_one_keeps_the_accent() {
    let mut model = model_of(&json!([
        window(120, 40),
        widget(1, "column", &json!({})),
        widget(2, "checkbox", &json!({"label": "Option"})),
        append(1, 2),
        root(1),
    ]));
    let mut hand = Hand::new();
    let box_fill = |picture: &Pixmap| pixel(picture, 8, 10);
    assert_eq!(
        box_fill(&hand.draw(&model)),
        WHITE,
        "at rest the box is white"
    );

    hand.input(
        &mut model,
        InputEvent::PointerDown {
            x: 60.0,
            y: 10.0,
            button: Button::Left,
        },
    );

    assert_eq!(
        box_fill(&hand.draw(&model)),
        [0xC4, 0xC4, 0xC4, 255],
        "pressed it is darker"
    );

    let mut checked = model_of(&json!([
        window(120, 40),
        widget(1, "column", &json!({})),
        widget(2, "checkbox", &json!({"label": "Option", "checked": true})),
        append(1, 2),
        root(1),
    ]));
    let mut hand = Hand::new();
    hand.input(
        &mut checked,
        InputEvent::PointerDown {
            x: 60.0,
            y: 10.0,
            button: Button::Left,
        },
    );
    assert_eq!(
        pixel(&hand.draw(&checked), 13, 13),
        [0x2D, 0x6C, 0xDF, 255],
        "a checked one stays the accent, pressed or not"
    );
}

#[test]
fn a_window_with_nothing_animated_is_not_animating() {
    let model = model_of(&form());

    assert!(!Interaction::new().animating(&model, 1));
}

#[test]
fn the_widget_gallery_shows_every_widget_with_its_states() {
    let model = model_of(&json!([
        window(300, 340),
        widget(1, "column", &json!({"padding": 12, "gap": 8})),
        widget(2, "label", &json!({"text": "Widget gallery"})),
        widget(3, "row", &json!({"gap": 8})),
        widget(4, "button", &json!({"label": "Button"})),
        widget(5, "button", &json!({"label": "Disabled", "enabled": false})),
        widget(6, "checkbox", &json!({"label": "Unchecked"})),
        widget(7, "checkbox", &json!({"label": "Checked", "checked": true})),
        widget(8, "progress_bar", &json!({"value": 0.4})),
        widget(9, "progress_bar", &json!({"indeterminate": true})),
        widget(10, "text_input", &json!({"text": "Some text"})),
        widget(11, "text_input", &json!({"placeholder": "Placeholder"})),
        json!({"op": "widget.create", "id": 12, "type": "scroll_view", "props": {"grow": 1}}),
        widget(13, "column", &json!({})),
        widget(14, "label", &json!({"text": "Scrolled list, item one"})),
        widget(15, "label", &json!({"text": "Scrolled list, item two"})),
        widget(16, "label", &json!({"text": "Scrolled list, item three"})),
        append(1, 2),
        append(1, 3),
        append(3, 4),
        append(3, 5),
        append(1, 6),
        append(1, 7),
        append(1, 8),
        append(1, 9),
        append(1, 10),
        append(1, 11),
        append(1, 12),
        append(12, 13),
        append(13, 14),
        append(13, 15),
        append(13, 16),
        root(1),
    ]));
    let mut hand = Hand::new();
    hand.at(350); // a quarter of the sweep of the indeterminate bar

    check_golden("gallery", &hand.draw(&model));
}

fn menu_over_the_form() -> (Model, Hand) {
    let mut commands: Vec<Value> = serde_json::from_value(form()).unwrap();
    commands.extend([
        widget(20, "column", &json!({})),
        widget(21, "button", &json!({"label": "Open"})),
        widget(22, "button", &json!({"label": "Close"})),
        append(20, 21),
        append(20, 22),
    ]);
    let mut model = model_of(&Value::Array(commands));
    let mut hand = Hand::new();
    hand.view.request(
        crate::model::Request::OverlayShow {
            window: 1,
            widget: 20,
            x: 150.0,
            y: 36.0,
            anchor: None,
        },
        &mut model,
        &**FONT,
        &mut Vec::<Event>::new(),
        &mut |_| {},
    );

    (model, hand)
}

#[test]
fn an_overlay_is_drawn_above_the_content_with_a_panel_and_a_shadow() {
    let (model, hand) = menu_over_the_form();
    let with = hand.draw(&model);
    let without = picture(&model, 1.0, &ScrollOffsets::new());

    assert_ne!(with.data(), without.data());
    // The menu is at (150, 36), over the text input (12, 40, 182, 28) and the Save button: what was there is covered.
    assert_eq!(
        pixel(&with, 155, 70),
        [0xE6, 0xE6, 0xE6, 255],
        "a menu button's own fill where the input's text was"
    );
    assert_ne!(pixel(&without, 155, 70), pixel(&with, 155, 70));
    // Outside the overlay (and its shadow) nothing changed.
    assert_eq!(pixel(&with, 5, 5), pixel(&without, 5, 5));
    check_golden("overlay_menu", &with);
}

/// The form in a taller window, and a dropdown menu under its Save button: an item with a shortcut, a checked one, a
/// disabled one, a separator and a last item.
fn dropdown_under_save() -> (Model, Hand) {
    let mut commands: Vec<Value> = serde_json::from_value(form()).unwrap();
    commands[0] = window(260, 200);
    commands.extend([
        widget(20, "column", &json!({})),
        widget(
            21,
            "menu_item",
            &json!({"label": "New", "shortcut": "Ctrl+N"}),
        ),
        widget(
            22,
            "menu_item",
            &json!({"label": "Wrap lines", "checked": true}),
        ),
        widget(
            23,
            "menu_item",
            &json!({"label": "Print", "enabled": false}),
        ),
        widget(24, "menu_separator", &json!({})),
        widget(
            25,
            "menu_item",
            &json!({"label": "Quit", "shortcut": "Ctrl+Q"}),
        ),
        append(20, 21),
        append(20, 22),
        append(20, 23),
        append(20, 24),
        append(20, 25),
    ]);
    let mut model = model_of(&Value::Array(commands));
    let mut hand = Hand::new();
    hand.view.request(
        crate::model::Request::OverlayShow {
            window: 1,
            widget: 20,
            x: 0.0,
            y: 0.0,
            anchor: Some(5),
        },
        &mut model,
        &**FONT,
        &mut Vec::<Event>::new(),
        &mut |_| {},
    );

    (model, hand)
}

#[test]
fn a_dropdown_menu_is_drawn_under_its_anchor_with_shortcuts_a_check_a_disabled_item_and_a_separator()
 {
    let (mut model, mut hand) = dropdown_under_save();
    // Down twice from nothing: the focus is on the second item; the pointer is over the last.
    for _ in 0..2 {
        hand.input(
            &mut model,
            InputEvent::Key(crate::input::KeyPress {
                key: "ArrowDown".to_owned(),
                code: String::new(),
                down: true,
                repeat: false,
            }),
        );
    }
    hand.pointer(&mut model, 225.0, 166.0);
    hand.at(1000);
    let picture = hand.draw(&model);

    check_golden("menu", &picture);
}

#[test]
fn an_overlay_asked_for_beyond_the_window_is_drawn_inside_it() {
    let (mut model, mut hand) = menu_over_the_form();
    hand.view.request(
        crate::model::Request::OverlayShow {
            window: 1,
            widget: 20,
            x: 5000.0,
            y: 5000.0,
            anchor: None,
        },
        &mut model,
        &**FONT,
        &mut Vec::<Event>::new(),
        &mut |_| {},
    );

    let drawn = hand.draw(&model);

    // Moved in to the bottom right corner of the 260 by 120 window: the corner pixel is the panel's border or fill,
    // and not the white of the window.
    assert_ne!(pixel(&drawn, 259, 119), WHITE);
    assert_eq!((drawn.width(), drawn.height()), (260, 120));
}

// ---------------------------------------------------------------- what is drawn where, without a picture file

#[test]
fn a_window_with_no_root_is_plain_white() {
    let model = model_of(&json!([window(40, 30)]));

    let drawn = picture(&model, 1.0, &ScrollOffsets::new());

    assert!(drawn.pixels().iter().all(|pixel| [
        pixel.red(),
        pixel.green(),
        pixel.blue(),
        pixel.alpha()
    ] == WHITE));
}

#[test]
fn a_progress_bar_is_filled_up_to_its_value_and_the_rest_is_the_track() {
    let model = model_of(&json!([
        window(200, 8),
        widget(1, "progress_bar", &json!({"value": 0.5})),
        root(1),
    ]));

    let drawn = picture(&model, 1.0, &ScrollOffsets::new());

    assert_eq!(
        pixel(&drawn, 50, 4),
        [0x2D, 0x6C, 0xDF, 255],
        "the filled part is the accent"
    );
    assert_eq!(
        pixel(&drawn, 150, 4),
        [0xDC, 0xDC, 0xDC, 255],
        "the rest is the track"
    );
    assert_eq!(
        pixel(&drawn, 0, 0),
        WHITE,
        "the corner is rounded off, so the window shows through"
    );
}

#[test]
fn a_scroll_view_paints_nothing_outside_itself_and_scrolling_moves_what_is_inside() {
    // The title (20 high) is above the scroll view. Scrolled by 40, the items shift up by 40 and would spill over
    // the title if they were not clipped: the rows of the title must be the same as when nothing is scrolled.
    let model = model_of(&scrolling_window());
    let at_top = picture(&model, 1.0, &ScrollOffsets::new());
    let scrolled = picture(&model, 1.0, &ScrollOffsets::from([(3, (0.0, 40.0))]));
    let row = |picture: &Pixmap, y: u32| -> Vec<[u8; 4]> {
        (0..picture.width()).map(|x| pixel(picture, x, y)).collect()
    };

    for y in 0..20 {
        assert_eq!(
            row(&at_top, y),
            row(&scrolled, y),
            "row {y} belongs to the title"
        );
    }

    assert!(
        (20..70).any(|y| row(&at_top, y) != row(&scrolled, y)),
        "the items moved"
    );
}

fn sideways_window() -> Value {
    json!([
        window(200, 60),
        json!({"op": "widget.create", "id": 1, "type": "scroll_view"}),
        widget(2, "row", &json!({"gap": 0})),
        widget(3, "text_input", &json!({"text": "first"})),
        widget(4, "text_input", &json!({"text": "second"})),
        widget(5, "text_input", &json!({"text": "third"})),
        append(1, 2),
        append(2, 3),
        append(2, 4),
        append(2, 5),
        root(1),
    ])
}

#[test]
fn a_scroll_view_scrolls_sideways_over_what_overflows_it() {
    // The inputs are 160 wide each, 480 in all, in a view 200 wide: scrolled by 160 the second input is at the left.
    let model = model_of(&sideways_window());
    let at_start = picture(&model, 1.0, &ScrollOffsets::new());
    let scrolled = picture(&model, 1.0, &ScrollOffsets::from([(1, (160.0, 0.0))]));
    let furthest = picture(&model, 1.0, &ScrollOffsets::from([(1, (280.0, 0.0))]));
    let beyond = picture(&model, 1.0, &ScrollOffsets::from([(1, (9000.0, 0.0))]));

    assert_ne!(at_start.data(), scrolled.data());
    assert_eq!(
        furthest.data(),
        beyond.data(),
        "480 - 200 = 280 is as far as it goes"
    );
    check_golden("scroll_sideways", &scrolled);
}

#[test]
fn a_clip_inside_a_clip_shows_only_what_both_show() {
    use crate::draw::clip_mask;
    use crate::layout::PixelRect;

    let outer = clip_mask(
        (20, 20),
        PixelRect {
            x: 0,
            y: 0,
            width: 10,
            height: 20,
        },
        None,
    )
    .unwrap();
    let inner = clip_mask(
        (20, 20),
        PixelRect {
            x: 5,
            y: 0,
            width: 15,
            height: 20,
        },
        Some(&outer),
    )
    .unwrap();
    let value = |mask: &tiny_skia::Mask, x: usize| mask.data()[10 * 20 + x];

    assert_eq!(value(&outer, 2), 255);
    assert_eq!(value(&outer, 12), 0);
    assert_eq!(
        value(&inner, 2),
        0,
        "inside the outer clip but outside the inner one"
    );
    assert_eq!(value(&inner, 7), 255, "inside both");
    assert_eq!(
        value(&inner, 12),
        0,
        "inside the inner clip but outside the outer one: this is what a nested scroll view needs"
    );
}

#[test]
fn a_scroll_offset_cannot_go_past_what_the_scroll_view_holds() {
    // 8 items of 20 are 160 high in a view 50 high: at most 110 can be scrolled. Asking for far more is the same as 110.
    let model = model_of(&scrolling_window());

    let at_the_end = picture(&model, 1.0, &ScrollOffsets::from([(3, (0.0, 110.0))]));
    let beyond = picture(&model, 1.0, &ScrollOffsets::from([(3, (0.0, 5000.0))]));
    let before = picture(&model, 1.0, &ScrollOffsets::from([(3, (0.0, -30.0))]));

    assert_eq!(at_the_end.data(), beyond.data());
    assert_eq!(
        before.data(),
        picture(&model, 1.0, &ScrollOffsets::new()).data(),
        "and not before the start either"
    );
}

#[test]
fn drawing_through_the_headless_backend_puts_the_widgets_in_the_windows_picture() {
    use crate::backend::{Backend, HeadlessShared};

    let shared = Arc::new(HeadlessShared::default());
    let mut backend = HeadlessBackend::new(&Config::default(), shared.clone());
    let model = {
        let text = json!({"batch": 1, "commands": [window(100, 40), widget(1, "label", &json!({"text": "Hi"})), root(1)]}).to_string();
        let mut model = Model::new(Arc::new(Diagnostics::default()));
        model.apply_batch(
            parse_batch(&text).unwrap(),
            &mut Vec::<Event>::new(),
            &mut backend,
        );
        model
    };

    backend.draw(1, &model, &Interaction::new());

    let mut pixels = vec![0u8; 100 * 40 * 4];
    let (width, height, length) = shared.read(1, &mut pixels).unwrap();
    assert_eq!((width, height, length), (100, 40, 100 * 40 * 4));
    assert!(
        pixels
            .chunks_exact(4)
            .any(|pixel| pixel[..3] != [255, 255, 255]),
        "the label's text was drawn"
    );
    assert_eq!(&pixels[..4], &[255, 255, 255, 255], "and the rest is white");
}

#[test]
fn the_ruler_of_the_real_font_grows_with_the_text_and_counts_lines() {
    let empty = FONT.text_size("");
    let short = FONT.text_size("ab");
    let long = FONT.text_size("abcdefgh");
    let lines = FONT.text_size("ab\ncd\nef");

    assert_eq!(empty.0, 0.0);
    assert!(
        short.0 > 0.0 && long.0 > short.0 * 3.0,
        "{short:?} {long:?}"
    );
    assert_eq!(short.1, LINE_HEIGHT);
    assert_eq!(lines.1, 3.0 * LINE_HEIGHT);
}

// ---------------------------------------------------------------- the canvas (P3.13)

/// A chart as an application would send it: a panel, grid lines, axes with labels, bars, an area with a line through
/// it and markers on the line.
fn chart_ops() -> Value {
    let bars = [40.0, 65.0, 52.0, 90.0, 78.0, 110.0];
    let mut ops = vec![
        json!({"op": "rect", "x": 0, "y": 0, "w": 300, "h": 170, "radius": 6, "fill": "#F7F9FC", "stroke": "#D0D7E2"}),
        json!({"op": "text", "x": 12, "y": 6, "text": "Visits per month", "fill": "#202020"}),
    ];
    for step in 0..=4 {
        let y = 140.0 - f64::from(step) * 25.0;
        ops.push(json!({"op": "line", "x1": 36, "y1": y, "x2": 288, "y2": y, "stroke": "#E1E5EC"}));
        ops.push(json!({"op": "text", "x": 8, "y": y - 8.0, "text": (step * 40).to_string(), "fill": "#707070", "size": 10}));
    }
    for (index, value) in bars.iter().enumerate() {
        let x = 44.0 + index as f64 * 40.0;
        ops.push(json!({"op": "rect", "x": x, "y": 140.0 - value, "w": 26, "h": value, "fill": "#2D6CDF66"}));
    }
    let points: Vec<Value> = bars
        .iter()
        .enumerate()
        .map(|(index, value)| json!([57.0 + index as f64 * 40.0, 140.0 - value * 0.8 - 6.0]))
        .collect();
    let mut area = points.clone();
    area.push(json!([257, 140]));
    area.push(json!([57, 140]));
    ops.push(json!({"op": "path", "points": area, "fill": "#E8833A33"}));
    ops.push(json!({"op": "path", "points": points.clone(), "stroke": "#E8833A", "line_width": 2}));
    for point in points {
        ops.push(json!({"op": "circle", "x": point[0], "y": point[1], "r": 3.5, "fill": "#FFFFFF", "stroke": "#E8833A", "line_width": 2}));
    }
    ops.push(json!({"op": "line", "x1": 36, "y1": 140, "x2": 288, "y2": 140, "stroke": "#404040"}));
    ops.push(json!({"op": "line", "x1": 36, "y1": 30, "x2": 36, "y2": 140, "stroke": "#404040"}));

    Value::Array(ops)
}

fn chart_model() -> Model {
    model_of(&json!([
        window(320, 190),
        widget(1, "column", &json!({"padding": 10})),
        widget(
            2,
            "canvas",
            &json!({"width": 300, "height": 170, "ops": chart_ops()})
        ),
        append(1, 2),
        root(1),
    ]))
}

#[test]
fn a_canvas_draws_a_chart_from_its_draw_list() {
    let model = chart_model();
    let chart = picture(&model, 1.0, &ScrollOffsets::new());

    // The panel is at (10, 10): its fill inside, the page's white outside, its border between.
    assert_eq!(pixel(&chart, 20, 100), [0xF7, 0xF9, 0xFC, 255]);
    assert_eq!(pixel(&chart, 5, 5), [0xFF, 0xFF, 0xFF, 255]);
    // The first bar (x 44 to 70, up from y 140, 40 high; the canvas is at (10, 10)): a translucent blue over the panel.
    let bar = pixel(&chart, 10 + 50, 10 + 140 - 10);
    assert!(bar[2] > bar[0] && bar[0] < 0xF7, "a blue bar: {bar:?}");
    check_golden("canvas_chart", &chart);
}

#[test]
fn a_canvas_drawn_at_twice_the_scale_is_the_same_chart_twice_as_sharp() {
    let model = chart_model();
    let double = picture(&model, 2.0, &ScrollOffsets::new());

    assert_eq!((double.width(), double.height()), (640, 380));
    check_golden("canvas_chart_x2", &double);
}

#[test]
fn nothing_is_drawn_outside_the_canvas_and_a_draw_list_replaced_is_a_new_picture() {
    let mut model = model_of(&json!([
        window(100, 60),
        widget(1, "column", &json!({"padding": 30})),
        widget(
            2,
            "canvas",
            &json!({"width": 40, "height": 20, "ops": [
                {"op": "rect", "x": -30, "y": -30, "w": 200, "h": 200, "fill": "#FF0000"},
                {"op": "circle", "x": 20, "y": 10, "r": 80, "stroke": "#0000FF", "line_width": 8},
                {"op": "text", "x": 30, "y": 5, "text": "overflowing text that is far too long", "fill": "#00AA00"},
            ]})
        ),
        append(1, 2),
        root(1),
    ]));
    let first = picture(&model, 1.0, &ScrollOffsets::new());

    assert_eq!(
        pixel(&first, 30, 30),
        [255, 0, 0, 255],
        "inside the canvas (20, 20 to 60, 40)"
    );
    let mut stray = Vec::new();
    for y in 0..first.height() {
        for x in 0..first.width() {
            let inside = (30..70).contains(&x) && (30..50).contains(&y);
            if !inside && pixel(&first, x, y) != [0xFF, 0xFF, 0xFF, 255] {
                stray.push((x, y));
            }
        }
    }
    assert!(stray.is_empty(), "drawn outside the canvas: {stray:?}");
    let ink = (30..70)
        .flat_map(|x| (30..50).map(move |y| (x, y)))
        .filter(|(x, y)| pixel(&first, *x, *y) == [0, 0xAA, 0, 255])
        .count();
    assert!(ink > 0, "the text is drawn where it is inside the canvas");

    let text =
        json!({"batch": 2, "commands": [{"op": "widget.set", "id": 2, "props": {"ops": []}}]})
            .to_string();
    model.apply_batch(
        parse_batch(&text).unwrap(),
        &mut Vec::<Event>::new(),
        &mut HeadlessBackend::new(&Config::default(), Arc::default()),
    );
    let empty = picture(&model, 1.0, &ScrollOffsets::new());
    assert_eq!(
        pixel(&empty, 40, 40),
        [0xFF, 0xFF, 0xFF, 255],
        "an empty list draws nothing"
    );
}

#[test]
fn shapes_at_every_fraction_of_a_pixel_never_make_the_rasterizer_panic() {
    // tiny-skia panicked once on a thin antialiased rectangle (F63): strokes and fills this thin, at awkward places and
    // scales, are drawn on purpose.
    let mut ops = Vec::new();
    for step in 0..40 {
        let offset = f64::from(step) * 0.137;
        ops.push(json!({"op": "line", "x1": offset, "y1": 3.0 + offset, "x2": 60.0 + offset, "y2": 3.0 + offset, "stroke": "#000000", "line_width": 0.1 + offset / 10.0}));
        ops.push(json!({"op": "line", "x1": 3.0 + offset, "y1": 0, "x2": 3.0 + offset, "y2": 40, "stroke": "#000000", "line_width": 0.01}));
        ops.push(json!({"op": "rect", "x": offset, "y": offset, "w": 0.01 + offset, "h": 0.02, "fill": "#000000", "stroke": "#FF0000", "line_width": 0.05}));
        ops.push(json!({"op": "rect", "x": offset, "y": offset, "w": 0, "h": 0, "fill": "#000000", "stroke": "#FF0000"}));
        ops.push(json!({"op": "circle", "x": offset, "y": offset, "r": offset / 50.0, "fill": "#000000", "stroke": "#000000", "line_width": 0.3}));
        ops.push(json!({"op": "path", "points": [[offset, 0], [offset, 0]], "stroke": "#000000"}));
        ops.push(json!({"op": "path", "points": [[0, offset], [50, offset], [100, offset]], "fill": "#000000", "stroke": "#000000", "line_width": 0.2}));
    }
    let model = model_of(&json!([
        window(80, 60),
        widget(1, "column", &json!({})),
        widget(2, "canvas", &json!({"width": 80, "height": 60, "ops": ops})),
        append(1, 2),
        root(1),
    ]));

    for scale in [1.0, 1.25, 1.5, 2.0, 3.0] {
        let drawn = picture(&model, scale, &ScrollOffsets::new());
        assert_eq!(drawn.width(), (80.0 * scale).round() as u32);
    }
}

#[test]
fn a_line_ends_where_it_says_with_flat_ends() {
    let model = model_of(&json!([
        window(60, 40),
        widget(1, "column", &json!({})),
        widget(
            2,
            "canvas",
            &json!({"width": 60, "height": 40, "ops": [
                {"op": "line", "x1": 10, "y1": 20, "x2": 30, "y2": 20, "stroke": "#000000", "line_width": 10},
            ]})
        ),
        append(1, 2),
        root(1),
    ]));
    let drawn = picture(&model, 1.0, &ScrollOffsets::new());

    assert_eq!(pixel(&drawn, 20, 20), [0, 0, 0, 255], "on the line");
    assert_eq!(pixel(&drawn, 11, 16), [0, 0, 0, 255], "inside its end");
    assert_eq!(
        pixel(&drawn, 8, 20),
        [0xFF, 0xFF, 0xFF, 255],
        "before its start: no cap sticks out"
    );
    assert_eq!(
        pixel(&drawn, 32, 20),
        [0xFF, 0xFF, 0xFF, 255],
        "after its end: no cap sticks out"
    );
}
