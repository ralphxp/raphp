//! The configuration, the clocks, the pictures and the headless backend, without a loop (plan P3.4).

use std::sync::Arc;
use std::time::Duration;

use crate::backend::{Backend, BackendError, HeadlessBackend, HeadlessShared};
use crate::clock::{Clock, ManualClock, SystemClock};
use crate::config::{BackendKind, Config};
use crate::surface::{Surface, WHITE};

// ---------------------------------------------------------------- configuration

#[test]
fn an_empty_configuration_means_the_defaults() {
    for text in ["", "   ", "\n"] {
        assert_eq!(Config::parse(text).unwrap(), Config::default(), "{text:?}");
    }

    let config = Config::default();
    assert_eq!(config.backend, BackendKind::Headless);
    assert_eq!(config.scale, 1.0);
    assert_eq!(config.surface_budget_bytes, 256 << 20);
    assert_eq!(config.frame_interval, Duration::from_nanos(16_666_667));
}

#[test]
fn every_setting_is_read() {
    let config = Config::parse(
        r#"{"backend":"headless","refresh_hz":100,"scale":2.5,"surface_budget_mib":32}"#,
    )
    .unwrap();

    assert_eq!(config.frame_interval, Duration::from_millis(10));
    assert_eq!(config.scale, 2.5);
    assert_eq!(config.surface_budget_bytes, 32 << 20);
    assert_eq!(Config::parse("{}").unwrap(), Config::default());
}

