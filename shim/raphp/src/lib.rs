//! `libraphp`, the native shim (FRAMEWORK 7). PHP loads it through FFI and talks to it only through the
//! functions in [`abi`]; the contract is written down in `docs/protocol/shim-abi.md`.
//!
//! Rules that hold for the whole crate:
//! - **Nothing unwinds across the C boundary.** Every `extern "C"` function runs under `catch_unwind`.
//! - **The shim never aborts its process** (`panic = "abort"` is not used, and a test enforces it).
//! - **The UI thread never calls PHP.**

mod abi;
mod backend;
mod clock;
mod config;
mod diagnostics;
#[allow(
    dead_code,
    reason = "the producers are the backends of steps P3.4 and P3.6; until then only the tests and the test-hooks build create events. Remove this when they exist."
)]
mod events;
mod health;
mod model;
mod protocol;
mod shim;
mod status;
mod surface;
mod ui_thread;
mod window;

pub use abi::{
    raphp_abi_version, raphp_diagnostics, raphp_event_fd, raphp_headless_advance,
    raphp_headless_frame, raphp_init, raphp_last_error, raphp_max_event_bytes,
    raphp_next_event_size, raphp_poll, raphp_send, raphp_shutdown,
};
pub use shim::RaphpShim;
pub use status::RaphpStatus;

#[cfg(test)]
mod backend_tests;
#[cfg(test)]
mod contract_tests;
#[cfg(test)]
mod event_tests;
#[cfg(test)]
mod headless_tests;
#[cfg(test)]
mod model_tests;
#[cfg(test)]
mod tests;
#[cfg(test)]
mod window_tests;

/// The guard, reachable from the tests with any closure.
#[cfg(test)]
pub(crate) use abi::guarded as abi_test_guard;
