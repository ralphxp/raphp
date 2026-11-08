//! What `raphp_init` is told: the JSON text PHP passes as the configuration. Strict, like the commands: an
//! unknown key is an error.

use std::time::Duration;

use serde_json::Value;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub(crate) enum BackendKind {
    /// No window system: a deterministic clock and pictures in memory.
    Headless,
}

#[derive(Clone, Debug, PartialEq)]
pub(crate) struct Config {
    pub backend: BackendKind,
    /// The shortest time between two frames of a window (the headless stand-in for the monitor's refresh).
    pub frame_interval: Duration,
    /// Physical pixels per logical pixel.
    pub scale: f64,
    /// The most memory all the pictures of the headless backend may take together.
    pub surface_budget_bytes: usize,
}

impl Default for Config {
    fn default() -> Self {
        Self {
            backend: BackendKind::Headless,
            frame_interval: Duration::from_nanos(16_666_667), // 60 per second
            scale: 1.0,
            surface_budget_bytes: 256 << 20,
        }
    }
}

impl Config {
    /// Reads the configuration text: empty means the defaults, otherwise a JSON object with any of
    /// `backend` ("headless"; "window" arrives with P3.6), `refresh_hz` (1 to 1000), `scale` (0.25 to 8) and
    /// `surface_budget_mib` (1 to 4096).
    pub fn parse(text: &str) -> Result<Self, String> {
        let mut config = Self::default();
        if text.trim().is_empty() {
            return Ok(config);
        }

        let Value::Object(map) = serde_json::from_str(text)
            .map_err(|error| format!("the configuration is not valid JSON: {error}"))?
        else {
            return Err("the configuration must be a JSON object".to_owned());
        };

        for (key, value) in &map {
            match key.as_str() {
                "backend" => match value.as_str() {
                    Some("headless") => config.backend = BackendKind::Headless,
                    Some("window") => {
                        return Err("the window backend is not built yet (step P3.6)".to_owned());
                    }
                    _ => return Err("\"backend\" must be \"headless\"".to_owned()),
                },
                "refresh_hz" => {
                    let hz = number(value, 1.0, 1000.0, "refresh_hz")?;
                    config.frame_interval = Duration::from_secs_f64(1.0 / hz);
                }
                "scale" => config.scale = number(value, 0.25, 8.0, "scale")?,
                "surface_budget_mib" => {
                    let mib = number(value, 1.0, 4096.0, "surface_budget_mib")?;
                    if mib.fract() != 0.0 {
                        return Err("\"surface_budget_mib\" must be a whole number".to_owned());
                    }
                    config.surface_budget_bytes = (mib as usize) << 20;
                }
                other => {
                    let shown: String = other.chars().take(64).collect();

                    return Err(format!("the configuration has no setting \"{shown}\""));
                }
            }
        }

        Ok(config)
    }
}

fn number(value: &Value, min: f64, max: f64, name: &str) -> Result<f64, String> {
    match value.as_f64() {
        Some(number) if number.is_finite() && number >= min && number <= max => Ok(number),
        _ => Err(format!("\"{name}\" must be a number from {min} to {max}")),
    }
}
