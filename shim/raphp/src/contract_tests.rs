//! The command protocol through the C functions: the golden cases (plan P3.3), and the ordering contract between the
//! commands PHP sends and the events it reads (R15).

use std::ffi::c_char;
use std::ptr;
use std::sync::Arc;
use std::thread;
use std::time::{Duration, Instant};

use serde_json::{Value, json};

use crate::events::EventQueue;
use crate::protocol::MAX_BATCH_BYTES;
use crate::shim::{RaphpShim, Shim};
use crate::status::RaphpStatus;
use crate::{
    raphp_diagnostics, raphp_headless_advance, raphp_last_error, raphp_poll, raphp_send,
    raphp_shutdown,
};

fn start() -> *mut RaphpShim {
    let events = Arc::new(EventQueue::new().expect("an eventfd"));
    let shim = Shim::start_model(
        events,
        Duration::from_secs(2),
        &crate::config::Config::default(),
    )
    .expect("starts");

    Box::into_raw(Box::new(RaphpShim { shim }))
}

fn send(shim: *const RaphpShim, batch: &Value) -> RaphpStatus {
    send_text(shim, &batch.to_string())
}

fn send_text(shim: *const RaphpShim, batch: &str) -> RaphpStatus {
    unsafe { raphp_send(shim, batch.as_ptr().cast::<c_char>(), batch.len()) }
}

/// Every event that is queued now.
fn drain(shim: *const RaphpShim) -> Vec<Value> {
    let mut events = Vec::new();
    let mut buffer = vec![0u8; 64 * 1024];
    loop {
        let result =
            unsafe { raphp_poll(shim, buffer.as_mut_ptr().cast::<c_char>(), buffer.len()) };
        if result == 0 {
            return events;
        }
        if result == RaphpStatus::BufferTooSmall as isize {
            let doubled = buffer.len() * 2;
            buffer.resize(doubled, 0);

            continue;
        }

        assert!(result > 0, "raphp_poll failed with {result}");
        let text = std::str::from_utf8(&buffer[..result as usize]).expect("UTF-8");
        events.extend(
            text.lines()
                .map(|line| serde_json::from_str::<Value>(line).expect("an event is JSON")),
        );
    }
}

/// Collects events until the `batch.done` of the given batch has arrived (the UI thread works on its own).
fn until_done(shim: *const RaphpShim, batch: u64) -> Vec<Value> {
    let deadline = Instant::now() + Duration::from_secs(10);
    let mut collected = Vec::new();
    loop {
        collected.extend(drain(shim));
        if collected
            .iter()
            .any(|event| event["type"] == "batch.done" && event["batch"] == batch)
        {
            return collected;
        }

        assert!(
            Instant::now() < deadline,
            "batch {batch} was never reported done: {collected:?}"
        );
        thread::sleep(Duration::from_millis(2));
    }
}

