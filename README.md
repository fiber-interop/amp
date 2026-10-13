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
use function async\{go, await, sleep};

$a = go(function () { sleep(0.1); return 1; });   // Amp\Future
$b = \Amp\async(fn() => 2);                       // also awaitable by the api
echo await($a) + await($b);                       // 3
echo $a->await();                                 // 1, Amp's own await works on api tasks
```

## Mapping

| api | Amp |
|---|---|
| `go($f)` | `Amp\async($f)`, returns the `Amp\Future` |
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

`Future::await()` and `Amp\delay()` suspend the current fiber through Revolt, which is only correct in `{main}`,
in a fiber started by Amp/Revolt, or in a Revolt callback. When `await()`, `sleep()` etc. are called from a fiber that
belongs to somebody else (a plain `new Fiber`, another library's scheduler), the adapter never suspends it: it observes
the future with `map()`/`catch()` and drives the Revolt loop in place from inside that fiber until the wait is done.
Which case applies is decided by `debug_backtrace()` (is the first of "Revolt driver frame" / "Fiber::start/resume"
below us the driver?).

## What AMPHP would own

- `src/Adapter.php` (about 200 lines) and `src/register.php`.
- Proposed one-line changes to `amphp/amp` so that the translation disappears:
  - `Amp\TimeoutException implements async\TimeoutException`
  - `Amp\CancelledException implements async\CancelledException`

  (both are marker interfaces in `fiber-interop/api`; the dependency can be a `suggest` or a tiny
  `fiber-interop/contracts` split). Until then the adapter throws `async\Timeout` (previous: the `Amp\CancelledException`)
  when its own timeout fires, and `FiberInterop\Amp\Cancelled` (previous: the Amp exception) when the awaited future itself
  failed with an `Amp\CancelledException`, including its own `TimeoutCancellation`. Every other exception is rethrown unchanged.
- The only Revolt internals used: `Revolt\EventLoop\Internal\DriverSuspension::$run` (reflection, to drive the loop
  in place from a foreign fiber) and the `Revolt\EventLoop\Internal\` frame prefix in the backtrace check. A public
  "run the loop from this fiber" in Revolt would remove both.

## Limitations

- `go()`'s awaitable is an `Amp\Future`, which has no `then()`: other adapters (Revolt, ReactPHP) can only await it if
  their `canAwait()` knows `Amp\Future` (the fiber-interop/revolt adapter does).
- In-place waiting cannot work while the Revolt driver's own loop fiber is the one executing the foreign fiber; that
  throws a `LogicException`.
- `fiber-interop/revolt` and this package both drive Revolt; install only one adapter (or call `async\setup()`).
- Nothing here cancels a running task; Amp's `Cancellation` objects stay the tool for that, inside the task.

## Tests

```
composer install
vendor/bin/phpunit
php vendor/fiber-interop/api/bin/fiber-interop-conformance tests/bootstrap.php
```
