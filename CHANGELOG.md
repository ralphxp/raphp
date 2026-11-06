# Changelog

All notable changes are recorded here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/) once a first release exists.

## [Unreleased]

### Changed
- Renamed the project from the code name niphy to Raphp (namespace `Raphp\`, `libraphp`, `raphp.json`, `RAPHP_*` variables, CLI `rlm`). Spike code under `spikes/` keeps the old names.

### Added
- Composition root (step P1.5): typed configuration loaded from `raphp.json` plus environment overrides, a PSR-20 UTC clock, a PSR-3 Monolog logger with an enum log level, and a PSR-11 PHP-DI container.
- Design document, implementation plan, Phase 0 spike report and progress tracker under `docs/`.
- Repository scaffold: `LICENSE` (MIT), `README.md`, `CONTRIBUTING.md`, `.editorconfig`, `composer.json` and the directory layout.
