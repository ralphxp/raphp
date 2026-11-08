//! The C ABI. Every function here is `extern "C"`, and every one runs under `catch_unwind`: a panic never
//! crosses into PHP. Strings are UTF-8 with an explicit length. The shim copies every input; PHP owns every
//! buffer it passes in, and the shim never hands out an allocation (FRAMEWORK 7.3).

use std::cell::RefCell;
use std::ffi::{c_char, c_int};
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::ptr;

use std::sync::Arc;

use crate::config::Config;
use crate::events::{EventQueue, MAX_EVENT_BYTES, Polled};
use crate::shim::{DEFAULT_SHUTDOWN_TIMEOUT, RaphpShim, Shim};
use crate::status::{RaphpStatus, ShimError};
use crate::ui_thread::panic_message;

/// The ABI version: major in the high 16 bits, minor in the low 16. A minor bump adds functions; a major
/// bump breaks. Version 0.x is not stable.
const ABI_MAJOR: u32 = 0;
const ABI_MINOR: u32 = 4;

thread_local! {
    /// The reason the last call failed when there is no handle to keep it on (a failed `raphp_init`).
    static NO_HANDLE_ERROR: RefCell<String> = const { RefCell::new(String::new()) };
}

fn set_no_handle_error(message: impl Into<String>) {
    NO_HANDLE_ERROR.with(|error| *error.borrow_mut() = message.into());
}

/// Turns the text PHP passed (pointer and length) into a `&str`.
///
/// # Safety
/// `text` must point to `len` readable bytes, or be null with `len` 0.
unsafe fn utf8<'a>(text: *const c_char, len: usize, what: &str) -> Result<&'a str, ShimError> {
    if text.is_null() {
        return if len == 0 {
            Ok("")
        } else {
            Err(ShimError::invalid(format!(
                "{what}: a null pointer with a length of {len}"
            )))
        };
    }

    if isize::try_from(len).is_err() {
        return Err(ShimError::invalid(format!(
            "{what}: the length {len} is too large"
        )));
    }

    // SAFETY: the caller guarantees `len` readable bytes at `text`.
    let bytes = unsafe { std::slice::from_raw_parts(text.cast::<u8>(), len) };

    std::str::from_utf8(bytes).map_err(|_| ShimError::invalid(format!("{what}: not valid UTF-8")))
}

/// Runs `call` on a live shim: refuses a null or poisoned handle, and turns a panic into `Panicked` and
/// a poisoned shim. This is the guard every handle-taking function goes through.
pub(crate) fn guarded(
    shim: *const RaphpShim,
    call: impl FnOnce(&Shim) -> Result<(), ShimError>,
) -> RaphpStatus {
    match run_guarded(shim, false, |shim| call(shim).map(|()| 0)) {
        Ok(_) => RaphpStatus::Ok,
        Err(status) => status,
    }
}

/// The same for functions that must keep working on a poisoned shim, because what they give is the way
/// to learn what happened: reading the events (including the last one, `shim.failed`) and their size.
/// Returns the call's number, or the status that stopped it.
pub(crate) fn guarded_allow_poisoned(
    shim: *const RaphpShim,
    call: impl FnOnce(&Shim) -> Result<isize, ShimError>,
) -> Result<isize, RaphpStatus> {
    run_guarded(shim, true, call)
}

fn run_guarded(
    shim: *const RaphpShim,
    allow_poisoned: bool,
    call: impl FnOnce(&Shim) -> Result<isize, ShimError>,
) -> Result<isize, RaphpStatus> {
    // SAFETY: PHP passes a pointer from `raphp_init` that it has not shut down (the PHP wrapper enforces it).
    let Some(handle) = (unsafe { shim.as_ref() }) else {
        set_no_handle_error("the shim handle is null");

        return Err(RaphpStatus::InvalidArgument);
    };
    let shim = &handle.shim;

    if !allow_poisoned && shim.health.is_poisoned() {
        return Err(RaphpStatus::Poisoned);
    }

    match catch_unwind(AssertUnwindSafe(|| call(shim))) {
        Ok(Ok(number)) => Ok(number),
        Ok(Err(error)) => {
            shim.health.set_error(error.message);

            Err(error.status)
        }
        Err(payload) => {
            shim.health.poison(format!(
                "a call into the shim panicked: {}",
                panic_message(&*payload)
            ));

            Err(RaphpStatus::Panicked)
        }
    }
}

