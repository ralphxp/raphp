//! The clipboard the text inputs copy to and paste from (plan P3.11b).
//!
//! The interaction state owns one clipboard through this trait. The in-memory one is what the headless backend and the
//! tests use: copy and paste work inside the shim, and a test can look at what was copied. The window backend puts the
//! system clipboard behind the same trait where it can (`docs/protocol/text-input.md` says which systems).

/// Text on a clipboard.
pub(crate) trait Clipboard {
    /// What is on the clipboard, if there is text on it.
    fn get(&mut self) -> Option<String>;

    fn set(&mut self, text: &str);
}

/// A clipboard in the shim's own memory.
#[derive(Default)]
pub(crate) struct MemoryClipboard {
    text: Option<String>,
}

impl Clipboard for MemoryClipboard {
    fn get(&mut self) -> Option<String> {
        self.text.clone()
    }

    fn set(&mut self, text: &str) {
        self.text = Some(text.to_owned());
    }
}

/// The system clipboard on Wayland, through the connection winit already has to the compositor. GNOME offers no
/// data-control protocol, so this is the route that works there (F7).
pub(crate) struct WaylandClipboard(smithay_clipboard::Clipboard);

impl WaylandClipboard {
    /// # Safety
    /// `display` must be the `wl_display` pointer of the connection the window loop uses, and stay valid until this
    /// value is dropped (the window loop drops it before its connection closes).
    pub unsafe fn new(display: *mut std::ffi::c_void) -> Self {
        // SAFETY: forwarded from this function's contract.
        Self(unsafe { smithay_clipboard::Clipboard::new(display) })
    }
}

impl Clipboard for WaylandClipboard {
    fn get(&mut self) -> Option<String> {
        self.0.load().ok()
    }

    fn set(&mut self, text: &str) {
        self.0.store(text.to_owned());
    }
}
