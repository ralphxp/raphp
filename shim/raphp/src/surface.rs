//! The in-memory picture of a window in the headless backend: RGBA, 8 bits a channel, row after row.

/// Opaque white: what a window shows before anything is drawn on it.
pub(crate) const WHITE: [u8; 4] = [0xFF, 0xFF, 0xFF, 0xFF];

pub(crate) struct Surface {
    pub width: u32,
    pub height: u32,
    pixels: Vec<u8>,
}

impl Surface {
    /// A surface filled with white. The caller has already checked that its size is affordable.
    pub fn new(width: u32, height: u32) -> Self {
        let bytes = Self::bytes_for(width, height);

        Self {
            width,
            height,
            pixels: [WHITE].concat().repeat(bytes / 4),
        }
    }

    /// How much memory a surface of this size takes.
    pub fn bytes_for(width: u32, height: u32) -> usize {
        let bytes = u64::from(width)
            .saturating_mul(u64::from(height))
            .saturating_mul(4);

        usize::try_from(bytes).unwrap_or(usize::MAX)
    }

    pub fn clear(&mut self, color: [u8; 4]) {
        for pixel in self.pixels.chunks_exact_mut(4) {
            pixel.copy_from_slice(&color);
        }
    }

    pub fn pixels(&self) -> &[u8] {
        &self.pixels
    }

    /// Replaces the picture with RGBA bytes of the same size. False (and no change) when the size differs.
    pub fn replace_pixels(&mut self, rgba: &[u8]) -> bool {
        if rgba.len() != self.pixels.len() {
            return false;
        }

        self.pixels.copy_from_slice(rgba);

        true
    }
}
