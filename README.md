# fiber-interop/amp

The native AMPHP v3 adapter for [`fiber-interop/api`](../api). Code written against `async\go()`, `async\await()`,
`async\sleep()`, `async\readable()` and `async\writable()` runs on Amp's own primitives, and the awaitable `go()`
returns is a real `Amp\Future`.

```
composer require fiber-interop/amp
```

Installing the package registers the adapter (Composer `files` autoload calls `async\register(...)`); if it is the only
adapter installed, nothing else is needed.

```php
use function async\{run, go, await, sleep};

// run() is the only entry point: structured, it returns when the tasks started inside it are done
echo run(function () {
    $a = go(function () { sleep(0.1); return 1; });   // Amp\Future
    $b = \Amp\async(fn() => 2);                       // also awaitable by the api
    echo $a->await();                                 // 1, Amp's own await works on api tasks
    return await($a) + await($b);                     // 3
});
```

The api functions throw `NotInRunException` outside `run()`.

## Mapping

| api | Amp |
|---|---|
| `go($f)` | `Amp\async($f)`, returns the `Amp\Future` |
| `service($f)` | `Amp\async($f)` too. The api's `run()` does not wait for services, so a sleeping service does not hold `run()` open (no `unreference()` needed) |
| `await(Future, $t)` | `Future::await(new TimeoutCancellation($t))`, exceptions translated (below) |
| `await(then-able)` | `then()` callbacks, suspended through Revolt |
| `sleep($s)` | `Amp\delay($s)` |
| `readable()` / `writable()` | `EventLoop::onReadable/onWritable` + `Suspension`, one watcher per wait, timeout via `EventLoop::delay` |
| `canAwait($o)` | `Amp\Future` or any object with `then()` |
| unawaited failure | Amp's own: an unobserved failed `Future` reports to the Revolt error handler when it is destroyed |

Timeouts leave the task running (the `TimeoutCancellation` only cancels the wait). Callbacks queued by the adapter
(timers, IO watchers) run in the `\Scope` they were queued from; task bodies run in the scope captured by `go()`
(done by the api).

## In-place rule

