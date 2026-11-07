# Raphp benchmarks: soak and performance baseline

Step P2.13, 2026-11-07. This is the record of what was measured, on what, and what it does and does not show. Numbers are from one machine under ordinary desktop load, so treat the speeds as an order of magnitude. The memory numbers repeat closely; the speeds do not (see "How much to trust this").

## How to run it

```
make bench                                          # the full run, about 45 s (not part of `make check`)
php tools/bench/bench.php --json=out.json           # the same, with the raw numbers saved
php tools/bench/bench.php --large-concurrency       # only: several large messages in flight at once
php tools/bench/bench.php --quick                   # a smoke test of the script (a test runs this one)
```

The script starts a real master (this process, using the `Supervisor` and its normal call path), starts one real worker process (`tools/bench/worker.php`, on the real `WorkerRuntime`, with the heartbeat on at 2 s), and exits with 1 if a memory tolerance is exceeded.

Memory is read from `/proc/<pid>/status` (`VmRSS`, `VmHWM` for the peak, `RssAnon` for the private part; the plan named `/proc/self/statm`, which holds the same total but needs the page size) and from `memory_get_usage()` on both sides. `gc_collect_cycles()` runs before every sample, so garbage waiting for the collector is not counted as a leak.

## Machine

| | |
|---|---|
| CPU | Intel Core i5-6300U @ 2.40 GHz (2 cores, 4 threads), a laptop |
| RAM | 15.5 GiB |
| Kernel | Linux 7.2.9 (Fedora 44) |
| PHP | 8.5.12 CLI, opcache on, JIT disabled, no xdebug, `memory_limit` 128M (php.ini) |
| Load | A desktop session (GNOME, VS Code) was running. Load average at the start of the final run: 2.78 / 2.01 / 1.67 on 4 threads |

## The tolerance, and when it was fixed

I fixed these before the first run:

- **Soak:** after a warm-up of the first 10% of the calls, the growth of **resident memory** must be at most **4 MiB** and the growth of **`memory_get_usage()`** at most **256 KiB**, on each side (master and worker), over the remaining 45,000 calls.
- Private resident memory is held to the same 4 MiB.

Added after the first full run, which had passed A to F: the **plateau check (G)** and the **`--large-concurrency` mode (L)**. They came from two things the first run raised: the master's resident memory ended 16 MiB above where it started after the bursts, and I wanted to know whether that was a level or a leak. G repeats the bursts twice more and requires the second repeat to add at most the same tolerances. The first run's numbers were close to the final run's.

## Results

### A. Soak: 50,000 sequential round trips (the one-call-at-a-time workload)

| | |
|---|---|
| Throughput | 5,918 calls/s (8.4 s) |
| Latency (ms) | p50 0.163, p95 0.272, p99 0.364, max 2.685 |

