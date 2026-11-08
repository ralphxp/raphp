use std::io;
use std::sync::mpsc::{Receiver, Sender, channel};
use std::sync::{Arc, Mutex, PoisonError};
use std::time::Duration;

use crate::diagnostics::Diagnostics;
use crate::events::{Event, EventQueue};
use crate::health::Health;
use crate::model::{EventSink, Model};
use crate::protocol::{Batch, parse_batch};
use crate::status::{RaphpStatus, ShimError};
use crate::ui_thread::{StopOutcome, StopSignal, UiThread};

/// What the UI thread is sent.
pub(crate) enum Msg {
    Batch(Batch),
    /// Look at the stop signal again.
    Wake,
}

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
    /// How commands reach the UI thread; none for a shim whose UI thread is a test body.
    commands: Option<Sender<Msg>>,
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
        Self::launch(events, shutdown_timeout, None, Arc::default(), body)
    }

    /// Starts the real shim: its UI thread applies the commands it is sent to a [`Model`].
    pub fn start_model(events: Arc<EventQueue>, shutdown_timeout: Duration) -> io::Result<Self> {
        let (commands, receiver) = channel::<Msg>();
        let waker = commands.clone();
        let diagnostics = Arc::new(Diagnostics::default());
        let model = Model::new(diagnostics.clone());
        let queue = events.clone();

        Self::launch(
            events,
            shutdown_timeout,
            Some(commands),
            diagnostics,
            move |stop: Arc<StopSignal>| {
                stop.set_waker(move || {
                    let _ = waker.send(Msg::Wake);
                });
                model_loop(&receiver, model, &queue, &stop);
            },
        )
    }

    fn launch<F>(
        events: Arc<EventQueue>,
        shutdown_timeout: Duration,
        commands: Option<Sender<Msg>>,
        diagnostics: Arc<Diagnostics>,
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
            diagnostics,
            commands,
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
        let Some(commands) = &self.commands else {
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
struct QueueSink<'a>(&'a EventQueue);

impl EventSink for QueueSink<'_> {
    fn emit(&mut self, event: Event) {
        // A refusal means the queue is closed (the shim is stopping) or the event was malformed; either way
        // nobody can be told, and the loop is about to end or has a bug the tests would show.
        let _ = self.0.push_or_report(event, "event");
    }
}

/// The UI thread of the shim, until the window backends exist (P3.4, P3.6): it applies batches to the model, in
/// the order they were sent, and ends when asked to stop or when `app.quit` is applied.
fn model_loop(receiver: &Receiver<Msg>, mut model: Model, events: &EventQueue, stop: &StopSignal) {
    let mut sink = QueueSink(events);
    while !stop.requested() {
        match receiver.recv() {
            Ok(Msg::Batch(batch)) => {
                if model.apply_batch(batch, &mut sink).quit {
                    // Asked to stop, by the application's own command: an orderly end, not a failure.
                    stop.request();

                    return;
                }
            }
            Ok(Msg::Wake) => {}
            Err(_) => return,
        }
    }
}
