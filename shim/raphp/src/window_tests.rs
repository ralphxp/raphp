//! The window backend (plan P3.6). Most of it needs a window system, so the tests are in three kinds:
//! - always run: the events' shapes, how the model reacts to a backend that refuses, the configuration, and
//!   `raphp_init` with no display (in a child process, so the environment of this one is not touched);
//! - **opt-in, `#[ignore]`**: tests that open a real window on the session they run in (`make window-test`).
//!   They are never part of `make check`, so that a check never puts a window on anybody's screen.

use std::ffi::c_char;
use std::process::Command as Process;
use std::ptr;
use std::sync::Arc;
use std::time::{Duration, Instant};

use serde_json::{Value, json};

use crate::backend::{Backend, BackendError};
use crate::clock::{Clock, ManualClock};
use crate::diagnostics::Diagnostics;
use crate::events::{ContinuousKind, Event, EventClass};
use crate::input::Interaction;
use crate::model::Model;
use crate::protocol::parse_batch;
use crate::shim::RaphpShim;
use crate::status::RaphpStatus;
use crate::window::{close_requested_event, focus_event, resized_event, scale_event, start_error};
use crate::{
    raphp_diagnostics, raphp_init, raphp_last_error, raphp_poll, raphp_send, raphp_shutdown,
};

// ---------------------------------------------------------------- always run

fn json_of(event: &Event) -> Value {
    serde_json::from_str(&event.json).unwrap()
}

#[test]
fn the_window_events_have_the_documented_shape() {
    assert_eq!(
        json_of(&close_requested_event(3)),
        json!({"type": "window.close_requested", "window": 3})
    );
    assert_eq!(
        json_of(&focus_event(3, true)),
        json!({"type": "window.focus", "window": 3, "focused": true})
    );
    assert_eq!(
        json_of(&focus_event(3, false)),
        json!({"type": "window.focus", "window": 3, "focused": false})
    );
    assert_eq!(
        json_of(&scale_event(3, 1.5)),
        json!({"type": "window.scale", "window": 3, "scale": 1.5})
    );
    assert_eq!(
        json_of(&resized_event(3, 640.0, 480.5, 2.0)),
        json!({"type": "window.resized", "window": 3, "width": 640.0, "height": 480.5, "scale": 2.0})
    );
}

#[test]
fn only_a_resize_is_continuous_and_it_is_kept_per_window() {
    for event in [
        close_requested_event(1),
        focus_event(1, false),
        scale_event(1, 1.0),
    ] {
        assert!(
            matches!(event.class, EventClass::Discrete),
            "{}",
            event.json
        );
    }

    let EventClass::Continuous(key) = resized_event(4, 1.0, 1.0, 1.0).class else {
        panic!("a resize is continuous: only the latest size matters");
    };
    assert_eq!((key.window, key.kind), (4, ContinuousKind::Resize));
}

/// A backend that refuses to make windows, as a window system with no display does.
struct Refusing;

impl Backend for Refusing {
    fn clock(&self) -> &dyn Clock {
        static CLOCK: std::sync::LazyLock<ManualClock> =
            std::sync::LazyLock::new(ManualClock::default);
        &*CLOCK
    }

    fn frame_interval(&self) -> Duration {
        Duration::ZERO
    }

    fn create_window(&mut self, _id: u64, _width: f64, _height: f64) -> Result<(), BackendError> {
        Err(BackendError::Failed("no display".to_owned()))
    }

    fn resize_window(&mut self, _id: u64, _width: f64, _height: f64) -> Result<(), BackendError> {
        Err(BackendError::Failed("cannot resize".to_owned()))
    }

    fn destroy_window(&mut self, _id: u64) {}

    fn set_title(&mut self, _id: u64, _title: &str) {}

    fn draw(&mut self, _id: u64, _model: &Model, _view: &Interaction) {}

    fn advance_clock(&mut self, _millis: u64) -> bool {
        false
    }
}

/// A backend that makes every window and records the titles it is told to show.
#[derive(Default)]
struct Recording {
    titles: Vec<(u64, String)>,
}

impl Backend for Recording {
    fn clock(&self) -> &dyn Clock {
        static CLOCK: std::sync::LazyLock<ManualClock> =
            std::sync::LazyLock::new(ManualClock::default);
        &*CLOCK
    }

