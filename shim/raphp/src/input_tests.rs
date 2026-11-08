//! Input and focus (plan P3.9), through the model and the interaction state with no window and no loop: hit testing,
//! hover, clicks, the keyboard, focus, scrolling. Text is measured by the placeholder ruler (8 by 16 per character
//! and line), so every rectangle is exact.

use std::cell::RefCell;
use std::sync::Arc;

use serde_json::{Value, json};

use crate::backend::HeadlessBackend;
use crate::config::Config;
use crate::diagnostics::Diagnostics;
use crate::events::{ContinuousKind, Event, EventClass};
use crate::input::{Button, InputEvent, Interaction, KeyPress, Modifiers, parse_input};
use crate::layout::PlaceholderMetrics;
use crate::model::{Model, Request};
use crate::protocol::{Command, parse_batch, parse_command};

pub(crate) struct Fixture {
    pub(crate) model: Model,
    pub(crate) interaction: Interaction,
    pub(crate) events: Vec<Event>,
    pub(crate) redraws: Vec<u64>,
}

impl Fixture {
    pub(crate) fn new(commands: &Value) -> Self {
        let mut fixture = Self {
            model: Model::new(Arc::new(Diagnostics::default())),
            interaction: Interaction::new(),
            events: Vec::new(),
            redraws: Vec::new(),
        };
        fixture.apply(commands);
        assert!(
            fixture
                .events
                .iter()
                .all(|event| !event.json.contains("\"error\"")),
            "setup: {:?}",
            fixture.events
        );
        fixture.events.clear();

        fixture
    }

    /// Applies a batch, then does what the loop does after one: forget what is gone, then handle the requests.
    pub(crate) fn apply(&mut self, commands: &Value) {
        let text = json!({"batch": 1, "commands": commands}).to_string();
        let mut backend = HeadlessBackend::new(&Config::default(), Arc::default());
        let outcome =
            self.model
                .apply_batch(parse_batch(&text).unwrap(), &mut self.events, &mut backend);
        let redraws = RefCell::new(&mut self.redraws);
        let mut redraw = |window: u64| redraws.borrow_mut().push(window);
        self.interaction
            .sync(&self.model, &mut self.events, &mut redraw);
        for request in outcome.requests {
            self.interaction.request(
                request,
                &mut self.model,
                &PlaceholderMetrics,
                &mut self.events,
                &mut redraw,
            );
        }
    }

    /// Handles one input event; returns the events it made (as JSON) and whether it asked for a frame.
    pub(crate) fn input(&mut self, event: InputEvent) -> (Vec<Value>, bool) {
        self.events.clear();
        self.redraws.clear();
        let redraws = RefCell::new(&mut self.redraws);
        let mut redraw = |window: u64| redraws.borrow_mut().push(window);
        self.interaction.input(
            1,
            event,
            &mut self.model,
            &PlaceholderMetrics,
            &mut self.events,
            &mut redraw,
        );

        (self.json(), !self.redraws.is_empty())
    }

    pub(crate) fn json(&self) -> Vec<Value> {
        self.events
            .iter()
            .map(|event| serde_json::from_str(&event.json).unwrap())
            .collect()
    }

    pub(crate) fn click(&mut self, x: f32, y: f32) -> Vec<Value> {
        let mut seen = self
            .input(InputEvent::PointerDown {
                x,
                y,
                button: Button::Left,
            })
            .0;
        seen.extend(
            self.input(InputEvent::PointerUp {
                x,
                y,
                button: Button::Left,
            })
            .0,
        );

        seen
    }

    pub(crate) fn key(&mut self, key: &str, down: bool) -> Vec<Value> {
        let press = KeyPress {
            key: key.to_owned(),
            code: String::new(),
            down,
            repeat: false,
        };

        self.input(InputEvent::Key(press)).0
    }
}

pub(crate) fn types(events: &[Value]) -> Vec<&str> {
    events
        .iter()
        .map(|event| event["type"].as_str().unwrap())
        .collect()
}

/// A window 400 by 300 with a column (padding 10, gap 10) holding a button (10,10,380,28), a checkbox (10,48,380,16) and
/// a text input (10,74,380,28), ids 2, 3 and 4.
pub(crate) fn form() -> Value {
    json!([
        {"op": "window.create", "id": 1, "width": 400, "height": 300},
        {"op": "widget.create", "id": 1, "type": "column", "props": {"padding": 10, "gap": 10}},
        {"op": "widget.create", "id": 2, "type": "button", "props": {"label": "OK"}},
        {"op": "widget.create", "id": 3, "type": "checkbox", "props": {"label": "Agree"}},
        {"op": "widget.create", "id": 4, "type": "text_input", "props": {}},
        {"op": "widget.append", "parent": 1, "child": 2},
        {"op": "widget.append", "parent": 1, "child": 3},
        {"op": "widget.append", "parent": 1, "child": 4},
        {"op": "window.set_root", "id": 1, "widget": 1},
    ])
}

pub(crate) fn target(event: &Value) -> Option<u64> {
    event["target"].as_u64()
}

// ---------------------------------------------------------------- the pointer

#[test]
fn the_pointer_is_over_the_deepest_widget_under_it_or_none_outside_the_window() {
    let mut fixture = Fixture::new(&form());

    let over = |fixture: &mut Fixture, x: f32, y: f32| {
        target(&fixture.input(InputEvent::PointerMove { x, y }).0[0])
    };

    assert_eq!(over(&mut fixture, 20.0, 20.0), Some(2), "the button");
    assert_eq!(over(&mut fixture, 20.0, 55.0), Some(3), "the checkbox");
    assert_eq!(over(&mut fixture, 20.0, 80.0), Some(4), "the text input");
    assert_eq!(
        over(&mut fixture, 20.0, 200.0),
        Some(1),
        "the column behind them"
    );
    assert_eq!(over(&mut fixture, 500.0, 500.0), None, "outside the window");
    assert_eq!(
        over(&mut fixture, 10.0, 10.0),
        Some(2),
        "a top left corner belongs to the widget"
    );
    assert_eq!(
        over(&mut fixture, 390.0, 20.0),
        Some(1),
        "a right edge does not: the rectangle ends before it"
    );
    assert_eq!(
        over(&mut fixture, 20.0, 38.0),
        Some(1),
        "nor does a bottom edge"
    );
}

