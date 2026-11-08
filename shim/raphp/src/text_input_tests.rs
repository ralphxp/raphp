//! Text input (plan P3.11b): typing, the editing keys, the clipboard, a click and a drag, a double click, the revision
//! that protects what was typed, and scrolling. Through the model and the interaction state, no window. Text is measured
//! by the placeholder ruler (8 pixels a character), and the text input of `form()` (id 4) is at (10, 74), 380 wide, so
//! its text starts at x = 18 (the padding is 8).

use std::time::Duration;

use serde_json::{Value, json};

use crate::input::{Button, InputEvent, KeyPress, Modifiers, parse_input};
use crate::input_tests::{Fixture, form, target, types};

/// The text input, id 4 of the form, with some text, and the focus on it by way of PHP.
fn typing(text: &str) -> Fixture {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([{"op": "widget.set", "id": 4, "props": {"text": text}}]));
    fixture.apply(&json!([{"op": "widget.focus", "id": 4}]));
    fixture.events.clear();

    fixture
}

fn text_of(fixture: &Fixture) -> String {
    fixture
        .model
        .widget_view(4)
        .unwrap()
        .props
        .get("text")
        .and_then(Value::as_str)
        .unwrap_or("")
        .to_owned()
}

fn cursor_of(fixture: &Fixture) -> (usize, usize) {
    let cursor = fixture
        .interaction
        .cursor(4)
        .expect("the input has a caret");

    (cursor.caret, cursor.anchor)
}

fn type_text(fixture: &mut Fixture, text: &str) -> Vec<Value> {
    fixture.input(InputEvent::Text(text.to_owned())).0
}

fn modifiers(fixture: &mut Fixture, shift: bool, ctrl: bool) {
    fixture.input(InputEvent::Modifiers(Modifiers {
        shift,
        ctrl,
        alt: false,
        meta: false,
    }));
}

/// A key goes down with the modifiers given, then they are released.
fn press(fixture: &mut Fixture, key: &str, shift: bool, ctrl: bool) -> Vec<Value> {
    modifiers(fixture, shift, ctrl);
    let events = fixture.key(key, true);
    modifiers(fixture, false, false);

    events
}

/// The x of the pointer over the text at a byte index of the text, with 8 pixels a character.
fn x_of(index: usize) -> f32 {
    18.0 + 8.0 * index as f32
}

#[test]
fn text_typed_into_the_focused_input_changes_its_text_at_once_and_php_is_told_with_a_revision() {
    let mut fixture = typing("");

    let first = type_text(&mut fixture, "hi");
    assert_eq!(types(&first), ["text.changed"]);
    assert_eq!(
        (first[0]["text"].as_str(), first[0]["revision"].as_u64()),
        (Some("hi"), Some(1))
    );
    assert_eq!(
        (first[0]["caret"].as_u64(), first[0]["anchor"].as_u64()),
        (Some(2), Some(2))
    );
    assert_eq!(
        text_of(&fixture),
        "hi",
        "the model's text is already the new one: no round trip"
    );

    let second = type_text(&mut fixture, "!");
    assert_eq!(
        (second[0]["text"].as_str(), second[0]["revision"].as_u64()),
        (Some("hi!"), Some(2))
    );
    assert_eq!(first[0]["widget"], 4);
    assert!(fixture.redraws.contains(&1), "typing asks for a frame");
}