    fn frame_interval(&self) -> Duration {
        Duration::ZERO
    }

    fn create_window(&mut self, _id: u64, _width: f64, _height: f64) -> Result<(), BackendError> {
        Ok(())
    }

    fn resize_window(&mut self, _id: u64, _width: f64, _height: f64) -> Result<(), BackendError> {
        Ok(())
    }

    fn destroy_window(&mut self, _id: u64) {}

    fn set_title(&mut self, id: u64, title: &str) {
        self.titles.push((id, title.to_owned()));
    }

    fn draw(&mut self, _id: u64, _model: &Model, _view: &Interaction) {}

    fn advance_clock(&mut self, _millis: u64) -> bool {
        false
    }
}

#[test]
fn the_title_reaches_the_backend_when_a_window_is_made_and_when_it_changes_and_only_then() {
    let mut model = Model::new(Arc::new(Diagnostics::default()));
    let mut backend = Recording::default();

    apply(
        &mut model,
        &mut backend,
        &json!([{"op": "window.create", "id": 1, "title": "First"}]),
    );
    apply(
        &mut model,
        &mut backend,
        &json!([{"op": "window.create", "id": 2}]),
    );
    apply(
        &mut model,
        &mut backend,
        &json!([{"op": "window.set", "id": 1, "width": 500}]),
    );
    apply(
        &mut model,
        &mut backend,
        &json!([{"op": "window.set", "id": 1, "title": "Second"}]),
    );

    assert_eq!(
        backend.titles,
        [
            (1, "First".to_owned()),
            (2, String::new()),
            (1, "Second".to_owned())
        ],
        "a window without a title gets an empty one, and a resize says nothing about the title"
    );
}

#[test]
fn a_loop_that_cannot_be_built_says_why_and_a_second_one_says_what_to_do() {
    let again = start_error(&winit::error::EventLoopError::RecreationAttempt);
    assert!(again.contains("only once in a process"), "{again}");
    assert!(again.contains("Restart the process"), "{again}");

    let other = start_error(&winit::error::EventLoopError::ExitFailure(3));
    assert!(
        other.contains("the window system is not available"),
        "{other}"
    );
}

fn apply(model: &mut Model, backend: &mut dyn Backend, commands: &Value) -> Vec<Value> {
    let text = json!({"batch": 1, "commands": commands}).to_string();
    let mut events: Vec<Event> = Vec::new();
    model.apply_batch(parse_batch(&text).unwrap(), &mut events, backend);

    events.iter().map(json_of).collect()
}

#[test]
fn a_window_the_window_system_refuses_is_a_backend_error_and_changes_nothing() {
    let mut model = Model::new(Arc::new(Diagnostics::default()));

    let events = apply(
        &mut model,
        &mut Refusing,
        &json!([{"op": "window.create", "id": 1, "title": "x"}]),
    );

    assert_eq!(events[0]["type"], "error");
    assert_eq!(events[0]["code"], "backend");
    assert_eq!(events[0]["target"], 1);
    assert_eq!(events[0]["message"], "no display");
    assert!(!model.has_window(1), "nothing was made");
    assert_eq!(events[1]["failed"], 1);
}

#[test]
fn the_model_follows_a_size_the_window_system_chose() {
    let diagnostics = Arc::new(Diagnostics::default());
    let mut model = Model::new(diagnostics.clone());
    let mut headless =
        crate::backend::HeadlessBackend::new(&crate::config::Config::default(), Arc::default());
    apply(
        &mut model,
        &mut headless,
        &json!([{"op": "window.create", "id": 1, "width": 400, "height": 300}]),
    );
    assert_eq!(diagnostics_requested(&diagnostics), 1, "its first frame");

    assert!(
        model.window_resized(1, 512.0, 384.0),
        "a window the model knows"
    );
    assert!(!model.window_resized(9, 1.0, 1.0), "and one it does not");

    // The model now has the window at 512 by 384: setting that is no change and asks for no frame, while
    // setting the old size is a real change back.
    apply(
        &mut model,
        &mut headless,
        &json!([{"op": "window.set", "id": 1, "width": 512, "height": 384}]),
    );
    assert_eq!(
        diagnostics_requested(&diagnostics),
        1,
        "no new frame for the size it already has"
    );
    apply(
        &mut model,
        &mut headless,
        &json!([{"op": "window.set", "id": 1, "width": 400, "height": 300}]),
    );
    assert_eq!(
        diagnostics_requested(&diagnostics),
        2,
        "a real change of size asks for one"
    );
}