`Future::await()` and `Amp\delay()` suspend the current fiber through Revolt, which is only correct in `{main}` (the
outermost `run()`'s wait), in a fiber started by Amp/Revolt, or in a Revolt callback. When `await()`, `sleep()` etc. are
called from a fiber that belongs to somebody else (a plain `new Fiber`, another library's scheduler), the adapter never
suspends it: it observes the future with `map()`/`catch()` and drives an event loop in place from inside that fiber until
the wait is done. Which case applies is decided by `debug_backtrace()` (is the first of "Revolt driver frame" /
"Fiber::start/resume" below us the driver?).

A foreign fiber started from inside a task (the usual case: inside `run()`) cannot resume the running Revolt loop: the
loop's own fiber and the current callback fiber are ancestors on the stack. For that case the adapter runs the same
driver's `tick()` and `invokeCallbacks()` by hand from the foreign fiber (reflection on `AbstractDriver`, on a fresh
callback fiber), exactly as `fiber-interop/revolt` does. Other tasks keep running meanwhile, and the foreign fiber can
await any outer-loop future, including a raw `Amp\async()` one.

## What AMPHP would own

- `src/Adapter.php` (about 200 lines) and `src/register.php`.
- Proposed one-line changes to `amphp/amp` so that the translation disappears:
  - `Amp\TimeoutException implements async\TimeoutException`
  - `Amp\CancelledException implements async\CancelledException`

  (both are marker interfaces in `fiber-interop/api`; the dependency can be a `suggest` or a tiny
  `fiber-interop/contracts` split). Until then the adapter throws `async\Timeout` (previous: the `Amp\CancelledException`)
  when its own timeout fires, and `FiberInterop\Amp\Cancelled` (previous: the Amp exception) when the awaited future itself
  failed with an `Amp\CancelledException`, including its own `TimeoutCancellation`. Every other exception is rethrown unchanged.
- The only Revolt internals used: `Revolt\EventLoop\Internal\DriverSuspension::$run` and `AbstractDriver` internals `tick()`, `invokeCallbacks()`, `createCallbackFiber()` (reflection, to drive the loop
  in place from a foreign fiber; a public re-entrant "run until" in Revolt would remove them) and the `Revolt\EventLoop\Internal\` frame prefix in the backtrace check. A public
  "run the loop from this fiber" in Revolt would remove both.

## Scope state

`$_GET`, `$_POST`, `$_COOKIE` and `$_FILES` are per `\Scope` (see `fiber-interop/scope`). The loop must call `fiber_interop\scope\activate()` whenever it hands control to user code. Revolt has no hook for that, so the adapter's constructor installs a decorating driver (`EventLoop::setDriver()`, once; skipped if a scope-activating decorator, this one or another adapter's, is already installed, or if the loop is already running). It wraps every callback registered through `defer/delay/repeat/onReadable/onWritable/onSignal/queue` as `$scope->run($cb, ...)` with the `\Scope` current at registration (so callbacks, including an `Amp\async()` task's start, run in the scope they were registered from), makes `Suspension::suspend()` call `activate()` when it returns, and calls `activate()` when `run()` returns. Amp's `async()`, `Future::await()` and `delay()` all go through `EventLoop`, so they are covered. `activate()` is one comparison unless the scope really changed. The in-place path (a foreign fiber driving the loop) calls it when its wait ends.

Probes (`php probes/<name>.php`, exit 0 and print `ok`):

- `scope-api.php` passes: two scopes interleave through `async\sleep()`, each keeps its own `$_GET`, the root value is back after `run()`.
- `scope-loop.php` passes: the same with `Amp\async` + `Amp\delay` only. The scope is entered around `Amp\async()`; the driver runs the task in it and keeps it active across `delay()`. Both of these fail with the decorator removed.
- `scope-raw-fiber.php` passes, but not because of this adapter: a plain `new Fiber` has no scope of its own and sees the creator's through the polyfill's "most recently current" fallback.

Caveats: callbacks registered before the adapter was constructed are not wrapped; 

## Semantics: closing a stream

`fclose()` (or destroying the resource) while fibers wait in `readable()`/`writable()` wakes them within ~20 ms and
the waits return normally; the next operation on the closed resource fails. Two parts:

- A timer: while any stream wait is pending, an unreferenced `EventLoop::repeat()` (20 ms) runs `is_resource()` on each
  waited stream; for a gone one it cancels the watcher (errors ignored) and resumes its waiters. No timer exists when
  no stream wait is pending. This bounds the wake-up at 20 ms (and covers the ev/uv drivers, which never raise).
- Recovery: if a stream is closed inside a callback and the loop selects before the timer fires, Revolt's
  `StreamSelectDriver` lets PHP's `stream_select()` fail (`TypeError` "supplied resource is not a valid stream
  resource", or `ValueError` "No stream arrays were passed" when the dead stream was the only one). The decorating driver
  catches exactly that from `run()`, from {main}'s suspension and from the in-place drivers, sweeps once, and runs the
  loop again.

No work is done per callback, per suspend or per loop iteration.

## Limitations

- `go()`'s awaitable is an `Amp\Future`, which has no `then()`: other adapters (Revolt, ReactPHP) can only await it if
  their `canAwait()` knows `Amp\Future` (the fiber-interop/revolt adapter does).
- `fiber-interop/revolt` and this package both drive Revolt; install only one adapter (or call `async\setup()`).
- Nothing here cancels a running task; Amp's `Cancellation` objects stay the tool for that, inside the task.

## Tests

```
composer install
vendor/bin/phpunit
php vendor/fiber-interop/api/bin/fiber-interop-conformance tests/bootstrap.php
```
