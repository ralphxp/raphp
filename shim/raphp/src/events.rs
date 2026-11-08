//! The queue of events going from the shim to PHP (FRAMEWORK 7.5), and the rules that keep it honest.
//!
//! The queue lives in memory and holds one line of JSON per event. An `eventfd` is only the wake-up call,
//! never the buffer: a pipe used as the buffer lost 88% of the events when PHP stalled for 300 ms (spike 1).
//!
//! - **Discrete events** (click, key, text, focus, close, errors) are never merged and never dropped, and
//!   they keep their order.
//! - **Continuous events** (pointer move, scroll position, resize) carry *state*, not deltas, because only
//!   the latest is kept: a new one replaces an earlier queued one with the same window and kind, if no
//!   discrete event was queued in between. So a discrete event is never overtaken and never overtakes.
//! - **Under pressure** (O7): past the soft limit an older continuous event is removed even across a
//!   discrete event (the newest goes to the end, which is where it belongs), and superseded ones are swept;
//!   past the hard limit, with nothing left to merge, the producer waits for PHP to drain (back-pressure).
//!   Nothing discrete is ever dropped.
//! - **A single event** may be at most [`MAX_EVENT_BYTES`]. A larger one is refused and replaced by a small
//!   error event ([`EventQueue::push_or_report`]), so PHP's buffer need is bounded (R16).

use std::collections::{BTreeMap, HashMap};
use std::os::fd::{AsRawFd, FromRawFd, OwnedFd, RawFd};
use std::sync::{Condvar, Mutex, MutexGuard, PoisonError};

/// The largest single event, with its newline (my number: realistic big events are a clipboard paste, a
/// drag-and-drop list of files, IME text).
pub(crate) const MAX_EVENT_BYTES: usize = 1 << 20;

/// Queued bytes after which older continuous events are removed to make room (my number).
pub(crate) const SOFT_LIMIT_BYTES: usize = 1 << 20;

/// Queued bytes after which a producer waits for PHP (my number; at least [`MAX_EVENT_BYTES`]).
pub(crate) const HARD_LIMIT_BYTES: usize = 4 << 20;

/// The kinds of continuous event: absolute state, of which only the latest matters.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub(crate) enum ContinuousKind {
    PointerMove,
    Scroll,
    Resize,
}

/// Which continuous events replace each other: the same window and the same kind.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash)]
pub(crate) struct CoalesceKey {
    pub window: u64,
    pub kind: ContinuousKind,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum EventClass {
    Discrete,
    Continuous(CoalesceKey),
}

/// One event: its class and one line of JSON (no newline inside).
#[derive(Clone, Debug)]
pub(crate) struct Event {
    pub class: EventClass,
    pub json: String,
}

impl Event {
    pub fn discrete(json: impl Into<String>) -> Self {
        Self {
            class: EventClass::Discrete,
            json: json.into(),
        }
    }

