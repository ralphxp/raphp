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
.PHONY: help install check cs fix stan test rust shim header

help: ## Show this help
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "} {printf "  %-8s %s\n", $$1, $$2}'

install: ## Install PHP dependencies
	composer install --no-interaction

check: cs stan test rust ## Run every check (what CI runs)

cs: ## PHP formatting check (changes nothing)
	$(PHP) $(BIN)/php-cs-fixer fix --dry-run --diff --config=.php-cs-fixer.php

fix: ## Apply PHP formatting
	$(PHP) $(BIN)/php-cs-fixer fix --config=.php-cs-fixer.php

stan: ## PHPStan at the strictest level, plus the forbidden-calls rules
	$(PHP) $(BIN)/phpstan analyse --memory-limit=1G --no-progress

test: ## PHPUnit (all errors visible, fails on deprecations)
	$(PHP) $(BIN)/phpunit

rust: ## rustfmt, clippy and cargo test (skipped until shim/Cargo.toml exists)
	@if [ -f $(RUST_DIR)/Cargo.toml ]; then \
		cd $(RUST_DIR) && cargo fmt --check && cargo clippy --all-targets -- -D warnings && cargo test; \
	else \
		echo "rust: SKIPPED. shim/Cargo.toml does not exist yet (created in P3.1)."; \
		echo "rust: toolchain present: $$(cargo fmt --version | cut -d' ' -f1-2), $$(cargo clippy --version | cut -d' ' -f1-2)"; \
	fi

shim: ## Build the shim (not available until P3.1)
	@echo "shim: not available yet, the crate is created in P3.1"; exit 1

header: ## Generate the C header (not available until P3.1)
	@echo "header: not available yet, cbindgen is set up in P3.1"; exit 1
