#!/usr/bin/env python3
"""Mutation testing for Raphp: change the code one small piece at a time and check that a test fails.

A mutant that no test notices ("SURVIVED") points at a missing or weak test.

    python3 tools/mutation/mutate.py mutants.json [--tests "php/tests/Rpc php/tests/Ipc"] [-v]

`mutants.json` is a list of entries `[file, old, new, label]` (and optionally a fifth element, the
PHPUnit --filter for that mutant): `file` is relative to php/src, `old` must appear in it, and the
first occurrence is replaced by `new`.

What makes it quick (the old version ran a whole test directory for every mutant):
- each mutant runs only the tests that belong to the file it changes (see FOCUS, or the fifth element);
  if the filter matches no test, it falls back to the directory of that file;
- PHPUnit stops at the first failing test (--stop-on-defect), so a mutant that is caught costs
  seconds, not the whole run;
- the baseline (unmodified code, same tests) must pass first, otherwise every mutant would look killed.

It works on a COPY of php/src, php/tests, docs/protocol and vendor in a temporary directory, never in
the repository, and removes it when done. Exit code 0 even with survivors: read the list.
"""
import json
import re
import shutil
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

# Tests that exercise a source file, when the file's own name is not enough.
# The value is a PHPUnit --filter pattern (a regular expression over class and test names).
FOCUS = {
    'Rpc/RpcPeer.php': 'RpcPeer',
    'Rpc/Internal/Slots.php': 'Slots|RpcPeer',
    'Rpc/Internal/WaitList.php': 'WaitList|Slots|OutboundQueue',
    'Rpc/Internal/OutboundQueue.php': 'OutboundQueue|RpcPeer',
    'Rpc/Internal/MethodInvoker.php': 'AttributeHandlers',
    'Rpc/Internal/ParameterBinding.php': 'AttributeHandlers',
    'Rpc/AttributeHandlers.php': 'AttributeHandlers',
    'Rpc/RpcPeerOptions.php': 'RpcValues|RpcPeer',
    'Rpc/Middleware/RetryMiddleware.php': 'RetryMiddleware',
    'Rpc/Middleware/TimingMiddleware.php': 'MiddlewareTest|RpcPeerTest',
    'Rpc/Middleware/TraceIdMiddleware.php': 'MiddlewareTest|RpcPeerProtocol',
    'Rpc/Middleware/ErrorMappingMiddleware.php': 'MiddlewareTest|RpcPeerTest',
    'Ipc/Codec/JsonLineCodec.php': 'JsonLine|GoldenFiles|EnvelopeReader',
    'Ipc/Codec/JsonLineDecoder.php': 'JsonLine|GoldenFiles',
    'Ipc/Message/Validation.php': 'Messages|EnvelopeReader',
    'Ipc/Transport/StreamTransport.php': 'StreamTransport',
    'Process/WorkerHandle.php': 'ProcessSpawner',
    'Process/OutputCapture.php': 'ProcessSpawner',
    'Process/ProcessSpawner.php': 'ProcessSpawner|ProcessOrphan',
    'Process/OrphanGuard.php': 'WorkerSpec|ProcessOrphan|ProcessSpawner',
    'Process/ChannelFactory.php': 'ChannelFactory|ProcessSpawner|ProcessOrphan',
    'Runtime/Libc/ParentDeathSignal.php': 'ProcessOrphan',
    'Supervisor/Internal/Member.php': 'SupervisorTest',
    'Supervisor/Supervisor.php': 'SupervisorTest',
    'Supervisor/RestartPolicy.php': 'SupervisorValues|SupervisorTest',
    'Supervisor/RecyclePolicy.php': 'SupervisorValues',
    'Supervisor/WorkerDefinition.php': 'SupervisorValues',
    'Supervisor/Internal/ProcessMemory.php': 'SupervisorValues',
    'Worker/WorkerArguments.php': 'WorkerArguments|WorkerRuntime',
    'Worker/WorkerRuntime.php': 'WorkerRuntime|ProcessOrphan|ProcessSpawner',
    'Process/InheritedChannel.php': 'InheritedChannel|WorkerRuntime',
    'Core/Kernel.php': 'Kernel',
    'Async/BackgroundTasks.php': 'BackgroundTasks',
    'Process/ProcessRunner.php': 'ProcessRunnerTest',
    'Process/Internal/ExecutableFinder.php': 'ProcessRunnerTest',
    'Runtime/Accelerators.php': 'AcceleratorsTest',
    'Runtime/Accelerator.php': 'AcceleratorsTest',
    'Stream/LineBuffer.php': 'LineBufferTest|LineReaderTest',
    'Stream/LineReader.php': 'LineReaderTest',
    'Stream/ProgressStream.php': 'ProgressStreamTest',
    'Filesystem/CooperativeFilesystem.php': 'CooperativeFilesystemTest|TimeSliceTest|BlockingCallDetector',
    'Filesystem/CooperativeReadStream.php': 'CooperativeFilesystemTest|BlockingCallDetector',
    'Filesystem/Internal/TimeSlice.php': 'TimeSliceTest|CooperativeFilesystemTest',
}


