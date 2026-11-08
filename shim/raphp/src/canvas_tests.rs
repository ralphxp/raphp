//! The draw list of a canvas (plan P3.13): what is accepted, what each operation becomes, and every way it is refused.

use serde_json::{Value, json};

use crate::canvas::{
    MAX_OPS, MAX_PATH_POINTS, MAX_TEXT_BYTES, MAX_TOTAL_POINTS, Op, Rgba, Stroke, parse,
};

fn ok(list: &Value) -> Vec<Op> {
    parse(list).unwrap_or_else(|why| panic!("{list}: {why}"))
}

fn refused(list: &Value) -> String {
    parse(list).expect_err(&format!("{list} should be refused"))
}

fn rgb(red: u8, green: u8, blue: u8) -> Rgba {
    Rgba {
        red,
        green,
        blue,
        alpha: 255,
    }
}

#[test]
fn every_operation_becomes_what_it_says_with_the_defaults_filled_in() {
    let ops = ok(&json!([
        {"op": "rect", "x": 1, "y": 2, "w": 30, "h": 40},
        {"op": "rect", "x": 1.5, "y": 2, "w": 3, "h": 4, "radius": 5, "fill": "#102030", "stroke": "#AABBCC", "line_width": 2.5},
        {"op": "line", "x1": 0, "y1": 1, "x2": 2, "y2": 3, "stroke": "#000000"},
        {"op": "path", "points": [[0, 0], [10, 5], [20, 0]], "stroke": "#FF0000", "closed": true},
        {"op": "path", "points": [[0, 0], [10, 5]], "fill": "#00FF0080"},
        {"op": "circle", "x": 5, "y": 6, "r": 7, "fill": "#FFFFFF"},
        {"op": "text", "x": 3, "y": 4, "text": "Hi"},
        {"op": "text", "x": 3, "y": 4, "text": "Big", "fill": "#112233", "size": 20},
    ]));

    assert_eq!(
        ops,
        vec![
            Op::Rect {
                at: (1.0, 2.0),
                size: (30.0, 40.0),
                radius: 0.0,
                fill: None,
                stroke: None
            },
            Op::Rect {
                at: (1.5, 2.0),
                size: (3.0, 4.0),
                radius: 5.0,
                fill: Some(rgb(0x10, 0x20, 0x30)),
                stroke: Some(Stroke {
                    color: rgb(0xAA, 0xBB, 0xCC),
                    width: 2.5
                })
            },
            Op::Line {
                from: (0.0, 1.0),
                to: (2.0, 3.0),
                stroke: Stroke {
                    color: rgb(0, 0, 0),
                    width: 1.0
                }
            },
            Op::Path {
                points: vec![(0.0, 0.0), (10.0, 5.0), (20.0, 0.0)],
                closed: true,
                fill: None,
                stroke: Some(Stroke {
                    color: rgb(255, 0, 0),
                    width: 1.0
                })
            },
            Op::Path {
                points: vec![(0.0, 0.0), (10.0, 5.0)],
                closed: false,
                fill: Some(Rgba {
                    red: 0,
                    green: 255,
                    blue: 0,
                    alpha: 0x80
                }),
                stroke: None
            },
            Op::Circle {
                center: (5.0, 6.0),
                radius: 7.0,
                fill: Some(rgb(255, 255, 255)),
                stroke: None
            },
            Op::Text {
                at: (3.0, 4.0),
                text: "Hi".to_owned(),
                color: None,
                size: 14.0
            },
            Op::Text {
                at: (3.0, 4.0),
                text: "Big".to_owned(),
                color: Some(rgb(0x11, 0x22, 0x33)),
                size: 20.0
            },
        ]
    );
}

#[test]
fn an_empty_list_draws_nothing_and_is_fine() {
    assert!(ok(&json!([])).is_empty());
}