/// The ABI version, `major << 16 | minor`. PHP compares it before anything else.
#[unsafe(no_mangle)]
pub extern "C" fn raphp_abi_version() -> u32 {
    // Cannot panic, but the rule is "every function", and the cost is nothing.
    catch_unwind(|| (ABI_MAJOR << 16) | ABI_MINOR).unwrap_or(0)
}

/// Creates the shim and starts its UI thread. `cfg` is UTF-8 text of `len` bytes (it may be empty, and may
/// be null when `len` is 0). Returns null on failure, with the reason in `raphp_last_error(NULL, ...)` for
/// the calling thread.
///
/// # Safety
/// `cfg` must point to `len` readable bytes, or be null with `len` 0.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_init(cfg: *const c_char, len: usize) -> *mut RaphpShim {
    let created = catch_unwind(AssertUnwindSafe(|| {
        // SAFETY: forwarded from this function's contract.
        // SAFETY: forwarded from this function's contract.
        let text = unsafe { utf8(cfg, len, "the configuration") }?;
        let config = Config::parse(text).map_err(ShimError::invalid)?;

        let events = EventQueue::new().map_err(|error| {
            ShimError::new(
                RaphpStatus::Panicked,
                format!("cannot create the event descriptor: {error}"),
            )
        })?;

        Shim::start_model(Arc::new(events), DEFAULT_SHUTDOWN_TIMEOUT, &config).map_err(|error| {
            ShimError::new(
                RaphpStatus::Panicked,
                format!("cannot start the UI thread: {error}"),
            )
        })
    }));

    match created {
        Ok(Ok(shim)) => Box::into_raw(Box::new(RaphpShim { shim })),
        Ok(Err(error)) => {
            set_no_handle_error(error.message);

            ptr::null_mut()
        }
        Err(payload) => {
            set_no_handle_error(format!("raphp_init panicked: {}", panic_message(&*payload)));

            ptr::null_mut()
        }
    }
}

/// Sends a batch of commands (UTF-8, `len` bytes). Returns at once; the UI thread does the work.
///
/// # Safety
/// `batch` must point to `len` readable bytes, or be null with `len` 0.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_send(
    shim: *const RaphpShim,
    batch: *const c_char,
    len: usize,
) -> RaphpStatus {
    guarded(shim, |shim| {
        // SAFETY: forwarded from this function's contract.
        let batch = unsafe { utf8(batch, len, "the batch") }?;

        shim.send(batch)
    })
}

/// Copies the last error's text (UTF-8, not NUL-terminated) into `out`, at most `cap` bytes and never
/// cutting a character, and returns the **full** length of the message, so PHP can ask again with a
/// larger buffer. With a null `shim` it is the reason the calling thread's last `raphp_init` failed. With a
/// null `out` it only returns the length. It works on a poisoned shim.
///
/// # Safety
/// `out` must point to `cap` writable bytes, or be null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_last_error(
    shim: *const RaphpShim,
    out: *mut c_char,
    cap: usize,
) -> usize {
    catch_unwind(AssertUnwindSafe(|| {
        // SAFETY: PHP passes a live handle or null.
        let message = match unsafe { shim.as_ref() } {
            Some(handle) => handle.shim.health.error(),
            None => NO_HANDLE_ERROR.with(|error| error.borrow().clone()),
        };

        if !out.is_null() && cap > 0 {
            let mut end = message.len().min(cap);
            while !message.is_char_boundary(end) {
                end -= 1;
            }

            // SAFETY: the caller guarantees `cap` writable bytes, and `end <= cap`.
            unsafe { ptr::copy_nonoverlapping(message.as_ptr(), out.cast::<u8>(), end) };
        }

        message.len()
    }))
    .unwrap_or(0)
}

