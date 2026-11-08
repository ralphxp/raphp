//! The window backend (plan P3.6): real windows through winit, on Wayland or X11, with the event loop on the
//! shim's UI thread.
//!
//! PHP's batches arrive as winit user events, so one loop does everything and the UI thread never waits on
//! anything else. The commands are applied by the same [`Model`] code the headless backend uses; this module
//! only adds what a real window system adds: creating the windows, drawing when the window system asks
//! (never on a timer: F8), and turning what the user does to a window into events.
//!
//! The events (docs/protocol/shim-commands.md): `window.close_requested` (the window is NOT closed: PHP decides
//! and sends `window.destroy`), `window.focus`, `window.resized` (continuous: only the latest is kept),
//! `window.scale`. Nothing is emitted for a window PHP has destroyed.

use std::collections::HashMap;
use std::num::NonZeroU32;
use std::rc::Rc;
use std::sync::Arc;
use std::sync::mpsc::Sender;

use serde_json::json;
use softbuffer::{Context, Surface};
use winit::application::ApplicationHandler;
use winit::dpi::LogicalSize;
use winit::error::EventLoopError;
use winit::event::WindowEvent;
use winit::event_loop::{ActiveEventLoop, EventLoop, EventLoopProxy, OwnedDisplayHandle};
use winit::platform::wayland::EventLoopBuilderExtWayland;
use winit::window::{Window, WindowId};

use crate::backend::{Backend, BackendError};
use crate::clock::{Clock, SystemClock};
use crate::diagnostics::Diagnostics;
use crate::events::{ContinuousKind, Event, EventQueue};
use crate::model::Model;
use crate::shim::{Msg, QueueSink};
use crate::ui_thread::StopSignal;

/// White, in softbuffer's `0x00RRGGBB`. Until drawing exists (P3.8) every frame is blank.
const WHITE: u32 = 0x00FF_FFFF;

// ---------------------------------------------------------------- the events

pub(crate) fn close_requested_event(window: u64) -> Event {
    Event::discrete(json!({"type": "window.close_requested", "window": window}).to_string())
}

pub(crate) fn focus_event(window: u64, focused: bool) -> Event {
    Event::discrete(
        json!({"type": "window.focus", "window": window, "focused": focused}).to_string(),
    )
}

pub(crate) fn scale_event(window: u64, scale: f64) -> Event {
    Event::discrete(json!({"type": "window.scale", "window": window, "scale": scale}).to_string())
}

/// The size is in logical pixels, like the sizes in the commands.
pub(crate) fn resized_event(window: u64, width: f64, height: f64, scale: f64) -> Event {
    Event::continuous(
        window,
        ContinuousKind::Resize,
        json!({"type": "window.resized", "window": window, "width": width, "height": height, "scale": scale})
            .to_string(),
    )
}

// ---------------------------------------------------------------- the windows

struct Open {
    window: Rc<Window>,
    surface: Surface<OwnedDisplayHandle, Rc<Window>>,
}

/// The real windows, by the id PHP gave them.
struct Windows {
    clock: SystemClock,
    context: Option<Context<OwnedDisplayHandle>>,
    open: HashMap<u64, Open>,
    by_winit: HashMap<WindowId, u64>,
    /// Windows whose size request the window system applied at once (Wayland does): winit then sends no
    /// `Resized` event, so the loop reports the size itself after the batch (F62).
    resized_at_once: Vec<u64>,
}

impl Windows {
    fn new() -> Self {
        Self {
            clock: SystemClock::new(),
            context: None,
            open: HashMap::new(),
            by_winit: HashMap::new(),
            resized_at_once: Vec::new(),
        }
    }

    /// Draws one frame (a blank one, until P3.8). True if a frame was put on the window.
    fn draw(&mut self, id: u64) -> bool {
        let Some(open) = self.open.get_mut(&id) else {
            return false;
        };
        let size = open.window.inner_size();
        let (Some(width), Some(height)) =
            (NonZeroU32::new(size.width), NonZeroU32::new(size.height))
        else {
            return false; // a window with no area (minimized on some systems) has nothing to draw
        };

        if open.surface.resize(width, height).is_err() {
            return false;
        }

        let Ok(mut buffer) = open.surface.buffer_mut() else {
            return false;
        };
        buffer.fill(WHITE);

        buffer.present().is_ok()
    }
}

