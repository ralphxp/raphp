//! What the UI loop needs from a window system, and the one that needs none (plan P3.4).
//!
//! The loop, the model and the commands are the same whatever is underneath; a backend only creates, resizes
//! and destroys the pictures of windows and draws a frame into one. [`HeadlessBackend`] keeps the pictures in
//! memory and has a clock that only moves when told to; the window backend (`window.rs`, P3.6) uses winit.

use std::collections::BTreeMap;
use std::fmt;
use std::sync::{Arc, Mutex, PoisonError};
use std::time::Duration;

use crate::clock::{Clock, ManualClock};
use crate::config::Config;
use crate::draw::render;
use crate::input::Interaction;
use crate::model::Model;
use crate::surface::{Surface, WHITE};
use crate::text::TextRenderer;

/// Why a backend refused to make or resize a window.
#[derive(Debug, PartialEq, Eq)]
pub(crate) enum BackendError {
    /// It would take more than the backend may use (the headless pictures' memory budget).
    Limit(String),
    /// The window system could not do it.
    Failed(String),
}

impl fmt::Display for BackendError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Limit(reason) | Self::Failed(reason) => formatter.write_str(reason),
        }
    }
}

pub(crate) trait Backend {
    fn clock(&self) -> &dyn Clock;

    /// The shortest time between two frames of one window.
    fn frame_interval(&self) -> Duration;

    /// Makes a new window (or its picture), for a window of this size in logical pixels. An `Err` refuses the
    /// command that asked and changes nothing.
    fn create_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError>;

    fn resize_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError>;

    fn destroy_window(&mut self, id: u64);

    /// Sets the title of a window (a headless window has none to show).
    fn set_title(&mut self, id: u64, title: &str);

    /// Draws one frame of the window. Until drawing exists (P3.8) that is a blank window.
    fn draw(&mut self, id: u64, model: &Model, view: &Interaction);

    /// Moves a manual clock forward; false when the clock is the real one.
    fn advance_clock(&mut self, millis: u64) -> bool;
}

/// The pictures of the headless windows, shared with `raphp_headless_frame` so that PHP can read them.
#[derive(Default)]
pub(crate) struct HeadlessShared {
    surfaces: Mutex<BTreeMap<u64, Surface>>,
}

impl HeadlessShared {
    /// Copies the picture of a window into `out` (at most `out.len()` bytes) and returns its size in pixels
    /// and the full length in bytes, or `None` if there is no such window.
    pub fn read(&self, id: u64, out: &mut [u8]) -> Option<(u32, u32, usize)> {
        let surfaces = self.surfaces.lock().unwrap_or_else(PoisonError::into_inner);
        let surface = surfaces.get(&id)?;
        let pixels = surface.pixels();
        let length = pixels.len().min(out.len());
        out[..length].copy_from_slice(&pixels[..length]);

        Some((surface.width, surface.height, pixels.len()))
    }

    fn lock(&self) -> std::sync::MutexGuard<'_, BTreeMap<u64, Surface>> {
        self.surfaces.lock().unwrap_or_else(PoisonError::into_inner)
    }
}

pub(crate) struct HeadlessBackend {
    clock: Arc<ManualClock>,
    shared: Arc<HeadlessShared>,
    scale: f64,
    frame_interval: Duration,
    budget_bytes: usize,
    /// The bundled font, whatever the machine has: a headless picture is the same everywhere.
    text: Arc<TextRenderer>,
}

impl HeadlessBackend {
    pub fn new(config: &Config, shared: Arc<HeadlessShared>) -> Self {
        Self {
            clock: Arc::new(ManualClock::default()),
            shared,
            scale: config.scale,
            frame_interval: config.frame_interval,
            budget_bytes: config.surface_budget_bytes,
            text: TextRenderer::bundled(),
        }
    }

    /// The size in physical pixels of a window of this logical size, at least one pixel each way.
    fn physical(&self, width: f64, height: f64) -> (u32, u32) {
        let pixels = |logical: f64| {
            (logical * self.scale)
                .ceil()
                .clamp(1.0, f64::from(u32::MAX)) as u32
        };

        (pixels(width), pixels(height))
    }

    /// Checks that the pictures of all windows, with this one at the new size, fit in the budget.
    fn check_budget(&self, id: u64, width: u32, height: u32) -> Result<(), BackendError> {
        let surfaces = self.shared.lock();
        let others: usize = surfaces
            .iter()
            .filter(|(other, _)| **other != id)
            .map(|(_, surface)| Surface::bytes_for(surface.width, surface.height))
            .fold(0, usize::saturating_add);
        let needed = others.saturating_add(Surface::bytes_for(width, height));

        if needed > self.budget_bytes {
            return Err(BackendError::Limit(format!(
                "the pictures of the windows would need {} MiB, more than the budget of {} MiB",
                needed >> 20,
                self.budget_bytes >> 20
            )));
        }

        Ok(())
    }
}

impl Backend for HeadlessBackend {
    fn clock(&self) -> &dyn Clock {
        &*self.clock
    }

    fn frame_interval(&self) -> Duration {
        self.frame_interval
    }

    fn create_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError> {
        let (w, h) = self.physical(width, height);
        self.check_budget(id, w, h)?;
        self.shared.lock().insert(id, Surface::new(w, h));

        Ok(())
    }

    fn resize_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError> {
        let (w, h) = self.physical(width, height);
        self.check_budget(id, w, h)?;
        self.shared.lock().insert(id, Surface::new(w, h));

        Ok(())
    }

    fn destroy_window(&mut self, id: u64) {
        self.shared.lock().remove(&id);
    }

    fn set_title(&mut self, _id: u64, _title: &str) {}

    fn draw(&mut self, id: u64, model: &Model, view: &Interaction) {
        let size = self
            .shared
            .lock()
            .get(&id)
            .map(|surface| (surface.width, surface.height));
        let Some(size) = size else {
            return;
        };

        // A window with nothing in it is white; drawing it needs no fonts.
        let has_root = model
            .window_content(id)
            .is_some_and(|(_, _, root)| root.is_some());
        let picture = if has_root {
            render(model, id, size, self.scale, &self.text, view)
        } else {
            None
        };

        if let Some(surface) = self.shared.lock().get_mut(&id) {
            match picture {
                Some(picture) if surface.replace_pixels(picture.data()) => {}
                _ => surface.clear(WHITE),
            }
        }
    }

    fn advance_clock(&mut self, millis: u64) -> bool {
        self.clock.advance(millis);

        true
    }
}
