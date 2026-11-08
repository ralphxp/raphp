use std::ffi::c_char;
use std::ptr;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant};

use crate::shim::{RaphpShim, Shim};
use crate::status::{RaphpStatus, ShimError};
use crate::{raphp_abi_version, raphp_init, raphp_last_error, raphp_send, raphp_shutdown};

const QUICK: Duration = Duration::from_millis(100);

fn eventually(mut condition: impl FnMut() -> bool) -> bool {
    let deadline = Instant::now() + Duration::from_secs(5);
    while Instant::now() < deadline {
        if condition() {
            return true;
        }

        thread::sleep(Duration::from_millis(5));
    }

    false
}

/// A handle whose UI thread runs `body`, as `raphp_init` would make one.
fn handle_with<F>(shutdown_timeout: Duration, body: F) -> *mut RaphpShim
where
    F: FnOnce(Arc<crate::ui_thread::StopSignal>) + Send + 'static,
{
    let shim = Shim::start(shutdown_timeout, body).expect("the UI thread starts");

    Box::into_raw(Box::new(RaphpShim { shim }))
}

fn idle_handle() -> *mut RaphpShim {
    let events = Arc::new(crate::events::EventQueue::new().expect("an eventfd"));
    let shim = Shim::start_model(events, Duration::from_secs(2)).expect("starts");

    Box::into_raw(Box::new(RaphpShim { shim }))
}

fn send(shim: *const RaphpShim, batch: &str) -> RaphpStatus {
    unsafe { raphp_send(shim, batch.as_ptr().cast::<c_char>(), batch.len()) }
}

fn last_error(shim: *const RaphpShim) -> String {
    let needed = unsafe { raphp_last_error(shim, ptr::null_mut(), 0) };
    let mut buffer = vec![0u8; needed];
    let written =
        unsafe { raphp_last_error(shim, buffer.as_mut_ptr().cast::<c_char>(), buffer.len()) };
    assert_eq!(written, needed);

    String::from_utf8(buffer).expect("the error text is UTF-8")
}

fn is_poisoned(shim: *const RaphpShim) -> bool {
    unsafe { &*shim }.shim.health.is_poisoned()
}

// ---------------------------------------------------------------- the version and the plain life cycle

#[test]
fn the_abi_version_is_major_then_minor() {
    let version = raphp_abi_version();

    assert_eq!(version >> 16, 0, "major");
    assert_eq!(version & 0xffff, 3, "minor");
}

