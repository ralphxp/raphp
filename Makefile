# Developer commands. Run `make help`.
SHELL := /usr/bin/env bash
.SHELLFLAGS := -eu -o pipefail -c

# Composer probes git for the project version. On this mount git refuses (dubious
# ownership, F19/F20), so pin it to keep the output clean.
export COMPOSER_ROOT_VERSION ?= 0.0.0

PHP ?= php
BIN := vendor/bin
RUST_DIR := shim

.DEFAULT_GOAL := help
.PHONY: help install check fast cs fix stan test test-fast bench rust shim shim-test glibc header window-test

help: ## Show this help
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-8s %s\n", $$1, $$2}'

install: ## Install PHP dependencies
	composer install --no-interaction

check: cs stan shim shim-test test rust ## Run every check (what CI runs)

fast: cs stan test-fast ## Quick check while working: skips the slow tests (about 6 s of tests instead of 31)

cs: ## PHP formatting check (changes nothing)
	$(PHP) $(BIN)/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php

fix: ## Apply PHP formatting
	$(PHP) $(BIN)/php-cs-fixer fix --config=.php-cs-fixer.php

stan: ## PHPStan at the strictest level, plus the forbidden-calls rules
	$(PHP) $(BIN)/phpstan analyse --memory-limit=1G --no-progress

test: ## PHPUnit (all errors visible, fails on deprecations)
	$(PHP) $(BIN)/phpunit

test-fast: ## PHPUnit without the tests tagged slow (real processes, timers, heartbeats)
	$(PHP) $(BIN)/phpunit --exclude-group slow

bench: ## Soak and performance baseline (about 45 s; not part of check). See docs/BENCHMARKS.md
	$(PHP) tools/bench/bench.php

rust: ## rustfmt, clippy, cargo test, and a check that the committed C header is up to date
	cd $(RUST_DIR) && cargo fmt --check && cargo clippy --workspace --all-targets -- -D warnings && cargo test --workspace && cargo run -q -p xtask -- header --check

window-test: ## Tests that open a real window on THIS session (each in its own process; never part of check)
	tools/window-test.sh

shim: ## Build the release shim (libraphp.so) and report the glibc version it needs
	cd $(RUST_DIR) && cargo build --release -p raphp-shim
	@$(MAKE) --no-print-directory glibc

shim-test: ## Build the debug shim with the test-only commands, which the PHP tests of the binding load
	cd $(RUST_DIR) && cargo build -p raphp-shim --features test-hooks

glibc: ## Print the highest GLIBC_x.y symbol version the built shim requires (R23, D21) and record it
	@tools/shim-glibc.sh $(RUST_DIR)/target/release/libraphp.so $(RUST_DIR)/target/release/glibc-floor.txt

header: ## Regenerate the C header shim/include/raphp.h from the Rust sources (commit the result)
	cd $(RUST_DIR) && cargo run -q -p xtask -- header