#[test]
fn a_pointer_move_is_a_continuous_event_and_hover_follows_the_widgets_that_can_be_used() {
    let mut fixture = Fixture::new(&form());

    let (events, redraw) = fixture.input(InputEvent::PointerMove { x: 20.0, y: 20.0 });
    assert_eq!(types(&events), ["pointer.move"]);
    assert!(
        matches!(fixture.events[0].class, EventClass::Continuous(key) if key.kind == ContinuousKind::PointerMove && key.window == 1)
    );
    assert!(redraw, "the button is hovered now");
    assert_eq!(fixture.interaction.hover(1), Some(2));

    let (_, redraw) = fixture.input(InputEvent::PointerMove { x: 30.0, y: 22.0 });
    assert!(
        !redraw,
        "moving inside the same widget changes nothing to draw"
    );

    let (_, redraw) = fixture.input(InputEvent::PointerMove { x: 20.0, y: 200.0 });
    assert!(redraw, "the pointer left the button");
    assert_eq!(
        fixture.interaction.hover(1),
        None,
        "the column behind is not something that is hovered"
    );

    fixture.input(InputEvent::PointerMove { x: 20.0, y: 20.0 });
    let (events, redraw) = fixture.input(InputEvent::PointerLeave);
    assert_eq!(types(&events), ["pointer.leave"]);
    assert!(redraw);
    assert_eq!(fixture.interaction.hover(1), None);
    assert_eq!(fixture.interaction.pointer(1), None);
}

#[test]
fn a_click_is_a_press_and_a_release_on_the_same_enabled_widget_and_it_takes_the_focus() {
    let mut fixture = Fixture::new(&form());

    let events = fixture.click(20.0, 20.0);

    assert_eq!(
        types(&events),
        ["pointer.down", "focus.changed", "pointer.up", "click"]
    );
    assert_eq!(target(&events[0]), Some(2));
    assert_eq!(events[1]["widget"], 2);
    assert_eq!(
        (events[3]["widget"].as_u64(), events[3]["button"].as_str()),
        (Some(2), Some("left"))
    );
    assert_eq!(fixture.interaction.focus(1), Some(2));
    assert_eq!(fixture.interaction.pressed(1), None, "released");
}

#[test]
fn pressing_on_one_widget_and_releasing_on_another_is_no_click() {
    let mut fixture = Fixture::new(&form());

    let mut events = fixture
        .input(InputEvent::PointerDown {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        })
        .0;
    assert_eq!(fixture.interaction.pressed(1), Some(2));
    events.extend(
        fixture
            .input(InputEvent::PointerUp {
                x: 20.0,
                y: 80.0,
                button: Button::Left,
            })
            .0,
    );

    assert!(!types(&events).contains(&"click"));
    assert_eq!(fixture.interaction.pressed(1), None);
}

#[test]
fn only_the_button_that_went_down_can_click() {
    let mut fixture = Fixture::new(&form());

    let mut events = fixture
        .input(InputEvent::PointerDown {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        })
        .0;
    events.extend(
        fixture
            .input(InputEvent::PointerUp {
                x: 20.0,
                y: 20.0,
                button: Button::Right,
            })
            .0,
    );

    assert!(
        !types(&events).contains(&"click"),
        "left went down and right came up: not a click"
    );
    assert_eq!(fixture.interaction.pressed(1), None);
}

#[test]
fn a_right_click_is_a_click_but_does_not_move_the_focus() {
    let mut fixture = Fixture::new(&form());
    let events: Vec<Value> = [
        fixture
            .input(InputEvent::PointerDown {
                x: 20.0,
                y: 20.0,
                button: Button::Right,
            })
            .0,
        fixture
            .input(InputEvent::PointerUp {
                x: 20.0,
                y: 20.0,
                button: Button::Right,
            })
            .0,
    ]
    .concat();

    assert_eq!(types(&events), ["pointer.down", "pointer.up", "click"]);
    assert_eq!(events[2]["button"], "right");
    assert_eq!(fixture.interaction.focus(1), None);
}

#[test]
fn a_disabled_widget_neither_takes_clicks_nor_the_focus() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([{"op": "widget.set", "id": 2, "props": {"enabled": false}}]));

    let events = fixture.click(20.0, 20.0);

    assert_eq!(
        types(&events),
        ["pointer.down", "pointer.up"],
        "the pointer is reported, the widget does nothing"
    );
    assert_eq!(fixture.interaction.focus(1), None);
    fixture.apply(&json!([{"op": "widget.focus", "id": 2}]));
    assert_eq!(
        fixture.interaction.focus(1),
        None,
        "and PHP cannot focus it either"
    );
}

#[test]
fn a_click_on_nothing_changes_no_focus() {
    let mut fixture = Fixture::new(&form());
    fixture.click(20.0, 20.0);

    let events = fixture.click(20.0, 250.0);

    assert_eq!(types(&events), ["pointer.down", "pointer.up"]);
    assert_eq!(fixture.interaction.focus(1), Some(2));
}

// ---------------------------------------------------------------- the keyboard and the focus

#[test]
fn tab_walks_the_focus_through_the_widgets_that_can_take_it_and_wraps() {
    let mut fixture = Fixture::new(&form());

    let order: Vec<Option<u64>> = (0..4)
        .map(|_| {
            let events = fixture.key("Tab", true);
            assert_eq!(
                types(&events),
                ["focus.changed"],
                "Tab is the focus's, not a key for PHP"
            );

            events[0]["widget"].as_u64()
        })
        .collect();

    assert_eq!(
        order,
        [Some(2), Some(3), Some(4), Some(2)],
        "button, checkbox, text input, and round again"
    );
    assert!(
        fixture.key("Tab", false).is_empty(),
        "the release of Tab says nothing"
    );
}

#[test]
fn shift_tab_walks_backwards() {
    let mut fixture = Fixture::new(&form());
    fixture.input(InputEvent::Modifiers(Modifiers {
        shift: true,
        ..Modifiers::default()
    }));

    let first = fixture.key("Tab", true);
    let second = fixture.key("Tab", true);

    assert_eq!(
        first[0]["widget"], 4,
        "from nothing, backwards starts at the last"
    );
    assert_eq!(second[0]["widget"], 3);
}

