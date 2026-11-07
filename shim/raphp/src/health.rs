use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Mutex, OnceLock};

type PoisonHook = Box<dyn Fn(&str) + Send + Sync>;

/// Whether the shim still works, and the last reason it did not. Shared between the threads that call
/// into the shim and the UI thread.
#[derive(Default)]
pub(crate) struct Health {
    poisoned: AtomicBool,
    error: Mutex<String>,
    on_poison: OnceLock<PoisonHook>,
}

impl Health {
    pub fn is_poisoned(&self) -> bool {
        self.poisoned.load(Ordering::SeqCst)
    }

    /// Records a failure that leaves the shim usable (a bad argument).
    pub fn set_error(&self, message: impl Into<String>) {
        *self.lock() = message.into();
    }

    /// Marks the shim as failed for good. The first cause is kept: later failures are consequences.
    pub fn poison(&self, message: impl Into<String>) {
        let first = {
            let mut error = self.lock();
            if self.poisoned.swap(true, Ordering::SeqCst) {
                None
            } else {
                *error = message.into();

                Some(error.clone())
            }
        };

        // Told once, outside the lock, and only about the first cause.
        if let (Some(cause), Some(hook)) = (first, self.on_poison.get()) {
            hook(&cause);
        }
    }

    /// Registers what to do when the shim is poisoned (queue a last event for PHP). Once only.
    pub fn on_poison(&self, hook: impl Fn(&str) + Send + Sync + 'static) {
        let _ = self.on_poison.set(Box::new(hook));
    }

    pub fn error(&self) -> String {
        self.lock().clone()
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, String> {
        // A panic while holding this lock must not make the error unreadable: that is when it matters.
        self.error
            .lock()
            .unwrap_or_else(std::sync::PoisonError::into_inner)
    }
}
