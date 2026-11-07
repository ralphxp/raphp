//! The C ABI. Every function here is `extern "C"`, and every one runs under `catch_unwind`: a panic never
//! crosses into PHP. Strings are UTF-8 with an explicit length. The shim copies every input; PHP owns every
//! buffer it passes in, and the shim never hands out an allocation (FRAMEWORK 7.3).

use std::cell::RefCell;
use std::ffi::c_char;
use std::panic::{AssertUnwindSafe, catch_unwind};
use std::ptr;

use crate::shim::{DEFAULT_SHUTDOWN_TIMEOUT, RaphpShim, Shim, idle_ui_body};
use crate::status::{RaphpStatus, ShimError};
use crate::ui_thread::panic_message;

/// The ABI version: major in the high 16 bits, minor in the low 16. A minor bump adds functions; a major
/// bump breaks. Version 0.x is not stable.
const ABI_MAJOR: u32 = 0;
const ABI_MINOR: u32 = 1;

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
    // SAFETY: PHP passes a pointer from `raphp_init` that it has not shut down (the PHP wrapper enforces it).
    let Some(handle) = (unsafe { shim.as_ref() }) else {
        set_no_handle_error("the shim handle is null");

        return RaphpStatus::InvalidArgument;
    };
    let shim = &handle.shim;

    if shim.health.is_poisoned() {
        return RaphpStatus::Poisoned;
    }

    match catch_unwind(AssertUnwindSafe(|| call(shim))) {
        Ok(Ok(())) => RaphpStatus::Ok,
        Ok(Err(error)) => {
            shim.health.set_error(error.message);

            error.status
        }
        Err(payload) => {
            shim.health.poison(format!(
                "a call into the shim panicked: {}",
                panic_message(&*payload)
            ));

            RaphpStatus::Panicked
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
        // The configuration is checked now and read from step P3.3 on, when there is something to configure.
        unsafe { utf8(cfg, len, "the configuration") }?;

        Shim::start(DEFAULT_SHUTDOWN_TIMEOUT, idle_ui_body).map_err(|error| {
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