#[test]
fn a_key_goes_to_the_focused_widget_with_the_modifiers_and_to_nobody_without_focus() {
    let mut fixture = Fixture::new(&form());

    let nobody = fixture.key("a", true);
    assert_eq!(
        (nobody[0]["type"].as_str(), target(&nobody[0])),
        (Some("key.down"), None)
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": 4}]));
    fixture.input(InputEvent::Modifiers(Modifiers {
        ctrl: true,
        ..Modifiers::default()
    }));
    let press = KeyPress {
        key: "a".to_owned(),
        code: "KeyA".to_owned(),
        down: true,
        repeat: true,
    };
    let (events, _) = fixture.input(InputEvent::Key(press));

    assert_eq!(events[0]["key"], "a");
    assert_eq!(events[0]["code"], "KeyA");
    assert_eq!(events[0]["repeat"], true);
    assert_eq!(
        (events[0]["ctrl"].as_bool(), events[0]["shift"].as_bool()),
        (Some(true), Some(false))
    );
    assert_eq!(target(&events[0]), Some(4));
}

#[test]
fn enter_and_space_click_a_focused_button_or_checkbox_once_and_do_nothing_for_a_text_input() {
    let mut fixture = Fixture::new(&form());

    fixture.apply(&json!([{"op": "widget.focus", "id": 2}]));
    let enter = fixture.key("Enter", true);
    assert_eq!(types(&enter), ["key.down", "click"]);
    assert_eq!(enter[1]["button"], "keyboard");
    let repeat = fixture
        .input(InputEvent::Key(KeyPress {
            key: "Enter".to_owned(),
            code: String::new(),
            down: true,
            repeat: true,
        }))
        .0;
    assert_eq!(types(&repeat), ["key.down"], "a key held down clicks once");
    assert_eq!(types(&fixture.key("Enter", false)), ["key.up"]);

    fixture.apply(&json!([{"op": "widget.focus", "id": 3}]));
    assert_eq!(
        types(&fixture.key(" ", true)),
        ["key.down", "click"],
        "a space bar clicks a checkbox"
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": 4}]));
    assert_eq!(
        types(&fixture.key("Enter", true)),
        ["key.down", "text.submitted"],
        "Enter in a text input is a key for PHP, and also submits it"
    );
}

#[test]
fn php_can_give_the_focus_and_take_it_away() {
    let mut fixture = Fixture::new(&form());

    fixture.apply(&json!([{"op": "widget.focus", "id": 3}]));
    assert_eq!(fixture.interaction.focus(1), Some(3));
    assert!(types(&fixture.json()).contains(&"focus.changed"));

    fixture.events.clear();
    fixture.apply(&json!([{"op": "widget.focus", "id": 3}]));
    assert!(
        !types(&fixture.json()).contains(&"focus.changed"),
        "already there: nothing to tell"
    );

    fixture.apply(&json!([{"op": "widget.focus", "id": null, "window": 1}]));
    assert_eq!(fixture.interaction.focus(1), None);

    fixture.apply(&json!([{"op": "widget.focus", "id": 1}]));
    assert_eq!(
        fixture.interaction.focus(1),
        None,
        "a column cannot take the focus"
    );
}

#[test]
fn a_focused_widget_that_goes_away_leaves_the_focus_nowhere_and_php_is_told() {
    let mut fixture = Fixture::new(&form());
    fixture.click(20.0, 20.0);
    fixture.input(InputEvent::PointerMove { x: 20.0, y: 20.0 });
    fixture.events.clear();

    fixture.apply(&json!([{"op": "widget.destroy", "id": 2}]));

    assert_eq!(fixture.interaction.focus(1), None);
    assert_eq!(fixture.interaction.hover(1), None);
    let events = fixture.json();
    let changed: Vec<&Value> = events
        .iter()
        .filter(|event| event["type"] == "focus.changed")
        .collect();
    assert_eq!(changed.len(), 1);
    assert_eq!(
        changed[0]["widget"],
        Value::Null,
        "no widget, and no id of the one that is gone"
    );
    assert!(fixture.redraws.contains(&1));
}

#[test]
fn a_focused_widget_that_is_disabled_loses_the_focus() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([{"op": "widget.focus", "id": 2}]));
    fixture.events.clear();

    fixture.apply(&json!([{"op": "widget.set", "id": 2, "props": {"enabled": false}}]));

    assert_eq!(fixture.interaction.focus(1), None);
    assert!(types(&fixture.json()).contains(&"focus.changed"));
}

#[test]
fn hit_testing_follows_a_change_of_layout_and_not_a_layout_kept_from_before() {
    let mut fixture = Fixture::new(&form());
    let over = |fixture: &mut Fixture| {
        target(
            &fixture
                .input(InputEvent::PointerMove { x: 20.0, y: 20.0 })
                .0[0],
        )
    };
    assert_eq!(
        over(&mut fixture),
        Some(2),
        "the button, with a padding of 10"
    );

    fixture.apply(&json!([{"op": "widget.set", "id": 1, "props": {"padding": 30}}]));

    assert_eq!(
        over(&mut fixture),
        Some(1),
        "the padding moved the button away from that point"
    );
}

// ---------------------------------------------------------------- overlays (P3.10)

/// The form with a menu of two buttons, "Open" (id 21) and "Close" (id 22), in a column (id 20) of its own that hangs
/// from nothing, and a request to show it at the place given. The placeholder ruler makes the column 64 by 56: "Open" at
/// the top (28 high) and "Close" under it, both 64 wide.
fn with_menu(at: (f32, f32)) -> Fixture {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([
        {"op": "widget.create", "id": 20, "type": "column"},
        {"op": "widget.create", "id": 21, "type": "button", "props": {"label": "Open"}},
        {"op": "widget.create", "id": 22, "type": "button", "props": {"label": "Close"}},
        {"op": "widget.append", "parent": 20, "child": 21},
        {"op": "widget.append", "parent": 20, "child": 22},
        {"op": "overlay.show", "window": 1, "widget": 20, "x": at.0, "y": at.1},
    ]));
    fixture.events.clear();

    fixture
}

fn move_to(fixture: &mut Fixture, x: f32, y: f32) -> Option<u64> {
    target(&fixture.input(InputEvent::PointerMove { x, y }).0[0])
}

#[test]
fn an_overlay_is_hit_before_the_content_under_it_and_the_content_is_out_of_reach_while_it_is_open()
{
    let mut fixture = with_menu((100.0, 60.0));

    assert_eq!(
        move_to(&mut fixture, 110.0, 80.0),
        Some(21),
        "the overlay's button, over the text input under it (y 74 to 102)"
    );
    assert_eq!(
        move_to(&mut fixture, 110.0, 100.0),
        Some(22),
        "its second button"
    );
    assert_eq!(
        move_to(&mut fixture, 20.0, 20.0),
        None,
        "the form's button is out of reach"
    );
    assert_eq!(fixture.interaction.hover(1), None);
}

#[test]
fn a_press_outside_dismisses_the_top_overlay_and_is_not_a_press_on_anything_else() {
    let mut fixture = with_menu((100.0, 60.0));

    let down = fixture
        .input(InputEvent::PointerDown {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        })
        .0;
    assert_eq!(
        types(&down),
        ["overlay.dismissed"],
        "and no pointer.down for the form's button below"
    );
    assert_eq!(
        (down[0]["widget"].as_u64(), down[0]["reason"].as_str()),
        (Some(20), Some("outside_click"))
    );
    assert!(fixture.interaction.overlays(1).is_empty());

    let up = fixture
        .input(InputEvent::PointerUp {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        })
        .0;
    assert!(
        up.is_empty(),
        "the release of that press does nothing either: it is not a click on the form's button"
    );

    let clicks = fixture.click(20.0, 20.0);
    assert!(
        types(&clicks).contains(&"click"),
        "afterwards the content works as before"
    );
}

