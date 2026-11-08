//! The command parser and the model's rules, without threads or queues (plan P3.3).

use std::sync::Arc;

use serde_json::{Value, json};

use crate::backend::HeadlessBackend;
use crate::config::Config;
use crate::diagnostics::Diagnostics;
use crate::events::Event;
use crate::model::{MAX_DEPTH, MAX_WIDGETS, MAX_WINDOWS, Model};
use crate::protocol::{
    Command, ErrorCode, MAX_BATCH_BYTES, MAX_COMMANDS_PER_BATCH, MAX_ID, MAX_TEXT_BYTES,
    WindowSettings, parse_batch, parse_command,
};

fn model() -> Model {
    Model::new(Arc::new(Diagnostics::default()))
}

/// The headless backend, as the loop would own it (a fresh one each time is enough for the model's rules).
fn test_backend() -> HeadlessBackend {
    HeadlessBackend::new(&Config::default(), Arc::default())
}

/// Applies the commands as a batch with the given id and returns the events as JSON values.
fn run(model: &mut Model, batch: u64, commands: Value) -> Vec<Value> {
    let text = json!({"batch": batch, "commands": commands}).to_string();
    let mut events: Vec<Event> = Vec::new();
    model.apply_batch(
        parse_batch(&text).expect("a batch"),
        &mut events,
        &mut test_backend(),
    );

    events
        .iter()
        .map(|event| serde_json::from_str(&event.json).expect("an event is JSON"))
        .collect()
}

/// The error events of a run, as (index, code).
fn errors(events: &[Value]) -> Vec<(u64, String)> {
    events
        .iter()
        .filter(|event| event["type"] == "error")
        .map(|event| {
            (
                event["index"].as_u64().unwrap(),
                event["code"].as_str().unwrap().to_owned(),
            )
        })
        .collect()
}

fn code(result: Result<Command, crate::protocol::CommandError>) -> ErrorCode {
    result.expect_err("the command should be refused").code
}

// ---------------------------------------------------------------- batches

