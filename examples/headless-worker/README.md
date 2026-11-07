# headless-worker

A command-line program (no window) whose **master** process calls a **worker** process. It shows, with the framework's public pieces only:

- a call with **progress** (the worker reports each step, the master draws a bar);
- **cancellation** (Ctrl-C cancels the job in flight; the worker stops where it is);
- a **crash** (the worker kills itself with SIGKILL in the middle of a call; the supervisor restarts it and calls work again);
- an **orderly end** (the master says `bye`, the worker exits).

```
php examples/headless-worker/main.php demo              # the scripted tour (default): all of the above
php examples/headless-worker/main.php job [--steps=N]   # one job; press Ctrl-C to cancel it
php examples/headless-worker/main.php crash             # only the crash and the recovery
```

Output for people goes to stdout. Logs, and whatever the worker writes to stderr, go to stderr.

## Where to look

| File | What it shows |
|---|---|
| `main.php` | Assembling a master: logger, `ProcessSpawner`, `Supervisor`, `WorkerDefinition`, `Kernel`; the supervisor is stopped by a shutdown hook. |
| `src/Application.php` | Calling the worker: `Supervisor::call()` with `onProgress` and a `Cancellation`; Ctrl-C reaches the call through `Kernel::awaitStop()`; surviving `E_WORKER_CRASHED`. |
| `src/ConsoleEvents.php` | A PSR-14 listener that prints the supervisor's `WorkerEvent`s. |
| `worker.php` | The worker's entry: `WorkerArguments`, a PSR-11 container, `AttributeHandlers`, `WorkerRuntime`. |
| `src/ReportService.php` | The worker's methods, declared with `#[RpcMethod]`; progress through `ProgressReporter`, cancellation through `Cancellation`. |

## Notes

- Ctrl-C goes to the whole process group, the worker included. The worker ignores SIGINT on purpose (decision D42), so the master alone decides what happens to it: it cancels the job, says `bye`, and the worker leaves in order. If the worker died of the SIGINT it would look like a crash and be restarted.
- The restart delay in `main.php` is 0.2 s so the demonstration is short. The framework's default is my own unmeasured number (FRAMEWORK D41).
- `php/tests/Examples/HeadlessWorkerExampleTest.php` runs this example for real (a job, Ctrl-C, a crash, a bad command line).
