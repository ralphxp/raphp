//! The headless backend through the C functions: frames at the pace of a clock that only moves when told to,
//! redraws only for real changes, pictures that can be read back, and the budget (plan P3.4).

use std::ffi::c_char;
use std::ptr;
use std::time::Duration;

use serde_json::{Value, json};

use crate::shim::{RaphpShim, Shim};
use crate::status::RaphpStatus;
use crate::{
    raphp_diagnostics, raphp_headless_advance, raphp_headless_frame, raphp_init, raphp_last_error,
    raphp_poll, raphp_send, raphp_shutdown,
};

fn start_with(config: &str) -> *mut RaphpShim {
    let shim = unsafe { raphp_init(config.as_ptr().cast::<c_char>(), config.len()) };
    assert!(!shim.is_null(), "init failed: {}", last_error(ptr::null()));

    shim
}

/// 100 frames a second, so that the interval is a round 10 ms.
fn start() -> *mut RaphpShim {
    start_with(r#"{"refresh_hz":100}"#)
}

fn send(shim: *const RaphpShim, id: u64, commands: &Value) -> RaphpStatus {
    let text = json!({"batch": id, "commands": commands}).to_string();

    unsafe { raphp_send(shim, text.as_ptr().cast::<c_char>(), text.len()) }
}

/// Waits until the UI thread has applied everything sent and drawn the frames that are due: no polling.
fn barrier(shim: *const RaphpShim) {
    assert_eq!(unsafe { raphp_headless_advance(shim, 0) }, RaphpStatus::Ok);
}

fn advance(shim: *const RaphpShim, millis: u64) {
    assert_eq!(
        unsafe { raphp_headless_advance(shim, millis) },
        RaphpStatus::Ok
    );
}

fn last_error(shim: *const RaphpShim) -> String {
    let length = unsafe { raphp_last_error(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; length];
    unsafe { raphp_last_error(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };

    String::from_utf8(buffer).unwrap()
}

/// (redraws requested, frames drawn) of a window.
fn counters(shim: *const RaphpShim, window: u64) -> (u64, u64) {
    let length = unsafe { raphp_diagnostics(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; length];
    unsafe { raphp_diagnostics(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };
    let json: Value = serde_json::from_slice(&buffer).unwrap();
    let entry = &json["windows"][window.to_string()];

    (
        entry["redraws_requested"].as_u64().unwrap(),
        entry["frames_drawn"].as_u64().unwrap(),
    )
}

fn events(shim: *const RaphpShim) -> Vec<Value> {
    let mut buffer = vec![0u8; 64 * 1024];
    let result = unsafe { raphp_poll(shim, buffer.as_mut_ptr().cast::<c_char>(), buffer.len()) };
    if result <= 0 {
        return Vec::new();
    }

    std::str::from_utf8(&buffer[..result as usize])
        .unwrap()
        .lines()
        .map(|line| serde_json::from_str(line).unwrap())
        .collect()
}

fn frame(shim: *const RaphpShim, window: u64) -> Result<(u32, u32, Vec<u8>), isize> {
    let (mut width, mut height) = (0u32, 0u32);
    let length = unsafe {
        raphp_headless_frame(
            shim,
            window,
            ptr::null_mut(),
            0,
            &raw mut width,
            &raw mut height,
        )
    };
    if length < 0 {
        return Err(length);
    }

    let mut pixels = vec![0u8; length as usize];
    let again = unsafe {
        raphp_headless_frame(
            shim,
            window,
            pixels.as_mut_ptr(),
            pixels.len(),
            ptr::null_mut(),
            ptr::null_mut(),
        )
    };
    assert_eq!(again, length);

    Ok((width, height, pixels))
}

/// A window with a column holding one label, set up and drawn once.
fn window_with_label(shim: *const RaphpShim) {
    send(
        shim,
        1,
        &json!([
            {"op": "window.create", "id": 1, "width": 40, "height": 30},
            {"op": "widget.create", "id": 1, "type": "column"},
            {"op": "widget.create", "id": 2, "type": "label", "props": {"text": "a"}},
            {"op": "widget.append", "parent": 1, "child": 2},
            {"op": "window.set_root", "id": 1, "widget": 1},
        ]),
    );
    barrier(shim);
}

fn set_text(shim: *const RaphpShim, batch: u64, text: &str) {
    send(
        shim,
        batch,
        &json!([{"op": "widget.set", "id": 2, "props": {"text": text}}]),
    );
}

// ---------------------------------------------------------------- the first frame and the pace

#[test]
fn a_new_window_gets_its_first_frame_at_once() {
    let shim = start();

    window_with_label(shim);

    assert_eq!(
        counters(shim, 1),
        (1, 1),
        "one redraw asked for by the whole batch, one frame drawn"
    );
    let (width, height, pixels) = frame(shim, 1).unwrap();
    assert_eq!((width, height), (40, 30));
    assert_eq!(pixels.len(), 40 * 30 * 4);
    assert!(
        pixels.chunks_exact(4).any(|pixel| pixel[..3] != [0xFF; 3]),
        "the label's text is drawn"
    );
    assert_eq!(
        &pixels[pixels.len() - 4..],
        &[0xFF; 4],
        "and where nothing is drawn it is white"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn further_changes_wait_for_the_frame_interval_and_share_one_frame() {
    let shim = start(); // 10 ms between frames
    window_with_label(shim);

    for batch in 2..=11 {
        set_text(shim, batch, &format!("text {batch}")); // ten changes with the clock standing still
    }
    barrier(shim);
    assert_eq!(
        counters(shim, 1),
        (11, 1),
        "ten more redraws were asked for, but the interval has not passed"
    );

    advance(shim, 9);
    assert_eq!(counters(shim, 1), (11, 1), "9 ms is not 10");
    advance(shim, 1);
    assert_eq!(
        counters(shim, 1),
        (11, 2),
        "at 10 ms one frame draws everything that is pending"
    );

    advance(shim, 1000);
    assert_eq!(
        counters(shim, 1),
        (11, 2),
        "nothing is pending, so time passing draws nothing"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_change_after_a_quiet_spell_is_drawn_at_once() {
    let shim = start();
    window_with_label(shim);
    advance(shim, 500);

    set_text(shim, 2, "later");
    barrier(shim);

    assert_eq!(
        counters(shim, 1),
        (2, 2),
        "no frame in the last interval, so no wait"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_frame_is_never_drawn_sooner_than_the_interval_after_the_last() {
    let shim = start();
    window_with_label(shim); // frame at t = 0

    advance(shim, 4);
    set_text(shim, 2, "x"); // asked at t = 4
    barrier(shim);
    assert_eq!(counters(shim, 1).1, 1);
    advance(shim, 5); // t = 9
    assert_eq!(counters(shim, 1).1, 1, "9 ms after the last frame");
    advance(shim, 1); // t = 10
    assert_eq!(
        counters(shim, 1).1,
        2,
        "10 ms after the last frame, not 10 ms after the request"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn each_window_keeps_its_own_pace() {
    let shim = start();
    send(
        shim,
        1,
        &json!([
            {"op": "window.create", "id": 1}, {"op": "window.create", "id": 2},
            {"op": "widget.create", "id": 1, "type": "label"}, {"op": "widget.create", "id": 2, "type": "label"},
            {"op": "window.set_root", "id": 1, "widget": 1}, {"op": "window.set_root", "id": 2, "widget": 2},
        ]),
    );
    barrier(shim);
    advance(shim, 6);

    send(
        shim,
        2,
        &json!([{"op": "widget.set", "id": 1, "props": {"text": "one"}}]),
    );
    advance(shim, 3); // t = 9
    send(
        shim,
        3,
        &json!([{"op": "widget.set", "id": 2, "props": {"text": "two"}}]),
    );
    barrier(shim);
    assert_eq!(
        (counters(shim, 1), counters(shim, 2)),
        ((2, 1), (2, 1)),
        "both frames are pending"
    );

    advance(shim, 1); // t = 10: both had their first frame at t = 0
    assert_eq!((counters(shim, 1), counters(shim, 2)), ((2, 2), (2, 2)));
    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- redraws only for real changes (F8)

#[test]
fn what_changes_nothing_asks_for_nothing() {
    let shim = start();
    window_with_label(shim);
    advance(shim, 100);
    let before = counters(shim, 1);

    send(shim, 2, &json!([])); // an empty batch
    send(
        shim,
        3,
        &json!([{"op": "widget.set", "id": 2, "props": {"text": "a"}}]),
    ); // the value it already has
    send(
        shim,
        4,
        &json!([{"op": "widget.set", "id": 2, "props": {"text": null}}]),
    ); // resets to default: a change
    send(
        shim,
        5,
        &json!([{"op": "widget.set", "id": 2, "props": {"text": null}}]),
    ); // already the default: nothing
    send(
        shim,
        6,
        &json!([{"op": "widget.set", "id": 99, "props": {"text": "x"}}]),
    ); // refused
    send(
        shim,
        7,
        &json!([{"op": "window.set", "id": 1, "title": "only a title"}]),
    ); // the OS draws the title
    send(
        shim,
        8,
        &json!([{"op": "window.set", "id": 1, "width": 40, "height": 30}]),
    ); // the size it has
    send(
        shim,
        9,
        &json!([
            {"op": "widget.create", "id": 3, "type": "label"}, // on no window
            {"op": "widget.set", "id": 3, "props": {"text": "nowhere"}},
            {"op": "widget.destroy", "id": 3},
        ]),
    );
    barrier(shim);

    assert_eq!(
        counters(shim, 1),
        (before.0 + 1, before.1 + 1),
        "only the reset to the default asked for a frame"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn changes_to_the_tree_of_a_window_each_ask_for_a_frame() {
    let shim = start();
    window_with_label(shim);
    send(
        shim,
        2,
        &json!([{"op": "widget.create", "id": 3, "type": "label"}, {"op": "widget.create", "id": 4, "type": "label"}]),
    );
    barrier(shim);
    advance(shim, 100);
    let mut expected = counters(shim, 1);

    for (batch, command) in [
        json!({"op": "widget.append", "parent": 1, "child": 3}),
        json!({"op": "widget.insert", "parent": 1, "child": 4, "index": 0}),
        json!({"op": "widget.remove", "id": 3}),
        json!({"op": "widget.destroy", "id": 4}),
        json!({"op": "window.set_root", "id": 1, "widget": null}),
        json!({"op": "window.set_root", "id": 1, "widget": 1}),
    ]
    .into_iter()
    .enumerate()
    {
        send(shim, 10 + batch as u64, &json!([command]));
        advance(shim, 10);
        expected = (expected.0 + 1, expected.1 + 1);
        assert_eq!(counters(shim, 1), expected, "after {command}");
    }
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_destroyed_window_draws_no_more_frames() {
    let shim = start();
    window_with_label(shim);
    set_text(shim, 2, "pending"); // a frame is pending (the interval has not passed)
    send(shim, 3, &json!([{"op": "window.destroy", "id": 1}]));
    barrier(shim);

    advance(shim, 100);

    assert_eq!(
        frame(shim, 1),
        Err(RaphpStatus::InvalidArgument as isize),
        "its picture is gone"
    );
    let json: Value = {
        let length = unsafe { raphp_diagnostics(shim, ptr::null_mut(), 0) };
        let mut buffer = vec![0u8; length];
        unsafe { raphp_diagnostics(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };
        serde_json::from_slice(&buffer).unwrap()
    };
    assert_eq!(
        json["windows"],
        json!({}),
        "and so is its entry in the diagnostics"
    );
    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- resizing, HiDPI, the budget

#[test]
fn resizing_a_window_makes_a_new_picture_and_asks_for_a_frame() {
    let shim = start();
    window_with_label(shim);
    advance(shim, 100);
    let before = counters(shim, 1);

    send(
        shim,
        2,
        &json!([{"op": "window.set", "id": 1, "width": 10, "height": 20}]),
    );
    barrier(shim);

    let (width, height, pixels) = frame(shim, 1).unwrap();
    assert_eq!((width, height, pixels.len()), (10, 20, 10 * 20 * 4));
    assert_eq!(counters(shim, 1), (before.0 + 1, before.1 + 1));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn the_picture_is_in_physical_pixels() {
    let shim = start_with(r#"{"scale":2,"refresh_hz":100}"#);

    window_with_label(shim); // 40 by 30 logical

    let (width, height, pixels) = frame(shim, 1).unwrap();
    assert_eq!((width, height, pixels.len()), (80, 60, 80 * 60 * 4));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_window_that_does_not_fit_the_budget_is_refused_as_a_command_error() {
    let shim = start_with(r#"{"surface_budget_mib":1,"refresh_hz":100}"#);

    send(
        shim,
        1,
        &json!([
            {"op": "window.create", "id": 1, "width": 400, "height": 300},
            {"op": "window.create", "id": 2, "width": 2000, "height": 2000},
            {"op": "window.set", "id": 1, "width": 5000, "height": 5000},
            {"op": "window.set", "id": 1, "width": 410},
        ]),
    );
    barrier(shim);

    let events = events(shim);
    let errors: Vec<(&str, u64)> = events
        .iter()
        .filter(|event| event["type"] == "error")
        .map(|event| {
            (
                event["code"].as_str().unwrap(),
                event["index"].as_u64().unwrap(),
            )
        })
        .collect();
    assert_eq!(errors, vec![("limit", 1), ("limit", 2)]);
    assert!(events.iter().any(|event| {
        event["message"]
            .as_str()
            .is_some_and(|m| m.contains("budget of 1 MiB"))
    }));
    assert_eq!(
        frame(shim, 2),
        Err(RaphpStatus::InvalidArgument as isize),
        "the refused window has no picture"
    );
    let (width, height, _) = frame(shim, 1).unwrap();
    assert_eq!(
        (width, height),
        (410, 300),
        "the refused resize changed nothing; the one that fits applied"
    );
    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- the barrier, and errors of the control calls

#[test]
fn the_barrier_returns_only_after_everything_sent_before_it_has_been_applied() {
    let shim = start();

    for batch in 1..=300_u64 {
        send(
            shim,
            batch,
            &json!([{"op": "widget.create", "id": batch, "type": "label"}]),
        );
    }
    barrier(shim);

    let done = events(shim)
        .iter()
        .filter(|event| event["type"] == "batch.done")
        .count();
    assert_eq!(
        done, 300,
        "all 300 were applied by the time the barrier returned, with no waiting loop"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn the_control_calls_refuse_bad_arguments_and_a_closed_shim() {
    let shim = start();
    window_with_label(shim);
    let mut byte = 0u8;

    assert_eq!(
        unsafe { raphp_headless_advance(ptr::null(), 1) },
        RaphpStatus::InvalidArgument
    );
    assert_eq!(
        unsafe {
            raphp_headless_frame(
                ptr::null(),
                1,
                ptr::null_mut(),
                0,
                ptr::null_mut(),
                ptr::null_mut(),
            )
        },
        RaphpStatus::InvalidArgument as isize
    );
    assert_eq!(
        unsafe {
            raphp_headless_frame(
                shim,
                9,
                ptr::null_mut(),
                0,
                ptr::null_mut(),
                ptr::null_mut(),
            )
        },
        RaphpStatus::InvalidArgument as isize,
        "no window 9"
    );
    assert_eq!(
        unsafe {
            raphp_headless_frame(
                shim,
                1,
                ptr::null_mut(),
                5,
                ptr::null_mut(),
                ptr::null_mut(),
            )
        },
        RaphpStatus::InvalidArgument as isize,
        "null buffer with a capacity"
    );
    assert_eq!(
        unsafe {
            raphp_headless_frame(shim, 1, &raw mut byte, 1, ptr::null_mut(), ptr::null_mut())
        },
        40 * 30 * 4,
        "a small buffer gets the full length reported"
    );
    assert_eq!(byte, 0xFF);

    send(shim, 2, &json!([{"op": "app.quit"}]));
    let deadline = std::time::Instant::now() + Duration::from_secs(5);
    while unsafe { raphp_headless_advance(shim, 0) } == RaphpStatus::Ok {
        assert!(
            std::time::Instant::now() < deadline,
            "the shim kept answering after app.quit"
        );
    }
    assert_eq!(
        unsafe { raphp_headless_advance(shim, 0) },
        RaphpStatus::Closed
    );
    assert_eq!(
        frame(shim, 1).map(|(w, h, _)| (w, h)),
        Ok((40, 30)),
        "the last picture can still be read"
    );
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_shim_that_is_not_headless_says_so() {
    let shim = Shim::start(Duration::from_secs(2), crate::shim::idle_ui_body).expect("starts");

    let advance = shim.headless_advance(0).unwrap_err();
    let frame = shim.headless_frame(1, &mut []).unwrap_err();

    assert_eq!(advance.status, RaphpStatus::Unsupported);
    assert_eq!(frame.status, RaphpStatus::Unsupported);
    assert_eq!(shim.stop(), RaphpStatus::Ok);
}

#[test]
fn a_bad_configuration_stops_init_with_a_reason() {
    for (text, reason) in [
        (
            r#"{"backend":"window","scale":2}"#,
            "applies only to the headless backend",
        ),
        (r#"{"refresh_hz":0}"#, "from 1 to 1000"),
        (r#"{"colour":1}"#, "no setting"),
        ("not json", "not valid JSON"),
    ] {
        let shim = unsafe { raphp_init(text.as_ptr().cast::<c_char>(), text.len()) };

        assert!(shim.is_null(), "{text}");
        assert!(
            last_error(ptr::null()).contains(reason),
            "{text}: {}",
            last_error(ptr::null())
        );
    }
}

#[test]
fn a_poisoned_shim_still_lets_the_last_picture_be_read() {
    let shim = start();
    window_with_label(shim);
    send(shim, 2, &json!([{"op": "test.panic"}]));
    let deadline = std::time::Instant::now() + Duration::from_secs(5);
    while !unsafe { &*shim }.shim.health.is_poisoned() {
        assert!(std::time::Instant::now() < deadline);
        std::thread::sleep(Duration::from_millis(5));
    }

    assert_eq!(frame(shim, 1).map(|(w, h, _)| (w, h)), Ok((40, 30)));
    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- simulated input (P3.9)

fn simulate(shim: *const RaphpShim, batch: u64, event: &Value) {
    send(
        shim,
        batch,
        &json!([{"op": "input.simulate", "window": 1, "event": event}]),
    );
}

#[test]
fn a_simulated_click_reaches_php_as_events_that_follow_the_done_event_of_its_batch() {
    let shim = start();
    send(
        shim,
        1,
        &json!([
            {"op": "window.create", "id": 1, "width": 120, "height": 60},
            {"op": "widget.create", "id": 1, "type": "button", "props": {"label": "OK"}},
            {"op": "window.set_root", "id": 1, "widget": 1},
        ]),
    );
    barrier(shim);
    let _ = events(shim);

    send(
        shim,
        2,
        &json!([
            {"op": "input.simulate", "window": 1, "event": {"kind": "pointer.down", "x": 10, "y": 10}},
            {"op": "input.simulate", "window": 1, "event": {"kind": "pointer.up", "x": 10, "y": 10}},
        ]),
    );
    barrier(shim);

    let kinds: Vec<String> = events(shim)
        .iter()
        .map(|event| event["type"].as_str().unwrap().to_owned())
        .collect();
    assert_eq!(
        kinds,
        [
            "batch.done",
            "pointer.down",
            "focus.changed",
            "pointer.up",
            "click"
        ],
        "the batch took effect first; what the input led to follows"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_simulated_wheel_turn_scrolls_the_picture_and_asks_for_one_more_frame() {
    let shim = start();
    let mut commands = vec![
        json!({"op": "window.create", "id": 1, "width": 120, "height": 80}),
        json!({"op": "widget.create", "id": 1, "type": "scroll_view"}),
        json!({"op": "widget.create", "id": 2, "type": "column"}),
        json!({"op": "widget.append", "parent": 1, "child": 2}),
        json!({"op": "window.set_root", "id": 1, "widget": 1}),
    ];
    for index in 0..12_u64 {
        commands.push(json!({"op": "widget.create", "id": 10 + index, "type": "label", "props": {"text": format!("line {index}")}}));
        commands.push(json!({"op": "widget.append", "parent": 2, "child": 10 + index}));
    }
    send(shim, 1, &Value::Array(commands));
    barrier(shim);
    let before = frame(shim, 1).unwrap().2;
    let (requested, drawn) = counters(shim, 1);

    simulate(
        shim,
        2,
        &json!({"kind": "scroll", "x": 20, "y": 20, "dx": 0, "dy": 50}),
    );
    advance(shim, 10);

    let after = frame(shim, 1).unwrap().2;
    assert_ne!(before, after, "the picture shows the list scrolled");
    assert_eq!(
        counters(shim, 1),
        (requested + 1, drawn + 1),
        "one more frame asked for and drawn, and no more"
    );
    let scroll: Vec<Value> = events(shim)
        .into_iter()
        .filter(|event| event["type"] == "scroll")
        .collect();
    assert_eq!(scroll.len(), 1);
    assert_eq!(scroll[0]["offset"], json!([0.0, 50.0]));
    unsafe { raphp_shutdown(shim) };
}
