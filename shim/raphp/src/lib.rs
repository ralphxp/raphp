//! `libraphp`, the native shim (FRAMEWORK 7). PHP loads it through FFI and talks to it only through the
//! functions in [`abi`]; the contract is written down in `docs/protocol/shim-abi.md`.
//!
//! Rules that hold for the whole crate:
//! - **Nothing unwinds across the C boundary.** Every `extern "C"` function runs under `catch_unwind`.
//! - **The shim never aborts its process** (`panic = "abort"` is not used, and a test enforces it).
//! - **The UI thread never calls PHP.**

mod abi;
mod health;
mod shim;
mod status;
mod ui_thread;

pub use abi::{raphp_abi_version, raphp_init, raphp_last_error, raphp_send, raphp_shutdown};
pub use shim::RaphpShim;
pub use status::RaphpStatus;

#[cfg(test)]
mod tests;

/// The guard, reachable from the tests with any closure.
#[cfg(test)]
pub(crate) use abi::guarded as abi_test_guard;