fn diagnostics(shim: *const RaphpShim) -> Value {
    let length = unsafe { raphp_diagnostics(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; length];
    let written = unsafe { raphp_diagnostics(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };
    assert_eq!(written, length);

    serde_json::from_slice(&buffer).expect("the diagnostics are JSON")
}

fn last_error(shim: *const RaphpShim) -> String {
    let length = unsafe { raphp_last_error(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; length];
    unsafe { raphp_last_error(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };

    String::from_utf8(buffer).expect("UTF-8")
}

// ---------------------------------------------------------------- the golden cases

#[test]
fn the_golden_cases_produce_exactly_the_documented_events_and_counters() {
    let golden: Value = serde_json::from_str(include_str!(
        "../../../docs/protocol/golden/shim-commands.json"
    ))
    .expect("the golden file is JSON");
    let cases = golden["cases"].as_array().expect("cases");
    assert!(cases.len() >= 8);

    for case in cases {
        let name = case["name"].as_str().unwrap();
        let quits = case["quits"] == true;
        let shim = start();

        let mut last_id = None;
        for batch in case["batches"].as_array().unwrap() {
            assert_eq!(
                send(shim, batch),
                RaphpStatus::Ok,
                "{name}: the batch was accepted"
            );
            if let Some(id) = batch.get("batch") {
                last_id = id.as_u64();
            }
        }

        // A marker batch makes sure everything before it has been applied (not after a quit: nothing is taken then).
        let marker = 999_999;
        let wait_for = if quits {
            last_id.unwrap()
        } else {
            assert_eq!(
                send(shim, &json!({"batch": marker, "commands": []})),
                RaphpStatus::Ok
            );
            marker
        };
        let mut events = until_done(shim, wait_for);
        if !quits {
            events.retain(|event| !(event["type"] == "batch.done" && event["batch"] == marker));
        }
        for event in &mut events {
            if event["type"] == "error" {
                assert!(
                    !event["message"].as_str().unwrap_or("").is_empty(),
                    "{name}: an error has a message"
                );
                event.as_object_mut().unwrap().remove("message");
            }
        }

        assert_eq!(Value::Array(events), case["events"], "{name}: events");

        if !quits {
            // The frames are drawn after the done event: a barrier makes sure they have been.
            assert_eq!(unsafe { raphp_headless_advance(shim, 0) }, RaphpStatus::Ok);
        }
        let mut actual = diagnostics(shim);
        if !quits {
            actual["batches"] = json!(actual["batches"].as_u64().unwrap() - 1); // the marker batch
        }
        assert_eq!(actual, case["diagnostics"], "{name}: diagnostics");

        assert_eq!(
            unsafe { raphp_shutdown(shim) },
            RaphpStatus::Ok,
            "{name}: shutdown"
        );
    }
}

// ---------------------------------------------------------------- R15: the ordering contract

#[test]
fn batches_are_applied_in_the_order_they_were_sent() {
    let shim = start();
    send(
        shim,
        &json!({"batch": 1, "commands": [{"op": "widget.create", "id": 1, "type": "label"}]}),
    );

    for index in 2..=500_u64 {
        send(
            shim,
            &json!({"batch": index, "commands": [{"op": "widget.set", "id": 1, "props": {"text": index.to_string()}}]}),
        );
    }

    let events = until_done(shim, 500);
    let done: Vec<u64> = events
        .iter()
        .filter(|e| e["type"] == "batch.done")
        .map(|e| e["batch"].as_u64().unwrap())
        .collect();
    assert_eq!(
        done,
        (1..=500).collect::<Vec<u64>>(),
        "one done event per batch, in order"
    );
    assert!(events.iter().all(|event| event["type"] != "error"));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn an_error_is_tied_to_the_batch_and_the_command_that_caused_it() {
    let shim = start();

    send(
        shim,
        &json!({"batch": 10, "commands": [
            {"op": "widget.create", "id": 1, "type": "label"},
            {"op": "widget.create", "id": 2, "type": "label"},
            {"op": "widget.set", "id": 77, "props": {"text": "x"}},
        ]}),
    );
    send(
        shim,
        &json!({"batch": 11, "commands": [{"op": "widget.destroy", "id": 88}]}),
    );

    let events = until_done(shim, 11);

    let summary: Vec<(String, Value, Value)> = events
        .iter()
        .map(|e| {
            (
                e["type"].as_str().unwrap().to_owned(),
                e["batch"].clone(),
                e["index"].clone(),
            )
        })
        .collect();
    assert_eq!(
        summary,
        vec![
            ("error".to_owned(), json!(10), json!(2)),
            ("batch.done".to_owned(), json!(10), Value::Null),
            ("error".to_owned(), json!(11), json!(0)),
            ("batch.done".to_owned(), json!(11), Value::Null),
        ],
        "each error names its batch and index, and each batch ends with its done event"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn an_event_queued_before_a_batch_comes_before_its_done_event() {
    // A click for widget 5 is already queued when PHP sends the batch that destroys widget 5. PHP sees the click
    // first and the done event after it, so it knows the click was generated against the tree as it was before.
    let shim = start();
    send(
        shim,
        &json!({"batch": 1, "commands": [{"op": "widget.create", "id": 5, "type": "button"}]}),
    );
    until_done(shim, 1);
    send_text(shim, r##"#emit {"type":"click","widget":5}"##);

    send(
        shim,
        &json!({"batch": 2, "commands": [{"op": "widget.destroy", "id": 5}]}),
    );
    let events = until_done(shim, 2);

    let order: Vec<&str> = events.iter().map(|e| e["type"].as_str().unwrap()).collect();
    assert_eq!(order, vec!["click", "batch.done"]);
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_continuous_event_is_never_merged_across_a_done_event() {
    let shim = start();
    send_text(shim, r##"#emit-c 1 pointer_move {"type":"pointer","x":1}"##);
    send(shim, &json!({"batch": 1, "commands": []}));
    // Wait until the batch was applied without reading any event: the done event is queued before the counter moves.
    let deadline = Instant::now() + Duration::from_secs(5);
    while diagnostics(shim)["batches"] != 1 {
        assert!(Instant::now() < deadline, "the batch was never applied");
        thread::sleep(Duration::from_millis(2));
    }
    send_text(shim, r##"#emit-c 1 pointer_move {"type":"pointer","x":2}"##);

    let events = drain(shim);

    let order: Vec<(String, Value)> = events
        .iter()
        .map(|e| (e["type"].as_str().unwrap().to_owned(), e["x"].clone()))
        .collect();
    assert_eq!(
        order,
        vec![
            ("pointer".to_owned(), json!(1)),
            ("batch.done".to_owned(), Value::Null),
            ("pointer".to_owned(), json!(2))
        ],
        "the second move did not replace the first, which came before the done event"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_batch_that_is_not_a_batch_is_refused_at_once_and_the_shim_stays_usable() {
    let shim = start();

    assert_eq!(send_text(shim, "{not json"), RaphpStatus::InvalidArgument);
    assert!(last_error(shim).contains("not valid JSON"));
    assert_eq!(send_text(shim, "[]"), RaphpStatus::Ok);
    let too_big = format!("[\"{}\"]", "x".repeat(MAX_BATCH_BYTES));
    assert_eq!(send_text(shim, &too_big), RaphpStatus::InvalidArgument);
    assert!(last_error(shim).contains("more than the limit"));

    assert_eq!(
        send(shim, &json!({"batch": 1, "commands": []})),
        RaphpStatus::Ok
    );
    until_done(shim, 1);
    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- the life of the command loop

#[test]
fn after_app_quit_commands_are_refused_as_closed_and_events_can_still_be_read() {
    let shim = start();
    send(shim, &json!({"batch": 1, "commands": [{"op": "app.quit"}]}));
    let events = until_done(shim, 1);
    assert_eq!(events[0], json!({"type": "app.quit"}));

    // The UI thread ends by itself after the quit; give it a moment to be gone.
    let deadline = Instant::now() + Duration::from_secs(5);
    while send_text(shim, "[]") == RaphpStatus::Ok {
        assert!(
            Instant::now() < deadline,
            "the shim kept taking commands after app.quit"
        );
        thread::sleep(Duration::from_millis(5));
    }

    assert_eq!(send_text(shim, "[]"), RaphpStatus::Closed);
    assert!(last_error(shim).contains("stopped taking commands"));
    assert!(
        !unsafe { &*shim }.shim.health.is_poisoned(),
        "an orderly end is not a failure"
    );
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_bug_in_the_model_is_contained_like_any_failure_of_the_ui_thread() {
    let shim = start();

    send(
        shim,
        &json!({"batch": 1, "commands": [{"op": "test.panic"}]}),
    );

    let deadline = Instant::now() + Duration::from_secs(5);
    while !unsafe { &*shim }.shim.health.is_poisoned() {
        assert!(
            Instant::now() < deadline,
            "the panic on the UI thread was not noticed"
        );
        thread::sleep(Duration::from_millis(5));
    }

    assert_eq!(send_text(shim, "[]"), RaphpStatus::Poisoned);
    let events = drain(shim);
    assert_eq!(events.len(), 1);
    assert_eq!(events[0]["type"], "shim.failed");
    assert!(
        events[0]["reason"]
            .as_str()
            .unwrap()
            .contains("the UI thread panicked: the model panicked on purpose")
    );
    assert_eq!(
        diagnostics(shim)["batches"],
        0,
        "the diagnostics still answer"
    );
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn shutting_down_with_batches_still_queued_does_not_hang() {
    let shim = start();
    for index in 1..=2_000_u64 {
        send(
            shim,
            &json!({"batch": index, "commands": [{"op": "widget.create", "id": index, "type": "label"}]}),
        );
    }

    let started = Instant::now();
    let status = unsafe { raphp_shutdown(shim) };

    assert_eq!(status, RaphpStatus::Ok);
    assert!(started.elapsed() < Duration::from_secs(2));
}

// ---------------------------------------------------------------- the diagnostic call

#[test]
fn the_diagnostics_are_read_in_two_steps_and_cut_on_a_character_boundary() {
    let shim = start();
    send(
        shim,
        &json!({"batch": 1, "commands": [{"op": "window.create", "id": 1}]}),
    );
    until_done(shim, 1);

    let full = unsafe { raphp_diagnostics(shim, ptr::null_mut(), 0) };
    assert!(full > 20);
    let mut small = vec![0u8; 10];
    let reported = unsafe { raphp_diagnostics(shim, small.as_mut_ptr().cast::<c_char>(), 10) };
    assert_eq!(
        reported, full,
        "the full length is returned so the caller can ask again"
    );
    assert_eq!(&small[..], &diagnostics_text(shim).as_bytes()[..10]);
    assert_eq!(
        unsafe { raphp_diagnostics(ptr::null(), ptr::null_mut(), 0) },
        0,
        "a null handle gives nothing"
    );
    unsafe { raphp_shutdown(shim) };
}

fn diagnostics_text(shim: *const RaphpShim) -> String {
    unsafe { &*shim }.shim.diagnostics.to_json()
}

#[test]
fn once_a_done_event_is_read_the_diagnostics_already_include_that_batch() {
    let shim = start();

    for index in 1..=300_u64 {
        send(
            shim,
            &json!({"batch": index, "commands": [{"op": "widget.create", "id": index, "type": "label"}]}),
        );
        until_done(shim, index);

        let counters = diagnostics(shim);
        assert_eq!(
            counters["batches"], index,
            "after the done event of batch {index}"
        );
        assert_eq!(counters["widgets"], index);
        assert_eq!(counters["commands_applied"], index);
    }

    unsafe { raphp_shutdown(shim) };
}

#[test]
fn once_a_done_event_is_read_the_redraws_it_asked_for_are_already_counted() {
    let shim = start();

    for index in 1..=60_u64 {
        send(
            shim,
            &json!({"batch": index, "commands": [{"op": "window.create", "id": index}]}),
        );
        until_done(shim, index);

        // No barrier: the request is counted before the done event, the frame comes later.
        let json = diagnostics(shim);
        assert_eq!(
            json["windows"][index.to_string()]["redraws_requested"],
            1,
            "after the done event of batch {index}"
        );
    }

    unsafe { raphp_shutdown(shim) };
}