/// What the model needs from the window system, valid for the length of one callback (winit hands out its
/// `ActiveEventLoop`, which is needed to make a window, only inside a callback).
struct Ops<'a> {
    windows: &'a mut Windows,
    event_loop: &'a ActiveEventLoop,
}

impl Backend for Ops<'_> {
    fn clock(&self) -> &dyn Clock {
        &self.windows.clock
    }

    fn frame_interval(&self) -> std::time::Duration {
        std::time::Duration::ZERO // the window system paces frames: a redraw is asked for, and arrives when it is time
    }

    fn create_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError> {
        if self.windows.context.is_none() {
            let context =
                Context::new(self.event_loop.owned_display_handle()).map_err(|error| {
                    BackendError::Failed(format!("the drawing context could not be made: {error}"))
                })?;
            self.windows.context = Some(context);
        }

        let attributes = Window::default_attributes()
            .with_title("")
            .with_inner_size(LogicalSize::new(width, height));
        let window = Rc::new(self.event_loop.create_window(attributes).map_err(|error| {
            BackendError::Failed(format!("the window system refused the window: {error}"))
        })?);
        let surface = match &self.windows.context {
            Some(context) => Surface::new(context, window.clone()).map_err(|error| {
                BackendError::Failed(format!("the window cannot be drawn on: {error}"))
            })?,
            None => return Err(BackendError::Failed("no drawing context".to_owned())),
        };

        self.windows.by_winit.insert(window.id(), id);
        self.windows.open.insert(id, Open { window, surface });

        Ok(())
    }

    fn resize_window(&mut self, id: u64, width: f64, height: f64) -> Result<(), BackendError> {
        if let Some(open) = self.windows.open.get(&id) {
            // A request: the window system may give another size. If it applied the request at once there
            // is no `Resized` event from winit, so the size is reported after the batch; otherwise winit's
            // event reports it when it comes.
            if open
                .window
                .request_inner_size(LogicalSize::new(width, height))
                .is_some()
            {
                self.windows.resized_at_once.push(id);
            }
        }

        Ok(())
    }

    fn destroy_window(&mut self, id: u64) {
        if let Some(open) = self.windows.open.remove(&id) {
            self.windows.by_winit.remove(&open.window.id());
        }
    }

    fn set_title(&mut self, id: u64, title: &str) {
        if let Some(open) = self.windows.open.get(&id) {
            open.window.set_title(title);
        }
    }

    fn draw(&mut self, id: u64, _model: &Model) {
        let _ = self.windows.draw(id);
    }

    fn advance_clock(&mut self, _millis: u64) -> bool {
        false // a real window system has a real clock
    }
}

// ---------------------------------------------------------------- the loop

struct App {
    model: Model,
    events: Arc<EventQueue>,
    diagnostics: Arc<Diagnostics>,
    stop: Arc<StopSignal>,
    windows: Windows,
}

impl App {
    fn emit(&self, event: Event) {
        use crate::model::EventSink;

        QueueSink(&self.events).emit(event);
    }

    /// Tells PHP the size a window has now, in logical pixels, and makes the model follow it (the window
    /// system may have given another size than the one asked for).
    fn report_size(&mut self, id: u64) {
        let Some(open) = self.windows.open.get(&id) else {
            return;
        };
        if !self.model.has_window(id) {
            return;
        }

        let scale = open.window.scale_factor();
        let logical = open.window.inner_size().to_logical::<f64>(scale);
        self.model.window_resized(id, logical.width, logical.height);
        self.emit(resized_event(id, logical.width, logical.height, scale));
    }
}

impl ApplicationHandler<Msg> for App {
    fn resumed(&mut self, _event_loop: &ActiveEventLoop) {
        // Windows are made when PHP asks for them, not when the loop starts.
    }