#[test]
fn escape_dismisses_the_top_overlay_and_is_not_a_key_for_anyone() {
    let mut fixture = with_menu((100.0, 60.0));

    let escape = fixture.key("Escape", true);

    assert_eq!(types(&escape), ["overlay.dismissed"]);
    assert_eq!(escape[0]["reason"], "escape");
    assert_eq!(
        types(&fixture.key("Escape", true)),
        ["key.down"],
        "with no overlay left, Escape is a key like any other"
    );
}

#[test]
fn an_overlay_asked_for_beyond_the_window_is_kept_inside_it() {
    let mut fixture = with_menu((390.0, 290.0)); // the window is 400 by 300; the menu 64 by 56 goes to (336, 244)

    assert_eq!(
        move_to(&mut fixture, 340.0, 250.0),
        Some(21),
        "inside the moved menu"
    );
    assert_eq!(
        move_to(&mut fixture, 399.0, 299.0),
        Some(22),
        "its bottom right corner is in the window"
    );
    assert_eq!(
        move_to(&mut fixture, 330.0, 250.0),
        None,
        "left of where it would have been moved"
    );
}

#[test]
fn overlays_stack_and_a_press_outside_dismisses_only_the_top_one() {
    let mut fixture = with_menu((100.0, 60.0));
    fixture.apply(&json!([
        {"op": "widget.create", "id": 30, "type": "column"},
        {"op": "widget.create", "id": 31, "type": "button", "props": {"label": "Sub"}},
        {"op": "widget.append", "parent": 30, "child": 31},
        {"op": "overlay.show", "window": 1, "widget": 30, "x": 150.0, "y": 70.0},
    ]));

    assert_eq!(
        move_to(&mut fixture, 160.0, 80.0),
        Some(31),
        "the one shown last is on top where they overlap"
    );
    assert_eq!(
        move_to(&mut fixture, 110.0, 70.0),
        Some(21),
        "the one below is still reachable where the top one is not"
    );

    let down = fixture
        .input(InputEvent::PointerDown {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        })
        .0;
    assert_eq!(down[0]["widget"], 30);
    assert_eq!(fixture.interaction.overlays(1).len(), 1);
}

#[test]
fn the_focus_stays_in_the_top_overlay_and_its_widgets_can_be_clicked() {
    let mut fixture = with_menu((100.0, 60.0));

    let order: Vec<Option<u64>> = (0..3)
        .map(|_| fixture.key("Tab", true)[0]["widget"].as_u64())
        .collect();
    assert_eq!(
        order,
        [Some(21), Some(22), Some(21)],
        "Tab goes round the menu and never reaches the form behind it"
    );

    let events = fixture.click(110.0, 100.0);
    assert!(types(&events).contains(&"click"));
    assert_eq!(
        events
            .iter()
            .find(|event| event["type"] == "click")
            .unwrap()["widget"],
        22
    );
    assert_eq!(fixture.interaction.focus(1), Some(22));
}

#[test]
fn hiding_or_destroying_an_overlay_removes_it_without_an_event_and_the_widgets_live_on_when_hidden()
{
    let mut fixture = with_menu((100.0, 60.0));

    fixture.apply(&json!([{"op": "overlay.hide", "widget": 20}]));
    assert!(fixture.interaction.overlays(1).is_empty());
    assert!(
        !types(&fixture.json()).contains(&"overlay.dismissed"),
        "PHP did it: it is not told"
    );
    assert!(
        fixture.model.widget_view(20).is_some(),
        "and the widgets are still there to be shown again"
    );

    fixture
        .apply(&json!([{"op": "overlay.show", "window": 1, "widget": 20, "x": 10.0, "y": 10.0}]));
    assert_eq!(fixture.interaction.overlays(1).len(), 1);
    fixture.apply(&json!([{"op": "widget.destroy", "id": 20}]));
    assert!(
        fixture.interaction.overlays(1).is_empty(),
        "a destroyed widget cannot be an overlay"
    );
    assert_eq!(
        move_to(&mut fixture, 20.0, 20.0),
        Some(2),
        "and the content is reachable again"
    );
}

#[test]
fn a_press_on_nothing_at_all_is_reported_and_dismisses_nothing_when_there_is_no_overlay() {
    let mut fixture = Fixture::new(&form());

    let down = fixture
        .input(InputEvent::PointerDown {
            x: 500.0,
            y: 500.0,
            button: Button::Left,
        })
        .0;

    assert_eq!(
        types(&down),
        ["pointer.down"],
        "outside the window, still reported, with no target"
    );
    assert_eq!(target(&down[0]), None);
    let up = fixture
        .input(InputEvent::PointerUp {
            x: 500.0,
            y: 500.0,
            button: Button::Left,
        })
        .0;
    assert_eq!(
        types(&up),
        ["pointer.up"],
        "and the release is not swallowed"
    );
}

#[test]
fn an_overlay_that_becomes_part_of_any_tree_or_the_root_of_the_window_is_no_longer_an_overlay() {
    // Attached to another widget that hangs from nothing: no longer a tree of its own.
    let mut fixture = with_menu((100.0, 60.0));
    fixture.apply(&json!([
        {"op": "widget.create", "id": 40, "type": "column"},
        {"op": "widget.append", "parent": 40, "child": 20},
    ]));
    assert!(fixture.interaction.overlays(1).is_empty());

    // Made the root of the window (after the window's own root is let go).
    let mut fixture = with_menu((100.0, 60.0));
    fixture.apply(&json!([
        {"op": "window.set_root", "id": 1, "widget": null},
        {"op": "window.set_root", "id": 1, "widget": 20},
    ]));
    assert!(fixture.interaction.overlays(1).is_empty());
}

#[test]
fn a_widget_that_is_attached_to_a_parent_is_no_longer_an_overlay() {
    let mut fixture = with_menu((100.0, 60.0));

    fixture.apply(&json!([{"op": "widget.append", "parent": 1, "child": 20}]));

    assert!(
        fixture.interaction.overlays(1).is_empty(),
        "it is part of the window's tree now"
    );
}

#[test]
fn an_overlay_larger_than_the_window_is_put_at_its_top_left_and_one_that_fits_is_left_alone() {
    use crate::layout::overlay_origin;

    assert_eq!(
        overlay_origin((100.0, 80.0), (200.0, 300.0), (30.0, 40.0)),
        (0.0, 0.0)
    );
    assert_eq!(
        overlay_origin((100.0, 80.0), (50.0, 20.0), (30.0, 40.0)),
        (30.0, 40.0),
        "as asked when it fits"
    );
    assert_eq!(
        overlay_origin((100.0, 80.0), (50.0, 20.0), (90.0, 70.0)),
        (50.0, 60.0),
        "moved in just enough"
    );
    assert_eq!(
        overlay_origin((100.0, 80.0), (50.0, 20.0), (-5.0, -9.0)),
        (0.0, 0.0),
        "and from the other side too"
    );
}

