#!/usr/bin/env python3
"""Runs only the tests (and the cheap static checks) for what changed since the last commit.

    python3 tools/test-changed.py            # run them
    python3 tools/test-changed.py --list     # only say what would run

What "changed" means: files that git reports as modified, added or untracked, so the work of the step in hand and
nothing older. A source file is matched to its tests by name and place:

- `php/src/<Area>/<Name>.php` -> `php/tests/<Area>/<Name>Test.php` if that exists, else the whole `php/tests/<Area>/`;
- a changed PHP test file is run itself; `examples/` and `tools/bench/` -> `php/tests/Examples`;
- `shim/raphp/src/<file>.rs` -> the Rust test modules that can notice it (the table `FOCUS` of
  `tools/mutation/mutate_rust.py`), and a changed `*_tests.rs` -> its own module; golden pictures and fonts -> `draw_tests`;
- a change to something every test shares (`phpunit.xml`, `composer.*`, `php/tests/Support`, `php/tests/Fixtures`,
  `Cargo.toml`) runs the whole suite of that language, because there is no narrower honest answer.

The whole suite (`make check`) is for a phase checkpoint, and for when someone asks for it.
"""
import importlib.util
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools' / 'mutation'))


def changed_files() -> list[str]:
    out = subprocess.run(['git', 'status', '--porcelain', '--untracked-files=all'], cwd=ROOT, capture_output=True, text=True, check=True).stdout
    files = []
    for line in out.splitlines():
        path = line[3:].split(' -> ')[-1].strip().strip('"')
        files.append(path)

    return files


def rust_focus() -> dict[str, list[str]]:
    spec = importlib.util.spec_from_file_location('mutate_rust', ROOT / 'tools' / 'mutation' / 'mutate_rust.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    return module.FOCUS


def plan(files: list[str]) -> dict:
    php_paths: set[str] = set()
    php_all = False
    rust_modules: set[str] = set()
    rust_all = False
    php_src: list[str] = []
    focus = rust_focus()

    for f in files:
        p = Path(f)
        if f in ('phpunit.xml', 'composer.json', 'composer.lock') or f.startswith(('php/tests/Support/', 'php/tests/Fixtures/')):
            php_all = True
        elif f.startswith('php/src/') and p.suffix == '.php':
            php_src.append(f)
            area = p.parts[2]
            test = ROOT / 'php/tests' / area / (p.stem + 'Test.php')
            php_paths.add(str(test.relative_to(ROOT)) if test.exists() else f'php/tests/{area}')
            if len(p.parts) > 4:  # php/src/Area/Sub/Name.php: the tests of the sub-area, else of the area
                sub = ROOT / 'php/tests' / area / p.parts[3]
                if sub.exists():
                    php_paths.add(str(sub.relative_to(ROOT)))
        elif f.startswith('php/tests/') and p.name.endswith('Test.php'):
            php_paths.add(f)
        elif f.startswith(('examples/', 'tools/bench/')) and p.suffix == '.php':
            php_paths.add('php/tests/Examples')
            php_src.append(f)
        elif f.startswith('shim/raphp/src/') and p.suffix == '.rs':
            if p.name.endswith('_tests.rs') or p.name == 'tests.rs':
                rust_modules.add(p.stem)
            else:
                rust_modules.update(focus.get(p.name, []))
                if p.name not in focus:
                    rust_all = True
        elif f.startswith(('shim/raphp/tests/', 'shim/raphp/assets/')):
            rust_modules.add('draw_tests')
        elif f in ('shim/Cargo.toml', 'shim/raphp/Cargo.toml', 'shim/Cargo.lock'):
            rust_all = True

    # `tests` is a module of its own (shim/raphp/src/tests.rs), whose name is also a part of every other module's path.
    return {'php_paths': sorted(php_paths), 'php_all': php_all, 'php_src': php_src, 'rust_modules': sorted(rust_modules), 'rust_all': rust_all,
            'rust_touched': bool(rust_modules) or rust_all or any(f.startswith('shim/') for f in files)}


def run(command: list[str], cwd: Path = ROOT) -> int:
    print('$ ' + ' '.join(command), flush=True)

    return subprocess.run(command, cwd=cwd).returncode


def main() -> int:
    only_list = '--list' in sys.argv
    files = changed_files()
    p = plan(files)
    steps: list[tuple[str, list[str], Path]] = []

    if p['php_all']:
        steps.append(('all PHP tests (a shared file changed)', ['php', 'vendor/bin/phpunit'], ROOT))
    elif p['php_paths']:
        steps.append(('PHP tests of what changed', ['php', 'vendor/bin/phpunit', *p['php_paths']], ROOT))
    if p['php_src'] or p['php_all'] or p['php_paths']:
        php_files = [f for f in files if f.endswith('.php')]
        if php_files:
            steps.append(('PHP formatting of the changed files', ['php', 'vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--diff', '--config=.php-cs-fixer.php', *php_files], ROOT))
        steps.append(('PHPStan', ['php', 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G', '--no-progress'], ROOT))
    if p['rust_all']:
        steps.append(('all Rust tests (a shared file changed)', ['cargo', 'test', '--offline', '-p', 'raphp-shim', '--lib'], ROOT / 'shim'))
    elif p['rust_modules']:
        steps.append(('Rust tests of what changed', ['cargo', 'test', '--offline', '-p', 'raphp-shim', '--lib', '--', *[m + '::' for m in p['rust_modules']]], ROOT / 'shim'))
    if p['rust_touched']:
        steps.append(('rustfmt', ['cargo', 'fmt', '--check'], ROOT / 'shim'))
        steps.append(('clippy', ['cargo', 'clippy', '--offline', '--workspace', '--all-targets', '--', '-D', 'warnings'], ROOT / 'shim'))

    if not steps:
        print('Nothing changed since the last commit that has tests.')

        return 0

    print(f"{len(files)} changed file(s). Running only what belongs to them (the whole suite is `make check`).")
    for title, command, cwd in steps:
        print(f'\n== {title}')
        if only_list:
            print('   ' + ' '.join(command))

            continue
        if run(command, cwd) != 0:
            print(f'\nFAILED: {title}')

            return 1

    if not only_list:
        print('\nOK: everything that belongs to the changes passed.')

    return 0


if __name__ == '__main__':
    sys.exit(main())