#[test]
fn null_fields_are_the_same_as_absent_ones() {
    let ops = ok(
        &json!([{"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1, "fill": null, "radius": null, "line_width": null}]),
    );

    assert_eq!(
        ops,
        vec![Op::Rect {
            at: (0.0, 0.0),
            size: (1.0, 1.0),
            radius: 0.0,
            fill: None,
            stroke: None
        }]
    );
}

#[test]
fn colors_are_six_or_eight_hex_digits_after_a_hash_and_nothing_else() {
    let rect = |fill: &str| json!([{"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1, "fill": fill}]);

    assert!(parse(&rect("#abcdef")).is_ok(), "lower case");
    assert!(parse(&rect("#ABCDEF12")).is_ok(), "with alpha");
    for bad in [
        "",
        "#",
        "#FFF",
        "#FFFFF",
        "#FFFFFFF",
        "#FFFFFFFFF",
        "FFFFFF",
        "red",
        "#GGGGGG",
        "#12345é",
        " #FFFFFF",
    ] {
        let why = refused(&rect(bad));
        assert!(why.contains("\"fill\" is a color"), "{bad:?}: {why}");
    }
    assert!(
        refused(&json!([{"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1, "fill": 5}]))
            .contains("\"fill\" is a color string")
    );
}

#[test]
fn a_refusal_says_which_operation_and_what_is_wrong() {
    let why = refused(&json!([
        {"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1},
        {"op": "circle", "x": 0, "y": 0}
    ]));

    assert_eq!(why, "ops[1]: circle needs \"r\"");
}

#[test]
fn what_is_not_a_list_of_operations_is_refused() {
    assert!(refused(&json!({"op": "rect"})).contains("array"));
    assert!(refused(&json!("rect")).contains("array"));
    assert!(refused(&json!([5])).contains("an operation is an object"));
    assert!(refused(&json!([{"x": 1}])).contains("missing \"op\""));
    assert!(refused(&json!([{"op": 3}])).contains("missing \"op\""));
    let unknown = refused(&json!([{"op": "polygon"}]));
    assert!(
        unknown.contains("unknown operation \"polygon\""),
        "{unknown}"
    );
    let long = refused(&json!([{"op": "x".repeat(100)}]));
    assert!(
        long.len() < 200,
        "a long name is cut in the message: {long}"
    );
}

#[test]
fn a_field_an_operation_does_not_have_is_refused_rather_than_ignored() {
    let why = refused(
        &json!([{"op": "line", "x1": 0, "y1": 0, "x2": 1, "y2": 1, "stroke": "#000000", "fill": "#000000"}]),
    );
    assert_eq!(why, "ops[0]: line has no field \"fill\"");

    assert!(
        refused(&json!([{"op": "text", "x": 0, "y": 0, "text": "a", "stroke": "#000000"}]))
            .contains("text has no field \"stroke\"")
    );
    assert!(
        refused(&json!([{"op": "circle", "x": 0, "y": 0, "r": 1, "w": 2}]))
            .contains("circle has no field \"w\"")
    );
    assert!(
        refused(&json!([{"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1, "r": 2}]))
            .contains("rect has no field \"r\"")
    );
    assert!(
        refused(&json!([{"op": "path", "points": [[0, 0], [1, 1]], "fill": "#000000", "x": 0}]))
            .contains("path has no field \"x\"")
    );
}

#[test]
fn numbers_must_be_present_numbers_of_a_sane_size() {
    assert!(refused(&json!([{"op": "rect", "y": 0, "w": 1, "h": 1}])).contains("rect needs \"x\""));
    assert!(
        refused(&json!([{"op": "rect", "x": "0", "y": 0, "w": 1, "h": 1}]))
            .contains("\"x\" is a number")
    );
    assert!(
        refused(&json!([{"op": "rect", "x": 2_000_000, "y": 0, "w": 1, "h": 1}]))
            .contains("between")
    );
    assert!(
        parse(&json!([{"op": "rect", "x": 1_000_000, "y": -1_000_000, "w": 1, "h": 1}])).is_ok()
    );
    assert!(
        refused(&json!([{"op": "rect", "x": 0, "y": 0, "w": -1, "h": 1}]))
            .contains("\"w\" is not negative")
    );
    assert!(refused(&json!([{"op": "rect", "x": 0, "y": 0, "w": 1}])).contains("rect needs \"h\""));
    assert!(
        refused(&json!([{"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1, "radius": -2}]))
            .contains("\"radius\" is not negative")
    );
    assert!(
        parse(&json!([{"op": "circle", "x": 0, "y": 0, "r": 0}])).is_ok(),
        "an empty circle is allowed (it draws nothing)"
    );
    assert!(refused(&json!([{"op": "circle", "x": 0, "y": 0, "r": -1}])).contains("not negative"));
}

#[test]
fn the_line_width_is_above_nothing_and_a_line_needs_a_stroke() {
    let line = |extra: Value| {
        let mut op = json!({"op": "line", "x1": 0, "y1": 0, "x2": 1, "y2": 1, "stroke": "#000000"});
        op.as_object_mut()
            .unwrap()
            .extend(extra.as_object().unwrap().clone());
        json!([op])
    };

    assert!(parse(&line(json!({"line_width": 0.25}))).is_ok());
    assert!(parse(&line(json!({"line_width": 1000}))).is_ok());
    assert!(refused(&line(json!({"line_width": 0}))).contains("\"line_width\""));
    assert!(refused(&line(json!({"line_width": 1000.5}))).contains("\"line_width\""));
    assert!(refused(&line(json!({"line_width": -1}))).contains("\"line_width\""));
    assert!(refused(&line(json!({"line_width": "2"}))).contains("\"line_width\" is a number"));
    assert_eq!(
        refused(&json!([{"op": "line", "x1": 0, "y1": 0, "x2": 1, "y2": 1}])),
        "ops[0]: line needs \"stroke\""
    );
}

#[test]
fn a_path_needs_two_or_more_points_that_are_pairs_of_numbers_and_something_to_draw() {
    let path = |points: Value| json!([{"op": "path", "points": points, "stroke": "#000000"}]);

    assert!(
        refused(&json!([{"op": "path", "stroke": "#000000"}])).contains("path needs \"points\"")
    );
    assert!(
        refused(&json!([{"op": "path", "points": 3, "stroke": "#000000"}]))
            .contains("path needs \"points\"")
    );
    assert!(refused(&path(json!([[0, 0]]))).contains("from 2 to"));
    assert!(refused(&path(json!([]))).contains("from 2 to"));
    assert!(refused(&path(json!([[0, 0], [1]]))).contains("points[1] is a pair"));
    assert!(refused(&path(json!([[0, 0], [1, 2, 3]]))).contains("points[1] is a pair"));
    assert!(refused(&path(json!([[0, 0], 5]))).contains("points[1] is a pair"));
    assert!(refused(&path(json!([[0, 0], ["a", 1]]))).contains("points[1] holds numbers"));
    assert!(refused(&path(json!([[0, 0], [1, 2_000_000]]))).contains("points[1] holds numbers"));
    assert!(parse(&path(json!([[0, 0], [1_000_000, -1_000_000]]))).is_ok());
    assert!(refused(&path(json!([[0, 0], [1_000_001, 0]]))).contains("points[1] holds numbers"));
    assert!(
        refused(&json!([{"op": "path", "points": [[0, 0], [1, 1]]}]))
            .contains("needs a \"fill\" or a \"stroke\"")
    );
    assert!(
        refused(
            &json!([{"op": "path", "points": [[0, 0], [1, 1]], "stroke": "#000000", "closed": 1}])
        )
        .contains("\"closed\" is true or false")
    );
}

#[test]
fn the_sizes_of_a_list_are_limited() {
    let rect = json!({"op": "rect", "x": 0, "y": 0, "w": 1, "h": 1});

    assert_eq!(
        ok(&Value::Array(vec![rect.clone(); MAX_OPS])).len(),
        MAX_OPS
    );
    assert!(refused(&Value::Array(vec![rect; MAX_OPS + 1])).contains("at most 4096 operations"));

    let points = |count: usize| Value::Array(vec![json!([0, 0]); count]);
    let path = |count: usize| json!({"op": "path", "points": points(count), "stroke": "#000000"});
    assert!(parse(&json!([path(MAX_PATH_POINTS)])).is_ok());
    assert!(refused(&json!([path(MAX_PATH_POINTS + 1)])).contains("from 2 to 4096"));

    let many = vec![path(MAX_PATH_POINTS); MAX_TOTAL_POINTS / MAX_PATH_POINTS];
    assert!(parse(&Value::Array(many.clone())).is_ok());
    let mut too_many = many;
    too_many.push(path(2));
    assert!(refused(&Value::Array(too_many)).contains("at most 65536 path points"));
}

#[test]
fn text_is_a_string_of_limited_length_and_a_sane_size() {
    let text = |text: Value, size: Value| json!([{"op": "text", "x": 0, "y": 0, "text": text, "size": size}]);

    assert!(parse(&text(json!("é"), json!(4))).is_ok());
    assert!(parse(&text(json!("a".repeat(MAX_TEXT_BYTES)), json!(200))).is_ok());
    assert!(
        refused(&text(json!("a".repeat(MAX_TEXT_BYTES + 1)), json!(14)))
            .contains("at most 4096 bytes")
    );
    assert!(refused(&text(json!(5), json!(14))).contains("text needs \"text\""));
    assert!(refused(&json!([{"op": "text", "x": 0, "y": 0}])).contains("text needs \"text\""));
    assert!(refused(&text(json!("a"), json!(3.9))).contains("\"size\" is from 4 to 200"));
    assert!(refused(&text(json!("a"), json!(200.1))).contains("\"size\" is from 4 to 200"));
    assert!(
        parse(&text(json!("a"), json!(null))).is_ok(),
        "null is the default size"
    );
}
