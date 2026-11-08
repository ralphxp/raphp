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
use winit::dpi::{LogicalPosition, LogicalSize};
use winit::error::EventLoopError;
use winit::event::{ElementState, Ime, MouseButton, MouseScrollDelta, WindowEvent};
use winit::event_loop::{ActiveEventLoop, EventLoop, EventLoopProxy, OwnedDisplayHandle};
use winit::keyboard::{Key, PhysicalKey};
use winit::platform::wayland::EventLoopBuilderExtWayland;
use winit::raw_window_handle::{HasDisplayHandle, RawDisplayHandle};
use winit::window::{Window, WindowId};

use crate::backend::{Backend, BackendError};
use crate::clipboard::{MemoryClipboard, WaylandClipboard};
use crate::clock::{Clock, SystemClock};
use crate::diagnostics::Diagnostics;
use crate::draw::render;
use crate::events::{ContinuousKind, Event, EventQueue};
use crate::input::{Button, InputEvent, Interaction, KeyPress, Modifiers, WHEEL_LINE};
use crate::model::Model;
use crate::shim::{Msg, QueueSink};
use crate::text::TextRenderer;
use crate::ui_thread::StopSignal;

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
    /// The system's fonts, got when the first frame is drawn (a scan was started when the loop did).
    text: Option<Arc<TextRenderer>>,
}

impl Windows {
    fn new() -> Self {
        Self {
            clock: SystemClock::new(),
            context: None,
            open: HashMap::new(),
            by_winit: HashMap::new(),
            resized_at_once: Vec::new(),
            text: None,
        }
    }

    /// The system's fonts, which measure text for hit testing as they do for drawing.
    fn text(&mut self) -> Arc<TextRenderer> {
        self.text.get_or_insert_with(TextRenderer::system).clone()
    }

