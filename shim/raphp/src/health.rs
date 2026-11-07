use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, Ordering};

/// Whether the shim still works, and the last reason it did not. Shared between the threads that call
/// into the shim and the UI thread.
#[derive(Debug, Default)]
pub(crate) struct Health {
    poisoned: AtomicBool,
    error: Mutex<String>,
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
        let mut error = self.lock();
        if !self.poisoned.swap(true, Ordering::SeqCst) {
            *error = message.into();
        }
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