#[test]
fn showing_an_overlay_again_moves_it_and_a_focused_overlay_widget_keeps_its_focus() {
    let mut fixture = with_menu((100.0, 60.0));
    fixture.apply(&json!([{"op": "widget.focus", "id": 21}]));
    assert_eq!(fixture.interaction.focus(1), Some(21));

    fixture
        .apply(&json!([{"op": "overlay.show", "window": 1, "widget": 20, "x": 200.0, "y": 200.0}]));

    assert_eq!(
        fixture.interaction.overlays(1).len(),
        1,
        "one overlay, moved"
    );
    assert_eq!(fixture.interaction.overlays(1)[0].at, (200.0, 200.0));
    assert_eq!(
        fixture.interaction.focus(1),
        Some(21),
        "an overlay's widget is in a window as far as focus goes"
    );
}

#[test]
fn the_overlay_commands_are_read_and_checked() {
    let mut fixture = Fixture::new(&form());
    let ok = |text: &str| parse_command(&serde_json::from_str(text).unwrap());

    assert!(matches!(
        ok(r#"{"op":"overlay.show","window":1,"widget":5,"x":1.5,"y":-2}"#),
        Ok(Command::OverlayShow {
            window: 1,
            widget: 5,
            ..
        })
    ));
    assert!(
        ok(r#"{"op":"overlay.show","window":1,"widget":5,"x":1}"#).is_err(),
        "y is required"
    );
    assert!(ok(r#"{"op":"overlay.show","window":1,"widget":5,"x":"1","y":2}"#).is_err());
    assert!(matches!(
        ok(r#"{"op":"overlay.hide","widget":5}"#),
        Ok(Command::OverlayHide { widget: 5 })
    ));

    fixture.apply(&json!([
        {"op": "widget.create", "id": 20, "type": "column"},
        {"op": "overlay.show", "window": 1, "widget": 2, "x": 0, "y": 0},
        {"op": "overlay.show", "window": 1, "widget": 1, "x": 0, "y": 0},
        {"op": "overlay.show", "window": 1, "widget": 99, "x": 0, "y": 0},
        {"op": "overlay.show", "window": 9, "widget": 20, "x": 0, "y": 0},
        {"op": "overlay.hide", "widget": 99},
    ]));
    let codes: Vec<String> = fixture
        .json()
        .iter()
        .filter(|event| event["type"] == "error")
        .map(|event| format!("{}:{}", event["index"], event["code"].as_str().unwrap()))
        .collect();
    assert_eq!(
        codes,
        [
            "1:has_parent",
            "2:already_root",
            "3:unknown_widget",
            "4:unknown_window",
            "5:unknown_widget"
        ]
    );
    assert!(
        fixture.interaction.overlays(1).is_empty(),
        "none of them was shown"
    );
}

// ---------------------------------------------------------------- scrolling

/// A window 200 by 100: a title (16 high), then a scroll view that grows (84 high) holding 8 labels of 16 (128 high), ids 10 to 17.
fn scrolling() -> Value {
    let mut commands = vec![
        json!({"op": "window.create", "id": 1, "width": 200, "height": 100}),
        json!({"op": "widget.create", "id": 1, "type": "column"}),
        json!({"op": "widget.create", "id": 2, "type": "label", "props": {"text": "Title"}}),
        json!({"op": "widget.create", "id": 3, "type": "scroll_view", "props": {"grow": 1}}),
        json!({"op": "widget.create", "id": 4, "type": "column"}),
        json!({"op": "widget.append", "parent": 1, "child": 2}),
        json!({"op": "widget.append", "parent": 1, "child": 3}),
        json!({"op": "widget.append", "parent": 3, "child": 4}),
        json!({"op": "window.set_root", "id": 1, "widget": 1}),
    ];
    for index in 0..8_u64 {
        commands.push(json!({"op": "widget.create", "id": 10 + index, "type": "label", "props": {"text": format!("row {index}")}}));
        commands.push(json!({"op": "widget.append", "parent": 4, "child": 10 + index}));
    }

    Value::Array(commands)
}

fn wheel(fixture: &mut Fixture, y: f32, dy: f32) -> (Value, bool) {
    let (events, redraw) = fixture.input(InputEvent::Scroll {
        x: 50.0,
        y,
        dx: 0.0,
        dy,
    });

    (events[0].clone(), redraw)
}

#[test]
fn the_wheel_scrolls_the_scroll_view_under_the_pointer_as_far_as_its_content_goes() {
    let mut fixture = Fixture::new(&scrolling());

    let (event, redraw) = wheel(&mut fixture, 50.0, 30.0);
    assert_eq!(
        (event["type"].as_str(), event["view"].as_u64()),
        (Some("scroll"), Some(3))
    );
    assert_eq!(event["offset"], json!([0.0, 30.0]));
    assert!(redraw);
    assert!(
        matches!(fixture.events[0].class, EventClass::Continuous(key) if key.kind == ContinuousKind::Scroll)
    );

    let (event, _) = wheel(&mut fixture, 50.0, 100.0);
    assert_eq!(
        event["offset"],
        json!([0.0, 44.0]),
        "128 of content in a view of 84: 44 is as far as it goes"
    );

    let (event, redraw) = wheel(&mut fixture, 50.0, 10.0);
    assert_eq!(
        (event["view"].as_u64(), event["offset"].clone()),
        (Some(3), json!([0.0, 44.0])),
        "nowhere further to go, and the event still says where the view is: it replaces older ones in the queue, so it carries state"
    );
    assert!(!redraw);

    let (event, _) = wheel(&mut fixture, 50.0, -500.0);
    assert_eq!(
        event["offset"],
        json!([0.0, 0.0]),
        "and back to the start, not before it"
    );
    assert_eq!(fixture.interaction.scroll.get(&3), Some(&(0.0, 0.0)));
}

#[test]
fn with_one_scroll_view_in_another_the_wheel_moves_the_one_that_can_move_and_names_the_innermost() {
    // The outer scroll view is the root (200 by 120). It holds a column with a label (16) and an inner scroll view whose
    // 12 labels (192) fit it exactly, so only the outer one has anywhere to go: 208 of content in 120, 88 to scroll.
    let mut commands = vec![
        json!({"op": "window.create", "id": 1, "width": 200, "height": 120}),
        json!({"op": "widget.create", "id": 1, "type": "scroll_view"}),
        json!({"op": "widget.create", "id": 2, "type": "column"}),
        json!({"op": "widget.create", "id": 3, "type": "label", "props": {"text": "top"}}),
        json!({"op": "widget.create", "id": 4, "type": "scroll_view"}),
        json!({"op": "widget.create", "id": 5, "type": "column"}),
        json!({"op": "widget.append", "parent": 1, "child": 2}),
        json!({"op": "widget.append", "parent": 2, "child": 3}),
        json!({"op": "widget.append", "parent": 2, "child": 4}),
        json!({"op": "widget.append", "parent": 4, "child": 5}),
        json!({"op": "window.set_root", "id": 1, "widget": 1}),
    ];
    for index in 0..12_u64 {
        commands.push(json!({"op": "widget.create", "id": 10 + index, "type": "label", "props": {"text": "item"}}));
        commands.push(json!({"op": "widget.append", "parent": 5, "child": 10 + index}));
    }
    let mut fixture = Fixture::new(&Value::Array(commands));

    let (moved, redraw) = wheel(&mut fixture, 60.0, 1000.0);
    assert_eq!(
        (moved["view"].as_u64(), moved["offset"].clone()),
        (Some(1), json!([0.0, 88.0])),
        "the outer one moved, as far as it goes"
    );
    assert!(redraw);

    let (stuck, redraw) = wheel(&mut fixture, 60.0, 10.0);
    assert_eq!(
        (stuck["view"].as_u64(), stuck["offset"].clone()),
        (Some(4), json!([0.0, 0.0])),
        "nothing can move, and the event names the innermost scroll view under the pointer"
    );
    assert!(!redraw);
}

#[test]
fn the_wheel_over_something_that_is_not_in_a_scroll_view_scrolls_nothing() {
    let mut fixture = Fixture::new(&scrolling());

    let (event, redraw) = wheel(&mut fixture, 5.0, 30.0); // the title

    assert_eq!(event["view"], Value::Null);
    assert_eq!(target(&event), Some(2));
    assert!(!redraw);
}

#[test]
fn what_is_under_the_pointer_follows_the_scroll_and_a_scroll_view_clips_what_it_holds() {
    let mut fixture = Fixture::new(&scrolling());
    wheel(&mut fixture, 50.0, 44.0);

    let at = |fixture: &mut Fixture, y: f32| {
        target(&fixture.input(InputEvent::PointerMove { x: 50.0, y }).0[0])
    };

    // The view starts at 16 and shows what is 44 further down: (90 - 16 + 44) / 16 = 7.4, the eighth label.
    assert_eq!(at(&mut fixture, 90.0), Some(17));
    // The first label is scrolled out of sight above the view, over the title's place: the title wins there.
    assert_eq!(
        at(&mut fixture, 5.0),
        Some(2),
        "a scrolled-away label cannot be hit through the clip"
    );
}

// ---------------------------------------------------------------- the order of redraw and events, and the commands

#[test]
fn a_redraw_is_counted_before_the_events_that_announce_it() {
    let mut fixture = Fixture::new(&form());
    let log: RefCell<Vec<String>> = RefCell::new(Vec::new());

    struct Logging<'a>(&'a RefCell<Vec<String>>);
    impl crate::model::EventSink for Logging<'_> {
        fn emit(&mut self, event: Event) {
            let json: Value = serde_json::from_str(&event.json).unwrap();
            self.0
                .borrow_mut()
                .push(format!("event {}", json["type"].as_str().unwrap_or("")));
        }
    }

    let mut redraw = |window: u64| log.borrow_mut().push(format!("redraw {window}"));
    fixture.interaction.input(
        1,
        InputEvent::PointerDown {
            x: 20.0,
            y: 20.0,
            button: Button::Left,
        },
        &mut fixture.model,
        &PlaceholderMetrics,
        &mut Logging(&log),
        &mut redraw,
    );

    assert_eq!(
        *log.borrow(),
        ["redraw 1", "event pointer.down", "event focus.changed"]
    );
}

#[test]
fn the_commands_for_input_and_focus_are_read_strictly() {
    let ok = |text: &str| parse_command(&serde_json::from_str(text).unwrap());

    assert!(matches!(
        ok(r#"{"op":"widget.focus","id":5}"#),
        Ok(Command::WidgetFocus {
            id: Some(5),
            window: None
        })
    ));
    assert!(matches!(
        ok(r#"{"op":"widget.focus","id":null,"window":2}"#),
        Ok(Command::WidgetFocus {
            id: None,
            window: Some(2)
        })
    ));
    assert!(
        ok(r#"{"op":"widget.focus"}"#).is_err(),
        "the id is required, null for none"
    );
    assert!(
        ok(r#"{"op":"widget.focus","id":5,"colour":1}"#).is_err(),
        "an unknown field is an error"
    );
    assert!(matches!(
        ok(r#"{"op":"input.simulate","window":1,"event":{"kind":"pointer.move","x":1,"y":2}}"#),
        Ok(Command::InputSimulate { window: 1, .. })
    ));
    assert!(ok(r#"{"op":"input.simulate","window":1}"#).is_err());
    assert!(
        ok(r#"{"op":"input.simulate","window":1,"event":{"kind":"pointer.move","x":"1"}}"#)
            .is_err()
    );
    assert!(ok(r#"{"op":"input.simulate","window":1,"event":{"kind":"dance"}}"#).is_err());
}

#[test]
fn every_kind_of_simulated_input_is_read_and_bad_ones_say_what_is_wrong() {
    let read = |text: &str| parse_input(&serde_json::from_str(text).unwrap());

    assert_eq!(
        read(r#"{"kind":"pointer.move","x":1.5,"y":2}"#),
        Ok(InputEvent::PointerMove { x: 1.5, y: 2.0 })
    );
    assert_eq!(
        read(r#"{"kind":"pointer.leave"}"#),
        Ok(InputEvent::PointerLeave)
    );
    assert_eq!(
        read(r#"{"kind":"pointer.down","x":1,"y":2}"#),
        Ok(InputEvent::PointerDown {
            x: 1.0,
            y: 2.0,
            button: Button::Left
        }),
        "left unless said"
    );
    assert_eq!(
        read(r#"{"kind":"pointer.up","x":1,"y":2,"button":"right"}"#),
        Ok(InputEvent::PointerUp {
            x: 1.0,
            y: 2.0,
            button: Button::Right
        })
    );
    assert_eq!(
        read(r#"{"kind":"scroll","x":1,"y":2,"dx":3,"dy":-4}"#),
        Ok(InputEvent::Scroll {
            x: 1.0,
            y: 2.0,
            dx: 3.0,
            dy: -4.0
        })
    );
    assert_eq!(
        read(r#"{"kind":"key.down","key":"a","code":"KeyA","repeat":true}"#),
        Ok(InputEvent::Key(KeyPress {
            key: "a".to_owned(),
            code: "KeyA".to_owned(),
            down: true,
            repeat: true
        }))
    );
    assert_eq!(
        read(r#"{"kind":"key.up","key":"a"}"#),
        Ok(InputEvent::Key(KeyPress {
            key: "a".to_owned(),
            code: String::new(),
            down: false,
            repeat: false
        }))
    );
    assert_eq!(
        read(r#"{"kind":"modifiers","shift":true,"alt":true}"#),
        Ok(InputEvent::Modifiers(Modifiers {
            shift: true,
            ctrl: false,
            alt: true,
            meta: false
        }))
    );

    for (text, reason) in [
        ("5", "must be an object"),
        (r#"{}"#, "needs a \"kind\""),
        (r#"{"kind":"pointer.move","x":1}"#, "\"y\" must be a number"),
        (
            r#"{"kind":"pointer.down","x":1,"y":2,"button":"red"}"#,
            "left, middle, right or other",
        ),
        (r#"{"kind":"key.down","key":5}"#, "\"key\" must be a string"),
        (r#"{"kind":"modifiers","shift":1}"#, "true or false"),
        (r#"{"kind":"jump"}"#, "no input event \"jump\""),
    ] {
        let error = read(text).unwrap_err();
        assert!(error.contains(reason), "{text}: {error}");
    }
}

#[test]
fn a_batch_that_asks_for_input_or_focus_reports_the_requests_in_order_and_refuses_unknown_targets()
{
    let mut model = Model::new(Arc::new(Diagnostics::default()));
    let commands = json!([
        {"op": "window.create", "id": 1},
        {"op": "widget.create", "id": 1, "type": "button"},
        {"op": "widget.focus", "id": 1},
        {"op": "input.simulate", "window": 1, "event": {"kind": "pointer.leave"}},
        {"op": "widget.focus", "id": 99},
        {"op": "input.simulate", "window": 9, "event": {"kind": "pointer.leave"}},
        {"op": "widget.focus", "id": null},
    ]);
    let text = json!({"batch": 1, "commands": commands}).to_string();
    let mut events: Vec<Event> = Vec::new();
    let outcome = model.apply_batch(
        parse_batch(&text).unwrap(),
        &mut events,
        &mut HeadlessBackend::new(&Config::default(), Arc::default()),
    );

    assert_eq!(
        outcome.requests,
        [
            Request::Focus {
                widget: Some(1),
                window: None
            },
            Request::Input {
                window: 1,
                event: InputEvent::PointerLeave
            },
        ]
    );
    let codes: Vec<String> = events
        .iter()
        .map(|event| serde_json::from_str::<Value>(&event.json).unwrap())
        .filter(|event| event["type"] == "error")
        .map(|event| format!("{}:{}", event["index"], event["code"].as_str().unwrap()))
        .collect();
    assert_eq!(
        codes,
        ["4:unknown_widget", "5:unknown_window", "6:missing_field"]
    );
}

// ---------------------------------------------------------------- menus (P3.12a)

/// The form, and a menu of three items (the middle one disabled) and a separator, anchored to the form's button.
fn with_dropdown() -> Fixture {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([
        {"op": "widget.create", "id": 40, "type": "column"},
        {"op": "widget.create", "id": 41, "type": "menu_item", "props": {"label": "New", "shortcut": "Ctrl+N"}},
        {"op": "widget.create", "id": 42, "type": "menu_item", "props": {"label": "Open", "enabled": false}},
        {"op": "widget.create", "id": 43, "type": "menu_separator"},
        {"op": "widget.create", "id": 44, "type": "menu_item", "props": {"label": "Quit"}},
        {"op": "widget.append", "parent": 40, "child": 41},
        {"op": "widget.append", "parent": 40, "child": 42},
        {"op": "widget.append", "parent": 40, "child": 43},
        {"op": "widget.append", "parent": 40, "child": 44},
        {"op": "overlay.show", "window": 1, "widget": 40, "anchor": 2},
    ]));
    fixture.events.clear();

    fixture
}

fn rect_of(fixture: &Fixture, id: u64) -> crate::layout::Rect {
    crate::layout::layout_window(&fixture.model, 1, &PlaceholderMetrics)
        .unwrap()
        .rect(id)
        .unwrap()
}

#[test]
fn a_menu_anchored_to_a_widget_opens_under_it_and_flips_above_it_when_there_is_no_room_below() {
    let mut fixture = with_dropdown();
    let anchor = rect_of(&fixture, 2);

    assert_eq!(
        move_to(&mut fixture, anchor.x + 2.0, anchor.y + anchor.height + 2.0),
        Some(41),
        "its first item is just under the button, at the button's left edge"
    );
    assert_eq!(
        move_to(&mut fixture, anchor.x + 2.0, anchor.y + 2.0),
        None,
        "and the button itself is covered by the open menu's reach: nothing outside the menu is hit"
    );

    // Low in a short window the menu does not fit under the button but does above it.
    let mut low = Fixture::new(&json!([
        {"op": "window.create", "id": 1, "width": 400, "height": 200},
        {"op": "widget.create", "id": 1, "type": "column", "props": {"padding": 150}},
        {"op": "widget.create", "id": 2, "type": "button", "props": {"label": "OK"}},
        {"op": "widget.append", "parent": 1, "child": 2},
        {"op": "window.set_root", "id": 1, "widget": 1},
    ]));
    low.apply(&json!([
        {"op": "widget.create", "id": 40, "type": "column"},
        {"op": "widget.create", "id": 41, "type": "menu_item", "props": {"label": "New"}},
        {"op": "widget.create", "id": 44, "type": "menu_item", "props": {"label": "Quit"}},
        {"op": "widget.append", "parent": 40, "child": 41},
        {"op": "widget.append", "parent": 40, "child": 44},
        {"op": "overlay.show", "window": 1, "widget": 40, "anchor": 2},
    ]));
    let anchor = rect_of(&low, 2);
    assert_eq!(
        move_to(&mut low, anchor.x + 2.0, anchor.y - 2.0),
        Some(44),
        "the menu's last item is just above the button"
    );
    assert_eq!(
        move_to(&mut low, anchor.x + 2.0, anchor.y + anchor.height + 2.0),
        None,
        "and there is nothing under it"
    );
}

#[test]
fn the_arrow_keys_go_from_item_to_item_in_a_menu_skipping_what_is_disabled() {
    let mut fixture = with_dropdown();

    let order: Vec<Option<u64>> = (0..4)
        .map(|_| {
            let events = fixture.key("ArrowDown", true);
            assert_eq!(types(&events), ["focus.changed"], "the arrow is consumed");
            events[0]["widget"].as_u64()
        })
        .collect();
    assert_eq!(
        order,
        [Some(41), Some(44), Some(41), Some(44)],
        "down goes round the enabled items"
    );

    let up = fixture.key("ArrowUp", true);
    assert_eq!(up[0]["widget"], 41, "and up goes back");
}

#[test]
fn enter_or_space_on_a_menu_item_clicks_it_and_closes_the_menu_and_a_disabled_one_does_nothing() {
    let mut fixture = with_dropdown();
    fixture.key("ArrowDown", true);

    let enter = fixture.key("Enter", true);
    assert_eq!(
        types(&enter),
        ["key.down", "click", "overlay.dismissed"],
        "the click first, then why the menu went"
    );
    assert_eq!(enter[1]["widget"], 41);
    assert_eq!(enter[2]["reason"], "activated");
    assert!(fixture.interaction.overlays(1).is_empty());

    let mut fixture = with_dropdown();
    fixture.key("ArrowDown", true);
    fixture.key("ArrowDown", true);
    let space = fixture.key(" ", true);
    assert!(types(&space).contains(&"click"));
    assert_eq!(
        space.iter().find(|e| e["type"] == "click").unwrap()["widget"],
        44
    );
    assert!(fixture.interaction.overlays(1).is_empty());
}

#[test]
fn clicking_a_menu_item_with_the_mouse_clicks_it_and_closes_the_menu_but_the_disabled_one_and_the_separator_stay()
 {
    let mut fixture = with_dropdown();
    let first = rect_of(&fixture, 2);
    let (x, y) = (first.x + 4.0, first.y + first.height + 4.0);

    let events = fixture.click(x, y);
    let kinds = types(&events);
    let click = kinds.iter().position(|k| *k == "click").expect("a click");
    let dismissed = kinds
        .iter()
        .position(|k| *k == "overlay.dismissed")
        .expect("dismissed");
    assert!(click < dismissed, "{kinds:?}");
    assert_eq!(events[dismissed]["reason"], "activated");
    assert!(fixture.interaction.overlays(1).is_empty());
}

#[test]
fn a_disabled_item_and_the_separator_leave_the_menu_open_when_clicked() {
    let mut fixture = with_dropdown();
    let anchor = rect_of(&fixture, 2);
    let top = anchor.y + anchor.height; // New is 24 high, Open (disabled) the next 24

    let disabled = fixture.click(anchor.x + 4.0, top + 30.0);
    assert!(
        !types(&disabled).contains(&"click"),
        "a disabled item is not clicked: {disabled:?}"
    );
    assert_eq!(fixture.interaction.overlays(1).len(), 1);

    let separator = fixture.click(anchor.x + 4.0, top + 52.0);
    assert!(!types(&separator).contains(&"click"));
    assert!(!types(&separator).contains(&"overlay.dismissed"));
    assert_eq!(fixture.interaction.overlays(1).len(), 1);
}

#[test]
fn choosing_an_item_closes_only_the_menu_it_is_in() {
    let mut fixture = with_dropdown();
    fixture.apply(&json!([
        {"op": "widget.create", "id": 50, "type": "column"},
        {"op": "widget.create", "id": 51, "type": "menu_item", "props": {"label": "Sub"}},
        {"op": "widget.append", "parent": 50, "child": 51},
        {"op": "overlay.show", "window": 1, "widget": 50, "x": 300.0, "y": 200.0},
    ]));
    fixture.events.clear();

    let events = fixture.click(310.0, 210.0);
    let dismissed: Vec<&Value> = events
        .iter()
        .filter(|event| event["type"] == "overlay.dismissed")
        .collect();
    assert_eq!(dismissed.len(), 1, "{events:?}");
    assert_eq!(dismissed[0]["widget"], 50);
    let left: Vec<u64> = fixture
        .interaction
        .overlays(1)
        .iter()
        .map(|overlay| overlay.widget)
        .collect();
    assert_eq!(left, [40], "the menu under it stays");
}

#[test]
fn a_text_input_in_an_overlay_keeps_the_arrow_keys_for_its_caret() {
    let mut fixture = Fixture::new(&form());
    fixture.apply(&json!([
        {"op": "widget.create", "id": 60, "type": "column"},
        {"op": "widget.create", "id": 61, "type": "text_input", "props": {"text": "abc"}},
        {"op": "widget.create", "id": 62, "type": "menu_item", "props": {"label": "Go"}},
        {"op": "widget.append", "parent": 60, "child": 61},
        {"op": "widget.append", "parent": 60, "child": 62},
        {"op": "overlay.show", "window": 1, "widget": 60, "x": 100.0, "y": 100.0},
        {"op": "widget.focus", "id": 61},
    ]));
    fixture.events.clear();

    fixture.key("ArrowDown", true);
    assert_eq!(
        fixture.interaction.focus(1),
        Some(61),
        "the arrow was the input's, not a move to the next item"
    );
}

#[test]
fn a_menu_anchored_to_a_widget_in_a_scroll_view_follows_it_as_the_view_scrolls() {
    let mut fixture = Fixture::new(&json!([
        {"op": "window.create", "id": 1, "width": 400, "height": 200},
        {"op": "widget.create", "id": 1, "type": "column", "props": {"padding": 20}},
        {"op": "widget.create", "id": 9, "type": "scroll_view", "props": {"grow": 1}},
        {"op": "widget.create", "id": 10, "type": "column"},
        {"op": "widget.append", "parent": 1, "child": 9},
        {"op": "widget.append", "parent": 9, "child": 10},
        {"op": "widget.create", "id": 11, "type": "button", "props": {"label": "A"}},
        {"op": "widget.create", "id": 12, "type": "button", "props": {"label": "B"}},
        {"op": "widget.create", "id": 13, "type": "button", "props": {"label": "C"}},
        {"op": "widget.create", "id": 14, "type": "button", "props": {"label": "D"}},
        {"op": "widget.create", "id": 15, "type": "button", "props": {"label": "E"}},
        {"op": "widget.create", "id": 16, "type": "button", "props": {"label": "F"}},
        {"op": "widget.create", "id": 17, "type": "button", "props": {"label": "G"}},
        {"op": "widget.create", "id": 18, "type": "button", "props": {"label": "H"}},
        {"op": "widget.append", "parent": 10, "child": 11},
        {"op": "widget.append", "parent": 10, "child": 12},
        {"op": "widget.append", "parent": 10, "child": 13},
        {"op": "widget.append", "parent": 10, "child": 14},
        {"op": "widget.append", "parent": 10, "child": 15},
        {"op": "widget.append", "parent": 10, "child": 16},
        {"op": "widget.append", "parent": 10, "child": 17},
        {"op": "widget.append", "parent": 10, "child": 18},
        {"op": "window.set_root", "id": 1, "widget": 1},
        {"op": "widget.create", "id": 40, "type": "column"},
        {"op": "widget.create", "id": 41, "type": "menu_item", "props": {"label": "New"}},
        {"op": "widget.append", "parent": 40, "child": 41},
    ]));
    fixture.input(InputEvent::Scroll {
        x: 20.0,
        y: 20.0,
        dx: 0.0,
        dy: 30.0,
    });
    let offset = fixture.interaction.scroll.get(&9).copied().unwrap().1;
    assert!(
        offset > 4.0,
        "the list scrolled well clear of rounding: {offset}"
    );
    fixture.apply(&json!([{"op": "overlay.show", "window": 1, "widget": 40, "anchor": 12}]));
    let button = rect_of(&fixture, 12);
    let under = button.y - offset + button.height;

    assert_eq!(
        move_to(&mut fixture, button.x + 0.5, under + 0.5),
        Some(41),
        "the menu is under where the button is drawn now, not under where it was laid out"
    );
}