    /// Draws one frame of the window's tree. True if a frame was put on the window.
    fn draw(&mut self, id: u64, model: &Model, view: &Interaction) -> bool {
        let text = self.text();
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

        let scale = open.window.scale_factor();
        let Some(picture) = render(model, id, (size.width, size.height), scale, &text, view) else {
            return false;
        };
        let Ok(mut buffer) = open.surface.buffer_mut() else {
            return false;
        };
        for (pixel, drawn) in buffer.iter_mut().zip(picture.pixels()) {
            // The picture is opaque, so premultiplied and plain colors are the same.
            *pixel = u32::from(drawn.red()) << 16
                | u32::from(drawn.green()) << 8
                | u32::from(drawn.blue());
        }

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

    fn draw(&mut self, id: u64, model: &Model, view: &Interaction) {
        let _ = self.windows.draw(id, model, view);
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
    interaction: Interaction,
    /// The modifier keys as winit last said, to tell typed text from a shortcut.
    held: Modifiers,
    /// The windows an input method is running for (a text input has the focus in them).
    ime_on: std::collections::HashSet<u64>,
    clipboard_taken: bool,
}

impl App {
    fn scale_of(&self, id: u64) -> f64 {
        self.windows
            .open
            .get(&id)
            .map_or(1.0, |open| open.window.scale_factor())
    }

    /// Hands an input event to the interaction state: what it asks of drawing is counted and requested before the
    /// events it leads to are queued.
    fn route(&mut self, id: u64, event: InputEvent) {
        self.interaction.set_time(self.windows.clock.now());
        let ruler = self.windows.text();
        let windows = &self.windows;
        let diagnostics = &self.diagnostics;
        let mut redraw = |window: u64| {
            diagnostics.redraw_requested(window);
            if let Some(open) = windows.open.get(&window) {
                open.window.request_redraw();
            }
        };
        let mut sink = QueueSink(&self.events);
        self.interaction
            .input(id, event, &mut self.model, &*ruler, &mut sink, &mut redraw);
        self.sync_ime(id);
    }

    /// Runs an input method while a text input has the focus in the window, and tells it where the caret is so that its
    /// candidate list comes up next to it.
    fn sync_ime(&mut self, id: u64) {
        let ruler = self.windows.text();
        let wants = self.interaction.wants_text(id, &self.model);
        let Some(open) = self.windows.open.get(&id) else {
            return;
        };
        if wants != self.ime_on.contains(&id) {
            open.window.set_ime_allowed(wants);
            if wants {
                self.ime_on.insert(id);
            } else {
                self.ime_on.remove(&id);
            }
        }

        if wants && let Some((x, y, height)) = self.interaction.caret_area(id, &self.model, &*ruler)
        {
            open.window.set_ime_cursor_area(
                LogicalPosition::new(f64::from(x), f64::from(y)),
                LogicalSize::new(1.0, f64::from(height)),
            );
        }
    }

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
    fn resumed(&mut self, event_loop: &ActiveEventLoop) {
        // Windows are made when PHP asks for them, not when the loop starts. What is done here is to take the system
        // clipboard, where there is one that can be had: on Wayland, through winit's own connection.
        if self.clipboard_taken {
            return;
        }
        self.clipboard_taken = true;

        if let Ok(handle) = event_loop.display_handle()
            && let RawDisplayHandle::Wayland(wayland) = handle.as_raw()
        {
            // SAFETY: the pointer is the loop's own connection, which stays open until `run_app` returns; the
            // clipboard is put back to the in-memory one in `exiting`, before that.
            let clipboard = unsafe { WaylandClipboard::new(wayland.display.as_ptr()) };
            self.interaction.set_clipboard(Box::new(clipboard));
        }
    }

    fn exiting(&mut self, _event_loop: &ActiveEventLoop) {
        // Drop the Wayland clipboard while its connection is still there.
        self.interaction
            .set_clipboard(Box::new(MemoryClipboard::default()));
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

                // What the batch left of the user's interaction, then what it asked of it (a focus, a
                // simulated input); each counts its redraws before its events are queued.
                self.interaction.set_time(self.windows.clock.now());
                let ruler = self.windows.text();
                let windows = &self.windows;
                let diagnostics = &self.diagnostics;
                let mut redraw = |window: u64| {
                    diagnostics.redraw_requested(window);
                    if let Some(open) = windows.open.get(&window) {
                        open.window.request_redraw();
                    }
                };
                self.interaction.sync(&self.model, &mut sink, &mut redraw);
                for request in outcome.requests {
                    self.interaction.request(
                        request,
                        &mut self.model,
                        &*ruler,
                        &mut sink,
                        &mut redraw,
                    );
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
            WindowEvent::CursorMoved { position, .. } => {
                let scale = self.scale_of(id);
                let logical = position.to_logical::<f32>(scale);
                self.route(
                    id,
                    InputEvent::PointerMove {
                        x: logical.x,
                        y: logical.y,
                    },
                );
            }
            WindowEvent::CursorLeft { .. } => self.route(id, InputEvent::PointerLeave),
            WindowEvent::MouseInput { state, button, .. } => {
                let (x, y) = self.interaction.pointer(id).unwrap_or((0.0, 0.0));
                let button = button_of(button);
                self.route(
                    id,
                    if state == ElementState::Pressed {
                        InputEvent::PointerDown { x, y, button }
                    } else {
                        InputEvent::PointerUp { x, y, button }
                    },
                );
            }
            WindowEvent::MouseWheel { delta, .. } => {
                let (x, y) = self.interaction.pointer(id).unwrap_or((0.0, 0.0));
                // A wheel turned away from the user shows what is above: the offset goes down.
                let (dx, dy) = match delta {
                    MouseScrollDelta::LineDelta(dx, dy) => (-dx * WHEEL_LINE, -dy * WHEEL_LINE),
                    MouseScrollDelta::PixelDelta(pixels) => {
                        let logical = pixels.to_logical::<f32>(self.scale_of(id));
                        (-logical.x, -logical.y)
                    }
                };
                self.route(id, InputEvent::Scroll { x, y, dx, dy });
            }
            WindowEvent::ModifiersChanged(modifiers) => {
                let state = modifiers.state();
                self.held = Modifiers {
                    shift: state.shift_key(),
                    ctrl: state.control_key(),
                    alt: state.alt_key(),
                    meta: state.super_key(),
                };
                self.route(id, InputEvent::Modifiers(self.held));
            }
            WindowEvent::Ime(Ime::Commit(text)) => self.route(id, InputEvent::Text(text)),
            WindowEvent::Ime(Ime::Preedit(text, cursor)) => {
                self.route(id, InputEvent::Preedit { text, cursor });
            }
            WindowEvent::Ime(Ime::Disabled) => {
                self.route(
                    id,
                    InputEvent::Preedit {
                        text: String::new(),
                        cursor: None,
                    },
                );
            }
            WindowEvent::Ime(Ime::Enabled) => {}
            WindowEvent::KeyboardInput { event, .. } => {
                let press = KeyPress {
                    key: key_name(&event.logical_key),
                    code: match event.physical_key {
                        PhysicalKey::Code(code) => format!("{code:?}"),
                        PhysicalKey::Unidentified(_) => String::new(),
                    },
                    down: event.state == ElementState::Pressed,
                    repeat: event.repeat,
                };
                let typed = (press.down && !(self.held.meta || (self.held.ctrl && !self.held.alt)))
                    .then(|| event.text.as_ref().map(ToString::to_string))
                    .flatten();
                self.route(id, InputEvent::Key(press));
                if let Some(text) = typed {
                    // What the key types goes to a text input that has the focus (control characters are dropped there).
                    self.route(id, InputEvent::Text(text));
                }
            }
            WindowEvent::RedrawRequested => {
                self.interaction.set_time(self.windows.clock.now());
                let drawn = self.windows.draw(id, &self.model, &self.interaction);
                if drawn {
                    self.diagnostics.frame_drawn(id);
                }

                // Still animating (a fading hover, a sweeping progress bar): ask for the next frame, which the
                // window system gives at the monitor's pace, so the animation never runs faster than the display and
                // needs no call from PHP.
                if self.interaction.animating(&self.model, id) {
                    self.diagnostics.redraw_requested(id);
                    if let Some(open) = self.windows.open.get(&id) {
                        open.window.request_redraw();
                    }
                }
            }
            _ => {}
        }
    }
}

/// What `raphp_init` says when the loop cannot be built. winit allows ONE event loop per process for the
/// whole life of the process, even after the first has ended (found in P3.6, F61), so a second window
/// shim in the same process cannot be started: the master has to be restarted.
pub(crate) fn start_error(error: &EventLoopError) -> String {
    match error {
        EventLoopError::RecreationAttempt => "the window backend can be started only once in a process: winit allows one event loop per process, even after the first has ended. Restart the process".to_owned(),
        other => format!("the window system is not available: {other}"),
    }
}

fn button_of(button: MouseButton) -> Button {
    match button {
        MouseButton::Left => Button::Left,
        MouseButton::Middle => Button::Middle,
        MouseButton::Right => Button::Right,
        _ => Button::Other,
    }
}

/// The logical name of a key: the character it types, or the name winit gives a key that types none ("Enter").
fn key_name(key: &Key) -> String {
    match key {
        Key::Named(named) => format!("{named:?}"),
        Key::Character(text) => text.to_string(),
        Key::Unidentified(_) => "Unidentified".to_owned(),
        Key::Dead(_) => "Dead".to_owned(),
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
    TextRenderer::warm_up(); // scan the system's fonts while the window system is being reached
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
        interaction: Interaction::new(),
        held: Modifiers::default(),
        ime_on: std::collections::HashSet::new(),
        clipboard_taken: false,
    };
    if let Err(error) = event_loop.run_app(&mut app) {
        panic!("the window system's event loop failed: {error}");
    }
}
