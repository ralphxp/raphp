//! The event queue: coalescing, overflow, back-pressure, the oversized-event contract (R16), the wake-up rule,
//! and a stress test with concurrent producers and a slow consumer (plan P3.2).

use std::collections::HashMap;
use std::ffi::c_char;
use std::ptr;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant};

use crate::events::{
    ContinuousKind, Event, EventQueue, HARD_LIMIT_BYTES, MAX_EVENT_BYTES, Polled, PushError,
    SOFT_LIMIT_BYTES,
};
use crate::shim::{RaphpShim, Shim, idle_ui_body};
use crate::status::RaphpStatus;
use crate::{
    raphp_event_fd, raphp_max_event_bytes, raphp_next_event_size, raphp_poll, raphp_send,
    raphp_shutdown,
};

// ---------------------------------------------------------------- helpers

fn readable(fd: i32, timeout_ms: i32) -> bool {
    let mut poll = libc::pollfd {
        fd,
        events: libc::POLLIN,
        revents: 0,
    };

    // SAFETY: one valid pollfd, count 1.
    unsafe { libc::poll(&raw mut poll, 1, timeout_ms) > 0 }
}

fn clear(fd: i32) {
    let mut counter = [0u8; 8];

    // SAFETY: an 8-byte buffer, as an eventfd read needs.
    unsafe { libc::read(fd, counter.as_mut_ptr().cast::<libc::c_void>(), 8) };
}

/// Everything in the queue, read with a buffer of `cap` bytes the way PHP must: grow on "too small".
fn drain(queue: &EventQueue, cap: usize) -> Vec<String> {
    let mut lines = Vec::new();
    let mut buffer = vec![0u8; cap];
    loop {
        match queue.poll(&mut buffer) {
            Polled::Empty => return lines,
            Polled::Written(bytes) => lines.extend(
                std::str::from_utf8(&buffer[..bytes])
                    .expect("UTF-8")
                    .lines()
                    .map(str::to_owned),
            ),
            Polled::TooSmall(needed) => buffer.resize(needed, 0),
        }
    }
}

fn queue() -> EventQueue {
    EventQueue::new().expect("an eventfd")
}

fn small_queue(soft: usize) -> EventQueue {
    EventQueue::with_limits(soft, HARD_LIMIT_BYTES).expect("an eventfd")
}

fn pointer(window: u64, value: u32) -> Event {
    Event::continuous(
        window,
        ContinuousKind::PointerMove,
        format!("{{\"type\":\"pointer\",\"w\":{window},\"v\":{value}}}"),
    )
}

fn click(name: &str) -> Event {
    Event::discrete(format!("{{\"type\":\"click\",\"id\":\"{name}\"}}"))
}

fn padded(name: &str, bytes: usize) -> Event {
    Event::discrete(format!(
        "{{\"type\":\"pad\",\"id\":\"{name}\",\"pad\":\"{}\"}}",
        "x".repeat(bytes)
    ))
}

// ---------------------------------------------------------------- coalescing

