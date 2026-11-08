#!/bin/sh
# Runs the tests that open a real window (plan P3.6), each in its OWN process: winit allows one event loop per
# process for the whole life of the process (F61), so two of them cannot share one. A window opens on this
# session for a few seconds at a time.
set -eu
cd "$(dirname "$0")/../shim"

cargo test --offline -p raphp-shim --lib --no-run >/dev/null 2>&1 || cargo test --offline -p raphp-shim --lib --no-run
tests=$(cargo test --offline -p raphp-shim --lib window_tests -- --ignored --list 2>/dev/null | sed -n 's/^\(window_tests::[^:]*\): test$/\1/p')
failed=0
for name in $tests; do
    printf '%s ... ' "$name"
    if output=$(cargo test --offline -p raphp-shim --lib "$name" -- --ignored --exact --nocapture 2>&1); then
        echo ok
        printf '%s\n' "$output" | grep -E '^(IDLE WINDOW|RESIZE EVENTS)' || true
    else
        echo FAILED
        printf '%s\n' "$output" | tail -15
        failed=1
    fi
done

cd ..
for name in $(php vendor/bin/phpunit --group window --list-tests 2>/dev/null | sed -n 's/^ - \(.*\)$/\1/p'); do
    printf '%s ... ' "$name"
    short=${name##*\\}
    if output=$(RAPHP_WINDOW_TESTS=1 php vendor/bin/phpunit --group window --filter "$short\$" 2>&1); then
        echo ok
    else
        echo FAILED
        printf '%s\n' "$output" | tail -25
        failed=1
    fi
done

exit "$failed"
