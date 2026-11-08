//! Text for the shim (plan P3.8): measuring it for layout and drawing it, through cosmic-text.
//!
//! One [`TextRenderer`] owns a font system. There are two kinds:
//! - the **bundled** one has one font, Liberation Sans Regular (SIL Open Font License, `assets/fonts`), compiled into
//!   the library. The headless backend uses it, so a headless picture is the same on every machine and no scan of
//!   fonts holds up the UI thread; the golden pictures of the tests are made with it;
//! - the **system** one has the machine's fonts, for real windows. Scanning them takes up to a second in a release
//!   build (cosmic-text says up to ten times that in a debug build), so it is done once per process, by a
//!   background thread started with the window loop, which the first drawing waits for if it is not finished.

use std::sync::{Arc, LazyLock, Mutex, PoisonError};

use cosmic_text::{
    Attrs, Buffer, Color as TextColor, Family, FontSystem, Metrics, Shaping, SwashCache, fontdb,
};
use tiny_skia::{Color, Mask, Paint, Pixmap, Rect, Transform};

use crate::layout::TextMetrics;

/// The size of text and of a line, in logical pixels (my numbers, until a theme exists).
pub(crate) const FONT_SIZE: f32 = 14.0;
pub(crate) const LINE_HEIGHT: f32 = 20.0;

struct Engine {
    fonts: FontSystem,
    swash: SwashCache,
}

pub(crate) struct TextRenderer {
    engine: Mutex<Engine>,
}

static SYSTEM: LazyLock<Arc<TextRenderer>> =
    LazyLock::new(|| Arc::new(TextRenderer::from_fonts(FontSystem::new())));

static BUNDLED: LazyLock<Arc<TextRenderer>> = LazyLock::new(|| {
    Arc::new(TextRenderer::with_font_data(
        include_bytes!("../assets/fonts/LiberationSans-Regular.ttf").to_vec(),
    ))
});

impl TextRenderer {
    fn from_fonts(fonts: FontSystem) -> Self {
        Self {
            engine: Mutex::new(Engine {
                fonts,
                swash: SwashCache::new(),
            }),
        }
    }

    /// The renderer with the system's fonts, shared by the whole process. The first call scans the fonts.
    pub fn system() -> Arc<Self> {
        SYSTEM.clone()
    }

    /// Starts the scan of the system's fonts on another thread, so that the first drawing does not have to wait
    /// for all of it.
    pub fn warm_up() {
        let _ = std::thread::Builder::new()
            .name("raphp-fonts".to_owned())
            .spawn(|| {
                let _ = TextRenderer::system();
            });
    }

    /// The renderer with the bundled font alone, shared by the whole process.
    pub fn bundled() -> Arc<Self> {
        BUNDLED.clone()
    }

    /// A renderer with exactly one font, which is also what "sans-serif" means.
    fn with_font_data(data: Vec<u8>) -> Self {
        let mut database = fontdb::Database::new();
        database.load_font_data(data);
        let family = database
            .faces()
            .next()
            .and_then(|face| face.families.first().map(|(name, _)| name.clone()));
        if let Some(family) = family {
            database.set_sans_serif_family(family);
        }

        Self::from_fonts(FontSystem::new_with_locale_and_db(
            "en-US".to_owned(),
            database,
        ))
    }

    fn engine(&self) -> std::sync::MutexGuard<'_, Engine> {
        self.engine.lock().unwrap_or_else(PoisonError::into_inner)
    }

    fn buffer(engine: &mut Engine, text: &str, scale: f32) -> Buffer {
        let mut buffer = Buffer::new(
            &mut engine.fonts,
            Metrics::new(FONT_SIZE * scale, LINE_HEIGHT * scale),
        );
        buffer.set_size(None, None); // no wrapping: a line is as wide as it is
        buffer.set_text(
            text,
            &Attrs::new().family(Family::SansSerif),
            Shaping::Advanced,
            None,
        );
        buffer.shape_until_scroll(&mut engine.fonts, false);

        buffer
    }

    /// Draws text with its top left corner at `at` (physical pixels), in the style, clipped by `clip` if there is one.
    pub fn paint(
        &self,
        pixmap: &mut Pixmap,
        text: &str,
        at: (f32, f32),
        style: &TextStyle,
        clip: Option<&Mask>,
    ) {
        let mut engine = self.engine();
        let engine = &mut *engine;
        let mut buffer = Self::buffer(engine, text, style.scale);
        let ink = {
            let c = style.color.to_color_u8();
            TextColor::rgba(c.red(), c.green(), c.blue(), c.alpha())
        };

        buffer.draw(
            &mut engine.fonts,
            &mut engine.swash,
            ink,
            |gx, gy, width, height, pixel| {
                let Some(rect) = Rect::from_xywh(
                    at.0 + gx as f32,
                    at.1 + gy as f32,
                    width as f32,
                    height as f32,
                ) else {
                    return;
                };
                let mut paint = Paint::default();
                paint.set_color_rgba8(pixel.r(), pixel.g(), pixel.b(), pixel.a());
                pixmap.fill_rect(rect, &paint, Transform::identity(), clip);
            },
        );
    }
}

/// How text is drawn: at what scale (physical pixels per logical pixel) and in what color.
pub(crate) struct TextStyle {
    pub scale: f32,
    pub color: Color,
}

impl TextMetrics for TextRenderer {
    fn text_size(&self, text: &str) -> (f32, f32) {
        let mut engine = self.engine();
        let buffer = Self::buffer(&mut engine, text, 1.0);
        let width = buffer
            .layout_runs()
            .map(|run| run.line_w)
            .fold(0.0_f32, f32::max);
        let lines = text.split('\n').count();

        (width, lines as f32 * LINE_HEIGHT)
    }
}
