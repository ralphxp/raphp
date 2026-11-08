//! Time for the UI loop. The window backend uses the real clock; the headless backend uses a manual one that
//! only moves when a test says so, so that what depends on time (frame pacing now, animations later) can be
//! tested exactly, without sleeping (plan P3.4).

use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};

pub(crate) trait Clock: Send + Sync {
    /// Time since the shim started.
    fn now(&self) -> Duration;

    /// How long the loop may sleep waiting for `remaining` to pass. `None` means time does not pass by
    /// itself (a manual clock): the loop waits for a message instead, and an advance of the clock is one.
    fn sleep_budget(&self, remaining: Duration) -> Option<Duration>;
}

/// The real, monotonic clock.
#[allow(dead_code, reason = "used by the window backend of step P3.6")]
pub(crate) struct SystemClock {
    start: Instant,
}

#[allow(dead_code, reason = "used by the window backend of step P3.6")]
impl SystemClock {
    pub fn new() -> Self {
        Self {
            start: Instant::now(),
        }
    }
}

impl Clock for SystemClock {
    fn now(&self) -> Duration {
        self.start.elapsed()
    }

    fn sleep_budget(&self, remaining: Duration) -> Option<Duration> {
        Some(remaining)
    }
}

/// A clock that starts at zero and moves only when [`Self::advance`] is called.
#[derive(Default)]
pub(crate) struct ManualClock {
    millis: AtomicU64,
}

impl ManualClock {
    pub fn advance(&self, millis: u64) {
        // Saturating: a test that advances by u64::MAX must not wrap the clock back to the past.
        let _ = self
            .millis
            .fetch_update(Ordering::SeqCst, Ordering::SeqCst, |now| {
                Some(now.saturating_add(millis))
            });
    }
}

impl Clock for ManualClock {
    fn now(&self) -> Duration {
        Duration::from_millis(self.millis.load(Ordering::SeqCst))
    }

    fn sleep_budget(&self, _remaining: Duration) -> Option<Duration> {
        None
    }
}