    fn user_event(&mut self, event_loop: &ActiveEventLoop, message: Msg) {
        match message {
            Msg::Wake => {
                if self.stop.requested() {
                    event_loop.exit();
                }
            }
            // Only the headless backend has a clock to move.
            Msg::Advance { ack, .. } => {
                let _ = ack.send(false);
            }
            Msg::Batch(batch) => {
                let mut sink = QueueSink(&self.events);
                let outcome = {
                    let mut ops = Ops {
                        windows: &mut self.windows,
                        event_loop,
                    };
                    self.model.apply_batch(batch, &mut sink, &mut ops)
                };

                // The counters already include these redraws (the model updated them before `batch.done`).
                for id in outcome.dirty {
                    if let Some(open) = self.windows.open.get(&id) {
                        open.window.request_redraw();
                    }
                }

                for id in std::mem::take(&mut self.windows.resized_at_once) {
                    self.report_size(id);
                }

                if outcome.quit {
                    self.stop.request();
                    event_loop.exit();
                }
            }
        }
    }

    fn window_event(
        &mut self,
        _event_loop: &ActiveEventLoop,
        winit_id: WindowId,
        event: WindowEvent,
    ) {
        let Some(&id) = self.windows.by_winit.get(&winit_id) else {
            return;
        };
        if !self.model.has_window(id) {
            return; // PHP destroyed it: nothing more is said about it (R15)
        }

        match event {
            WindowEvent::CloseRequested => self.emit(close_requested_event(id)),
            WindowEvent::Focused(focused) => self.emit(focus_event(id, focused)),
            WindowEvent::ScaleFactorChanged { scale_factor, .. } => {
                self.emit(scale_event(id, scale_factor));
            }
            WindowEvent::Resized(size) => {
                let Some(open) = self.windows.open.get(&id) else {
                    return;
                };
                let scale = open.window.scale_factor();
                let logical = size.to_logical::<f64>(scale);
                self.model.window_resized(id, logical.width, logical.height);
                self.emit(resized_event(id, logical.width, logical.height, scale));

                // The surface must follow the window, so a frame is needed; it is the window system's change,
                // and is counted like any other request.
                self.diagnostics.redraw_requested(id);
                if let Some(open) = self.windows.open.get(&id) {
                    open.window.request_redraw();
                }
            }
            WindowEvent::RedrawRequested => {
                if self.windows.draw(id) {
                    self.diagnostics.frame_drawn(id);
                }
            }
            _ => {}
        }
    }
}

/// What `raphp_init` says when the loop cannot be built. winit allows ONE event loop per process for the
/// whole life of the process, even after the first has ended (found in P3.6, F61), so a second window
/// shim in the same process cannot be started: the master has to be restarted.
fn start_error(error: &EventLoopError) -> String {
    match error {
        EventLoopError::RecreationAttempt => "the window backend can be started only once in a process: winit allows one event loop per process, even after the first has ended. Restart the process".to_owned(),
        other => format!("the window system is not available: {other}"),
    }
}

/// Runs the window loop on the calling thread (the UI thread) until it is told to stop. `ready` gets the
/// way to reach the loop, or the reason there is no window system.
///
/// # Panics
/// When winit's loop ends with an error: a failure of the UI thread, reported through the same path as any
/// other (the shim is poisoned and PHP gets `shim.failed` with this text).
pub(crate) fn run(
    model: Model,
    events: Arc<EventQueue>,
    diagnostics: Arc<Diagnostics>,
    stop: Arc<StopSignal>,
    ready: &Sender<Result<EventLoopProxy<Msg>, String>>,
) {
    let mut builder = EventLoop::<Msg>::with_user_event();
    builder.with_any_thread(true); // the shim's loop is not on the process's main thread (spike P0.1)
    let event_loop = match builder.build() {
        Ok(event_loop) => event_loop,
        Err(error) => {
            let _ = ready.send(Err(start_error(&error)));

            return;
        }
    };

    let proxy = event_loop.create_proxy();
    let waker = proxy.clone();
    stop.set_waker(move || {
        let _ = waker.send_event(Msg::Wake);
    });
    let _ = ready.send(Ok(proxy));

    let mut app = App {
        model,
        events,
        diagnostics,
        stop,
        windows: Windows::new(),
    };
    if let Err(error) = event_loop.run_app(&mut app) {
        panic!("the window system's event loop failed: {error}");
    }
}