    pub fn continuous(window: u64, kind: ContinuousKind, json: impl Into<String>) -> Self {
        Self {
            class: EventClass::Continuous(CoalesceKey { window, kind }),
            json: json.into(),
        }
    }
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum PushError {
    /// The event is empty or has a newline inside.
    Invalid,
    /// The event is larger than [`MAX_EVENT_BYTES`] (its size with the newline is given).
    TooLarge(usize),
    /// The queue is closed (the shim is shutting down or has failed): nobody will read the event.
    Closed,
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum Polled {
    /// Nothing is queued.
    Empty,
    /// This many bytes of whole events were copied out.
    Written(usize),
    /// The next event is larger than the buffer. It stays at the head of the queue; this is its size.
    TooSmall(usize),
}

struct Entry {
    line: Vec<u8>,
    key: Option<CoalesceKey>,
    /// The number of discrete events queued before this one: equal numbers mean "no discrete event between".
    barrier: u64,
    /// A newer event with the same key is queued, so this one only costs space.
    superseded: bool,
}

struct State {
    entries: BTreeMap<u64, Entry>,
    next_sequence: u64,
    latest: HashMap<CoalesceKey, u64>,
    barrier: u64,
    bytes: usize,
    superseded: usize,
    closed: bool,
    #[cfg(test)]
    max_bytes_seen: usize,
    /// How many times a producer had to wait for PHP (tests only: proves back-pressure was exercised).
    #[cfg(test)]
    waits: usize,
}

pub(crate) struct EventQueue {
    state: Mutex<State>,
    space: Condvar,
    wake: OwnedFd,
    soft_limit: usize,
    hard_limit: usize,
}

impl EventQueue {
    pub fn new() -> std::io::Result<Self> {
        Self::with_limits(SOFT_LIMIT_BYTES, HARD_LIMIT_BYTES)
    }

    pub fn with_limits(soft_limit: usize, hard_limit: usize) -> std::io::Result<Self> {
        // SAFETY: eventfd takes plain integers; a negative result is an error.
        let fd = unsafe { libc::eventfd(0, libc::EFD_CLOEXEC | libc::EFD_NONBLOCK) };
        if fd < 0 {
            return Err(std::io::Error::last_os_error());
        }

        Ok(Self {
            state: Mutex::new(State {
                entries: BTreeMap::new(),
                next_sequence: 0,
                latest: HashMap::new(),
                barrier: 0,
                bytes: 0,
                superseded: 0,
                closed: false,
                #[cfg(test)]
                max_bytes_seen: 0,
                #[cfg(test)]
                waits: 0,
            }),
            space: Condvar::new(),
            // SAFETY: `fd` is a new, valid descriptor that nothing else owns.
            wake: unsafe { OwnedFd::from_raw_fd(fd) },
            soft_limit,
            hard_limit: hard_limit.max(MAX_EVENT_BYTES),
        })
    }

    /// The descriptor PHP waits on. It is close-on-exec, and valid until the queue is dropped.
    pub fn wake_fd(&self) -> RawFd {
        self.wake.as_raw_fd()
    }

    /// Queues an event, merging it with an earlier one when the rules allow, and waiting for PHP to drain
    /// when the queue is full of events that cannot be merged.
    pub fn push(&self, event: Event) -> Result<(), PushError> {
        if event.json.is_empty() || event.json.contains('\n') {
            return Err(PushError::Invalid);
        }

        let length = event.json.len() + 1;
        if length > MAX_EVENT_BYTES {
            return Err(PushError::TooLarge(length));
        }

        let mut line = event.json.into_bytes();
        line.push(b'\n');

        let mut state = self.lock();
        loop {
            if state.closed {
                return Err(PushError::Closed);
            }

            if self.try_place(&mut state, event.class, &mut line) {
                break;
            }

            // Full of events that cannot be merged: wait for PHP, which drains and wakes us.
            #[cfg(test)]
            {
                state.waits += 1;
            }
            state = self
                .space
                .wait(state)
                .unwrap_or_else(PoisonError::into_inner);
        }

        let first = state.entries.len() == 1;
        drop(state);
        if first {
            self.signal();
        }

        Ok(())
    }

    /// Like [`Self::push`], but an event that is too large is replaced by a small error event naming it, so
    /// that PHP is told instead of the event vanishing. Returns whether the original was queued.
    pub fn push_or_report(&self, event: Event, what: &str) -> Result<bool, PushError> {
        match self.push(event) {
            Ok(()) => Ok(true),
            Err(PushError::TooLarge(bytes)) => {
                let report = format!(
                    "{{\"type\":\"event.too_large\",\"event\":{},\"bytes\":{bytes},\"limit\":{MAX_EVENT_BYTES}}}",
                    json_string(what)
                );
                self.push(Event::discrete(report)).map(|()| false)
            }
            Err(other) => Err(other),
        }
    }

    /// Records that the shim failed: queues one last discrete event with the reason, whatever the limits,
    /// and from now on no producer waits (the queue is closed to them). PHP can still drain what is there.
    pub fn fail(&self, reason: &str) {
        let json = format!(
            "{{\"type\":\"shim.failed\",\"reason\":{}}}",
            json_string(reason)
        );
        let mut state = self.lock();
        if state.closed {
            return;
        }

        state.closed = true;
        state.barrier += 1;
        let barrier = state.barrier;
        let mut line = json.into_bytes();
        line.push(b'\n');
        Self::append(&mut state, None, line, barrier);
        self.space.notify_all();
        drop(state);
        self.signal();
    }

    /// Stops accepting events and wakes every waiting producer. What is queued stays readable.
    pub fn close(&self) {
        let mut state = self.lock();
        state.closed = true;
        self.space.notify_all();
    }

    /// Copies as many whole events as fit into `out`, in order. An event is never split, and never skipped:
    /// if the first one does not fit, nothing is copied and its size is returned, the event staying queued.
    /// When events remain afterwards the wake-up is raised again, so a drain that stops early (a small
    /// buffer, a large event) is called back instead of leaving events stranded.
    pub fn poll(&self, out: &mut [u8]) -> Polled {
        let mut state = self.lock();
        let Some(first) = state.entries.values().next() else {
            return Polled::Empty;
        };

        let first_length = first.line.len();
        if first_length > out.len() {
            drop(state);
            self.signal();

            return Polled::TooSmall(first_length);
        }

        let mut written = 0;
        while let Some(entry) = state.entries.values().next() {
            let length = entry.line.len();
            if written + length > out.len() {
                break;
            }

            let (sequence, entry) = state.entries.pop_first().expect("an entry was just seen");
            out[written..written + length].copy_from_slice(&entry.line);
            written += length;
            state.bytes -= length;
            if entry.superseded {
                state.superseded -= 1;
            }

            if let Some(key) = entry.key
                && state.latest.get(&key) == Some(&sequence)
            {
                state.latest.remove(&key);
            }
        }

        let more = !state.entries.is_empty();
        self.space.notify_all();
        drop(state);
        if more {
            self.signal();
        }

        Polled::Written(written)
    }

    /// The size, newline included, of the event at the head of the queue; 0 when it is empty.
    pub fn next_event_size(&self) -> usize {
        self.lock()
            .entries
            .values()
            .next()
            .map_or(0, |entry| entry.line.len())
    }

    #[cfg(test)]
    pub fn queued_bytes(&self) -> usize {
        self.lock().bytes
    }

    #[cfg(test)]
    pub fn max_bytes_seen(&self) -> usize {
        self.lock().max_bytes_seen
    }

    #[cfg(test)]
    pub fn waits(&self) -> usize {
        self.lock().waits
    }

    #[cfg(test)]
    pub fn len(&self) -> usize {
        self.lock().entries.len()
    }

    /// Places an event if the rules and the limits allow. Returns false when the producer must wait.
    fn try_place(&self, state: &mut State, class: EventClass, line: &mut Vec<u8>) -> bool {
        let length = line.len();

        if let EventClass::Continuous(key) = class
            && let Some(&sequence) = state.latest.get(&key)
        {
            let barrier_now = state.barrier;
            if let Some(entry) = state.entries.get_mut(&sequence)
                && entry.barrier == barrier_now
            {
                // The same state again with no discrete event between: the newest replaces it.
                let old = std::mem::replace(&mut entry.line, std::mem::take(line));
                state.bytes = state.bytes - old.len() + length;
                #[cfg(test)]
                {
                    state.max_bytes_seen = state.max_bytes_seen.max(state.bytes);
                }

                return true;
            }

            if state.bytes + length > self.soft_limit {
                // Coalesce harder: drop the older one across the discrete events, the newest goes last.
                if let Some(old) = state.entries.remove(&sequence) {
                    state.bytes -= old.line.len();
                    if old.superseded {
                        state.superseded -= 1;
                    }
                }
            }
        }

        if state.bytes + length > self.soft_limit && state.superseded > 0 {
            Self::sweep(state);
        }

        if state.bytes + length > self.hard_limit && !state.entries.is_empty() {
            return false;
        }

        let key = match class {
            EventClass::Continuous(key) => Some(key),
            EventClass::Discrete => {
                state.barrier += 1;

                None
            }
        };
        let barrier = state.barrier;
        Self::append(state, key, std::mem::take(line), barrier);

        true
    }

    fn append(state: &mut State, key: Option<CoalesceKey>, line: Vec<u8>, barrier: u64) {
        let sequence = state.next_sequence;
        state.next_sequence += 1;
        state.bytes += line.len();
        #[cfg(test)]
        {
            state.max_bytes_seen = state.max_bytes_seen.max(state.bytes);
        }

        if let Some(key) = key
            && let Some(older) = state.latest.insert(key, sequence)
            && let Some(entry) = state.entries.get_mut(&older)
            && !entry.superseded
        {
            entry.superseded = true;
            state.superseded += 1;
        }

        state.entries.insert(
            sequence,
            Entry {
                line,
                key,
                barrier,
                superseded: false,
            },
        );
    }

    /// Removes every continuous event that a newer one with the same key has superseded.
    fn sweep(state: &mut State) {
        let mut freed = 0;
        state.entries.retain(|_, entry| {
            if entry.superseded {
                freed += entry.line.len();

                false
            } else {
                true
            }
        });
        state.bytes -= freed;
        state.superseded = 0;
    }

    fn lock(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(PoisonError::into_inner)
    }

    /// Raises the wake-up. An `eventfd` adds up writes, so many before one read are one wake-up. A write
    /// that cannot happen (the counter is full, which would take 2^64 writes) changes nothing: PHP is
    /// already awake.
    fn signal(&self) {
        let one: u64 = 1;
        // SAFETY: the descriptor is valid while `self` lives, and the buffer is 8 readable bytes.
        let _ = unsafe {
            libc::write(
                self.wake.as_raw_fd(),
                (&raw const one).cast::<libc::c_void>(),
                8,
            )
        };
    }
}

/// A JSON string literal for `text`.
fn json_string(text: &str) -> String {
    let mut out = String::with_capacity(text.len() + 2);
    out.push('"');
    for character in text.chars() {
        match character {
            '"' => out.push_str("\\\""),
            '\\' => out.push_str("\\\\"),
            '\n' => out.push_str("\\n"),
            '\r' => out.push_str("\\r"),
            '\t' => out.push_str("\\t"),
            control if (control as u32) < 0x20 => {
                out.push_str(&format!("\\u{:04x}", control as u32))
            }
            other => out.push(other),
        }
    }
    out.push('"');

    out
}
