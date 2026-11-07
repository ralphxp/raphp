# Blocking calls: what stops the event loop, and what to use instead

Step P2B.5. Everything in Raphp runs on one event loop per process. While one piece of code is stuck inside a blocking PHP function, nothing else in that process runs: no timer, no other connection, no cancellation, no heartbeat. This page lists the usual culprits, how long they stalled the loop on the development machine, and what to use instead. A later `rlm doctor` (Phase 5) is meant to automate the checks; today this page and the recipes at the end are the tool.

## Why it matters here

- **In the master** the loop also serves the window. A stall is a frozen window. A frame at 60 per second is about 16 ms, so anything longer than a few milliseconds shows.
- **In a worker** the loop answers the master's heartbeat. A worker silent for the heartbeat allowance (2 s by default, three misses) is declared dead and restarted (`FRAMEWORK.md` 6.8). Cancellation also needs the loop: a call cannot be cancelled while the worker is stuck.
- PHP cannot read or write a regular file without blocking: a file is always "ready", so there is nothing for the loop to wait on. The real asynchronous routes need ext-eio or helper processes. Raphp's filesystem service works in small slices instead (decision D44).

## What stalled the loop, measured

`php tools/bench/blocking.php` runs each call with a timer that should tick every millisecond and reports the longest gap. Machine: Intel i5-6300U laptop with a desktop session running, PHP 8.5.12, local btrfs. Treat the numbers as a scale: they move by a factor of two between runs.

| Blocking call | Longest stall | Use instead | Available |
|---|---|---|---|
| `usleep(50_000)`, `time_nanosleep(...)` (and `sleep()`, whole seconds) | the whole wait (50 ms) | `Amp\delay(0.05)`: 2 ms | now |
| `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()` + `fgets()`, backticks | the whole command (56 to 59 ms for a 50 ms command) | `ProcessRunner::run([...])` | now |
| `file_get_contents()` of a local file | 63 to 124 ms for 32 MiB | `Filesystem::openRead()`: 7 ms for 32 MiB. For a small file `Filesystem::read()` | now |
| `file_put_contents()`, `fwrite()` of a large string | 78 to 139 ms for 32 MiB | `Filesystem::write()` writes in slices, but the string must already be in memory | now (no chunked write stream yet) |
| `copy()` | 700 ms for 32 MiB | none yet: a chunked write needs `Filesystem::openWrite()`, which does not exist | not yet |
| `scandir()`, `glob()`, `opendir()` loops | 96 ms and 225 ms for 20,000 entries | `Filesystem::list()`: 27 ms for 20,000 entries (the final sort is one stall); filter the names yourself instead of `glob()` | now |
| `password_hash()`, `hash_pbkdf2()`, big `json_decode()`, big `sort()`, image or archive work, any long loop | the whole computation: 330 ms to 900 ms in the cases measured | a worker: `Supervisor::call()` with a pool; the master only awaits | now |
| `fgets(STDIN)`, `readline()`, `stream_get_line()` on stdin | until a line arrives | `Amp\ByteStream\getStdin()` with `LineReader`: the loop kept ticking between lines (checked) | now |
| `file_get_contents('http://...')`, `curl_exec()`, `fsockopen()`, `stream_socket_client()`, `socket_accept()`, `mysqli`/`PDO` queries | the whole round trip | Raphp's network and HTTP services arrive in Phase 7. Until then the Amp packages (`amphp/socket`, `amphp/http-client`) are the non-blocking route; Raphp does not wrap or test them yet | Phase 7 |
| `gethostbyname()`, `dns_get_record()` | until the resolver answers (seconds when a server is down) | `amphp/dns`; Raphp's wrapper arrives in Phase 8 | Phase 8 |
| `flock()` with `LOCK_EX` / `LOCK_SH` (without `LOCK_NB`) | until the lock is free | `LOCK_NB` in a loop with `Amp\delay()`, or do it in a worker | now (by hand) |
| `pcntl_wait()`, `pcntl_waitpid()` without `WNOHANG`, `proc_close()`, `stream_get_contents()` on a child's pipe | until the child acts | `ProcessRunner`. Never call `waitpid` on a Raphp worker: its exit status belongs to `WorkerHandle` (`FRAMEWORK.md` 5.1) | now |
| `stream_select()`, `stream_set_blocking(..., true)` reads on sockets | until something happens | the loop's own streams (`Amp\ByteStream`), never your own select loop | now |

