//! Counters that tests and tools can read from any thread while the UI thread works (plan P3.3, used by P3.6
//! and P3.10 to test redraw pacing, F8). The UI thread writes them; `raphp_diagnostics` reads them.

use std::collections::BTreeMap;
use std::sync::atomic::{AtomicU64, AtomicUsize, Ordering};
use std::sync::{Mutex, PoisonError};

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub(crate) struct WindowCounters {
    /// How many times something asked for a redraw of the window. Filled in from P3.8, when drawing exists.
    pub redraws_requested: u64,
    /// How many frames were really drawn. Filled in from P3.8.
    pub frames_drawn: u64,
}

#[derive(Debug, Default)]
pub(crate) struct Diagnostics {
    batches: AtomicU64,
    commands_applied: AtomicU64,
    commands_failed: AtomicU64,
    widgets: AtomicUsize,
    windows: Mutex<BTreeMap<u64, WindowCounters>>,
}

impl Diagnostics {
    pub fn batch_done(&self, applied: u64, failed: u64) {
        self.batches.fetch_add(1, Ordering::SeqCst);
        self.commands_applied.fetch_add(applied, Ordering::SeqCst);
        self.commands_failed.fetch_add(failed, Ordering::SeqCst);
    }

    pub fn set_widgets(&self, count: usize) {
        self.widgets.store(count, Ordering::SeqCst);
    }

    pub fn window_created(&self, id: u64) {
        self.lock().insert(id, WindowCounters::default());
    }

    pub fn window_destroyed(&self, id: u64) {
        self.lock().remove(&id);
    }

    /// Called by the model when a batch leaves a window needing a frame.
    pub fn redraw_requested(&self, id: u64) {
        if let Some(counters) = self.lock().get_mut(&id) {
            counters.redraws_requested += 1;
        }
    }

    /// Called by the UI loop after a frame is drawn.
    pub fn frame_drawn(&self, id: u64) {
        if let Some(counters) = self.lock().get_mut(&id) {
            counters.frames_drawn += 1;
        }
    }

    /// The counters as one line of JSON.
    pub fn to_json(&self) -> String {
        let windows: Vec<String> = self
            .lock()
            .iter()
            .map(|(id, counters)| {
                format!(
                    "\"{id}\":{{\"redraws_requested\":{},\"frames_drawn\":{}}}",
                    counters.redraws_requested, counters.frames_drawn
                )
            })
            .collect();

        format!(
            "{{\"batches\":{},\"commands_applied\":{},\"commands_failed\":{},\"widgets\":{},\"windows\":{{{}}}}}",
            self.batches.load(Ordering::SeqCst),
            self.commands_applied.load(Ordering::SeqCst),
            self.commands_failed.load(Ordering::SeqCst),
            self.widgets.load(Ordering::SeqCst),
            windows.join(",")
        )
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, BTreeMap<u64, WindowCounters>> {
        self.windows.lock().unwrap_or_else(PoisonError::into_inner)
    }
}