#[test]
fn a_batch_is_an_array_or_an_object_with_an_id_and_commands() {
    let anonymous = parse_batch(r#"[{"op":"app.quit"}]"#).unwrap();
    assert_eq!((anonymous.id, anonymous.commands.len()), (None, 1));

    let named = parse_batch(r#"{"batch":7,"commands":[]}"#).unwrap();
    assert_eq!((named.id, named.commands.len()), (Some(7), 0));
}

#[test]
fn a_batch_that_is_not_a_batch_is_refused_with_a_reason() {
    for (text, reason) in [
        ("not json", "not valid JSON"),
        ("42", "a batch is a JSON array"),
        ("\"x\"", "a batch is a JSON array"),
        (r#"{"commands":[]}"#, "needs a \"batch\" id"),
        (r#"{"batch":1}"#, "needs a \"commands\" array"),
        (r#"{"batch":"1","commands":[]}"#, "must be an integer"),
        (r#"{"batch":-1,"commands":[]}"#, "from 0 to"),
        (r#"{"batch":1.5,"commands":[]}"#, "from 0 to"),
        (r#"{"batch":9007199254740992,"commands":[]}"#, "from 0 to"),
        (r#"{"batch":1,"commands":{}}"#, "must be an array"),
        (
            r#"{"batch":1,"commands":[],"extra":1}"#,
            "no field \"extra\"",
        ),
    ] {
        let error = parse_batch(text).expect_err(text);
        assert!(error.contains(reason), "{text}: {error}");
    }
}

#[test]
fn the_size_and_count_limits_of_a_batch_are_enforced_at_the_edge() {
    let at_the_limit = format!("[{}]", vec!["{}"; MAX_COMMANDS_PER_BATCH].join(","));
    assert_eq!(
        parse_batch(&at_the_limit).unwrap().commands.len(),
        MAX_COMMANDS_PER_BATCH
    );

    let over = format!("[{}]", vec!["{}"; MAX_COMMANDS_PER_BATCH + 1].join(","));
    assert!(
        parse_batch(&over)
            .unwrap_err()
            .contains("commands, more than")
    );

    let huge = format!("[\"{}\"]", "x".repeat(MAX_BATCH_BYTES));
    assert!(
        parse_batch(&huge)
            .unwrap_err()
            .contains("more than the limit")
    );

    let deep = "[".repeat(200) + &"]".repeat(200);
    assert!(
        parse_batch(&deep).is_err(),
        "JSON nested too deeply is refused, not recursed into"
    );
}

// ---------------------------------------------------------------- commands

#[test]
fn every_command_has_the_shape_the_documentation_gives() {
    let cases = [
        (
            json!({"op":"window.create","id":1,"title":"T","width":300,"height":200}),
            "window.create",
        ),
        (json!({"op":"window.set","id":1,"title":"T"}), "window.set"),
        (
            json!({"op":"window.set_root","id":1,"widget":2}),
            "window.set_root",
        ),
        (
            json!({"op":"window.set_root","id":1,"widget":null}),
            "window.set_root",
        ),
        (json!({"op":"window.destroy","id":1}), "window.destroy"),
        (
            json!({"op":"widget.create","id":2,"type":"label","props":{"text":"x"}}),
            "widget.create",
        ),
        (
            json!({"op":"widget.create","id":2,"type":"label"}),
            "widget.create",
        ),
        (
            json!({"op":"widget.set","id":2,"props":{"text":"y"},"base_rev":3}),
            "widget.set",
        ),
        (
            json!({"op":"widget.append","parent":2,"child":3}),
            "widget.append",
        ),
        (
            json!({"op":"widget.insert","parent":2,"child":3,"index":0}),
            "widget.insert",
        ),
        (json!({"op":"widget.remove","id":2}), "widget.remove"),
        (json!({"op":"widget.destroy","id":2}), "widget.destroy"),
        (json!({"op":"app.quit"}), "app.quit"),
    ];

    for (command, name) in cases {
        assert!(parse_command(&command).is_ok(), "{name}: {command}");
    }
}

#[test]
fn a_command_that_is_malformed_is_refused_with_the_right_reason() {
    assert_eq!(code(parse_command(&json!(5))), ErrorCode::BadField);
    assert_eq!(code(parse_command(&json!({}))), ErrorCode::MissingField);
    assert_eq!(code(parse_command(&json!({"op":5}))), ErrorCode::BadField);
    assert_eq!(
        code(parse_command(&json!({"op":"widget.fly"}))),
        ErrorCode::UnknownOp
    );
    assert_eq!(
        code(parse_command(&json!({"op":"widget.destroy"}))),
        ErrorCode::MissingField
    );
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.destroy","id":1,"extra":true})
        )),
        ErrorCode::UnknownField
    );
    assert_eq!(
        code(parse_command(&json!({"op":"app.quit","now":true}))),
        ErrorCode::UnknownField
    );
    for bad_id in [
        json!(0),
        json!(-1),
        json!(1.5),
        json!("1"),
        json!(null),
        json!(MAX_ID + 1),
        json!([1]),
    ] {
        assert_eq!(
            code(parse_command(&json!({"op":"widget.destroy","id":bad_id}))),
            ErrorCode::BadField,
            "{bad_id}"
        );
    }
    assert!(
        parse_command(&json!({"op":"widget.destroy","id":MAX_ID})).is_ok(),
        "the largest id is allowed"
    );
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.insert","parent":1,"child":2})
        )),
        ErrorCode::MissingField
    );
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.insert","parent":1,"child":2,"index":-1})
        )),
        ErrorCode::BadField
    );
    assert_eq!(
        code(parse_command(&json!({"op":"widget.set","id":1}))),
        ErrorCode::MissingField
    );
    assert_eq!(
        code(parse_command(&json!({"op":"widget.set","id":1,"props":[]}))),
        ErrorCode::BadField
    );
    assert_eq!(
        code(parse_command(&json!({"op":"widget.create","id":1}))),
        ErrorCode::MissingField
    );
    assert_eq!(
        code(parse_command(&json!({"op":"window.set_root","id":1}))),
        ErrorCode::MissingField,
        "null must be said"
    );
    assert_eq!(
        code(parse_command(&json!({"op":"window.set","id":1}))),
        ErrorCode::MissingField
    );
}

#[test]
fn window_settings_are_checked() {
    for bad in [
        json!({"op":"window.create","id":1,"title":5}),
        json!({"op":"window.create","id":1,"width":0}),
        json!({"op":"window.create","id":1,"width":-5}),
        json!({"op":"window.create","id":1,"height":100001}),
        json!({"op":"window.create","id":1,"width":"wide"}),
        json!({"op":"window.create","id":1,"title":"x".repeat(2000)}),
    ] {
        assert_eq!(code(parse_command(&bad)), ErrorCode::BadField, "{bad}");
    }

    let parsed =
        parse_command(&json!({"op":"window.create","id":1,"title":"ok","width":100000,"height":1}))
            .unwrap();
    assert_eq!(
        parsed,
        Command::WindowCreate {
            id: 1,
            settings: WindowSettings {
                title: Some("ok".into()),
                width: Some(100_000.0),
                height: Some(1.0)
            }
        }
    );
}

// ---------------------------------------------------------------- the widget tree