### Their replacements, measured the same way

| Replacement | Longest stall |
|---|---|
| `Amp\delay(0.05)` | 1 to 2 ms |
| `ProcessRunner::run(['sleep', '0.05'])`, the first call in a process | 25 to 54 ms (loading classes, starting the machinery) |
| `ProcessRunner::run(...)`, later calls | 5 to 11 ms: the fork |
| `Filesystem::openRead()` reading 32 MiB to the end | 2 to 7 ms |
| `Filesystem::list()` of 20,000 entries | 27 to 33 ms |

So the replacements are not free. Starting a command still blocks for the fork: about 5 ms for a master of 55 MiB or 260 MiB, and 18 ms for one of 870 MiB (the page tables are copied). The first use in a process costs more. Do not start commands inside a frame handler. `Filesystem::list()` and `Filesystem::read()` each end with one step that cannot be spread out (a sort, the joining of the pieces): about 1 ms per 1,000 entries and about 2 ms per MiB. A single read of one chunk can also block as long as a slow or remote disk takes to deliver it.

## Calls that are fast but still block

Local metadata calls (`file_exists()`, `is_file()`, `filesize()`, `unlink()`, `rename()`, `mkdir()`, `touch()`, `stat()`) take microseconds to a millisecond on a local disk, and Raphp has no replacement for those that change things (the filesystem service has no delete, rename or mkdir; say if you need them). They are fine in setup code. Keep them out of per-frame code and out of loops over thousands of paths, and expect them to stall for as long as the disk takes on a network drive.

## How to find them in your own code

**1. A stall detector (10 lines).** A timer that ticks every millisecond reveals any blocking call anywhere in the process, including in libraries:

```php
$last = hrtime(true);
EventLoop::repeat(0.001, static function () use (&$last): void {
    $now = hrtime(true);
    if (($now - $last) / 1e6 > 20) {                      // longer than 20 ms
        error_log(sprintf('the event loop was blocked for %.0f ms', ($now - $last) / 1e6));
    }
    $last = $now;
});
```

The test `php/tests/Filesystem/BlockingCallDetectorTest.php` uses the same idea. It tells you that something blocked and for how long, not what; run the suspect code alone to find it.

**2. Forbid the functions in your project.** The `spaze/phpstan-disallowed-calls` extension that Raphp's own `phpstan.neon` uses can ban them, with the replacement in the message:

```neon
parameters:
    disallowedFunctionCalls:
        - function: ['usleep()', 'sleep()', 'time_nanosleep()']
          message: 'Blocks the event loop: use Amp\delay()'
        - function: ['exec()', 'shell_exec()', 'system()', 'passthru()', 'popen()']
          message: 'Blocks the event loop: use Raphp\Process\ProcessRunner'
        - function: ['file_get_contents()', 'file_put_contents()', 'fopen()', 'scandir()', 'glob()']
          message: 'Blocks the event loop: use Raphp\Filesystem\Filesystem (small files: read(), large: openRead())'
          allowIn: ['bin/*']   # setup code before the loop starts may use them
```

Raphp bans `proc_open()` and `Process::start()` this way, outside the classes that own them (D18, D45).

## What this page does not cover

- It was measured on one machine under load, with no network.
- Third-party libraries can block inside their own code. The stall detector is how to find out.
- CPU-bound work in a worker blocks that worker's loop too: a worker doing a 900 ms sort answers no heartbeat and no cancellation for 900 ms. That is fine below the heartbeat allowance and wrong above it; split the work or use a pool and keep each call short.
