use std::collections::{BTreeSet, HashMap};
use std::io;
use std::sync::mpsc::{Receiver, RecvTimeoutError, Sender, channel};
use std::sync::{Arc, Mutex, OnceLock, PoisonError};
use std::time::Duration;

use crate::backend::{Backend, HeadlessBackend, HeadlessShared};
use crate::config::{BackendKind, Config};
use crate::diagnostics::Diagnostics;
use crate::events::{Event, EventQueue};
use crate::health::Health;
use crate::input::Interaction;
use crate::model::{EventSink, Model};
use crate::protocol::{Batch, parse_batch};
use crate::status::{RaphpStatus, ShimError};
use crate::text::TextRenderer;
use crate::ui_thread::{StopOutcome, StopSignal, UiThread};
use crate::window;

/// What the UI thread is sent.
pub(crate) enum Msg {
    Batch(Batch),
    /// Look at the stop signal again.
    Wake,
    /// Move the headless clock forward, draw what is then due, and answer on `ack` (true when the clock moved).
    /// With 0 it is a barrier: everything sent before it has been applied when the answer comes.
    Advance {
        millis: u64,
        ack: Sender<bool>,
    },
}

/// How messages reach the UI thread: a channel for the headless loop, winit's proxy for the window loop.
#[derive(Clone)]
pub(crate) struct Commands(Arc<dyn Fn(Msg) -> bool + Send + Sync>);

impl Commands {
    fn new(send: impl Fn(Msg) -> bool + Send + Sync + 'static) -> Self {
        Self(Arc::new(send))
    }

    /// An `Err` means the UI thread is gone.
    fn send(&self, message: Msg) -> Result<(), ()> {
        if (self.0)(message) { Ok(()) } else { Err(()) }
    }
}

/// How long `raphp_init` waits for the window loop to start (my number, unmeasured).
const WINDOW_START_WAIT: Duration = Duration::from_secs(10);

/// How long a headless control call waits for the UI thread (my number).
const HEADLESS_WAIT: Duration = Duration::from_secs(10);

/// How long `raphp_shutdown` waits for the UI thread before leaving it behind (my number, unmeasured).
pub(crate) const DEFAULT_SHUTDOWN_TIMEOUT: Duration = Duration::from_secs(3);

/// The shim, as PHP sees it: an opaque handle. Created by `raphp_init`, freed by `raphp_shutdown`.
pub struct RaphpShim {
    pub(crate) shim: Shim,
}

pub(crate) struct Shim {
    pub health: Arc<Health>,
    pub events: Arc<EventQueue>,
    pub diagnostics: Arc<Diagnostics>,
    /// The pictures of the headless windows; none for another backend or a test body.
    headless: Option<Arc<HeadlessShared>>,
    /// How commands reach the UI thread; empty for a shim whose UI thread is a test body.
    commands: OnceLock<Commands>,
    ui: Mutex<Option<UiThread>>,
    shutdown_timeout: Duration,
}

impl Shim {
    /// Starts a shim whose UI thread runs `body`; tests pass bodies that panic, return early or refuse to stop.
    #[cfg(test)]
    pub fn start<F>(shutdown_timeout: Duration, body: F) -> io::Result<Self>
    where
        F: FnOnce(Arc<StopSignal>) + Send + 'static,
    {
        Self::start_with_events(Arc::new(EventQueue::new()?), shutdown_timeout, body)
    }

    /// The same, with an event queue chosen by the caller (the tests use small limits).
    #[cfg(test)]
    pub fn start_with_events<F>(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        body: F,
    ) -> io::Result<Self>
    where
        F: FnOnce(Arc<StopSignal>) + Send + 'static,
    {
        Self::launch(events, shutdown_timeout, None, Arc::default(), None, body)
    }

    /// Starts the real shim: its UI thread applies the commands it is sent to a [`Model`], on the backend the
    /// configuration names. The headless backend draws frames at the pace of a clock a test moves; the window
    /// backend opens real windows, and fails here (with the reason) when there is no window system.
    pub fn start_model(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        config: &Config,
    ) -> io::Result<Self> {
        let diagnostics = Arc::new(Diagnostics::default());
        let model = Model::new(diagnostics.clone());

        match config.backend {
            BackendKind::Headless => {
                Self::start_headless(events, shutdown_timeout, config, diagnostics, model)
            }
            BackendKind::Window => Self::start_window(events, shutdown_timeout, diagnostics, model),
        }
    }

    fn start_headless(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        config: &Config,
        diagnostics: Arc<Diagnostics>,
        model: Model,
    ) -> io::Result<Self> {
        let (sender, receiver) = channel::<Msg>();
        let waker = sender.clone();
        let queue = events.clone();
        let loop_diagnostics = diagnostics.clone();
        let shared = Arc::new(HeadlessShared::default());
        let backend: Box<dyn Backend + Send> =
            Box::new(HeadlessBackend::new(config, shared.clone()));
        let commands = Commands::new(move |message| sender.send(message).is_ok());

        Self::launch(
            events,
            shutdown_timeout,
            Some(commands),
            diagnostics,
            Some(shared),
            move |stop: Arc<StopSignal>| {
                stop.set_waker(move || {
                    let _ = waker.send(Msg::Wake);
                });
                ui_loop(&receiver, model, backend, &queue, &loop_diagnostics, &stop);
            },
        )
    }

