use std::io;
use std::sync::{Arc, Mutex, PoisonError};
use std::time::Duration;

use crate::events::EventQueue;
use crate::health::Health;
use crate::status::{RaphpStatus, ShimError};
use crate::ui_thread::{StopOutcome, StopSignal, UiThread};

/// How long `raphp_shutdown` waits for the UI thread before leaving it behind (my number, unmeasured).
pub(crate) const DEFAULT_SHUTDOWN_TIMEOUT: Duration = Duration::from_secs(3);

/// The shim, as PHP sees it: an opaque handle. Created by `raphp_init`, freed by `raphp_shutdown`.
pub struct RaphpShim {
    pub(crate) shim: Shim,
}

pub(crate) struct Shim {
    pub health: Arc<Health>,
    pub events: Arc<EventQueue>,
    ui: Mutex<Option<UiThread>>,
    shutdown_timeout: Duration,
}

impl Shim {
    /// Starts a shim whose UI thread runs `body`. `raphp_init` passes the real UI loop; tests pass
    /// bodies that panic, return early or refuse to stop.
    pub fn start<F>(shutdown_timeout: Duration, body: F) -> io::Result<Self>
    where
        F: FnOnce(Arc<StopSignal>) + Send + 'static,
    {
        Self::start_with_events(Arc::new(EventQueue::new()?), shutdown_timeout, body)
    }

    /// The same, with an event queue chosen by the caller (the tests use small limits).
    pub fn start_with_events<F>(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        body: F,
    ) -> io::Result<Self>
    where
        F: FnOnce(Arc<StopSignal>) + Send + 'static,
    {
        let health = Arc::new(Health::default());
        // When the shim fails, PHP must hear of it through the events and not only at its next call.
        let queue = events.clone();
        health.on_poison(move |cause| queue.fail(cause));
        let ui = UiThread::spawn(health.clone(), body)?;

        Ok(Self {
            health,
            events,
            ui: Mutex::new(Some(ui)),
            shutdown_timeout,
        })
    }

    /// Runs a batch of commands. Until the command model exists (P3.3) only the empty batch is accepted.
    pub fn send(&self, batch: &str) -> Result<(), ShimError> {
        #[cfg(any(test, feature = "test-hooks"))]
        if let Some(result) = self.test_command(batch) {
            return result;
        }

        if batch.trim() == "[]" {
            return Ok(());
        }

        Err(ShimError::new(
            RaphpStatus::Unsupported,
            "commands are not implemented yet (the command model arrives in step P3.3)",
        ))
    }

    /// Commands that exist only in test builds, so that the tests can make the shim do things the UI thread
    /// will do for real later: `#panic`, `#emit <json>`, `#emit-c <window> <kind> <json>`,
    /// `#emit-large <bytes>`, `#emit-report <bytes>`, `#poison <reason>`.
    #[cfg(any(test, feature = "test-hooks"))]
    fn test_command(&self, batch: &str) -> Option<Result<(), ShimError>> {
        use crate::events::{ContinuousKind, Event, PushError};

        let rest = batch.strip_prefix('#')?;
        let (name, arguments) = rest.split_once(' ').unwrap_or((rest, ""));
        let pushed = |result: Result<(), PushError>| {
            result
                .map_err(|error| ShimError::invalid(format!("the event was not queued: {error:?}")))
        };

        Some(match name {
            "panic" => panic!("a command panicked on purpose"),
            "emit" => pushed(self.events.push(Event::discrete(arguments))),
            "emit-c" => {
                let mut parts = arguments.splitn(3, ' ');
                let window: u64 = parts.next().and_then(|text| text.parse().ok()).unwrap_or(0);
                let kind = match parts.next() {
                    Some("pointer_move") => ContinuousKind::PointerMove,
                    Some("scroll") => ContinuousKind::Scroll,
                    _ => ContinuousKind::Resize,
                };
                pushed(self.events.push(Event::continuous(
                    window,
                    kind,
                    parts.next().unwrap_or(""),
                )))
            }
            "emit-large" | "emit-report" => {
                let bytes: usize = arguments.parse().unwrap_or(0);
                let event = Event::discrete(format!(
                    "{{\"type\":\"large\",\"pad\":\"{}\"}}",
                    "x".repeat(bytes)
                ));
                if name == "emit-large" {
                    pushed(self.events.push(event))
                } else {
                    pushed(self.events.push_or_report(event, "large").map(|_| ()))
                }
            }
            "poison" => {
                self.health.poison(arguments);

                Ok(())
            }
            _ => return None,
        })
    }

    /// Stops the UI thread, waiting a bounded time. Safe to call on a poisoned shim, whose UI thread has
    /// usually ended already.
    pub fn stop(&self) -> RaphpStatus {
        // Wake any producer that is waiting for PHP to drain, so that it cannot hold up the shutdown.
        self.events.close();
        let ui = self
            .ui
            .lock()
            .unwrap_or_else(PoisonError::into_inner)
            .take();

        match ui.map(|thread| thread.stop_and_join(self.shutdown_timeout)) {
            Some(StopOutcome::TimedOut) => RaphpStatus::TimedOut,
            Some(StopOutcome::Finished) | None => RaphpStatus::Ok,
        }
    }
}

/// The UI thread of a shim that has no window system yet: it waits to be told to stop. The real loop
/// replaces it with the backends (P3.4 and P3.6).
pub(crate) fn idle_ui_body(stop: Arc<StopSignal>) {
    while !stop.wait(Duration::from_millis(250)) {}
}