/// Stops the UI thread (waiting a bounded time) and frees the handle, which is invalid afterwards
/// whatever the result. Returns `RAPHP_STATUS_TIMED_OUT` if the UI thread had to be left behind. It works
/// on a poisoned shim.
///
/// # Safety
/// `shim` must come from `raphp_init` and not have been shut down.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_shutdown(shim: *mut RaphpShim) -> RaphpStatus {
    if shim.is_null() {
        set_no_handle_error("the shim handle is null");

        return RaphpStatus::InvalidArgument;
    }

    // SAFETY: from `raphp_init`, and not yet freed (the contract above).
    let handle = unsafe { Box::from_raw(shim) };

    catch_unwind(AssertUnwindSafe(|| handle.shim.stop())).unwrap_or(RaphpStatus::Panicked)
}

/// The descriptor that becomes readable when events are queued. It belongs to the shim (close-on-exec, valid
/// until `raphp_shutdown`): PHP watches it and never closes it. **Clear it first** (read 8 bytes), **then
/// drain with `raphp_poll` until it returns 0**: that order cannot lose a wake-up. Returns -1 for a null
/// handle. Works on a poisoned shim.
///
/// # Safety
/// `shim` must come from `raphp_init` and not have been shut down.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_event_fd(shim: *const RaphpShim) -> c_int {
    match guarded_allow_poisoned(shim, |shim| Ok(shim.events.wake_fd() as isize)) {
        Ok(fd) => fd as c_int,
        Err(_) => -1,
    }
}

/// Copies whole events (one JSON line each, every line ending in `\n`) into `out`, as many as fit, in order.
/// Returns the bytes written; `0` when nothing is queued; `RAPHP_STATUS_BUFFER_TOO_SMALL` when the **next**
/// event is larger than `cap`: then nothing was copied, the event stays at the head of the queue (later
/// events never overtake it), the wake-up is raised again, and `raphp_next_event_size` says how large a
/// buffer it needs. Any other negative value is a `RaphpStatus`. Works on a poisoned shim, so the last
/// event (`shim.failed`) can be read.
///
/// # Safety
/// `out` must point to `cap` writable bytes (it may be null when `cap` is 0).
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_poll(shim: *const RaphpShim, out: *mut c_char, cap: usize) -> isize {
    let result = guarded_allow_poisoned(shim, |shim| {
        if out.is_null() && cap > 0 {
            return Err(ShimError::invalid(
                "the output buffer is null but its capacity is not 0",
            ));
        }

        let mut empty = [0u8; 0];
        let buffer: &mut [u8] = if cap == 0 {
            &mut empty
        } else {
            // SAFETY: the caller guarantees `cap` writable bytes at `out`.
            unsafe { std::slice::from_raw_parts_mut(out.cast::<u8>(), cap) }
        };

        match shim.events.poll(buffer) {
            Polled::Empty => Ok(0),
            Polled::Written(bytes) => Ok(isize::try_from(bytes).unwrap_or(isize::MAX)),
            Polled::TooSmall(_) => Ok(RaphpStatus::BufferTooSmall as isize),
        }
    });

    match result {
        Ok(number) => number,
        Err(status) => status as isize,
    }
}

/// The size in bytes, newline included, of the event at the head of the queue; 0 when it is empty or the
/// handle is null. After `RAPHP_STATUS_BUFFER_TOO_SMALL` this is the buffer size to poll with. Works on a
/// poisoned shim.
///
/// # Safety
/// `shim` must come from `raphp_init` and not have been shut down.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_next_event_size(shim: *const RaphpShim) -> usize {
    guarded_allow_poisoned(shim, |shim| {
        Ok(isize::try_from(shim.events.next_event_size()).unwrap_or(isize::MAX))
    })
    .map_or(0, |size| usize::try_from(size).unwrap_or(0))
}

/// The largest single event the shim will queue, newline included. A larger one is replaced by a small
/// `event.too_large` event, so a poll buffer of this size can never be too small.
#[unsafe(no_mangle)]
pub extern "C" fn raphp_max_event_bytes() -> usize {
    catch_unwind(|| MAX_EVENT_BYTES).unwrap_or(0)
}

