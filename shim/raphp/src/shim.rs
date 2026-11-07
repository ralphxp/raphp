use std::io;
use std::sync::{Arc, Mutex, PoisonError};
use std::time::Duration;

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
        let health = Arc::new(Health::default());
        let ui = UiThread::spawn(health.clone(), body)?;

        Ok(Self {
            health,
            ui: Mutex::new(Some(ui)),
            shutdown_timeout,
        })
    }

    /// Runs a batch of commands. Until the command model exists (P3.3) only the empty batch is accepted.
    pub fn send(&self, batch: &str) -> Result<(), ShimError> {
        #[cfg(any(test, feature = "test-hooks"))]
        if batch == "#panic" {
            panic!("a command panicked on purpose");
        }

        if batch.trim() == "[]" {
            return Ok(());
        }

        Err(ShimError::new(
            RaphpStatus::Unsupported,
            "commands are not implemented yet (the command model arrives in step P3.3)",
        ))
    }

    /// Stops the UI thread, waiting a bounded time. Safe to call on a poisoned shim, whose UI thread has
    /// usually ended already.
    pub fn stop(&self) -> RaphpStatus {
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