fn tree() -> Model {
    let mut model = model();
    let events = run(
        &mut model,
        1,
        json!([
            {"op":"window.create","id":1,"title":"App"},
            {"op":"widget.create","id":1,"type":"column","props":{"gap":4}},
            {"op":"widget.create","id":2,"type":"label","props":{"text":"hello"}},
            {"op":"widget.create","id":3,"type":"button","props":{"label":"Go"}},
            {"op":"widget.append","parent":1,"child":2},
            {"op":"widget.append","parent":1,"child":3},
            {"op":"window.set_root","id":1,"widget":1},
        ]),
    );
    assert_eq!(errors(&events), vec![]);

    model
}

#[test]
fn a_tree_is_built_and_its_properties_read_back() {
    let model = tree();

    assert_eq!(model.widget_count(), 3);
    assert_eq!(model.children_of(1), vec![2, 3]);
    assert_eq!(model.parent_of(2), Some(1));
    assert_eq!(model.window_root(1), Some(1));
    assert_eq!(model.prop(2, "text"), Some(json!("hello")));
    assert_eq!(
        model.window_settings(1).unwrap().width,
        Some(800.0),
        "the default size"
    );
}

#[test]
fn a_batch_reports_how_many_commands_took_effect_and_how_many_did_not() {
    let mut model = model();

    let events = run(
        &mut model,
        5,
        json!([
            {"op":"widget.create","id":1,"type":"label"},
            {"op":"widget.destroy","id":99},
            {"op":"widget.create","id":2,"type":"label"},
        ]),
    );

    assert_eq!(
        events.last().unwrap(),
        &json!({"type":"batch.done","batch":5,"applied":2,"failed":1})
    );
    assert_eq!(
        model.widget_count(),
        2,
        "the failure did not stop the next command"
    );
}

#[test]
fn an_error_event_names_the_batch_the_command_and_what_it_was_about() {
    let mut model = model();

    let events = run(
        &mut model,
        9,
        json!([
            {"op":"widget.create","id":1,"type":"label"},
            {"op":"widget.set","id":42,"props":{"text":"x"}},
        ]),
    );

    assert_eq!(events[0]["type"], "error");
    assert_eq!(events[0]["batch"], 9);
    assert_eq!(events[0]["index"], 1);
    assert_eq!(events[0]["op"], "widget.set");
    assert_eq!(events[0]["code"], "unknown_widget");
    assert_eq!(events[0]["target"], 42);
    assert!(!events[0]["message"].as_str().unwrap().is_empty());
}

#[test]
fn an_anonymous_batch_reports_errors_without_a_batch_id_and_ends_without_a_done_event() {
    let mut model = model();
    let mut events: Vec<Event> = Vec::new();

    model.apply_batch(
        parse_batch(r#"[{"op":"widget.destroy","id":3}]"#).unwrap(),
        &mut events,
        &mut test_backend(),
    );

    assert_eq!(events.len(), 1);
    let error: Value = serde_json::from_str(&events[0].json).unwrap();
    assert_eq!(error["batch"], Value::Null);
}

#[test]
fn unknown_ids_unknown_types_and_unknown_properties_are_errors_that_change_nothing() {
    let mut model = tree();

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.set","id":99,"props":{"text":"x"}},
            {"op":"widget.create","id":10,"type":"hologram"},
            {"op":"widget.create","id":11,"type":"label","props":{"colour":"red"}},
            {"op":"widget.set","id":2,"props":{"colour":"red"}},
            {"op":"widget.set","id":2,"props":{"text":5}},
            {"op":"widget.set","id":2,"props":{"text":"fine","colour":"red"}},
            {"op":"window.set","id":9,"title":"x"},
            {"op":"window.destroy","id":9},
            {"op":"window.set_root","id":9,"widget":1},
            {"op":"widget.append","parent":99,"child":2},
            {"op":"widget.append","parent":1,"child":99},
        ]),
    );

    assert_eq!(
        errors(&events),
        [
            (0, "unknown_widget"),
            (1, "unknown_type"),
            (2, "unknown_prop"),
            (3, "unknown_prop"),
            (4, "bad_prop"),
            (5, "unknown_prop"),
            (6, "unknown_window"),
            (7, "unknown_window"),
            (8, "unknown_window"),
            (9, "unknown_widget"),
            (10, "unknown_widget"),
        ]
        .map(|(index, code)| (index, code.to_owned()))
        .to_vec()
    );
    assert_eq!(model.widget_count(), 3, "no widget was created");
    assert_eq!(
        model.prop(2, "text"),
        Some(json!("hello")),
        "a set with one bad property changed none of them"
    );
}

