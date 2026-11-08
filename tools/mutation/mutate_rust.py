#!/usr/bin/env python3
"""Focused mutation testing for the Rust shim (the PHP counterpart is mutate.py).

    python3 tools/mutation/mutate_rust.py mutants.json

`mutants.json` is a list of [file relative to shim/raphp/src, text to find, replacement, label]. The text
must occur exactly once. Each change is made in a COPY of `shim/` and `docs/protocol/` (never in the repository), and
`cargo test -p raphp-shim --lib` must fail for the mutant to count as killed. A mutant that does not
compile is "not applicable". The shim's dependencies (winit and softbuffer since P3.6) take minutes to build,
so set RAPHP_MUTATION_TARGET to a directory to keep them between runs; each mutant then builds in seconds.
"""
import json
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


# The test modules that can notice a change to a source file. A mutant is first run against these only (a few seconds
# instead of the whole suite); one that survives is run once more against everything before it is called a survivor.
FOCUS = {
    'input.rs': ['input_tests', 'headless_tests', 'draw_tests', 'text_input_tests'],
    'input/editing.rs': ['text_input_tests', 'edit_tests', 'draw_tests', 'input_tests'],
    'editing.rs': ['text_input_tests', 'edit_tests', 'draw_tests', 'input_tests'],
    'edit.rs': ['edit_tests', 'text_input_tests'],
    'clipboard.rs': ['text_input_tests'],
    'canvas.rs': ['canvas_tests', 'draw_tests', 'model_tests'],
    'draw.rs': ['draw_tests'],
    'text.rs': ['draw_tests'],
    'layout.rs': ['layout_tests', 'input_tests', 'draw_tests'],
    'model.rs': ['model_tests', 'input_tests', 'text_input_tests', 'contract_tests', 'headless_tests', 'window_tests'],
    'protocol.rs': ['model_tests', 'input_tests'],
    'backend.rs': ['backend_tests', 'headless_tests', 'draw_tests'],
    'surface.rs': ['backend_tests', 'headless_tests', 'draw_tests'],
    'config.rs': ['backend_tests', 'headless_tests'],
    'clock.rs': ['backend_tests', 'headless_tests'],
    'window.rs': ['window_tests'],
    'shim.rs': ['headless_tests', 'tests', 'contract_tests'],
    'events.rs': ['event_tests', 'contract_tests'],
    'diagnostics.rs': ['contract_tests', 'headless_tests', 'model_tests'],
}


def cargo_test(work: Path, timeout: int, modules: list[str] | None = None) -> tuple[int, str]:
    os = __import__('os')
    env = {**os.environ, 'CARGO_TARGET_DIR': os.environ.get('RAPHP_MUTATION_TARGET') or str(work / 'target')}
    command = ['cargo', 'test', '-p', 'raphp-shim', '--lib', '--offline']
    if modules:
        command += ['--', *[f'{module}::' for module in modules]]
    try:
        done = subprocess.run(command, cwd=work / 'shim', env=env, capture_output=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return 124, 'timeout'
    return done.returncode, (done.stdout + done.stderr).decode('utf-8', 'replace')


def main() -> int:
    mutants = json.load(open(sys.argv[1]))
    work = Path(tempfile.mkdtemp(prefix='raphp-rust-mutation-'))
    started = time.time()
    try:
        shutil.copytree(ROOT / 'shim', work / 'shim', ignore=shutil.ignore_patterns('target'))
        (work / 'docs').mkdir()
        shutil.copytree(ROOT / 'docs/protocol', work / 'docs/protocol')  # the golden files some tests read
        code, output = cargo_test(work, 600)
        if code != 0:
            print('The baseline fails without any mutation, so no result would mean anything:\n' + output[-1500:])
            return 1
        print(f'baseline ok ({time.time() - started:.0f}s to copy, build and run it once)')

        killed = survived = not_applicable = 0
        for file, old, new, label in mutants:
            path = work / 'shim/raphp/src' / file
            original = path.read_text()
            if original.count(old) != 1:
                print(f'  BAD MUTANT (the text occurs {original.count(old)} times): {label}')
                not_applicable += 1
                continue
            path.write_text(original.replace(old, new))
            try:
                code, output = cargo_test(work, 120, FOCUS.get(file))
                compiled = 'error[E' not in output and 'error: could not compile' not in output
                if code == 0 and compiled and FOCUS.get(file):
                    # It passed the focused tests: make sure no other test notices it before it is called a survivor.
                    path.write_text(original.replace(old, new))
                    code, output = cargo_test(work, 120)
            finally:
                path.write_text(original)
            if 'error[E' in output or 'error: could not compile' in output:
                not_applicable += 1
                print(f'  NOT COMPILABLE  {label}')
            elif code == 0:
                survived += 1
                print(f'  SURVIVED  {label}')
            else:
                killed += 1
        print(f'\nmutants: {len(mutants)}   killed: {killed}   SURVIVED: {survived}   not applicable: {not_applicable}   time: {time.time() - started:.0f}s')
    finally:
        shutil.rmtree(work, ignore_errors=True)
    return 0


if __name__ == '__main__':
    sys.exit(main())