def default_filter(file: str) -> str:
    return FOCUS.get(file) or Path(file).stem


def phpunit(work: Path, args: list[str], timeout: int) -> tuple[int, str]:
    try:
        done = subprocess.run(['php', 'vendor/bin/phpunit', '--no-progress', *args], cwd=work, capture_output=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return 124, 'timeout'
    return done.returncode, (done.stdout + done.stderr).decode('utf-8', 'replace')


def main() -> int:
    arguments = sys.argv[1:]
    verbose = '-v' in arguments
    tests_dirs = 'php/tests'
    if '--tests' in arguments:
        tests_dirs = arguments[arguments.index('--tests') + 1]
    mutants = json.load(open(next(a for a in arguments if a.endswith('.json'))))

    work = Path(tempfile.mkdtemp(prefix='raphp-mutation-'))
    started = time.time()
    try:
        (work / 'php').mkdir()
        (work / 'docs').mkdir()
        shutil.copytree(ROOT / 'php/src', work / 'php/src')
        shutil.copytree(ROOT / 'php/tests', work / 'php/tests')
        shutil.copytree(ROOT / 'docs/protocol', work / 'docs/protocol')
        shutil.copytree(ROOT / 'vendor', work / 'vendor', symlinks=True)
        for name in ('composer.json', 'composer.lock', 'phpunit.xml'):
            shutil.copy(ROOT / name, work / name)
        subprocess.run(['composer', 'dump-autoload', '-q'], cwd=work, capture_output=True)

        code, output = phpunit(work, tests_dirs.split(), 600)
        if code != 0:
            print('The baseline fails without any mutation, so no result would mean anything:\n' + output[-1500:])
            return 1
        print(f'baseline ok ({time.time() - started:.0f}s to copy and run it once)', flush=True)

        killed = survived = missing = 0
        slowest = []
        for entry in mutants:
            file, old, new, label = entry[:4]
            path = work / 'php/src' / file
            original = path.read_text()
            if original.count(old) < 1:
                print(f'  N/A       {label}  (pattern not found in {file})', flush=True)
                missing += 1
                continue

            pattern = entry[4] if len(entry) > 4 else default_filter(file)
            path.write_text(original.replace(old, new, 1))
            began = time.time()
            try:
                code, output = phpunit(work, ['--stop-on-defect', f'--filter={pattern}'], 60)
                if 'No tests executed' in output or 'No tests found' in output:
                    directory = 'php/tests/' + file.split('/')[0]
                    code, output = phpunit(work, ['--stop-on-defect', directory], 60)
            finally:
                path.write_text(original)

            took = time.time() - began
            slowest.append((took, label))
            if code == 0:
                print(f'  SURVIVED  {label}', flush=True)
                survived += 1
            else:
                killed += 1
                if verbose:
                    print(f'  killed    {label}  ({took:.1f}s)', flush=True)

        slowest.sort(reverse=True)
        print(f'\nmutants: {killed + survived + missing}   killed: {killed}   SURVIVED: {survived}   not applicable: {missing}   '
              f'time: {time.time() - started:.0f}s')
        if verbose and slowest:
            print('slowest: ' + ', '.join(f'{t:.1f}s {l}' for t, l in slowest[:3]))
        return 0
    finally:
        shutil.rmtree(work, ignore_errors=True)


if __name__ == '__main__':
    sys.exit(main())