#[test]
fn property_values_are_checked_against_their_types_and_ranges() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":1,"type":"progress_bar"},
            {"op":"widget.create","id":2,"type":"checkbox"},
            {"op":"widget.create","id":3,"type":"column"},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.set","id":1,"props":{"value":0.5}},
            {"op":"widget.set","id":1,"props":{"value":0}},
            {"op":"widget.set","id":1,"props":{"value":1}},
            {"op":"widget.set","id":1,"props":{"value":1.0001}},
            {"op":"widget.set","id":1,"props":{"value":-0.1}},
            {"op":"widget.set","id":1,"props":{"value":"half"}},
            {"op":"widget.set","id":2,"props":{"checked":"yes"}},
            {"op":"widget.set","id":2,"props":{"checked":true}},
            {"op":"widget.set","id":3,"props":{"gap":-1}},
            {"op":"widget.set","id":3,"props":{"gap":10000}},
            {"op":"widget.set","id":3,"props":{"gap":10001}},
            {"op":"widget.set","id":2,"props":{"label":"x".repeat(MAX_TEXT_BYTES)}},
            {"op":"widget.set","id":2,"props":{"label":"x".repeat(MAX_TEXT_BYTES + 1)}},
        ]),
    );

    assert_eq!(
        errors(&events)
            .iter()
            .map(|(index, _)| *index)
            .collect::<Vec<_>>(),
        vec![3, 4, 5, 6, 8, 10, 12]
    );
}

#[test]
fn null_resets_a_property_to_its_default() {
    let mut model = tree();

    let events = run(
        &mut model,
        2,
        json!([{"op":"widget.set","id":2,"props":{"text":null}}]),
    );

    assert_eq!(errors(&events), vec![]);
    assert_eq!(model.prop(2, "text"), None);
}

#[test]
fn ids_only_go_up_and_are_never_reused() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":5,"type":"label"},
            {"op":"window.create","id":3},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.create","id":5,"type":"label"},
            {"op":"widget.create","id":4,"type":"label"},
            {"op":"widget.create","id":6,"type":"label"},
            {"op":"window.create","id":3},
            {"op":"window.create","id":2},
            {"op":"window.create","id":4},
        ]),
    );
    assert_eq!(
        errors(&events),
        [
            (0, "id_reused"),
            (1, "id_reused"),
            (3, "id_reused"),
            (4, "id_reused")
        ]
        .map(|(i, c)| (i, c.to_owned()))
        .to_vec()
    );

    run(&mut model, 3, json!([{"op":"widget.destroy","id":6}]));
    let events = run(
        &mut model,
        4,
        json!([{"op":"widget.create","id":6,"type":"label"}]),
    );
    assert_eq!(
        errors(&events)[0].1,
        "id_reused",
        "not even after the widget was destroyed"
    );
}

// ---------------------------------------------------------------- structure: cycles, parents, containers

#[test]
fn a_widget_cannot_be_put_under_itself_or_its_own_descendants() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":1,"type":"column"},
            {"op":"widget.create","id":2,"type":"column"},
            {"op":"widget.create","id":3,"type":"column"},
            {"op":"widget.append","parent":1,"child":2},
            {"op":"widget.append","parent":2,"child":3},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.append","parent":1,"child":1},
            {"op":"widget.append","parent":3,"child":1},
            {"op":"widget.append","parent":2,"child":1},
        ]),
    );

    assert_eq!(
        errors(&events),
        [(0, "cycle"), (1, "cycle"), (2, "cycle")]
            .map(|(i, c)| (i, c.to_owned()))
            .to_vec()
    );
    assert_eq!(model.children_of(1), vec![2], "the tree is unchanged");
    assert_eq!(model.parent_of(1), None);
}

#[test]
fn a_widget_with_a_parent_must_be_removed_before_it_is_attached_elsewhere() {
    let mut model = tree();
    run(
        &mut model,
        1,
        json!([{"op":"widget.create","id":4,"type":"row"}]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.append","parent":4,"child":2},
            {"op":"widget.remove","id":2},
            {"op":"widget.remove","id":2},
            {"op":"widget.append","parent":4,"child":2},
        ]),
    );

    assert_eq!(
        errors(&events),
        [(0, "has_parent"), (2, "no_parent")]
            .map(|(i, c)| (i, c.to_owned()))
            .to_vec()
    );
    assert_eq!(model.children_of(1), vec![3]);
    assert_eq!(model.children_of(4), vec![2]);
    assert_eq!(model.parent_of(2), Some(4));
}