#[test]
fn typed_text_goes_nowhere_without_a_focused_text_input_and_not_into_a_disabled_one() {
    let mut fixture = Fixture::new(&form());
    assert!(
        type_text(&mut fixture, "x").is_empty(),
        "nothing has the focus"
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": 2}]));
    assert!(
        type_text(&mut fixture, "x").is_empty(),
        "a button has it: not a text input"
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": 4}, {"op": "widget.set", "id": 4, "props": {"enabled": false}}]));
    assert!(
        type_text(&mut fixture, "x").is_empty(),
        "a disabled input cannot be typed into (and loses the focus)"
    );
    assert_eq!(text_of(&fixture), "");
}

#[test]
fn the_editing_keys_move_the_caret_delete_and_select() {
    let mut fixture = typing("");
    type_text(&mut fixture, "héllo wörld");
    assert_eq!(
        cursor_of(&fixture),
        (13, 13),
        "13 bytes: two letters take two each"
    );

    let backspace = press(&mut fixture, "Backspace", false, false);
    assert_eq!(
        types(&backspace),
        ["key.down", "text.changed"],
        "PHP hears of the key, then of the change"
    );
    assert_eq!(text_of(&fixture), "héllo wörl");

    press(&mut fixture, "Home", false, false);
    assert_eq!(cursor_of(&fixture), (0, 0));
    press(&mut fixture, "ArrowRight", false, false);
    press(&mut fixture, "ArrowRight", false, false);
    assert_eq!(cursor_of(&fixture), (3, 3), "past the h and the two-byte é");

    press(&mut fixture, "ArrowRight", true, false);
    press(&mut fixture, "ArrowRight", true, false);
    assert_eq!(
        cursor_of(&fixture),
        (5, 3),
        "shift extends the selection: anchor stays"
    );

    let replaced = type_text(&mut fixture, "J");
    assert_eq!(
        replaced[0]["text"], "héJo wörl",
        "typing replaces the selection"
    );

    press(&mut fixture, "Delete", false, false);
    assert_eq!(text_of(&fixture), "héJ wörl");
    press(&mut fixture, "End", true, false);
    assert_eq!(cursor_of(&fixture).0, text_of(&fixture).len());
    press(&mut fixture, "Delete", false, true);
    assert_eq!(
        text_of(&fixture),
        "héJ",
        "delete with control removes the selection first"
    );
}

#[test]
fn control_with_the_arrows_and_backspace_works_by_word() {
    let mut fixture = typing("one two three");
    press(&mut fixture, "End", false, false); // the focus had selected it all
    press(&mut fixture, "ArrowLeft", false, true);
    assert_eq!(cursor_of(&fixture), (8, 8), "the start of three");

    press(&mut fixture, "Backspace", false, true);
    assert_eq!(text_of(&fixture), "one three", "the word before the caret");
}

#[test]
fn enter_submits_once_with_the_text_and_its_revision() {
    let mut fixture = typing("done");
    press(&mut fixture, "End", false, false); // the focus had selected it all: typing would replace it
    type_text(&mut fixture, "!");

    let enter = press(&mut fixture, "Enter", false, false);
    assert_eq!(types(&enter), ["key.down", "text.submitted"]);
    assert_eq!(
        (enter[1]["text"].as_str(), enter[1]["revision"].as_u64()),
        (Some("done!"), Some(1))
    );

    modifiers(&mut fixture, false, false);
    let repeat = fixture
        .input(InputEvent::Key(KeyPress {
            key: "Enter".to_owned(),
            code: String::new(),
            down: true,
            repeat: true,
        }))
        .0;
    assert_eq!(types(&repeat), ["key.down"], "a held Enter submits once");
}

#[test]
fn copy_cut_and_paste_use_the_clipboard() {
    let mut fixture = typing("hello world");
    press(&mut fixture, "a", false, true); // select all (it was already, by the focus)
    let copy = press(&mut fixture, "c", false, true);
    assert_eq!(types(&copy), ["key.down"], "copying changes no text");
    assert_eq!(
        fixture.interaction.clipboard_text().as_deref(),
        Some("hello world")
    );

    press(&mut fixture, "End", false, false);
    press(&mut fixture, "v", false, true);
    assert_eq!(
        text_of(&fixture),
        "hello worldhello world",
        "pasted at the caret"
    );

    press(&mut fixture, "Home", false, false);
    press(&mut fixture, "ArrowRight", true, false);
    press(&mut fixture, "ArrowRight", true, false);
    let cut = press(&mut fixture, "x", false, true);
    assert_eq!(types(&cut), ["key.down", "text.changed"]);
    assert_eq!(
        fixture.interaction.clipboard_text().as_deref(),
        Some("he"),
        "what was cut is on the clipboard"
    );
    assert_eq!(text_of(&fixture), "llo worldhello world");

    let mut empty = typing("keep");
    press(&mut empty, "v", false, true);
    assert_eq!(
        text_of(&empty),
        "keep",
        "pasting with nothing on the clipboard changes nothing"
    );
}

#[test]
fn what_is_pasted_is_one_line_and_within_the_limit() {
    let mut fixture = typing("");
    fixture.input(InputEvent::Text("ab\r\ncd".to_owned()));
    assert_eq!(text_of(&fixture), "abcd", "line breaks are dropped");

    let big = "x".repeat(70_000);
    type_text(&mut fixture, &big);
    assert_eq!(text_of(&fixture).len(), 64 * 1024, "never more than 64 KiB");
}

#[test]
fn a_click_puts_the_caret_between_characters_and_shift_extends_a_selection() {
    let mut fixture = typing("abcdef");

    fixture.click(x_of(3) + 1.0, 80.0); // just past the third boundary
    assert_eq!(cursor_of(&fixture), (3, 3));
    fixture.click(x_of(2) + 3.0, 80.0); // nearer to 2 than to 3? 3 pixels in: nearer to 2
    assert_eq!(cursor_of(&fixture), (2, 2));
    fixture.click(380.0, 80.0); // far right of the text, still inside the input
    assert_eq!(cursor_of(&fixture), (6, 6), "the end");

    modifiers(&mut fixture, true, false);
    fixture.click(x_of(1), 80.0);
    assert_eq!(
        cursor_of(&fixture),
        (1, 6),
        "shift-click extends from the old anchor"
    );
}

#[test]
fn dragging_across_the_text_selects_it_and_letting_go_ends_the_drag() {
    let mut fixture = typing("abcdef");

    fixture.input(InputEvent::PointerDown {
        x: x_of(1),
        y: 80.0,
        button: Button::Left,
    });
    fixture.input(InputEvent::PointerMove {
        x: x_of(4),
        y: 80.0,
    });
    assert_eq!(
        cursor_of(&fixture),
        (4, 1),
        "from where the press was to where the pointer is"
    );

    fixture.input(InputEvent::PointerMove {
        x: x_of(3),
        y: 200.0,
    }); // out of the input, below it: the selection still follows
    assert_eq!(cursor_of(&fixture), (3, 1));

    fixture.input(InputEvent::PointerUp {
        x: x_of(3),
        y: 200.0,
        button: Button::Left,
    });
    fixture.input(InputEvent::PointerMove {
        x: x_of(5),
        y: 80.0,
    });
    assert_eq!(
        cursor_of(&fixture),
        (3, 1),
        "after the button came up, moving selects nothing more"
    );
}

#[test]
fn a_double_click_selects_the_word_and_a_slow_second_click_does_not() {
    let mut fixture = typing("one two");

    fixture.interaction.set_time(Duration::from_millis(1000));
    fixture.click(x_of(5), 80.0);
    fixture.interaction.set_time(Duration::from_millis(1200));
    fixture.click(x_of(5), 80.0);
    assert_eq!(cursor_of(&fixture), (7, 4), "two");

    let mut slow = typing("one two");
    slow.interaction.set_time(Duration::from_millis(1000));
    slow.click(x_of(5), 80.0);
    slow.interaction.set_time(Duration::from_millis(2000));
    slow.click(x_of(5), 80.0);
    assert_eq!(
        cursor_of(&slow),
        (5, 5),
        "a second click a second later is just another click"
    );
}

#[test]
fn a_php_value_built_before_the_typing_is_refused_and_one_with_the_revision_it_saw_is_applied() {
    let mut fixture = typing("");
    type_text(&mut fixture, "abc"); // revision 1
    fixture.events.clear();

    fixture.apply(&json!([{"op": "widget.set", "id": 4, "props": {"text": "old"}, "base_rev": 0}]));
    let refused = fixture.json();
    assert!(
        refused.iter().any(|event| event["code"] == "stale_text"),
        "{refused:?}"
    );
    assert_eq!(text_of(&fixture), "abc", "what was typed is still there");

    fixture
        .apply(&json!([{"op": "widget.set", "id": 4, "props": {"text": "kept"}, "base_rev": 1}]));
    assert_eq!(
        text_of(&fixture),
        "kept",
        "PHP, having seen the last change, may replace the text"
    );
    type_text(&mut fixture, "!");
    assert_eq!(
        text_of(&fixture),
        "kep!t",
        "the caret stayed where it was (3), a valid place in the new text: a re-render does not move it"
    );
}

#[test]
fn the_caret_stays_in_view_by_scrolling_the_text_and_goes_back_when_it_goes_home() {
    // 60 characters are 480 pixels in an input whose text room is 380 - 16 = 364.
    let mut fixture = typing("");
    type_text(&mut fixture, &"x".repeat(60));
    assert_eq!(
        fixture.interaction.cursor(4).unwrap().scroll,
        480.0 - 364.0,
        "scrolled so that the end is at the right edge"
    );

    press(&mut fixture, "Home", false, false);
    assert_eq!(fixture.interaction.cursor(4).unwrap().scroll, 0.0);

    press(&mut fixture, "End", false, false);
    press(&mut fixture, "ArrowLeft", false, false);
    assert_eq!(
        fixture.interaction.cursor(4).unwrap().scroll,
        116.0,
        "one character left of the end is still in view: no scroll"
    );
}

#[test]
fn tab_focus_selects_all_the_text_and_typing_replaces_it() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([{"op": "widget.set", "id": 4, "props": {"text": "abc"}}]));

    fixture.key("Tab", true);
    fixture.key("Tab", true);
    fixture.key("Tab", true);
    assert_eq!(fixture.interaction.focus(1), Some(4));
    assert_eq!(cursor_of(&fixture), (3, 0), "all selected");

    type_text(&mut fixture, "z");
    assert_eq!(text_of(&fixture), "z");
}