#[test]
fn the_latest_of_a_run_of_continuous_events_replaces_the_earlier_ones() {
    let queue = queue();

    for value in 1..=100 {
        queue.push(pointer(1, value)).unwrap();
    }

    assert_eq!(queue.len(), 1, "one slot, not a hundred");
    assert_eq!(
        drain(&queue, 4096),
        vec![r#"{"type":"pointer","w":1,"v":100}"#]
    );
}

#[test]
fn continuous_events_of_another_window_or_kind_do_not_replace_each_other() {
    let queue = queue();

    queue.push(pointer(1, 1)).unwrap();
    queue.push(pointer(2, 1)).unwrap();
    queue
        .push(Event::continuous(
            1,
            ContinuousKind::Resize,
            r#"{"type":"resize"}"#,
        ))
        .unwrap();
    queue
        .push(Event::continuous(
            1,
            ContinuousKind::Scroll,
            r#"{"type":"scroll"}"#,
        ))
        .unwrap();

    assert_eq!(queue.len(), 4);
}

#[test]
fn a_discrete_event_is_never_overtaken_nor_does_it_overtake() {
    let queue = queue();

    queue.push(pointer(1, 1)).unwrap();
    queue.push(click("a")).unwrap();
    queue.push(pointer(1, 2)).unwrap();
    queue.push(pointer(1, 3)).unwrap();
    queue.push(click("b")).unwrap();
    queue.push(pointer(1, 4)).unwrap();

    assert_eq!(
        drain(&queue, 4096),
        vec![
            r#"{"type":"pointer","w":1,"v":1}"#,
            r#"{"type":"click","id":"a"}"#,
            r#"{"type":"pointer","w":1,"v":3}"#, // 2 was replaced by 3: nothing discrete between them
            r#"{"type":"click","id":"b"}"#,
            r#"{"type":"pointer","w":1,"v":4}"#,
        ]
    );
}

#[test]
fn discrete_events_are_never_merged() {
    let queue = queue();

    for _ in 0..50 {
        queue.push(click("same")).unwrap();
    }

    assert_eq!(drain(&queue, 4096).len(), 50);
}

#[test]
fn after_a_poll_a_continuous_event_starts_a_new_slot() {
    let queue = queue();
    queue.push(pointer(1, 1)).unwrap();
    assert_eq!(drain(&queue, 4096).len(), 1);

    queue.push(pointer(1, 2)).unwrap();

    assert_eq!(
        drain(&queue, 4096),
        vec![r#"{"type":"pointer","w":1,"v":2}"#]
    );
}

#[test]
fn bytes_are_counted_correctly_through_merges_and_polls() {
    let queue = queue();
    queue.push(pointer(1, 1)).unwrap();
    queue.push(pointer(1, 22)).unwrap(); // longer than the first: the replacement changes the size
    queue.push(click("a")).unwrap();
    let expected: usize = [pointer(1, 22), click("a")]
        .iter()
        .map(|event| event.json.len() + 1)
        .sum();

    assert_eq!(queue.queued_bytes(), expected);
    drain(&queue, 4096);
    assert_eq!(queue.queued_bytes(), 0);
}

// ---------------------------------------------------------------- overflow (O7): coalesce harder

#[test]
fn past_the_soft_limit_an_older_continuous_event_goes_even_across_a_discrete_one() {
    let queue = small_queue(150);
    queue.push(pointer(1, 1)).unwrap(); // A
    queue.push(click("a")).unwrap();
    queue.push(pointer(1, 2)).unwrap(); // B: a discrete event in between, and under the limit, so it is kept
    assert_eq!(queue.len(), 3);

    queue.push(padded("big", 120)).unwrap(); // pushes the queue past the soft limit; sweeps the superseded A
    queue.push(pointer(1, 3)).unwrap(); // C: past the limit, so B is removed and C goes last

    let lines = drain(&queue, 4096);
    assert_eq!(lines.len(), 3, "{lines:?}");
    assert_eq!(lines[0], r#"{"type":"click","id":"a"}"#);
    assert!(lines[1].contains("\"big\""));
    assert_eq!(lines[2], r#"{"type":"pointer","w":1,"v":3}"#);
}

#[test]
fn nothing_discrete_is_lost_under_pressure() {
    let queue = small_queue(100);
    let mut expected = Vec::new();

    for index in 0..200 {
        queue.push(pointer(1, index)).unwrap();
        let name = format!("c{index}");
        queue.push(click(&name)).unwrap();
        expected.push(format!("{{\"type\":\"click\",\"id\":\"{name}\"}}"));
    }

    let clicks: Vec<String> = drain(&queue, 65_536)
        .into_iter()
        .filter(|line| line.contains("click"))
        .collect();
    assert_eq!(clicks, expected);
}

// ---------------------------------------------------------------- back-pressure and closing

#[test]
fn a_producer_waits_when_the_queue_is_full_of_events_that_cannot_be_merged() {
    let queue = Arc::new(small_queue(1));
    let chunk = 100_000;
    let fits = HARD_LIMIT_BYTES / (chunk + 40);
    for index in 0..fits {
        queue.push(padded(&format!("{index}"), chunk)).unwrap();
    }

    let done = Arc::new(AtomicBool::new(false));
    let producer = {
        let (queue, done) = (queue.clone(), done.clone());
        thread::spawn(move || {
            let result = queue.push(padded("waits", chunk));
            done.store(true, Ordering::SeqCst);

            result
        })
    };

    thread::sleep(Duration::from_millis(200));
    assert!(
        !done.load(Ordering::SeqCst),
        "the producer should be waiting for PHP"
    );

    let drained = drain(&queue, 65_536);
    assert!(!drained.is_empty());
    let result = producer.join().expect("the producer ends");
    assert_eq!(result, Ok(()), "it went on once PHP had drained");
    // The producer may queue its event while the first drain is still running, so look at everything.
    let everything: Vec<String> = drained.into_iter().chain(drain(&queue, 65_536)).collect();
    assert!(
        everything.last().unwrap().contains("\"waits\""),
        "and its event is queued, after the others"
    );
    assert!(queue.waits() > 0);
}

#[test]
fn closing_the_queue_wakes_a_waiting_producer_with_an_error() {
    let queue = Arc::new(small_queue(1));
    let chunk = 100_000;
    for index in 0..(HARD_LIMIT_BYTES / (chunk + 40)) {
        queue.push(padded(&format!("{index}"), chunk)).unwrap();
    }

    let producer = {
        let queue = queue.clone();
        thread::spawn(move || queue.push(padded("blocked", chunk)))
    };
    thread::sleep(Duration::from_millis(100));

    let started = Instant::now();
    queue.close();

    assert_eq!(producer.join().expect("ends"), Err(PushError::Closed));
    assert!(started.elapsed() < Duration::from_secs(2));
    assert_eq!(
        queue.push(click("late")),
        Err(PushError::Closed),
        "and nothing is accepted afterwards"
    );
    assert!(
        !drain(&queue, 65_536).is_empty(),
        "what was queued stays readable"
    );
}

#[test]
fn shutdown_does_not_wait_for_a_producer_that_is_waiting_for_php() {
    let events = Arc::new(small_queue(1));
    let chunk = 100_000;
    for index in 0..(HARD_LIMIT_BYTES / (chunk + 40)) {
        events.push(padded(&format!("{index}"), chunk)).unwrap();
    }
    let shim =
        Shim::start_with_events(events.clone(), Duration::from_secs(2), idle_ui_body).unwrap();
    let handle = Box::into_raw(Box::new(RaphpShim { shim }));
    let producer = {
        let events = events.clone();
        thread::spawn(move || events.push(padded("blocked", chunk)))
    };
    thread::sleep(Duration::from_millis(100));

    let started = Instant::now();
    let status = unsafe { raphp_shutdown(handle) };

    assert_eq!(status, RaphpStatus::Ok);
    assert_eq!(producer.join().expect("ends"), Err(PushError::Closed));
    assert!(started.elapsed() < Duration::from_secs(2));
}

// ---------------------------------------------------------------- R16: an event larger than the buffer

#[test]
fn an_event_larger_than_the_buffer_stays_queued_and_does_not_block_those_behind_it() {
    let queue = queue();
    let fd = queue.wake_fd();
    queue.push(padded("large", 200_000)).unwrap();
    queue.push(click("after")).unwrap();
    let large_size = queue.next_event_size();
    clear(fd);
    assert!(
        !readable(fd, 0),
        "cleared, as PHP clears it before draining"
    );

    let mut small = vec![0u8; 1024];
    let outcome = queue.poll(&mut small);

    assert_eq!(
        outcome,
        Polled::TooSmall(large_size),
        "the answer names the size that is needed"
    );
    assert_eq!(queue.len(), 2, "nothing was lost or skipped");
    assert_eq!(
        queue.next_event_size(),
        large_size,
        "the large event is still at the head"
    );
    assert!(
        readable(fd, 0),
        "the wake-up was raised again, so a drain that stops here is called back"
    );

    let lines = drain(&queue, large_size + 1_000);
    assert_eq!(lines.len(), 2);
    assert!(
        lines[0].contains("\"large\""),
        "in order: the large one first"
    );
    assert_eq!(
        lines[1], r#"{"type":"click","id":"after"}"#,
        "and the later event arrived"
    );
}

#[test]
fn a_buffer_that_holds_the_large_event_but_not_the_next_returns_what_fits_and_calls_back() {
    let queue = queue();
    let fd = queue.wake_fd();
    queue.push(padded("large", 5_000)).unwrap();
    queue.push(click("after")).unwrap();
    let large_size = queue.next_event_size();
    clear(fd);

    let mut buffer = vec![0u8; large_size];
    assert_eq!(queue.poll(&mut buffer), Polled::Written(large_size));

    assert_eq!(queue.len(), 1);
    assert!(
        readable(fd, 0),
        "an event remains, so the wake-up is raised again"
    );
}

#[test]
fn an_event_over_the_limit_is_refused_and_reported_by_a_small_error_event() {
    let queue = queue();
    let too_big = padded("huge", MAX_EVENT_BYTES);

    assert!(matches!(
        queue.push(too_big.clone()),
        Err(PushError::TooLarge(_))
    ));
    assert_eq!(queue.len(), 0);

    assert_eq!(queue.push_or_report(too_big, "huge"), Ok(false));
    queue.push(click("after")).unwrap();
    let lines = drain(&queue, 4096);

    assert_eq!(lines.len(), 2);
    assert!(lines[0].starts_with(r#"{"type":"event.too_large","event":"huge","bytes":"#));
    assert!(lines[0].contains(&format!("\"limit\":{MAX_EVENT_BYTES}")));
    assert_eq!(
        lines[1], r#"{"type":"click","id":"after"}"#,
        "later events still flow"
    );
}

#[test]
fn an_event_exactly_at_the_limit_is_accepted_and_a_buffer_of_that_size_never_falls_short() {
    let queue = queue();
    let overhead = padded("x", 0).json.len() + 1;
    let event = padded("x", MAX_EVENT_BYTES - overhead);
    assert_eq!(event.json.len() + 1, MAX_EVENT_BYTES);

    queue.push(event).unwrap();

    let mut buffer = vec![0u8; MAX_EVENT_BYTES];
    assert_eq!(queue.poll(&mut buffer), Polled::Written(MAX_EVENT_BYTES));
}

#[test]
fn an_empty_event_or_one_with_a_newline_inside_is_invalid() {
    let queue = queue();

    assert_eq!(queue.push(Event::discrete("")), Err(PushError::Invalid));
    assert_eq!(
        queue.push(Event::discrete("{\"a\":\n1}")),
        Err(PushError::Invalid)
    );
    assert_eq!(queue.len(), 0);
}

// ---------------------------------------------------------------- the wake-up rule

#[test]
fn the_wake_up_is_raised_by_an_event_and_is_gone_after_clearing_and_draining() {
    let queue = queue();
    let fd = queue.wake_fd();
    assert!(!readable(fd, 0), "nothing queued, nothing to wake for");

    queue.push(click("a")).unwrap();
    assert!(readable(fd, 0));

    clear(fd);
    assert_eq!(drain(&queue, 4096).len(), 1);
    assert!(!readable(fd, 0), "cleared and drained: quiet");

    queue.push(click("b")).unwrap();
    assert!(readable(fd, 0), "a new event wakes it again");
}

#[test]
fn an_event_that_arrives_between_clearing_and_draining_is_not_lost() {
    let queue = queue();
    let fd = queue.wake_fd();
    queue.push(click("a")).unwrap();

    clear(fd); // PHP clears first ...
    queue.push(click("b")).unwrap(); // ... an event arrives before it drains ...
    let lines = drain(&queue, 4096); // ... and the drain, which runs until empty, takes both

    assert_eq!(lines.len(), 2);
    queue.push(click("c")).unwrap();
    assert!(
        readable(fd, 0),
        "and an event after the drain wakes it again"
    );
}

#[test]
fn the_event_descriptor_is_close_on_exec() {
    let queue = queue();

    // SAFETY: querying a valid descriptor's flags.
    let flags = unsafe { libc::fcntl(queue.wake_fd(), libc::F_GETFD) };

    assert!(flags >= 0);
    assert_ne!(flags & libc::FD_CLOEXEC, 0, "a worker must not inherit it");
}

// ---------------------------------------------------------------- the stress test

#[test]
fn concurrent_producers_and_a_slow_consumer_lose_no_discrete_event_and_keep_the_latest_continuous_one()
 {
    const PRODUCERS: u64 = 8;
    const PER_PRODUCER: u64 = 4_000;
    const PAD: usize = 1_800;

    let queue = Arc::new(queue());
    let fd = queue.wake_fd();
    let producers: Vec<_> = (0..PRODUCERS)
        .map(|producer| {
            let queue = queue.clone();
            thread::spawn(move || {
                let padding = "x".repeat(PAD);
                for index in 0..PER_PRODUCER {
                    queue
                        .push(Event::discrete(format!(
                            "{{\"type\":\"key\",\"p\":{producer},\"n\":{index},\"pad\":\"{padding}\"}}"
                        )))
                        .expect("discrete events are never refused");
                    queue
                        .push(Event::continuous(
                            producer,
                            ContinuousKind::PointerMove,
                            format!("{{\"type\":\"pointer\",\"p\":{producer},\"v\":{index}}}"),
                        ))
                        .expect("continuous events are never refused");
                }
            })
        })
        .collect();

    // The consumer is slow on purpose: it takes 64 KiB at a time and rests between polls, so the queue fills.
    let deadline = Instant::now() + Duration::from_secs(120);
    let mut discrete: HashMap<u64, Vec<u64>> = HashMap::new();
    let mut continuous: HashMap<u64, Vec<u64>> = HashMap::new();
    let mut buffer = vec![0u8; 64 * 1024];
    let mut producers_done = false;
    loop {
        assert!(
            Instant::now() < deadline,
            "the consumer waited too long: a wake-up was lost, or a producer is stuck"
        );
        if !readable(fd, 100) && producers_done && queue.len() == 0 {
            break;
        }

        clear(fd);
        loop {
            match queue.poll(&mut buffer) {
                Polled::Empty => break,
                Polled::TooSmall(needed) => buffer.resize(needed, 0),
                Polled::Written(bytes) => {
                    for line in std::str::from_utf8(&buffer[..bytes]).unwrap().lines() {
                        let number = |field: &str| -> u64 {
                            let start = line.find(field).unwrap() + field.len();
                            line[start..]
                                .split([',', '}'])
                                .next()
                                .unwrap()
                                .parse()
                                .unwrap()
                        };
                        if line.contains("\"type\":\"key\"") {
                            discrete
                                .entry(number("\"p\":"))
                                .or_default()
                                .push(number("\"n\":"));
                        } else {
                            continuous
                                .entry(number("\"p\":"))
                                .or_default()
                                .push(number("\"v\":"));
                        }
                    }

                    thread::sleep(Duration::from_millis(1));
                }
            }
        }

        if !producers_done && producers.iter().all(thread::JoinHandle::is_finished) {
            producers_done = true;
        }
    }

    for producer in producers {
        producer.join().expect("a producer ended normally");
    }

    for producer in 0..PRODUCERS {
        let received = &discrete[&producer];
        let expected: Vec<u64> = (0..PER_PRODUCER).collect();
        assert_eq!(
            received, &expected,
            "producer {producer}: every discrete event, once, in order"
        );

        let values = &continuous[&producer];
        assert_eq!(
            *values.last().unwrap(),
            PER_PRODUCER - 1,
            "producer {producer}: the latest value was delivered"
        );
        assert!(
            values.windows(2).all(|pair| pair[0] < pair[1]),
            "producer {producer}: continuous events only ever move forward"
        );
    }

    assert!(
        queue.waits() > 0,
        "back-pressure was never exercised: make the test harder"
    );
    assert!(
        queue.max_bytes_seen() <= HARD_LIMIT_BYTES,
        "the queue grew past its hard limit"
    );
    assert!(
        queue.max_bytes_seen() > SOFT_LIMIT_BYTES,
        "the queue never got past the soft limit: make the test harder"
    );
}

// ---------------------------------------------------------------- through the C functions

fn idle_handle() -> *mut RaphpShim {
    let shim = Shim::start(Duration::from_secs(2), idle_ui_body).expect("starts");

    Box::into_raw(Box::new(RaphpShim { shim }))
}

fn send(shim: *const RaphpShim, batch: &str) -> RaphpStatus {
    unsafe { raphp_send(shim, batch.as_ptr().cast::<c_char>(), batch.len()) }
}

fn poll(shim: *const RaphpShim, cap: usize) -> (isize, String) {
    let mut buffer = vec![0u8; cap];
    let result = unsafe { raphp_poll(shim, buffer.as_mut_ptr().cast::<c_char>(), cap) };
    let text = if result > 0 {
        String::from_utf8(buffer[..result as usize].to_vec()).unwrap()
    } else {
        String::new()
    };

    (result, text)
}

#[test]
fn events_flow_through_the_c_functions() {
    let shim = idle_handle();
    let fd = unsafe { raphp_event_fd(shim) };
    assert!(fd >= 0);
    assert!(!readable(fd, 0));

    assert_eq!(send(shim, r##"#emit {"type":"hello"}"##), RaphpStatus::Ok);

    assert!(readable(fd, 100));
    clear(fd);
    assert_eq!(poll(shim, 4096), (17, "{\"type\":\"hello\"}\n".to_owned()));
    assert_eq!(poll(shim, 4096).0, 0, "empty");
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn the_c_functions_report_a_buffer_that_is_too_small_and_how_big_it_must_be() {
    let shim = idle_handle();
    send(shim, "#emit-large 3000");
    send(shim, r##"#emit {"type":"after"}"##);
    let size = unsafe { raphp_next_event_size(shim) };
    assert!(size > 3000);

    let (result, _) = poll(shim, 100);

    assert_eq!(result, RaphpStatus::BufferTooSmall as isize);
    assert_eq!(unsafe { raphp_next_event_size(shim) }, size, "still queued");
    let (result, text) = poll(shim, size + 100);
    assert_eq!(result as usize, text.len());
    assert!(text.contains("large") && text.ends_with("{\"type\":\"after\"}\n"));
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn an_event_over_the_limit_comes_back_as_an_error_event_through_the_c_functions() {
    let shim = idle_handle();

    assert_eq!(
        send(shim, &format!("#emit-report {MAX_EVENT_BYTES}")),
        RaphpStatus::Ok
    );

    let (_, text) = poll(shim, 4096);
    assert!(
        text.starts_with(r#"{"type":"event.too_large","event":"large""#),
        "{text}"
    );
    assert_eq!(raphp_max_event_bytes(), MAX_EVENT_BYTES);
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_poisoned_shim_still_delivers_its_last_event_and_then_nothing_more() {
    let shim = idle_handle();
    let fd = unsafe { raphp_event_fd(shim) };
    send(shim, r##"#emit {"type":"before"}"##);
    clear(fd);

    assert_eq!(
        send(shim, "#poison the window system failed"),
        RaphpStatus::Ok
    );

    assert!(
        readable(fd, 100),
        "PHP is woken by the failure, not only told at its next call"
    );
    assert_eq!(send(shim, "[]"), RaphpStatus::Poisoned, "calls are refused");
    let (_, text) = poll(shim, 4096);
    assert_eq!(
        text,
        "{\"type\":\"before\"}\n{\"type\":\"shim.failed\",\"reason\":\"the window system failed\"}\n",
        "the events before the failure, then the failure"
    );
    assert_eq!(poll(shim, 4096).0, 0);
    assert_eq!(
        unsafe { raphp_event_fd(shim) },
        fd,
        "the descriptor still works"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_failure_reason_with_quotes_and_newlines_is_a_valid_event() {
    let shim = idle_handle();

    unsafe { &*shim }
        .shim
        .health
        .poison("it said \"no\"\nthen\tdied \\ \u{1}");

    let (_, text) = poll(shim, 4096);
    assert_eq!(text.lines().count(), 1, "one line");
    assert_eq!(
        text,
        "{\"type\":\"shim.failed\",\"reason\":\"it said \\\"no\\\"\\nthen\\tdied \\\\ \\u0001\"}\n"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn poll_and_the_event_functions_refuse_bad_arguments() {
    let shim = idle_handle();
    let mut byte = 0u8;

    assert_eq!(
        unsafe { raphp_poll(ptr::null(), (&raw mut byte).cast::<c_char>(), 1) },
        RaphpStatus::InvalidArgument as isize
    );
    assert_eq!(
        unsafe { raphp_poll(shim, ptr::null_mut(), 10) },
        RaphpStatus::InvalidArgument as isize
    );
    assert_eq!(
        unsafe { raphp_poll(shim, ptr::null_mut(), 0) },
        0,
        "a zero capacity may be null: nothing queued"
    );
    assert_eq!(unsafe { raphp_event_fd(ptr::null()) }, -1);
    assert_eq!(unsafe { raphp_next_event_size(ptr::null()) }, 0);

    send(shim, r##"#emit {"type":"x"}"##);
    assert_eq!(
        unsafe { raphp_poll(shim, ptr::null_mut(), 0) },
        RaphpStatus::BufferTooSmall as isize,
        "with an event queued, no capacity is too small"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn a_panic_while_producing_an_event_is_contained_like_any_other() {
    let shim = idle_handle();

    assert_eq!(send(shim, "#panic"), RaphpStatus::Panicked);

    let (_, text) = poll(shim, 4096);
    assert!(
        text.contains("shim.failed") && text.contains("a command panicked on purpose"),
        "{text}"
    );
    unsafe { raphp_shutdown(shim) };
}

#[test]
fn after_a_failure_the_queue_takes_no_more_events_and_announces_the_failure_once() {
    let queue = queue();
    queue.push(click("before")).unwrap();

    queue.fail("first");
    queue.fail("second");

    assert_eq!(queue.push(click("after")), Err(PushError::Closed));
    let lines = drain(&queue, 4096);
    assert_eq!(
        lines,
        vec![
            r#"{"type":"click","id":"before"}"#,
            r#"{"type":"shim.failed","reason":"first"}"#
        ],
        "the events before the failure, then one failure event with the first reason"
    );
}

#[test]
fn an_event_that_fits_is_queued_as_it_is_and_reported_as_such() {
    let queue = queue();

    assert_eq!(queue.push_or_report(click("fine"), "click"), Ok(true));

    assert_eq!(drain(&queue, 4096), vec![r#"{"type":"click","id":"fine"}"#]);
}