#[test]
fn a_window_root_has_no_parent_and_cannot_be_given_one_or_become_a_second_root() {
    let mut model = tree();
    run(
        &mut model,
        1,
        json!([
            {"op":"window.create","id":2},
            {"op":"widget.create","id":4,"type":"column"},
            {"op":"widget.create","id":5,"type":"column"},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.append","parent":4,"child":1},
            {"op":"window.set_root","id":2,"widget":1},
            {"op":"widget.append","parent":4,"child":2},
            {"op":"window.set_root","id":2,"widget":2},
            {"op":"window.set_root","id":2,"widget":4},
            {"op":"window.set_root","id":2,"widget":5},
            {"op":"window.set_root","id":2,"widget":null},
            {"op":"window.set_root","id":2,"widget":5},
        ]),
    );

    assert_eq!(
        errors(&events),
        [
            (0, "already_root"),
            (1, "already_root"),
            (2, "has_parent"),
            (3, "has_parent"),
            (5, "window_has_root")
        ]
        .map(|(i, c)| (i, c.to_owned()))
        .to_vec()
    );
    assert_eq!(model.window_root(2), Some(5), "cleared, then set again");
    assert!(
        model.parent_of(4).is_none() && model.widget_count() == 5,
        "clearing a root destroys nothing"
    );
}

// ---------------------------------------------------------------- destroying

#[test]
fn destroying_a_widget_destroys_what_is_below_it_and_leaves_its_parent_consistent() {
    let mut model = tree();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":4,"type":"column"},
            {"op":"widget.create","id":5,"type":"label"},
            {"op":"widget.append","parent":4,"child":5},
            {"op":"widget.append","parent":1,"child":4},
        ]),
    );
    assert_eq!(model.widget_count(), 5);

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.destroy","id":4},
            {"op":"widget.set","id":5,"props":{"text":"gone"}},
            {"op":"widget.destroy","id":4},
        ]),
    );

    assert_eq!(
        errors(&events),
        [(1, "unknown_widget"), (2, "unknown_widget")]
            .map(|(i, c)| (i, c.to_owned()))
            .to_vec()
    );
    assert_eq!(model.widget_count(), 3);
    assert_eq!(model.children_of(1), vec![2, 3]);
}

#[test]
fn destroying_the_root_clears_the_window_and_destroying_a_window_destroys_its_tree() {
    let mut model = tree();

    run(&mut model, 1, json!([{"op":"widget.destroy","id":1}]));
    assert_eq!(model.window_root(1), None);
    assert_eq!(model.widget_count(), 0);

    let mut model = tree();
    let events = run(
        &mut model,
        1,
        json!([{"op":"window.destroy","id":1}, {"op":"window.destroy","id":1}]),
    );
    assert_eq!(errors(&events), vec![(1, "unknown_window".to_owned())]);
    assert_eq!(model.widget_count(), 0, "the tree went with the window");
}

#[test]
fn window_set_changes_only_what_it_names() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([{"op":"window.create","id":1,"title":"A","width":300,"height":200}]),
    );

    run(
        &mut model,
        2,
        json!([{"op":"window.set","id":1,"title":"B"}, {"op":"window.set","id":1,"height":50}]),
    );

    let settings = model.window_settings(1).unwrap();
    assert_eq!(
        (settings.title.as_deref(), settings.width, settings.height),
        (Some("B"), Some(300.0), Some(50.0))
    );
}

#[test]
fn the_number_of_widgets_and_windows_is_limited() {
    let mut model = model();
    let total = MAX_WIDGETS as u64 + 1;
    // Two batches: one batch holds at most MAX_COMMANDS_PER_BATCH commands.
    let half = total / 2;
    let create = |from: u64, to: u64| -> Value {
        Value::Array(
            (from..=to)
                .map(|id| json!({"op":"widget.create","id":id,"type":"label"}))
                .collect(),
        )
    };
    assert_eq!(errors(&run(&mut model, 1, create(1, half))), vec![]);
    let events = run(&mut model, 2, create(half + 1, total));
    assert_eq!(
        errors(&events),
        vec![(total - half - 1, "limit".to_owned())],
        "only the last one is over"
    );
    assert_eq!(model.widget_count(), MAX_WIDGETS);

    let mut model = self::model();
    let commands: Vec<Value> = (1..=(MAX_WINDOWS as u64 + 1))
        .map(|id| json!({"op":"window.create","id":id}))
        .collect();
    let events = run(&mut model, 1, Value::Array(commands));
    assert_eq!(
        errors(&events),
        vec![(MAX_WINDOWS as u64, "limit".to_owned())]
    );
}

// ---------------------------------------------------------------- depth