#[test]
fn a_text_input_in_an_overlay_can_be_typed_into_too() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([
        {"op": "widget.create", "id": 50, "type": "column"},
        {"op": "widget.create", "id": 51, "type": "text_input"},
        {"op": "widget.append", "parent": 50, "child": 51},
        {"op": "overlay.show", "window": 1, "widget": 50, "x": 100.0, "y": 100.0},
        {"op": "widget.focus", "id": 51},
    ]));
    fixture.events.clear();

    let events = type_text(&mut fixture, "menu");

    assert_eq!(
        (events[0]["widget"].as_u64(), events[0]["text"].as_str()),
        (Some(51), Some("menu"))
    );
    assert_eq!(
        target(
            &fixture
                .input(InputEvent::PointerMove { x: 120.0, y: 110.0 })
                .0[0]
        ),
        Some(51),
        "and the overlay's input is where the pointer finds it"
    );
}

#[test]
fn simulated_text_is_read_like_the_other_input_events() {
    let read = |text: &str| parse_input(&serde_json::from_str(text).unwrap());

    assert_eq!(
        read(r#"{"kind":"text","text":"é"}"#),
        Ok(InputEvent::Text("é".to_owned()))
    );
    assert!(read(r#"{"kind":"text"}"#).unwrap_err().contains("\"text\""));
    assert!(read(r#"{"kind":"text","text":5}"#).is_err());
}

// ---------------------------------------------------------------- input methods and the caret's place

#[test]
fn text_an_input_method_is_composing_is_kept_apart_from_the_text_until_it_is_committed() {
    let mut fixture = typing("ab");
    press(&mut fixture, "End", false, false);

    let (events, redraw) = fixture.input(InputEvent::Preedit {
        text: "ni".to_owned(),
        cursor: Some((2, 2)),
    });
    assert!(
        events.is_empty(),
        "composing is not a change of the text: PHP is told nothing"
    );
    assert!(redraw, "but it is drawn");
    assert_eq!(text_of(&fixture), "ab");
    assert_eq!(
        fixture
            .interaction
            .cursor(4)
            .unwrap()
            .preedit
            .as_ref()
            .map(|(text, _)| text.as_str()),
        Some("ni")
    );

    let (_, redraw) = fixture.input(InputEvent::Preedit {
        text: "ni".to_owned(),
        cursor: Some((2, 2)),
    });
    assert!(
        !redraw,
        "the same composition again changes nothing to draw"
    );

    let commit = type_text(&mut fixture, "你");
    assert_eq!(
        commit[0]["text"], "ab你",
        "the commit becomes text, at the caret"
    );
    assert!(
        fixture.interaction.cursor(4).unwrap().preedit.is_none(),
        "and ends the composition"
    );

    fixture.input(InputEvent::Preedit {
        text: "x".to_owned(),
        cursor: None,
    });
    fixture.input(InputEvent::Preedit {
        text: String::new(),
        cursor: None,
    });
    assert!(
        fixture.interaction.cursor(4).unwrap().preedit.is_none(),
        "empty text ends it too"
    );
}

#[test]
fn composing_goes_nowhere_unless_a_text_input_has_the_focus() {
    let mut fixture = Fixture::new(&form());

    let (events, redraw) = fixture.input(InputEvent::Preedit {
        text: "x".to_owned(),
        cursor: None,
    });

    assert!(events.is_empty() && !redraw);
    assert!(fixture.interaction.cursor(4).is_none());
}

#[test]
fn an_input_method_is_wanted_exactly_while_a_text_input_has_the_focus() {
    let mut fixture = Fixture::new(&form());
    assert!(!fixture.interaction.wants_text(1, &fixture.model));

    fixture.apply(&json!([{"op": "widget.focus", "id": 2}]));
    assert!(
        !fixture.interaction.wants_text(1, &fixture.model),
        "a button does not take text"
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": 4}]));
    assert!(fixture.interaction.wants_text(1, &fixture.model));

    fixture.apply(&json!([{"op": "widget.focus", "id": null, "window": 1}]));
    assert!(!fixture.interaction.wants_text(1, &fixture.model));
}

#[test]
fn the_caret_area_is_where_the_caret_is_on_the_window_for_the_input_method_to_sit_next_to() {
    let mut fixture = typing("abc");
    press(&mut fixture, "End", false, false);

    let area =
        fixture
            .interaction
            .caret_area(1, &fixture.model, &crate::layout::PlaceholderMetrics);
    // The input is at (10, 74), 28 high; its text starts 8 in; three characters are 24 pixels.
    assert_eq!(area, Some((10.0 + 8.0 + 24.0, 74.0, 28.0)));

    press(&mut fixture, "Home", false, false);
    assert_eq!(
        fixture
            .interaction
            .caret_area(1, &fixture.model, &crate::layout::PlaceholderMetrics),
        Some((18.0, 74.0, 28.0))
    );

    let mut nothing = Fixture::new(&form());
    assert_eq!(
        nothing
            .interaction
            .caret_area(1, &nothing.model, &crate::layout::PlaceholderMetrics),
        None
    );
}

#[test]
fn the_caret_area_of_an_input_in_an_overlay_follows_the_overlay() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([
        {"op": "widget.create", "id": 50, "type": "column"},
        {"op": "widget.create", "id": 51, "type": "text_input"},
        {"op": "widget.append", "parent": 50, "child": 51},
        {"op": "overlay.show", "window": 1, "widget": 50, "x": 100.0, "y": 100.0},
        {"op": "widget.focus", "id": 51},
    ]));

    let area =
        fixture
            .interaction
            .caret_area(1, &fixture.model, &crate::layout::PlaceholderMetrics);

    assert_eq!(
        area,
        Some((100.0 + 8.0, 100.0, 28.0)),
        "inside the overlay, which is at (100, 100)"
    );
}

#[test]
fn simulated_composing_is_read() {
    let read = |text: &str| parse_input(&serde_json::from_str(text).unwrap());

    assert_eq!(
        read(r#"{"kind":"preedit","text":"ni"}"#),
        Ok(InputEvent::Preedit {
            text: "ni".to_owned(),
            cursor: None
        })
    );
    assert!(read(r#"{"kind":"preedit"}"#).is_err());
}

// ---------------------------------------------------------------- more rules

#[test]
fn two_clicks_far_apart_are_not_a_double_click_and_after_one_moving_selects_nothing() {
    let mut fixture = typing("one two");
    fixture.interaction.set_time(Duration::from_millis(1000));
    fixture.click(x_of(1), 80.0);
    fixture.interaction.set_time(Duration::from_millis(1100));
    fixture.click(x_of(5), 80.0); // quickly, but 32 pixels away
    assert_eq!(
        cursor_of(&fixture),
        (5, 5),
        "not a double click: just a caret"
    );

    let mut fixture = typing("one two");
    fixture.interaction.set_time(Duration::from_millis(1000));
    fixture.input(InputEvent::PointerDown {
        x: x_of(5),
        y: 80.0,
        button: Button::Left,
    });
    fixture.input(InputEvent::PointerUp {
        x: x_of(5),
        y: 80.0,
        button: Button::Left,
    });
    fixture.interaction.set_time(Duration::from_millis(1100));
    fixture.input(InputEvent::PointerDown {
        x: x_of(5),
        y: 80.0,
        button: Button::Left,
    });
    assert_eq!(cursor_of(&fixture), (7, 4), "the word is selected");
    fixture.input(InputEvent::PointerMove {
        x: x_of(1),
        y: 80.0,
    });
    assert_eq!(
        cursor_of(&fixture),
        (7, 4),
        "and moving with the button still down does not change it"
    );
}

#[test]
fn only_a_key_going_down_edits_and_moving_the_caret_asks_for_a_frame() {
    let mut fixture = typing("abc");
    press(&mut fixture, "End", false, false);

    fixture.key("Backspace", false);
    assert_eq!(
        text_of(&fixture),
        "abc",
        "the release of a key does nothing"
    );

    fixture.key("ArrowLeft", true); // (not `press`: it sends the modifiers after, which would reset what is looked at)
    assert_eq!(
        fixture.redraws,
        [1],
        "the caret moved: the window needs a frame (and no event, PHP does not care)"
    );
    assert!(!types(&fixture.json()).contains(&"text.changed"));
}

#[test]
fn the_caret_area_follows_the_scrolling_of_a_long_text() {
    let mut fixture = typing("");
    type_text(&mut fixture, &"x".repeat(60)); // 480 pixels in a text room of 364: scrolled by 116

    let area =
        fixture
            .interaction
            .caret_area(1, &fixture.model, &crate::layout::PlaceholderMetrics);

    assert_eq!(
        area,
        Some((10.0 + 8.0 + 364.0, 74.0, 28.0)),
        "at the right edge of the text room, where the caret is shown"
    );
}

#[test]
fn the_model_lets_the_user_change_only_the_text_of_an_enabled_text_input() {
    let mut fixture = Fixture::new(&form());

    assert_eq!(fixture.model.user_set_text(4, "typed"), Some(1));
    assert_eq!(
        fixture.model.user_set_text(4, "more"),
        Some(2),
        "each change counts"
    );
    assert_eq!(
        fixture.model.user_set_text(2, "x"),
        None,
        "a button has no text to type"
    );
    assert_eq!(
        fixture.model.user_set_text(99, "x"),
        None,
        "nor does a widget that is not there"
    );
    fixture.apply(&json!([{"op": "widget.set", "id": 4, "props": {"enabled": false}}]));
    assert_eq!(
        fixture.model.user_set_text(4, "x"),
        None,
        "nor can a disabled input be typed into"
    );
}

#[test]
fn moving_the_caret_right_past_the_edge_scrolls_by_just_enough() {
    let mut fixture = typing("");
    type_text(&mut fixture, &"x".repeat(60));
    press(&mut fixture, "Home", false, false);

    for _ in 0..46 {
        fixture.key("ArrowRight", true);
    }

    // 46 characters are 368 pixels; the text room is 364: the caret is 4 pixels past the edge.
    assert_eq!(fixture.interaction.cursor(4).unwrap().scroll, 4.0);
}