    fn start_window(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        diagnostics: Arc<Diagnostics>,
        model: Model,
    ) -> io::Result<Self> {
        let (ready_sender, ready) = channel();
        let queue = events.clone();
        let loop_diagnostics = diagnostics.clone();

        let shim = Self::launch(
            events,
            shutdown_timeout,
            None,
            diagnostics,
            None,
            move |stop: Arc<StopSignal>| {
                window::run(model, queue, loop_diagnostics, stop, &ready_sender);
            },
        )?;

        // The loop is built on the UI thread; wait for it to say whether there is a window system.
        match ready.recv_timeout(WINDOW_START_WAIT) {
            Ok(Ok(proxy)) => {
                let commands = Commands::new(move |message| proxy.send_event(message).is_ok());
                let _ = shim.commands.set(commands);

                Ok(shim)
            }
            Ok(Err(reason)) => {
                shim.stop();

                Err(io::Error::other(reason))
            }
            Err(_) => {
                shim.stop();

                Err(io::Error::other(
                    "the window system did not answer in time (is a display available?)",
                ))
            }
        }
    }

    fn launch<F>(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        commands: Option<Commands>,
        diagnostics: Arc<Diagnostics>,
        headless: Option<Arc<HeadlessShared>>,
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
        let slot = OnceLock::new();
        if let Some(commands) = commands {
            let _ = slot.set(commands);
        }

        Ok(Self {
            health,
            events,
            diagnostics,
            headless,
            commands: slot,
            ui: Mutex::new(Some(ui)),
            shutdown_timeout,
        })
    }