#[test]
fn init_and_shutdown_round_trip_through_the_c_functions() {
    let config = "{}";
    let shim = unsafe { raphp_init(config.as_ptr().cast::<c_char>(), config.len()) };

    assert!(!shim.is_null());
    assert_eq!(send(shim, "[]"), RaphpStatus::Ok);
    assert!(!is_poisoned(shim));
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn an_empty_configuration_may_be_a_null_pointer() {
    let shim = unsafe { raphp_init(ptr::null(), 0) };

    assert!(!shim.is_null());
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_configuration_that_is_not_utf8_fails_with_a_reason_for_the_calling_thread_only() {
    let bad: [u8; 2] = [0xff, 0xfe];

    let shim = unsafe { raphp_init(bad.as_ptr().cast::<c_char>(), bad.len()) };

    assert!(shim.is_null());
    assert!(last_error(ptr::null()).contains("not valid UTF-8"));
    let other_thread = thread::spawn(|| last_error(ptr::null()))
        .join()
        .expect("joins");
    assert_eq!(other_thread, "", "another thread has its own last error");
}

#[test]
fn a_null_pointer_with_a_length_is_refused() {
    let shim = unsafe { raphp_init(ptr::null(), 5) };

    assert!(shim.is_null());
    assert!(last_error(ptr::null()).contains("null pointer"));
}

// ---------------------------------------------------------------- ordinary failures leave the shim usable

#[test]
fn bad_arguments_are_errors_and_the_shim_stays_usable() {
    let shim = idle_handle();

    assert_eq!(
        unsafe { raphp_send(shim, ptr::null(), 3) },
        RaphpStatus::InvalidArgument
    );
    assert!(last_error(shim).contains("null pointer"));
    let bad: [u8; 1] = [0x80];
    assert_eq!(
        unsafe { raphp_send(shim, bad.as_ptr().cast::<c_char>(), 1) },
        RaphpStatus::InvalidArgument
    );
    assert_eq!(send(shim, "not json at all"), RaphpStatus::InvalidArgument);
    assert!(last_error(shim).contains("not valid JSON"));
    assert_eq!(
        send(shim, "{\"commands\":[]}"),
        RaphpStatus::InvalidArgument
    );
    assert!(last_error(shim).contains("needs a \"batch\" id"));

    assert!(!is_poisoned(shim));
    assert_eq!(send(shim, "[]"), RaphpStatus::Ok, "still usable");
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_null_handle_is_refused_by_every_function_that_takes_one() {
    assert_eq!(send(ptr::null(), "[]"), RaphpStatus::InvalidArgument);
    assert_eq!(
        unsafe { raphp_shutdown(ptr::null_mut()) },
        RaphpStatus::InvalidArgument
    );
    assert!(last_error(ptr::null()).contains("null"));
}

// ---------------------------------------------------------------- a panic in a call (the plan's done-when)

#[test]
fn a_panic_inside_a_command_becomes_an_error_and_poisons_the_shim_cleanly() {
    let shim = idle_handle();

    let status = send(shim, "#panic");

    assert_eq!(
        status,
        RaphpStatus::Panicked,
        "the panic did not cross the C boundary"
    );
    assert!(is_poisoned(shim));
    assert!(last_error(shim).contains("a command panicked on purpose"));

    // Poisoned means: every later call is refused with the same, stable answer ...
    assert_eq!(send(shim, "[]"), RaphpStatus::Poisoned);
    assert_eq!(send(shim, "[]"), RaphpStatus::Poisoned);
    // ... the cause is still readable ...
    assert!(last_error(shim).contains("a command panicked on purpose"));
    // ... and shutting down works and does not hang.
    let started = Instant::now();
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
    assert!(started.elapsed() < Duration::from_secs(2));
}

#[test]
fn only_the_first_cause_of_a_poisoning_is_kept() {
    let shim = idle_handle();
    let handle = unsafe { &*shim };

    handle.shim.health.poison("first cause");
    handle.shim.health.poison("a consequence of the first");

    assert_eq!(last_error(shim), "first cause");
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn the_guard_turns_any_panic_into_a_poisoned_shim() {
    let shim = idle_handle();

    let status = crate::abi_test_guard(shim, |_shim| -> Result<(), ShimError> {
        panic!("a string panic")
    });
    assert_eq!(status, RaphpStatus::Panicked);
    assert!(last_error(shim).contains("a string panic"));
    unsafe { raphp_shutdown(shim) };

    let shim = idle_handle();
    let status = crate::abi_test_guard(shim, |_shim| -> Result<(), ShimError> {
        std::panic::panic_any(42_u32)
    });
    assert_eq!(status, RaphpStatus::Panicked);
    assert!(last_error(shim).contains("not text"));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn calls_from_several_threads_while_one_panics_never_deadlock_and_end_poisoned() {
    let shim = idle_handle() as usize;
    let workers: Vec<_> = (0..8)
        .map(|index| {
            thread::spawn(move || {
                let shim = shim as *const RaphpShim;
                let mut statuses = Vec::new();
                for round in 0..200 {
                    let batch = if index == 3 && round == 100 {
                        "#panic"
                    } else {
                        "[]"
                    };
                    statuses.push(send(shim, batch));
                }

                statuses
            })
        })
        .collect();

    let all: Vec<RaphpStatus> = workers
        .into_iter()
        .flat_map(|worker| worker.join().expect("no thread died"))
        .collect();

    assert_eq!(
        all.iter()
            .filter(|status| **status == RaphpStatus::Panicked)
            .count(),
        1
    );
    assert!(all.iter().all(|status| matches!(
        status,
        RaphpStatus::Ok | RaphpStatus::Panicked | RaphpStatus::Poisoned
    )));
    assert_eq!(send(shim as *const RaphpShim, "[]"), RaphpStatus::Poisoned);
    unsafe { raphp_shutdown(shim as *mut RaphpShim) };
}

// ---------------------------------------------------------------- the UI thread (R14)

#[test]
fn a_panic_on_the_ui_thread_poisons_the_shim_and_the_process_lives_on() {
    let shim = handle_with(Duration::from_secs(2), |_stop| {
        panic!("the window system failed")
    });

    assert!(
        eventually(|| is_poisoned(shim)),
        "the UI thread's panic was not noticed"
    );
    assert_eq!(send(shim, "[]"), RaphpStatus::Poisoned);
    assert!(last_error(shim).contains("the UI thread panicked: the window system failed"));
    assert_eq!(
        unsafe { raphp_shutdown(shim) },
        RaphpStatus::Ok,
        "shutting down a dead UI thread is immediate"
    );
}

#[test]
fn a_ui_thread_that_ends_by_itself_is_a_failure() {
    let shim = handle_with(Duration::from_secs(2), |_stop| {});

    assert!(eventually(|| is_poisoned(shim)));
    assert!(last_error(shim).contains("ended without being asked to"));
    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_ui_thread_that_ends_when_asked_is_not_a_failure() {
    let shim = idle_handle();

    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
}

#[test]
fn a_ui_thread_stuck_in_a_native_call_does_not_hang_the_shutdown() {
    let release = Arc::new(AtomicBool::new(false));
    let stuck = release.clone();
    let shim = handle_with(QUICK, move |_stop| {
        while !stuck.load(Ordering::SeqCst) {
            thread::sleep(Duration::from_millis(10)); // ignores the stop request, as a blocked native call would
        }
    });

    let started = Instant::now();
    let status = unsafe { raphp_shutdown(shim) };

    assert_eq!(status, RaphpStatus::TimedOut);
    assert!(
        started.elapsed() >= QUICK && started.elapsed() < Duration::from_secs(2),
        "the wait is bounded"
    );
    release.store(true, Ordering::SeqCst); // let the detached thread end before the test does
}

#[test]
fn the_shutdown_order_a_dead_ui_thread_then_shutdown_twice_is_a_null_handle_error() {
    let shim = handle_with(Duration::from_secs(2), |_stop| panic!("boom"));
    assert!(eventually(|| is_poisoned(shim)));

    assert_eq!(unsafe { raphp_shutdown(shim) }, RaphpStatus::Ok);
    assert_eq!(
        unsafe { raphp_shutdown(ptr::null_mut()) },
        RaphpStatus::InvalidArgument,
        "the handle must not be used again"
    );
}

// ---------------------------------------------------------------- the error text

#[test]
fn the_error_text_is_cut_on_a_character_boundary_and_the_full_length_is_returned() {
    let shim = idle_handle();
    let handle = unsafe { &*shim };
    handle.shim.health.set_error("héllo wörld ✓"); // 'é' and 'ö' take two bytes, '✓' three

    let full = unsafe { raphp_last_error(shim, ptr::null_mut(), 0) };
    assert_eq!(full, "héllo wörld ✓".len());

    for cap in 0..=full + 2 {
        let mut buffer = vec![0xAAu8; cap];
        let reported = unsafe { raphp_last_error(shim, buffer.as_mut_ptr().cast::<c_char>(), cap) };
        assert_eq!(reported, full, "cap {cap}");

        let written = buffer.iter().take_while(|byte| **byte != 0xAA).count();
        assert!(written <= cap);
        let text = std::str::from_utf8(&buffer[..written])
            .unwrap_or_else(|_| panic!("cap {cap}: not UTF-8"));
        assert!("héllo wörld ✓".starts_with(text), "cap {cap}");
        if cap >= full {
            assert_eq!(text, "héllo wörld ✓");
        }
    }

    unsafe { raphp_shutdown(shim) };
}

// ---------------------------------------------------------------- rules that keep the contract true

#[test]
fn the_build_never_aborts_on_a_panic() {
    // A compile-time check: a build made with panic = abort does not even compile its tests.
    const {
        assert!(
            cfg!(panic = "unwind"),
            "this build was made with panic = abort"
        );
    }

    // Comments are skipped: the manifest explains the rule in words that include the forbidden setting.
    let settings: String = include_str!("../../Cargo.toml")
        .lines()
        .map(|line| line.split('#').next().unwrap_or(""))
        .collect::<Vec<_>>()
        .join("\n");
    assert!(
        !settings.contains("panic = \"abort\""),
        "the workspace manifest sets panic = abort"
    );
    assert_eq!(
        settings.matches("panic = \"unwind\"").count(),
        2,
        "release and dev must both say panic = unwind in so many words"
    );
}

#[test]
fn every_exported_function_runs_under_catch_unwind() {
    let source = include_str!("abi.rs");
    let mut exported = 0;
    let mut rest = source;
    while let Some(start) = rest.find("#[unsafe(no_mangle)]") {
        rest = &rest[start + 1..];
        let end = rest.find("\n}\n").expect("a function body ends");
        let body = &rest[..end];
        assert!(
            body.contains("catch_unwind")
                || body.contains("guarded(")
                || body.contains("guarded_allow_poisoned("),
            "an exported function is not guarded:\n{body}"
        );
        exported += 1;
    }

    assert_eq!(
        exported, 10,
        "the list of exported functions changed: update the header test and the contract"
    );
}

// ---------------------------------------------------------------- edges found by mutation testing

#[test]
fn a_length_beyond_what_a_slice_can_have_is_refused_before_any_byte_is_read() {
    let shim = idle_handle();
    let one_byte = b"[";

    let status = unsafe { raphp_send(shim, one_byte.as_ptr().cast::<c_char>(), usize::MAX) };

    assert_eq!(status, RaphpStatus::InvalidArgument);
    assert!(last_error(shim).contains("too large"));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_null_output_buffer_with_a_capacity_only_reports_the_length() {
    let shim = idle_handle();
    send(shim, "not json"); // refused at once, with a reason

    let length = unsafe { raphp_last_error(shim, ptr::null_mut(), 64) };

    assert!(length > 0);
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn white_space_around_an_empty_batch_is_accepted() {
    let shim = idle_handle();

    assert_eq!(send(shim, "  [] \n"), RaphpStatus::Ok);
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_panic_with_a_formatted_message_keeps_its_text() {
    let shim = idle_handle();

    let status = crate::abi_test_guard(shim, |_shim| -> Result<(), ShimError> {
        let reason = format!("formatted {}", 7);
        panic!("{reason}")
    });

    assert_eq!(status, RaphpStatus::Panicked);
    assert!(last_error(shim).contains("formatted 7"));
    unsafe { raphp_shutdown(shim) };
}
