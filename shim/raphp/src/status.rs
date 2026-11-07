/// What an ABI function returns. Zero is success; every failure is negative, so PHP can test `< 0`.
///
/// A failure's reason is in `raphp_last_error`.
#[repr(i32)]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RaphpStatus {
    /// The call did what was asked.
    Ok = 0,
    /// A null pointer, a length that does not fit, or text that is not UTF-8. The shim is unharmed.
    InvalidArgument = -1,
    /// The shim failed earlier (a panic in a call, or a failure of its UI thread) and refuses every call
    /// except `raphp_last_error` and `raphp_shutdown`. The cause is in `raphp_last_error`.
    Poisoned = -2,
    /// This very call panicked. The shim is now poisoned.
    Panicked = -3,
    /// The request is well formed but not supported (an unknown command).
    Unsupported = -4,
    /// The UI thread did not stop within the time allowed, and was left to end with the process.
    TimedOut = -5,
    /// `raphp_poll`: the next event is larger than the buffer. It stays queued; `raphp_next_event_size` says
    /// how large a buffer it needs.
    BufferTooSmall = -6,
}

/// A failure with its reason, as the guarded calls produce it.
#[derive(Debug)]
pub(crate) struct ShimError {
    pub status: RaphpStatus,
    pub message: String,
}

impl ShimError {
    pub fn new(status: RaphpStatus, message: impl Into<String>) -> Self {
        Self {
            status,
            message: message.into(),
        }
    }

    pub fn invalid(message: impl Into<String>) -> Self {
        Self::new(RaphpStatus::InvalidArgument, message)
    }
}