/// Copies the diagnostic counters (one line of JSON: batches, commands applied and failed, widgets, and per
/// window `redraws_requested` and `frames_drawn`) into `out`, at most `cap` bytes, and returns the **full**
/// length, like `raphp_last_error`. Read-only; it works on a poisoned shim and does not wait for the UI thread.
/// The per-window redraw counters stay at 0 until drawing exists (step P3.8). With a null `shim` it returns 0.
///
/// # Safety
/// `out` must point to `cap` writable bytes, or be null.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_diagnostics(
    shim: *const RaphpShim,
    out: *mut c_char,
    cap: usize,
) -> usize {
    let json = guarded_allow_poisoned(shim, |shim| {
        let json = shim.diagnostics.to_json();
        if !out.is_null() && cap > 0 {
            let mut end = json.len().min(cap);
            while !json.is_char_boundary(end) {
                end -= 1;
            }

            // SAFETY: the caller guarantees `cap` writable bytes, and `end <= cap`.
            unsafe { ptr::copy_nonoverlapping(json.as_ptr(), out.cast::<u8>(), end) };
        }

        Ok(isize::try_from(json.len()).unwrap_or(isize::MAX))
    });

    json.map_or(0, |length| usize::try_from(length).unwrap_or(0))
}

/// Headless backend only: moves its clock forward by `millis`, then waits until the UI thread has applied everything
/// sent before this call and drawn the frames that are then due. With 0 it is only that wait: a barrier, which is how a
/// test knows that a batch has taken effect. **This is the one call that waits for the UI thread.** A shim on another
/// backend returns `RAPHP_STATUS_UNSUPPORTED`; if the UI thread is stuck (for example waiting for events nobody reads)
/// it gives up after 10 seconds with `RAPHP_STATUS_TIMED_OUT`.
///
/// # Safety
/// `shim` must come from `raphp_init` and not have been shut down.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_headless_advance(
    shim: *const RaphpShim,
    millis: u64,
) -> RaphpStatus {
    guarded(shim, |shim| shim.headless_advance(millis))
}

/// Headless backend only: copies the picture of a window (RGBA, 8 bits a channel, rows from the top) into `out`, at most
/// `cap` bytes, and returns the **full** length in bytes, which is `width * height * 4`; the size in pixels goes to
/// `width` and `height` when they are not null. A negative value is a `RaphpStatus`: `UNSUPPORTED` on another backend,
/// `INVALID_ARGUMENT` for a window that does not exist. Reads what was last drawn, so call `raphp_headless_advance`
/// with 0 first to be sure the frame you want has been drawn. Works on a poisoned shim.
///
/// # Safety
/// `out` must point to `cap` writable bytes (or be null with `cap` 0); `width` and `height` must be null or writable.
#[unsafe(no_mangle)]
pub unsafe extern "C" fn raphp_headless_frame(
    shim: *const RaphpShim,
    window: u64,
    out: *mut u8,
    cap: usize,
    width: *mut u32,
    height: *mut u32,
) -> isize {
    let result = guarded_allow_poisoned(shim, |shim| {
        if out.is_null() && cap > 0 {
            return Err(ShimError::invalid(
                "the output buffer is null but its capacity is not 0",
            ));
        }

        let mut empty = [0u8; 0];
        let buffer: &mut [u8] = if cap == 0 {
            &mut empty
        } else {
            // SAFETY: the caller guarantees `cap` writable bytes at `out`.
            unsafe { std::slice::from_raw_parts_mut(out, cap) }
        };

        let (w, h, length) = shim.headless_frame(window, buffer)?;
        // SAFETY: the caller guarantees that a non-null `width` or `height` is writable.
        unsafe {
            if let Some(width) = width.as_mut() {
                *width = w;
            }
            if let Some(height) = height.as_mut() {
                *height = h;
            }
        }

        Ok(isize::try_from(length).unwrap_or(isize::MAX))
    });

    match result {
        Ok(length) => length,
        Err(status) => status as isize,
    }
}