    /// Checks the shape of a batch and hands it to the UI thread, which applies it. Problems inside the
    /// commands come back as events; a batch that is not a batch at all is refused here.
    pub fn send(&self, batch: &str) -> Result<(), ShimError> {
        #[cfg(any(test, feature = "test-hooks"))]
        if let Some(result) = self.test_command(batch) {
            return result;
        }

        let parsed = parse_batch(batch).map_err(ShimError::invalid)?;
        let Some(commands) = self.commands.get() else {
            return Err(ShimError::new(
                RaphpStatus::Unsupported,
                "this shim has no command loop",
            ));
        };

        commands.send(Msg::Batch(parsed)).map_err(|_| {
            ShimError::new(RaphpStatus::Closed, "the shim has stopped taking commands")
        })
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

    /// Headless only: moves the clock forward by `millis` and waits until the UI thread has applied everything
    /// sent before and drawn the frames that are then due. With 0 it is just that wait: a barrier, which is
    /// what tests use to know that a batch has taken effect. This one call waits for the UI thread, on purpose.
    pub fn headless_advance(&self, millis: u64) -> Result<(), ShimError> {
        let Some(commands) = self.commands.get().filter(|_| self.headless.is_some()) else {
            return Err(ShimError::new(
                RaphpStatus::Unsupported,
                "this shim does not use the headless backend",
            ));
        };

        let (ack, answer) = channel();
        commands.send(Msg::Advance { millis, ack }).map_err(|_| {
            ShimError::new(RaphpStatus::Closed, "the shim has stopped taking commands")
        })?;

        match answer.recv_timeout(HEADLESS_WAIT) {
            Ok(true) => Ok(()),
            Ok(false) => Err(ShimError::new(
                RaphpStatus::Unsupported,
                "this shim does not use the headless backend",
            )),
            Err(RecvTimeoutError::Timeout) => Err(ShimError::new(
                RaphpStatus::TimedOut,
                "the UI thread did not answer in time (is it waiting for the events to be read?)",
            )),
            Err(RecvTimeoutError::Disconnected) => Err(ShimError::new(
                RaphpStatus::Closed,
                "the UI thread ended before it answered",
            )),
        }
    }

    /// Headless only: copies the picture of a window (RGBA, rows from the top) into `out` and returns its size in
    /// pixels and its full length in bytes.
    pub fn headless_frame(
        &self,
        window: u64,
        out: &mut [u8],
    ) -> Result<(u32, u32, usize), ShimError> {
        let Some(shared) = &self.headless else {
            return Err(ShimError::new(
                RaphpStatus::Unsupported,
                "this shim does not use the headless backend",
            ));
        };

        shared
            .read(window, out)
            .ok_or_else(|| ShimError::invalid(format!("there is no window {window}")))
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
#[cfg(test)]
pub(crate) fn idle_ui_body(stop: Arc<StopSignal>) {
    while !stop.wait(Duration::from_millis(250)) {}
}

/// Where the model's events go: into the shim's queue. A producer may have to wait for PHP (O7), and the
/// wait ends when the queue is closed.
pub(crate) struct QueueSink<'a>(pub(crate) &'a EventQueue);

impl EventSink for QueueSink<'_> {
    fn emit(&mut self, event: Event) {
        // A refusal means the queue is closed (the shim is stopping) or the event was malformed; either way
        // nobody can be told, and the loop is about to end or has a bug the tests would show.
        let _ = self.0.push_or_report(event, "event");
    }
}

/// Draws the frames that are due. A window that asked for a redraw gets a frame at once if it has not had one
/// within the frame interval, and otherwise as soon as the interval has passed; however many times it asked in
/// between, it gets one. Returns when the next pending frame becomes due, if one is waiting.
fn draw_due(
    pending: &mut BTreeSet<u64>,
    last_frame: &mut HashMap<u64, Duration>,
    backend: &mut dyn Backend,
    model: &Model,
    interaction: &Interaction,
    diagnostics: &Diagnostics,
) -> Option<Duration> {
    let interval = backend.frame_interval();
    let now = backend.clock().now();
    let mut next_due: Option<Duration> = None;

    for window in pending.clone() {
        let due = last_frame
            .get(&window)
            .map_or(Duration::ZERO, |last| *last + interval);
        if now >= due {
            backend.draw(window, model, interaction);
            diagnostics.frame_drawn(window);
            last_frame.insert(window, now);
            pending.remove(&window);

            // Something on the window is still changing by itself (a hover fading, a progress bar sweeping): it
            // needs the next frame, which the pace above allows one interval after this one.
            if interaction.animating(model, window) {
                diagnostics.redraw_requested(window);
                pending.insert(window);
            }
        } else {
            next_due = Some(next_due.map_or(due, |earlier| earlier.min(due)));
        }
    }

    next_due
}

/// The UI thread of the shim until the window backend exists (P3.6): it applies batches to the model in the
/// order they were sent, draws frames on the backend at its pace, and ends when asked to stop or when `app.quit`
/// is applied.
fn ui_loop(
    receiver: &Receiver<Msg>,
    mut model: Model,
    mut backend: Box<dyn Backend + Send>,
    events: &EventQueue,
    diagnostics: &Diagnostics,
    stop: &StopSignal,
) {
    let mut sink = QueueSink(events);
    let mut interaction = Interaction::new();
    let ruler = TextRenderer::bundled(); // the headless backend's font, so that what is hit is what is drawn
    let mut pending: BTreeSet<u64> = BTreeSet::new();
    let mut last_frame: HashMap<u64, Duration> = HashMap::new();

    while !stop.requested() {
        interaction.set_time(backend.clock().now());
        let next_due = draw_due(
            &mut pending,
            &mut last_frame,
            backend.as_mut(),
            &model,
            &interaction,
            diagnostics,
        );

        let message = match next_due {
            None => receiver.recv().ok(),
            Some(due) => {
                let remaining = due.saturating_sub(backend.clock().now());
                match backend.clock().sleep_budget(remaining) {
                    Some(budget) => match receiver.recv_timeout(budget) {
                        Ok(message) => Some(message),
                        Err(RecvTimeoutError::Timeout) => continue,
                        Err(RecvTimeoutError::Disconnected) => None,
                    },
                    // Time does not pass by itself: only a message (an advance of the clock) can change anything.
                    None => receiver.recv().ok(),
                }
            }
        };

        match message {
            None => return,
            Some(Msg::Wake) => {}
            Some(Msg::Batch(batch)) => {
                interaction.set_time(backend.clock().now());
                let outcome = model.apply_batch(batch, &mut sink, backend.as_mut());
                pending.extend(outcome.dirty);

                // What the batch left of the user's interaction (a destroyed widget had the focus), then what
                // the batch asked of it (a focus, a simulated click): each tells the loop which windows need a
                // frame, and the request is counted before the events that follow from it.
                let mut redraw = |window: u64| {
                    diagnostics.redraw_requested(window);
                    pending.insert(window);
                };
                interaction.sync(&model, &mut sink, &mut redraw);
                for request in outcome.requests {
                    interaction.request(request, &mut model, &*ruler, &mut sink, &mut redraw);
                }

                pending.retain(|window| model.has_window(*window));
                last_frame.retain(|window, _| model.has_window(*window));

                if outcome.quit {
                    // Asked to stop, by the application's own command: an orderly end, not a failure.
                    stop.request();

                    return;
                }
            }
            Some(Msg::Advance { millis, ack }) => {
                let moved = backend.advance_clock(millis);
                interaction.set_time(backend.clock().now());
                draw_due(
                    &mut pending,
                    &mut last_frame,
                    backend.as_mut(),
                    &model,
                    &interaction,
                    diagnostics,
                );
                let _ = ack.send(moved);
            }
        }
    }
}