fn chain(first: u64, length: u64) -> Vec<Value> {
    let mut commands: Vec<Value> = (first..first + length)
        .map(|id| json!({"op":"widget.create","id":id,"type":"column"}))
        .collect();
    commands.extend(
        (first..first + length - 1)
            .map(|id| json!({"op":"widget.append","parent":id,"child":id + 1})),
    );

    commands
}

#[test]
fn nesting_deeper_than_the_limit_is_refused_and_the_limit_itself_is_allowed() {
    let mut model = model();
    let depth = MAX_DEPTH as u64;
    let mut commands = chain(1, depth);
    commands.push(json!({"op":"widget.create","id":depth + 1,"type":"column"}));
    commands.push(json!({"op":"widget.append","parent":depth,"child":depth + 1}));

    let events = run(&mut model, 1, Value::Array(commands));

    assert_eq!(errors(&events).len(), 1, "{:?}", errors(&events));
    assert_eq!(errors(&events)[0].1, "limit");
    assert_eq!(
        model.children_of(depth - 1),
        vec![depth],
        "{MAX_DEPTH} levels are allowed"
    );
    assert!(model.children_of(depth).is_empty());
}

#[test]
fn a_subtree_cannot_be_attached_where_it_would_make_the_tree_too_deep() {
    let mut model = model();
    run(&mut model, 1, Value::Array(chain(1, 40)));
    run(&mut model, 2, Value::Array(chain(41, 30)));

    let too_deep = run(
        &mut model,
        3,
        json!([{"op":"widget.append","parent":40,"child":41}]),
    ); // 40 + 30 = 70 levels
    let fits = run(
        &mut model,
        4,
        json!([{"op":"widget.append","parent":30,"child":41}]),
    ); // 30 + 30 = 60 levels

    assert_eq!(errors(&too_deep), vec![(0, "limit".to_owned())]);
    assert_eq!(errors(&fits), vec![]);
}

// ---------------------------------------------------------------- R15: events, stale text, quitting

#[test]
fn a_user_edit_makes_a_later_text_set_with_an_old_revision_stale() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([{"op":"widget.create","id":1,"type":"text_input","props":{"text":"a"}}]),
    );
    model.simulate_user_edit(1, "ab"); // the user typed: revision 1

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.set","id":1,"props":{"text":"PHP's older view"},"base_rev":0},
            {"op":"widget.set","id":1,"props":{"placeholder":"fine"},"base_rev":0},
            {"op":"widget.set","id":1,"props":{"text":"PHP knows"},"base_rev":1},
            {"op":"widget.set","id":1,"props":{"text":"no revision given"}},
        ]),
    );

    assert_eq!(
        errors(&events),
        vec![(0, "stale_text".to_owned())],
        "only a text set is refused, and only with an old revision"
    );
    assert_eq!(model.prop(1, "text"), Some(json!("no revision given")));
    assert_eq!(model.prop(1, "placeholder"), Some(json!("fine")));
}

