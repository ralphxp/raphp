//! The UI thread's life: started by `raphp_init`, asked to stop by `raphp_shutdown`, and contained if it
//! fails (R14, `docs/protocol/shim-abi.md`).

use std::any::Any;
use std::io;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Condvar, Mutex, PoisonError};
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant};

use crate::health::Health;

/// How the body of the UI thread is told to stop, and how it can wait for that.
#[derive(Default)]
pub(crate) struct StopSignal {
    requested: AtomicBool,
    lock: Mutex<()>,
    changed: Condvar,
    waker: Mutex<Option<Box<dyn Fn() + Send + Sync>>>,
}

impl StopSignal {
    pub fn requested(&self) -> bool {
        self.requested.load(Ordering::SeqCst)
    }

    pub fn request(&self) {
        {
            let _guard = self.lock.lock().unwrap_or_else(PoisonError::into_inner);
            self.requested.store(true, Ordering::SeqCst);
            self.changed.notify_all();
        }

        // A body that waits on something other than this signal (the command channel) is woken too.
        if let Some(waker) = self
            .waker
            .lock()
            .unwrap_or_else(PoisonError::into_inner)
            .as_ref()
        {
            waker();
        }
    }

    /// Registers what to do when a stop is requested, besides waking [`Self::wait`].
    pub fn set_waker(&self, waker: impl Fn() + Send + Sync + 'static) {
        *self.waker.lock().unwrap_or_else(PoisonError::into_inner) = Some(Box::new(waker));
    }

    /// Waits until a stop is requested or the time is up. Returns whether a stop was requested.
    #[cfg(test)]
    pub fn wait(&self, timeout: Duration) -> bool {
        let guard = self.lock.lock().unwrap_or_else(PoisonError::into_inner);
        let (_guard, _) = self
            .changed
            .wait_timeout_while(guard, timeout, |_| !self.requested())
            .unwrap_or_else(PoisonError::into_inner);

        self.requested()
    }
}

#[derive(Debug, Default)]
struct Finished {
    done: Mutex<bool>,
    changed: Condvar,
}

impl Finished {
    fn set(&self) {
        *self.done.lock().unwrap_or_else(PoisonError::into_inner) = true;
        self.changed.notify_all();
    }

    fn wait(&self, timeout: Duration) -> bool {
        let guard = self.done.lock().unwrap_or_else(PoisonError::into_inner);
        let (guard, _) = self
            .changed
            .wait_timeout_while(guard, timeout, |done| !*done)
            .unwrap_or_else(PoisonError::into_inner);

        *guard
    }
}

#[derive(Debug, PartialEq, Eq)]
pub(crate) enum StopOutcome {
    /// The thread ended and was joined.
    Finished,
    /// The thread was still running when the time was up. It is detached and ends with the process.
    TimedOut,
}

pub(crate) struct UiThread {
    join: JoinHandle<()>,
    finished: Arc<Finished>,
    stop: Arc<StopSignal>,
}

impl UiThread {
    /// Starts the UI thread. `body` runs on it, under `catch_unwind`:
    /// - a panic poisons the shim with the panic's message;
    /// - returning without having been asked to stop poisons it too (the thread's work was cut short);
    /// - returning after a stop request is the orderly end.
    ///
    /// A call of `std::process::exit` or an abort inside `body` cannot be caught; the shim never makes one.
    pub fn spawn<F>(health: Arc<Health>, body: F) -> io::Result<Self>
    where
        F: FnOnce(Arc<StopSignal>) + Send + 'static,
    {
        let stop = Arc::new(StopSignal::default());
        let finished = Arc::new(Finished::default());
        let (thread_stop, thread_finished) = (stop.clone(), finished.clone());

        let join = thread::Builder::new()
            .name("raphp-ui".to_owned())
            .spawn(move || {
                let outcome = catch_unwind(AssertUnwindSafe(|| body(thread_stop.clone())));
                match outcome {
                    Err(payload) => {
                        health.poison(format!(
                            "the UI thread panicked: {}",
                            panic_message(&*payload)
                        ));
                    }
                    Ok(()) if !thread_stop.requested() => {
                        health.poison("the UI thread ended without being asked to");
                    }
                    Ok(()) => {}
                }

                thread_finished.set();
            })?;

        Ok(Self {
            join,
            finished,
            stop,
        })
    }

    /// Asks the thread to stop and waits up to `timeout` for it. The wait is bounded on purpose: a UI
    /// thread stuck in a native call must not hang the master's shutdown.
    pub fn stop_and_join(self, timeout: Duration) -> StopOutcome {
        self.stop.request();
        let started = Instant::now();

        if self.finished.wait(timeout) {
            // The body ran under catch_unwind, so the join cannot report a panic.
            let _ = self.join.join();
            StopOutcome::Finished
        } else {
            debug_assert!(started.elapsed() >= timeout);
            drop(self.join); // detaches
            StopOutcome::TimedOut
        }
    }
}

/// The text of a panic, for the error message.
pub(crate) fn panic_message(payload: &(dyn Any + Send)) -> String {
    if let Some(text) = payload.downcast_ref::<&str>() {
        (*text).to_owned()
    } else if let Some(text) = payload.downcast_ref::<String>() {
        text.clone()
    } else {
        "a panic with a payload that is not text".to_owned()
    }
}
