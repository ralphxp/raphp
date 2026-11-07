#!/usr/bin/env bash
# Prints the highest GLIBC_x.y symbol version a shared library requires, and records it in a file.
# Usage: tools/shim-glibc.sh <library.so> <record-file>
# The figure is the distributable floor of the shim (FRAMEWORK 7.9, R23, D21): a machine with an older
# glibc cannot load the library.
set -euo pipefail

library="${1:?usage: shim-glibc.sh <library.so> <record-file>}"
record="${2:?usage: shim-glibc.sh <library.so> <record-file>}"

if [ ! -f "$library" ]; then
    echo "glibc: $library does not exist: run make shim" >&2
    exit 1
fi

floor="$(objdump -T "$library" | grep -o 'GLIBC_[0-9][0-9.]*' | sort -V | tail -1 || true)"
host="$(ldd --version | sed -n '1p' | grep -o '[0-9][0-9.]*$')"

if [ -z "$floor" ]; then
    echo "glibc: $library requires no versioned glibc symbol (host glibc $host)"
    echo "none" > "$record"
else
    echo "glibc: the shim requires $floor (built on glibc $host)"
    echo "$floor" > "$record"
fi