#[test]
fn a_widget_event_is_only_emitted_while_the_widget_exists() {
    let mut model = tree();
    let mut sink: Vec<Event> = Vec::new();

    assert!(model.emit_widget_event(3, Event::discrete(r#"{"type":"click","id":3}"#), &mut sink));
    run(&mut model, 2, json!([{"op":"widget.destroy","id":3}]));
    assert!(!model.emit_widget_event(3, Event::discrete(r#"{"type":"click","id":3}"#), &mut sink));

    assert_eq!(sink.len(), 1, "nothing is emitted about a destroyed widget");
}

#[test]
fn app_quit_stops_the_batch_and_says_so() {
    let mut model = model();
    let text = r#"{"batch":3,"commands":[{"op":"widget.create","id":1,"type":"label"},{"op":"app.quit"},{"op":"widget.create","id":2,"type":"label"}]}"#;
    let mut events: Vec<Event> = Vec::new();

    let outcome = model.apply_batch(parse_batch(text).unwrap(), &mut events, &mut test_backend());

    assert!(outcome.quit);
    assert_eq!(
        model.widget_count(),
        1,
        "what came after the quit was not applied"
    );
    let lines: Vec<&str> = events.iter().map(|event| event.json.as_str()).collect();
    assert_eq!(
        lines,
        [
            r#"{"type":"app.quit"}"#,
            r#"{"applied":2,"batch":3,"failed":0,"type":"batch.done"}"#
        ]
    );
}

#[test]
fn the_diagnostics_count_batches_commands_and_widgets() {
    let diagnostics = Arc::new(Diagnostics::default());
    let mut model = Model::new(diagnostics.clone());

    let mut events: Vec<Event> = Vec::new();
    model.apply_batch(
        parse_batch(r#"{"batch":1,"commands":[{"op":"window.create","id":1},{"op":"widget.create","id":1,"type":"label"},{"op":"widget.destroy","id":9}]}"#).unwrap(),
        &mut events,
        &mut test_backend(),
    );

    let json: Value = serde_json::from_str(&diagnostics.to_json()).unwrap();
    assert_eq!(json["batches"], 1);
    assert_eq!(json["commands_applied"], 2);
    assert_eq!(json["commands_failed"], 1);
    assert_eq!(json["widgets"], 1);
    assert_eq!(
        json["windows"]["1"],
        json!({"redraws_requested": 1, "frames_drawn": 0}),
        "the new window asked for its first frame; the model alone draws none"
    );
}

#[test]
fn a_batch_reports_which_windows_need_a_frame_once_each() {
    let mut model = model();
    let mut events: Vec<Event> = Vec::new();
    let text = r#"{"batch":1,"commands":[{"op":"window.create","id":1},{"op":"window.create","id":2},{"op":"widget.create","id":1,"type":"label"},{"op":"window.set_root","id":1,"widget":1},{"op":"widget.set","id":1,"props":{"text":"a"}},{"op":"widget.set","id":1,"props":{"text":"b"}}]}"#;

    let outcome = model.apply_batch(parse_batch(text).unwrap(), &mut events, &mut test_backend());

    assert_eq!(
        outcome.dirty,
        vec![1, 2],
        "each window once, however many commands touched it"
    );

    let quiet = model.apply_batch(parse_batch("[]").unwrap(), &mut events, &mut test_backend());
    assert_eq!(
        quiet.dirty,
        Vec::<u64>::new(),
        "the next batch starts clean"
    );
}

// ---------------------------------------------------------------- edges found by mutation testing

#[test]
fn a_batch_exactly_at_the_size_limit_is_accepted_and_one_byte_more_is_not() {
    let filler = "x".repeat(MAX_BATCH_BYTES - 4); // ["..."] is the filler plus four bytes
    let at_the_limit = format!("[\"{filler}\"]");
    assert_eq!(at_the_limit.len(), MAX_BATCH_BYTES);
    assert_eq!(parse_batch(&at_the_limit).unwrap().commands.len(), 1);

    let over = format!("[\"{filler}x\"]");
    assert!(parse_batch(&over).is_err());
}

#[test]
fn the_largest_batch_id_is_allowed_and_the_next_is_not() {
    assert_eq!(
        parse_batch(&format!(r#"{{"batch":{MAX_ID},"commands":[]}}"#))
            .unwrap()
            .id,
        Some(MAX_ID)
    );
    assert_eq!(
        parse_batch(r#"{"batch":0,"commands":[]}"#).unwrap().id,
        Some(0)
    );
    assert!(parse_batch(&format!(r#"{{"batch":{},"commands":[]}}"#, MAX_ID + 1)).is_err());
}

#[test]
fn a_window_title_may_be_one_kilobyte_and_no_more() {
    let at_the_limit = json!({"op":"window.create","id":1,"title":"t".repeat(1024)});
    let over = json!({"op":"window.create","id":1,"title":"t".repeat(1025)});

    assert!(parse_command(&at_the_limit).is_ok());
    assert_eq!(code(parse_command(&over)), ErrorCode::BadField);
}

#[test]
fn base_rev_must_be_a_whole_number_in_range() {
    for bad in [json!(-1), json!(1.5), json!("1"), json!(MAX_ID + 1)] {
        let command = json!({"op":"widget.set","id":1,"props":{"text":"x"},"base_rev":bad});
        assert_eq!(code(parse_command(&command)), ErrorCode::BadField, "{bad}");
    }

    assert!(
        parse_command(&json!({"op":"widget.set","id":1,"props":{"text":"x"},"base_rev":MAX_ID}))
            .is_ok()
    );
    assert!(
        parse_command(&json!({"op":"widget.set","id":1,"props":{"text":"x"},"base_rev":null}))
            .is_ok(),
        "null means none"
    );
}

#[test]
fn an_insert_index_must_be_a_whole_number_from_zero() {
    for bad in [json!(-1), json!(1.5), json!("0"), json!(null)] {
        let command = json!({"op":"widget.insert","parent":1,"child":2,"index":bad});
        assert_eq!(code(parse_command(&command)), ErrorCode::BadField, "{bad}");
    }
}

#[test]
fn a_widget_type_must_be_a_string() {
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.create","id":1,"type":5})
        )),
        ErrorCode::BadField
    );
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.create","id":1,"type":"x".repeat(65)})
        )),
        ErrorCode::BadField
    );
    assert_eq!(
        code(parse_command(
            &json!({"op":"widget.create","id":1,"type":"label","props":5})
        )),
        ErrorCode::BadField
    );
}

#[test]
fn only_containers_take_children_and_a_scroll_view_takes_one() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":1,"type":"label"},
            {"op":"widget.create","id":2,"type":"label"},
            {"op":"widget.create","id":3,"type":"scroll_view"},
            {"op":"widget.create","id":4,"type":"label"},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.append","parent":1,"child":2},
            {"op":"widget.append","parent":3,"child":2},
            {"op":"widget.append","parent":3,"child":4},
        ]),
    );

    assert_eq!(
        errors(&events),
        [(0, "not_a_container"), (2, "limit")]
            .map(|(i, c)| (i, c.to_owned()))
            .to_vec()
    );
    assert_eq!(model.children_of(3), vec![2]);
}

#[test]
fn insert_puts_a_child_at_an_index_and_refuses_one_beyond_the_end() {
    let mut model = model();
    run(
        &mut model,
        1,
        json!([
            {"op":"widget.create","id":1,"type":"column"},
            {"op":"widget.create","id":2,"type":"label"},
            {"op":"widget.create","id":3,"type":"label"},
            {"op":"widget.create","id":4,"type":"label"},
            {"op":"widget.create","id":5,"type":"label"},
            {"op":"widget.create","id":6,"type":"label"},
            {"op":"widget.append","parent":1,"child":2},
            {"op":"widget.append","parent":1,"child":3},
        ]),
    );

    let events = run(
        &mut model,
        2,
        json!([
            {"op":"widget.insert","parent":1,"child":4,"index":0},
            {"op":"widget.insert","parent":1,"child":5,"index":3},
            {"op":"widget.insert","parent":1,"child":6,"index":5},
        ]),
    );

    assert_eq!(
        model.children_of(1),
        vec![4, 2, 3, 5],
        "index 3 was the end and is allowed"
    );
    assert_eq!(
        errors(&events),
        vec![(2, "bad_index".to_owned())],
        "index 5 is past the four children"
    );
}

#[test]
fn a_window_destroyed_in_the_same_batch_needs_no_frame() {
    let mut model = model();
    let mut events: Vec<Event> = Vec::new();
    let text = r#"[{"op":"window.create","id":1},{"op":"window.destroy","id":1},{"op":"window.create","id":2}]"#;

    let outcome = model.apply_batch(parse_batch(text).unwrap(), &mut events, &mut test_backend());

    assert_eq!(outcome.dirty, vec![2], "only the window that still exists");
}

#[test]
fn a_canvas_takes_a_draw_list_that_is_checked_when_it_is_set_and_refused_whole_when_it_is_wrong() {
    let mut model = tree();
    let list = json!([{"op": "rect", "x": 0, "y": 0, "w": 10, "h": 10, "fill": "#FF0000"}]);

    let events = run(
        &mut model,
        2,
        json!([
            {"op": "widget.create", "id": 20, "type": "canvas", "props": {"width": 100, "height": 50, "ops": list}},
            {"op": "widget.set", "id": 20, "props": {"ops": [{"op": "circle", "x": 1, "y": 1}]}},
            {"op": "widget.set", "id": 20, "props": {"ops": "rect"}},
            {"op": "widget.set", "id": 20, "props": {"ops": [], "width": -1}},
            {"op": "widget.set", "id": 20, "props": {"label": "x"}},
            {"op": "widget.create", "id": 21, "type": "canvas", "props": {"ops": [{"op": "line"}]}},
        ]),
    );

    assert_eq!(
        errors(&events),
        [
            (1, "bad_prop"),
            (2, "bad_prop"),
            (3, "bad_prop"),
            (4, "unknown_prop"),
            (5, "bad_prop"),
        ]
        .map(|(index, code)| (index, code.to_owned()))
        .to_vec()
    );
    let message = events.iter().find(|event| event["index"] == 1).unwrap()["message"]
        .as_str()
        .unwrap()
        .to_owned();
    assert!(
        message.contains("ops[0]: circle needs \"r\""),
        "the message says what is wrong with which operation: {message}"
    );
    assert_eq!(
        model.prop(20, "ops"),
        Some(list),
        "a refused list changed nothing, nor did a set with one bad property"
    );
    assert_eq!(model.prop(20, "width"), Some(json!(100)));
    assert!(
        model.widget_view(21).is_none(),
        "and a canvas with a bad list was not created"
    );

    run(
        &mut model,
        3,
        json!([{"op": "widget.set", "id": 20, "props": {"ops": null}}]),
    );
    assert_eq!(model.prop(20, "ops"), None, "null clears the drawing");
}