Memory every 5,000 calls (the table is in the script's output; this is its first and last row):

| After | Master RSS | Master private | Master usage | Worker RSS | Worker private | Worker usage |
|---|---|---|---|---|---|---|
| 5,000 (warm) | 56.6 MiB | 15.5 MiB | 2,402 KiB | 55.7 MiB | 14.8 MiB | 968 KiB |
| 50,000 | 56.7 MiB | 15.6 MiB | 2,409 KiB | 55.7 MiB | 14.8 MiB | 968 KiB |

| Growth over the last 45,000 calls | Measured | Tolerance | |
|---|---|---|---|
| Master resident | +144 KiB | 4,096 KiB | pass |
| Worker resident | +0 KiB | 4,096 KiB | pass |
| Master private | +80 KiB | 4,096 KiB | pass |
| Worker private | +0 KiB | 4,096 KiB | pass |
| Master `memory_get_usage()` | +7,024 B | 262,144 B | pass |
| Worker `memory_get_usage()` | +16 B | 262,144 B | pass |

The master's PHP-level growth is about 0.15 bytes per call, which is noise; there is no leak that shows at this scale. **This does not prove there is none at 10 million calls**; it shows none at the scale the plan asked for.

### B. Concurrency: 20,000 calls at each level

| In flight | Calls/s | p50 ms | p95 ms | p99 ms | max ms |
|---|---|---|---|---|---|
| 1 | 5,689 | 0.166 | 0.288 | 0.361 | 2.6 |
| 4 | 10,387 | 0.366 | 0.576 | 0.777 | 2.3 |
| 16 | 14,534 | 0.923 | 1.976 | 3.165 | 5.8 |
| 64 (the cap) | 17,511 | 2.634 | 8.255 | 12.444 | 32.1 |
| 256 | 18,464 | 13.329 | 17.259 | 20.851 | 25.2 |
| 1000 (the waiting list is 1,024) | 12,449 | 63.871 | 140.967 | 164.148 | 170.2 |

Throughput stops growing near 64 to 256 in flight, where master and worker are both busy; more concurrency then only adds waiting. After all levels the master's resident memory was 12 MiB higher than before them, its `memory_get_usage()` 0.8 MB higher, and the worker's unchanged. The plateau check (G) says what that 12 MiB is.

### C. Large payloads (a string echoed: sent, then received)

The line limit is 8 MiB. "PHP peak" is `memory_get_peak_usage(true)`, which only rises, so a row also contains the earlier rows (the master's peak was already 28 MiB after the concurrency levels).

| Payload | Reps | Median ms | MiB/s (both ways) | Master PHP peak | Worker PHP peak |
|---|---|---|---|---|---|
| 1 KiB | 300 | 0.176 | 11.1 | 28 MiB | 4 MiB |
| 64 KiB | 200 | 1.520 | 82.2 | 28 MiB | 4 MiB |
| 1 MiB | 40 | 19.631 | 101.9 | 38 MiB | 12 MiB |
| 4 MiB | 20 | 118.541 | 67.5 | 52 MiB | 24 MiB |
| 7 MiB | 12 | 182.718 | 76.6 | 68 MiB | 39 MiB |
| 8 MiB minus 4 KiB | 8 | 196.717 | 81.3 | 74 MiB | 44 MiB |

- A message needs about **6 times its size** in PHP memory on the master and about **5.5 times** on the worker, while it passes through (the JSON text, the line, the buffer, the decoded value). So an 8 MiB message needs roughly 48 MiB of headroom, and the default `memory_limit` of 128M leaves about a factor of 2.5.
- A 9 MiB payload is refused with `E_PAYLOAD_TOO_LARGE` and the connection still works afterwards.
- After the largest payloads and 1,000 small calls the master's resident memory was 70.9 MiB (boot: 55.9) and the worker's 57.3 MiB (boot: 55.6), with `memory_get_usage()` back to 2.1 MiB and 0.97 MiB: the PHP allocator keeps freed memory for reuse and does not give it all back to the system.

### D. Small calls while a 7 MiB echo is in flight (the R19 numbers)

8 transfers of 7 MiB (median 200 ms each). 8 small calls completed during them, with latency p50 **152 ms**, p95 176 ms (idle p50: 0.163 ms).

So a message that shares the stream with a large one waits for it: about 25 ms per MiB on this machine, at most about 0.2 s at the 8 MiB limit. This is the head-of-line delay the contract in `FRAMEWORK.md` 6.8 describes; it also applies to `cancel` and progress events. The heartbeat is not affected, because liveness is judged by bytes heard (the large message itself is evidence of life). **Not measured:** a `ping`'s own round trip. A ping goes through the same queue and the same stream, so I expect the same delay, but I did not measure it separately.

### G. Plateau: the bursts repeated twice more

| | Master RSS | Master private | Master usage(true) | Worker RSS |
|---|---|---|---|---|
| Before the repeats | 78.0 MiB | 36.8 MiB | 26 MiB | 57.3 MiB |
| After repeat 1 | 71.6 MiB | 30.5 MiB | 26 MiB | 57.3 MiB |
| After repeat 2 | 71.8 MiB | 30.6 MiB | 26 MiB | 57.3 MiB |

Growth from repeat 1 to repeat 2: master +156 KiB resident, +696 B `memory_get_usage()`; worker +0. All within tolerance. So the 16 MiB the master holds after the bursts is a level (the allocator's high-water mark for bursts of 1,000 fibers and 7 to 8 MiB messages), not a leak.

### L. Several 4 MiB echoes in flight at once (`--large-concurrency`; the master's `memory_limit` is lifted for this mode only)

| In flight | Payload sum | Wall | Failed | Master PHP peak | Worker PHP peak |
|---|---|---|---|---|---|
| 1 | 4 MiB | 101 ms | 0 | 24 MiB | 20 MiB |
| 2 | 8 MiB | 220 ms | 0 | 30 MiB | 28 MiB |
| 4 | 16 MiB | 372 ms | 0 | 40 MiB | 28 MiB |
| 8 | 32 MiB | 735 ms | 0 | 56 MiB | 24 MiB |
| 16 | 64 MiB | 1,464 ms | 0 | 88 MiB | 28 MiB |

The master's memory grows with the number of large calls in flight (about 4 MiB per call, linearly); the worker's stays flat because it takes one line at a time. **Nothing limits the bytes.** Every bound in the RPC layer (64 in flight, 1,024 waiting, 256 outbound messages, 256 inbound) counts messages, not bytes. At the defaults, 64 calls of 8 MiB each would hold about 512 MiB, and the master's `memory_limit` would end the process long before. See finding F48.

### E. Read chunk size (both ends in one process, 4 MiB echo, median of 15)

| Chunk | 4 KiB | 16 KiB | **64 KiB (default)** | 256 KiB | 1 MiB |
|---|---|---|---|---|---|
| Median ms | 123.2 | 104.0 | **94.4** | 107.0 | 106.3 |

64 KiB was the fastest; the differences from 16 KiB upward are small (about 10%) and within the noise of this machine. 4 KiB is clearly slower. No reason to change it.

### F. Worker start-up and restart

| | Median | Max |
|---|---|---|
| Cold start (`start()` to ready), 10 starts | 130 ms | 143 ms |
| Restart after SIGKILL with no wait, first crash, 5 starts | 147 ms | 159 ms |
| Restart after SIGKILL with the default 0.1 s wait, first crash, 5 starts | 299 ms | 339 ms |

Starting a worker takes about 130 ms on this machine, so the default first wait of 0.1 s roughly doubles the time to recover from a first crash.

## The master's baseline (R20)

| | Value |
|---|---|
| Master at boot (one worker, nothing called yet) | RSS 55.9 MiB, of which about 15.5 MiB private; `memory_get_usage()` 1.2 MiB |
| Master after all phases (the soak, the bursts, the large messages) | RSS 71.8 MiB, `memory_get_usage()` 2.2 MiB; peak RSS 118.4 MiB, reached while an 8 MiB message was passing |
| A worker, boot and end | RSS 55.7 and 57.3 MiB, private 14.8 and 16.4 MiB |

Most of a process's 56 MiB is not its own: a bare `php -r` already shows 52 MiB resident, of which only about 13 MiB is private (the rest is shared libraries and opcache, shared between processes). Eight workers therefore cost about 8 x 15 MiB of private memory, not 8 x 56 MiB.

**The budget and tolerance that later steps are held to (my proposal; you decide):**

1. **Steady-state growth:** at most 4 MiB resident and 256 KiB `memory_get_usage()` per 45,000 calls (or events) after warm-up, on each side. The headless master measured +144 KiB and +7 KiB.
2. **Plateau:** after a burst, repeating the same burst must not raise resident memory by more than the same 4 MiB (check G).
3. **Idle baseline:** the headless master's private memory at boot is about 15.5 MiB. Later phases may add the GUI's share, and P4.8 must record the master's baseline and growth under UI events and hold it to rules 1 and 2. That UI workload is not measured here.

## Findings

- **F48 (measured, P2.13): the queues are bounded by message count, not bytes.** Several large calls in flight make the master's memory grow linearly with their number, with no limit but PHP's `memory_limit`. The cap on a single message (8 MiB) is the only byte limit. I have **not** changed anything. A byte budget for the outbound queue (and for the bytes of calls in flight) is the obvious fix; it is a design change to decide, not a tuning. It is recorded as risk R26.
- **F49 (measured, P2.13): a message needs about 6 times its size in PHP memory.** With the default `memory_limit` of 128M an 8 MiB message is safe (about 48 MiB), but a worker or master with a limit of 64M or less could die on a message near the cap. The limit of 8 MiB was chosen without this measurement.
- **F50 (measured, P2.13): the recycling threshold reads `VmRSS`, which includes shared pages.** A fresh PHP process shows 52 to 56 MiB resident but only 13 to 16 MiB private. `RecyclePolicy::maxMemoryBytes` compares against `VmRSS` (D41), so a threshold has to be set above that baseline, and the share of other workers' pages is counted in each worker. `RssAnon` (private resident memory) measures a worker's own growth better. I have **not** changed `ProcessMemory`; it is a one-line change in tested code, to decide.

## Checking my unmeasured numbers

| Number | Measured | Verdict |
|---|---|---|
| In-flight cap 64 | Throughput rises until 64 to 256 in flight and then stops; 64 keeps p50 at 2.6 ms | Keep |
| Waiting list 1,024 | 1,000 waiting calls work; p99 rises to 164 ms, throughput drops about a third from the peak | Keep; no evidence either way for another value |
| Outbound buffer 256, inbound 256 | Never reached in these workloads | Unchanged, not shown to be right or wrong; see F48 for what they do not bound |
| 64 KiB read chunk | Fastest of five sizes, within noise from 16 KiB up | Keep |
| 8 MiB line limit | Works up to 8 MiB minus 4 KiB; 9 MiB refused cleanly; costs about 48 MiB of PHP memory and 0.2 s of head-of-line delay at the limit | Keep, with F49 noted |
| Handshake timeout 5 s (10 s for supervised workers) | A worker is ready in 130 to 170 ms | About 30 to 70 times the need, generous |
| Ready timeout 5 s | A restart takes 0.15 to 0.34 s | Generous |
| Restart wait 0.1 s doubling to 10 s, 5 crashes in 60 s | Process start is 130 ms | The first wait roughly equals the start time; the rest is a policy choice, not something to measure |
| Heartbeat 2 s, three misses | Not measured here (behaviour is covered by tests) | Unchanged |

## Filesystem: how long the loop stalls during a large file (P2B.1)

`php tools/bench/filesystem.php` (default 32 MiB; a timer that should tick every millisecond records the longest gap between ticks). Same machine and load as above. Hot cache means the file was just written; "cold" drops the file from the page cache with `dd iflag=nocache` (it has no effect on tmpfs, so only the btrfs cold rows are really cold).

| 32 MiB file, longest stall of the loop | tmpfs, hot | btrfs, hot | btrfs, cold |
|---|---|---|---|
| `openRead()`, slice 0 (a turn after every chunk) | 2.6 ms | 1.2 ms | 3.5 ms |
| `openRead()`, slice 0.5 ms | 1.2 ms | 1.2 ms | 5.0 ms |
| **`openRead()`, slice 2 ms (the default)** | **2.2 ms** | **2.3 ms** | **7.2 ms** |
| `openRead()`, slice 10 ms | 10.2 ms | 10.2 ms | 14.9 ms |
| `openRead()`, slice 50 ms | 18.7 ms | 35.6 ms | 50.3 ms |
| `read()` (the whole file as a string) | 57.5 ms | 89.5 ms | 52.8 ms |
| `file_get_contents()` (the built-in) | 34.8 ms | 37.8 ms | 97.4 ms |
| `fread()` loop without yielding | 16.6 ms | 34.2 ms | 82.2 ms |

Total time for the same rows (hot tmpfs): `openRead()` at the default slice 17.7 ms, `read()` 121.9 ms, `file_get_contents()` 34.7 ms.

- **The stall is about one slice** (the slice plus a chunk's own time), and a slice of 2 ms costs no speed against 10 or 50 ms; a slice of 0 is slower (28 ms against 18 ms for 32 MiB). So 2 ms stays. A cold disk adds the time of one chunk read, which is where the cooperative approach helps most: the built-in read stalled the loop for 97 ms on the cold file, the stream for 7 ms.
- **`read()` is not a good way to read a large file.** Putting the chunks together into one string is a single copy plus fresh memory pages that cannot be spread over turns of the loop: it took 120 to 230 ms for 32 MiB (three to six times the built-in) and still stalled the loop for 50 to 90 ms at the end, as long as or longer than the built-in's own stall. The chunk size makes no difference (65,536 bytes to 32 MiB: 99 to 113 ms). It needs twice the file's size in memory (a 64 MiB file ran out of the 128M `memory_limit`). It is fine for small files; large ones belong to `openRead()`. This is why `Filesystem::read()` says so in its documentation (F52).
- A single `fread()` of one chunk can still block as long as the disk takes to return it. A slow or remote disk stalls the loop for that long.

## Line splitting (P2B.3: `php tools/bench/lines.php`)

| | `LineBuffer` | Amp `splitLines()` |
|---|---|---|
| 16 MB without a newline, 4 KiB chunks | **46 ms** | **2,926 ms** (about 64 times slower) |
| Ordinary text: 195,000 lines in 64 KiB chunks | 34.5 ms | 41.5 ms |

Amp's splitter explodes the whole buffer again for every chunk, so a long line costs time in proportion to its square, and it has no limit on the length of a line. `LineBuffer` scans each chunk once and refuses a line over its limit (F54). On ordinary text the two are equal. The first version of `LineBuffer` (a loop of `strpos`) was 1.6 times slower than Amp on ordinary text (55 ms against 34 ms); switching to `explode()` fixed that.

## How much to trust this

- **One machine, a loaded desktop.** Two full runs (the first before G and L were added) gave speeds that differ by up to 30% (the soak: 4,808 and 5,918 calls/s; 64 in flight: 24,145 and 17,511 calls/s), while the steady-state memory numbers agreed within 0.3 MiB (the burst peaks within 2 to 4 MiB). Use the speeds as a rough scale only.
- **One worker, one master, one stream.** Pools, several streams and a worker making calls back to the master are not measured.
- **The payload is a long string of one letter.** Real data with escapes or deep structure costs more to encode and decode.
- **A synthetic workload.** There are no UI events, no real handlers, no disk or network. P4.8 measures the UI-event workload.
- **The soak is 50,000 calls plus about 150,000 more in the other phases**, which satisfies the plan's 10,000, but it is minutes, not days.