#[test]
fn a_bad_configuration_is_refused_with_a_reason() {
    for (text, reason) in [
        ("not json", "not valid JSON"),
        ("[]", "must be a JSON object"),
        ("5", "must be a JSON object"),
        (r#"{"colour":"red"}"#, "no setting \"colour\""),
        (
            r#"{"backend":"window","scale":2}"#,
            "applies only to the headless backend",
        ),
        (
            r#"{"backend":"window","refresh_hz":30}"#,
            "applies only to the headless backend",
        ),
        (
            r#"{"surface_budget_mib":8,"backend":"window"}"#,
            "applies only to the headless backend",
        ),
        (r#"{"backend":"gpu"}"#, "must be \"headless\""),
        (r#"{"backend":5}"#, "must be \"headless\""),
        (r#"{"refresh_hz":0}"#, "from 1 to 1000"),
        (r#"{"refresh_hz":1001}"#, "from 1 to 1000"),
        (r#"{"refresh_hz":"fast"}"#, "from 1 to 1000"),
        (r#"{"scale":0.2}"#, "from 0.25 to 8"),
        (r#"{"scale":8.1}"#, "from 0.25 to 8"),
        (r#"{"surface_budget_mib":0}"#, "from 1 to 4096"),
        (r#"{"surface_budget_mib":4097}"#, "from 1 to 4096"),
        (r#"{"surface_budget_mib":1.5}"#, "whole number"),
    ] {
        let error = Config::parse(text).unwrap_err();
        assert!(error.contains(reason), "{text}: {error}");
    }
}

#[test]
fn the_limits_of_each_setting_are_allowed() {
    for text in [
        r#"{"refresh_hz":1}"#,
        r#"{"refresh_hz":1000}"#,
        r#"{"scale":0.25}"#,
        r#"{"scale":8}"#,
        r#"{"surface_budget_mib":1}"#,
        r#"{"surface_budget_mib":4096}"#,
    ] {
        assert!(Config::parse(text).is_ok(), "{text}");
    }
}

// ---------------------------------------------------------------- clocks

#[test]
fn a_manual_clock_moves_only_when_told_to() {
    let clock = ManualClock::default();

    assert_eq!(clock.now(), Duration::ZERO);
    std::thread::sleep(Duration::from_millis(5));
    assert_eq!(clock.now(), Duration::ZERO, "time does not pass by itself");

    clock.advance(250);
    clock.advance(0);
    clock.advance(5);
    assert_eq!(clock.now(), Duration::from_millis(255));
    assert_eq!(
        clock.sleep_budget(Duration::from_secs(1)),
        None,
        "nothing to wait for but a message"
    );
}

#[test]
fn a_manual_clock_does_not_wrap_around() {
    let clock = ManualClock::default();

    clock.advance(u64::MAX);
    clock.advance(10);

    assert_eq!(
        clock.now(),
        Duration::from_millis(u64::MAX),
        "saturated, not wrapped back to the past"
    );
}

#[test]
fn the_system_clock_runs_and_may_be_waited_on() {
    let clock = SystemClock::new();
    let first = clock.now();
    std::thread::sleep(Duration::from_millis(5));

    assert!(clock.now() > first);
    assert_eq!(
        clock.sleep_budget(Duration::from_millis(7)),
        Some(Duration::from_millis(7))
    );
}

// ---------------------------------------------------------------- surfaces

#[test]
fn a_new_surface_is_white_and_clear_fills_it() {
    let mut surface = Surface::new(3, 2);

    assert_eq!(surface.pixels().len(), 3 * 2 * 4);
    assert!(surface.pixels().chunks(4).all(|pixel| pixel == WHITE));

    surface.clear([1, 2, 3, 4]);
    assert!(
        surface
            .pixels()
            .chunks(4)
            .all(|pixel| pixel == [1, 2, 3, 4])
    );
}

#[test]
fn the_size_of_a_surface_does_not_overflow() {
    assert_eq!(Surface::bytes_for(10, 10), 400);
    assert!(
        Surface::bytes_for(u32::MAX, u32::MAX) >= usize::MAX / 2,
        "a huge size is reported as huge, not wrapped"
    );
}

// ---------------------------------------------------------------- the headless backend

fn backend(config_text: &str) -> (HeadlessBackend, Arc<HeadlessShared>) {
    let shared = Arc::new(HeadlessShared::default());

    (
        HeadlessBackend::new(&Config::parse(config_text).unwrap(), shared.clone()),
        shared,
    )
}

fn read(shared: &HeadlessShared, id: u64) -> Option<(u32, u32, usize)> {
    shared.read(id, &mut [])
}

#[test]
fn a_window_gets_a_picture_of_its_size_in_physical_pixels() {
    let (mut backend, shared) = backend("{}");
    backend.create_window(1, 800.0, 600.0).unwrap();

    assert_eq!(read(&shared, 1), Some((800, 600, 800 * 600 * 4)));

    let (mut hidpi, hidpi_shared) = self::backend(r#"{"scale":2}"#);
    hidpi.create_window(1, 800.0, 600.0).unwrap();
    assert_eq!(
        read(&hidpi_shared, 1),
        Some((1600, 1200, 1600 * 1200 * 4)),
        "twice the pixels each way"
    );

    let (mut tiny, tiny_shared) = self::backend(r#"{"scale":0.25}"#);
    tiny.create_window(1, 1.0, 2.0).unwrap();
    assert_eq!(
        read(&tiny_shared, 1),
        Some((1, 1, 4)),
        "never smaller than one pixel"
    );

    let (mut fractional, fractional_shared) = self::backend(r#"{"scale":1.5}"#);
    fractional.create_window(1, 101.0, 101.0).unwrap();
    assert_eq!(
        read(&fractional_shared, 1),
        Some((152, 152, 152 * 152 * 4)),
        "rounded up"
    );
}

#[test]
fn resizing_makes_a_new_white_picture_and_destroying_removes_it() {
    let (mut backend, shared) = backend("{}");
    backend.create_window(1, 100.0, 50.0).unwrap();

    backend.resize_window(1, 20.0, 10.0).unwrap();
    assert_eq!(read(&shared, 1), Some((20, 10, 800)));

    backend.destroy_window(1);
    assert_eq!(read(&shared, 1), None);
    backend.destroy_window(1); // a second time is harmless
}

#[test]
fn a_picture_over_the_budget_is_refused_and_leaves_the_others_alone() {
    let (mut backend, shared) = backend(r#"{"surface_budget_mib":1}"#); // 262,144 pixels

    backend.create_window(1, 400.0, 300.0).unwrap(); // 120,000 pixels
    backend.create_window(2, 400.0, 300.0).unwrap(); // 240,000 together
    let error = backend.create_window(3, 200.0, 200.0).unwrap_err(); // 280,000 would not fit
    assert!(
        matches!(&error, BackendError::Limit(reason) if reason.contains("more than the budget of 1 MiB")),
        "{error}"
    );
    assert_eq!(read(&shared, 3), None, "no picture was made");

    // A resize that does not fit changes nothing; one that fits works.
    assert!(backend.resize_window(1, 1000.0, 1000.0).is_err());
    assert_eq!(
        read(&shared, 1),
        Some((400, 300, 400 * 300 * 4)),
        "the old picture is intact"
    );
    backend.resize_window(1, 405.0, 300.0).unwrap();

    backend.destroy_window(2);
    backend.create_window(3, 200.0, 200.0).unwrap(); // room again
}

#[test]
fn the_budget_is_exact_at_the_edge() {
    let (mut backend, _) = backend(r#"{"surface_budget_mib":1}"#);

    backend.create_window(1, 512.0, 512.0).unwrap(); // exactly 1 MiB
    assert!(
        backend.create_window(2, 1.0, 1.0).is_err(),
        "not one pixel more"
    );

    let (mut other, _) = self::backend(r#"{"surface_budget_mib":1}"#);
    assert!(
        other.create_window(1, 512.0, 513.0).is_err(),
        "one row over"
    );
}

#[test]
fn a_picture_can_be_read_in_pieces_and_whole() {
    let (mut backend, shared) = backend("{}");
    backend.create_window(1, 4.0, 2.0).unwrap();

    let mut small = [0u8; 10];
    assert_eq!(
        shared.read(1, &mut small),
        Some((4, 2, 32)),
        "the full length is reported"
    );
    assert_eq!(small, [0xFF; 10]);
    let mut whole = [0u8; 32];
    assert_eq!(shared.read(1, &mut whole), Some((4, 2, 32)));
    assert!(whole.iter().all(|byte| *byte == 0xFF));
    assert_eq!(shared.read(9, &mut whole), None, "no such window");
}

#[test]
fn advancing_the_headless_clock_is_what_moves_time_and_it_reports_that() {
    let (mut backend, _) = backend("{}");

    assert_eq!(backend.clock().now(), Duration::ZERO);
    assert!(backend.advance_clock(40));
    assert_eq!(backend.clock().now(), Duration::from_millis(40));
    assert_eq!(backend.frame_interval(), Duration::from_nanos(16_666_667));
}