fn diagnostics_requested(diagnostics: &Diagnostics) -> u64 {
    let json: Value = serde_json::from_str(&diagnostics.to_json()).unwrap();

    json["windows"]["1"]["redraws_requested"].as_u64().unwrap()
}

// ---- raphp_init with no display

fn last_error(shim: *const RaphpShim) -> String {
    let length = unsafe { raphp_last_error(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; length];
    unsafe { raphp_last_error(shim, buffer.as_mut_ptr().cast::<c_char>(), length) };

    String::from_utf8(buffer).unwrap()
}

const CHILD_FLAG: &str = "RAPHP_TEST_NO_DISPLAY_CHILD";

/// Runs only in the child process of the test below, which has no display variables.
#[test]
fn child_init_with_the_window_backend_and_no_display_fails_with_a_reason() {
    if std::env::var_os(CHILD_FLAG).is_none() {
        return;
    }

    let config = r#"{"backend":"window"}"#;
    let shim = unsafe { raphp_init(config.as_ptr().cast::<c_char>(), config.len()) };

    assert!(
        shim.is_null(),
        "there is no display, so there must be no shim"
    );
    let reason = last_error(ptr::null());
    assert!(reason.contains("window system"), "{reason}");
}

#[test]
fn without_a_display_the_window_backend_says_so_instead_of_hanging_or_crashing() {
    let started = Instant::now();
    let output = Process::new(std::env::current_exe().unwrap())
        .args([
            "--exact",
            "window_tests::child_init_with_the_window_backend_and_no_display_fails_with_a_reason",
            "--nocapture",
        ])
        .env(CHILD_FLAG, "1")
        .env_remove("WAYLAND_DISPLAY")
        .env_remove("DISPLAY")
        .output()
        .unwrap();

    assert!(
        output.status.success(),
        "the child failed:\n{}\n{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(
        String::from_utf8_lossy(&output.stdout).contains("1 passed"),
        "the child test did not run:\n{}",
        String::from_utf8_lossy(&output.stdout)
    );
    assert!(
        started.elapsed() < Duration::from_secs(8),
        "init must fail promptly"
    );
}

// ---------------------------------------------------------------- opt-in: a real window

fn start() -> *mut RaphpShim {
    let config = r#"{"backend":"window"}"#;
    let shim = unsafe { raphp_init(config.as_ptr().cast::<c_char>(), config.len()) };
    assert!(!shim.is_null(), "init failed: {}", last_error(ptr::null()));

    shim
}

fn send(shim: *const RaphpShim, id: u64, commands: &Value) {
    let text = json!({"batch": id, "commands": commands}).to_string();
    let status = unsafe { raphp_send(shim, text.as_ptr().cast::<c_char>(), text.len()) };
    assert_eq!(status, RaphpStatus::Ok, "{}", last_error(shim));
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

/// Polls until an event satisfies the test or the time is up; returns every event seen.
fn wait_for(
    shim: *const RaphpShim,
    seconds: u64,
    test: impl Fn(&Value) -> bool,
) -> (bool, Vec<Value>) {
    let deadline = Instant::now() + Duration::from_secs(seconds);
    let mut seen = Vec::new();
    while Instant::now() < deadline {
        for event in events(shim) {
            let found = test(&event);
            seen.push(event);
            if found {
                return (true, seen);
            }
        }

        std::thread::sleep(Duration::from_millis(10));
    }

    (false, seen)
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

fn frames_reach(shim: *const RaphpShim, window: u64, at_least: u64, seconds: u64) -> bool {
    let deadline = Instant::now() + Duration::from_secs(seconds);
    while Instant::now() < deadline {
        if counters(shim, window).1 >= at_least {
            return true;
        }

        std::thread::sleep(Duration::from_millis(10));
    }

    false
}

fn open_window(shim: *const RaphpShim) {
    send(
        shim,
        1,
        &json!([
            {"op": "window.create", "id": 1, "title": "Raphp window test", "width": 400, "height": 300},
            {"op": "widget.create", "id": 1, "type": "label", "props": {"text": "a"}},
            {"op": "window.set_root", "id": 1, "widget": 1},
        ]),
    );
    let (done, seen) = wait_for(shim, 10, |event| event["type"] == "batch.done");
    assert!(done, "the batch was never reported done: {seen:?}");
    assert!(
        !seen.iter().any(|event| event["type"] == "error"),
        "the window was refused: {seen:?}"
    );
}

fn cpu_millis() -> u64 {
    let mut usage = unsafe { std::mem::zeroed::<libc::rusage>() };
    unsafe { libc::getrusage(libc::RUSAGE_SELF, &raw mut usage) };
    let millis = |time: libc::timeval| {
        u64::try_from(time.tv_sec).unwrap_or(0) * 1000
            + u64::try_from(time.tv_usec).unwrap_or(0) / 1000
    };

    millis(usage.ru_utime) + millis(usage.ru_stime)
}

#[test]
#[ignore = "opens a real window on your session: run `make window-test`"]
fn a_real_window_opens_draws_its_first_frame_and_closes() {
    let shim = start();

    open_window(shim);

    assert!(
        frames_reach(shim, 1, 1, 10),
        "no first frame was drawn: {:?}",
        counters(shim, 1)
    );
    send(shim, 2, &json!([{"op": "window.destroy", "id": 1}]));
    let (done, _) = wait_for(shim, 10, |event| {
        event["type"] == "batch.done" && event["batch"] == 2
    });
    assert!(done);
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

/// The hard criterion of F8: after its first frames, a window with static content and no input draws nothing.
/// The CPU figure is recorded, not judged.
#[test]
#[ignore = "opens a real window on your session: run `make window-test`"]
fn an_idle_window_draws_no_frames_over_five_seconds() {
    let shim = start();
    open_window(shim);
    assert!(frames_reach(shim, 1, 1, 10));
    std::thread::sleep(Duration::from_secs(2)); // let the first configure and resize settle
    let before = counters(shim, 1);
    let (cpu_before, wall) = (cpu_millis(), Instant::now());

    std::thread::sleep(Duration::from_secs(5));

    let after = counters(shim, 1);
    let (cpu_ms, wall_ms) = (cpu_millis() - cpu_before, wall.elapsed().as_millis());
    println!(
        "IDLE WINDOW: {wall_ms} ms wall, {cpu_ms} ms CPU (whole process); (requested, drawn) {before:?} -> {after:?}"
    );
    assert_eq!(before, after, "an idle window must not draw or ask to");
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
#[ignore = "opens a real window on your session: run `make window-test`"]
fn a_change_draws_a_frame_and_a_new_title_does_not() {
    let shim = start();
    open_window(shim);
    assert!(frames_reach(shim, 1, 1, 10));
    std::thread::sleep(Duration::from_secs(2));
    let (_, drawn) = counters(shim, 1);

    send(
        shim,
        2,
        &json!([{"op": "widget.set", "id": 1, "props": {"text": "b"}}]),
    );
    assert!(
        frames_reach(shim, 1, drawn + 1, 5),
        "a changed label drew no frame"
    );
    std::thread::sleep(Duration::from_millis(500));
    let settled = counters(shim, 1);

    send(
        shim,
        3,
        &json!([{"op": "window.set", "id": 1, "title": "A new title"}]),
    );
    let (done, _) = wait_for(shim, 5, |event| {
        event["type"] == "batch.done" && event["batch"] == 3
    });
    assert!(done);
    std::thread::sleep(Duration::from_secs(1));

    assert_eq!(counters(shim, 1), settled, "a title change needs no frame");
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
#[ignore = "opens a real window on your session: run `make window-test`"]
fn the_window_system_reports_a_resize_in_logical_pixels() {
    let shim = start();
    open_window(shim);
    std::thread::sleep(Duration::from_secs(1));
    let _ = events(shim);

    send(
        shim,
        2,
        &json!([{"op": "window.set", "id": 1, "width": 520, "height": 340}]),
    );
    let (found, seen) = wait_for(shim, 5, |event| {
        event["type"] == "window.resized"
            && event["window"] == 1
            && (event["width"].as_f64().unwrap_or(0.0) - 520.0).abs() < 1.0
    });

    println!("RESIZE EVENTS: {seen:?}");
    assert!(
        found,
        "the window system never reported the new size: {seen:?}"
    );
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}
