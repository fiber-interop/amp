<?php

declare(strict_types=1);

namespace FiberInterop\Amp;

use Amp\CancelledException as AmpCancelled;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\TimeoutException as AmpTimeout;
use async\RejectedException;
use async\Timeout;
use Revolt\EventLoop;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\DriverSuspension;

/**
 * fiber-interop adapter on AMPHP v3 (Amp\async(), Amp\Future, Amp\delay(), Revolt).
 *
 * go() returns the real Amp\Future, so the result of go() can be handed to any Amp API.
 * Waiting strategy (README, "In-place rule"):
 *  - {main} (only the outermost run()'s wait), a fiber created by Amp\async(), or a fiber running inside a Revolt callback: Amp / Revolt suspension.
 *  - any other fiber (one nobody's loop owns): the loop is driven in place, from inside that fiber.
 */
final class Adapter implements \async\Adapter
{
    public function __construct()
    {
        // Per-scope superglobals: make Revolt activate the current \Scope whenever it hands control to user code.
        // Idempotent; skipped if a decorator is installed already (also another adapter's) or the loop is running.
        $driver = EventLoop::getDriver();
        if (!ActivatingDriver::isInstalled($driver) && !$driver->isRunning()) {
            EventLoop::setDriver(new ActivatingDriver($driver));
        }
    }

    public function go(\Closure $f): object
    {
        // The api has already wrapped $f so it runs in the scope captured at go().
        return \Amp\async($f);
    }

    public function service(\Closure $f): object
    {
        // A plain Amp\async() task. The api's run() waits only for its own (non-service) tasks, so a sleeping service
        // does not hold run() open and no unreference() of its watchers is needed (example 20).
        return \Amp\async($f);
    }

    public function canAwait(object $o): bool
    {
        return $o instanceof Future || \method_exists($o, 'then');
    }

    public function await(object $o, float $timeout): mixed
    {
        return $this->doAwait($o, $timeout);
    }

    private function doAwait(object $o, float $timeout): mixed
    {
        if ($o instanceof Future) {
            return $this->canSuspend() ? $this->awaitFuture($o, $timeout) : $this->wait(
                // In-place: Future::await() would suspend the foreign fiber, so observe the future from callbacks.
                static function (\Closure $settle) use ($o) {
                    $o->map(static fn($v) => $settle(true, $v))
                        ->catch(static fn(\Throwable $e) => $settle(false, $e))
                        ->ignore();
                },
                $timeout,
            );
        }

        return $this->wait(static function (\Closure $settle) use ($o) {
            $o->then(
                static fn($v) => $settle(true, $v),
                static fn($r) => $settle(false, $r instanceof \Throwable ? $r : new RejectedException($r)),
            );
        }, $timeout);
    }

    private function awaitFuture(Future $future, float $timeout): mixed
    {
        $cancellation = $timeout === \PHP_FLOAT_MAX ? null : new TimeoutCancellation($timeout);
        try {
            return $future->await($cancellation);
        } catch (AmpCancelled $e) {
            // Our own TimeoutCancellation fired: the future is left running. (Until Amp's exceptions implement the
            // async\ interfaces, translate; a CancelledException the future itself failed with is wrapped likewise.)
            if ($cancellation !== null && $cancellation->isRequested() && $e->getPrevious() instanceof AmpTimeout) {
                throw new Timeout(\sprintf('Timed out after %.3fs', $timeout), 0, $e);
            }
            throw new Cancelled($e->getMessage(), 0, $e);
        }
    }

    public function sleep(float $seconds): void
    {
        $this->doSleep($seconds);
    }

    private function doSleep(float $seconds): void
    {
        if ($this->canSuspend()) {
            \Amp\delay(\max(0.0, $seconds));
            return;
        }
        $this->wait(static function (\Closure $settle) use ($seconds) {
            $id = EventLoop::delay(\max(0.0, $seconds), static fn() => $settle(true, null));
            return static fn() => EventLoop::cancel($id);
        }, \PHP_FLOAT_MAX);
    }

    public function readable(mixed $resource, float $timeout): void
    {
        $this->waitStream('onReadable', $resource, $timeout);
    }

    public function writable(mixed $resource, float $timeout): void
    {
        $this->waitStream('onWritable', $resource, $timeout);
    }

    private function waitStream(string $method, mixed $resource, float $timeout): void
    {
        $this->doWaitStream($method, $resource, $timeout);
    }

    private function doWaitStream(string $method, mixed $resource, float $timeout): void
    {
        $this->wait(static function (\Closure $settle) use ($method, $resource) {
            $id = EventLoop::$method($resource, static fn() => $settle(true, null));
            return static fn() => EventLoop::cancel($id);
        }, $timeout);
    }

    /**
     * May the current context suspend through Revolt's Suspension?
     *
     * Amp\async() fibers are created by Revolt's driver, which cannot be asked directly: debug_backtrace() spans
     * fibers, and walking down from here the first of {Revolt driver frame, Fiber::start/resume} tells who entered
     * the current fiber: the driver (Amp\async(), a Revolt callback) or user code.
     */
    private function canSuspend(): bool
    {
        if (\Fiber::getCurrent() === null) {
            return true;
        }
        foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if ($class === \Fiber::class) {
                return false;
            }
            if (\str_starts_with($class, 'Revolt\\EventLoop\\Internal\\')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param \Closure(\Closure(bool, mixed): void): (\Closure|null) $subscribe registers interest, returns an optional canceller
     */
    private function wait(\Closure $subscribe, float $timeout): mixed
    {
        $suspend = $this->canSuspend();
        $scope = \Scope::getCurrent();
        $suspension = $suspend ? EventLoop::getSuspension() : null;
        $driver = EventLoop::getDriver();
        $done = false;
        $nested = false;
        $suspended = false;
        $result = null;
        $cancel = null;
        $timer = null;

        $settle = static function (bool $ok, mixed $value) use (&$done, &$suspended, &$result, &$cancel, &$timer, $suspension, $driver, &$nested): void {
            if ($done) {
                return;
            }
            $done = true;
            $result = [$ok, $value];
            if ($cancel !== null) {
                $cancel();
            }
            if ($timer !== null) {
                EventLoop::cancel($timer);
            }
            if ($suspension !== null) {
                $suspended && $suspension->resume();
            } else {
                $nested || $driver->stop(); // let the in-place driver loop return
            }
        };
        $inScope = static fn(\Closure $cb): \Closure => static fn(...$a) => $scope->run($cb, ...$a);
        $scoped = static fn(bool $ok, mixed $value) => $scope->run($settle, $ok, $value);

        $cancel = $subscribe($scoped);
        if (!$done && $timeout !== \PHP_FLOAT_MAX) {
            $timer = EventLoop::delay(\max(0.0, $timeout), $inScope(static fn() => $settle(
                false,
                new Timeout(\sprintf('Timed out after %.3fs', $timeout)),
            )));
        }

        if (!$done) {
            if ($suspension !== null) {
                $suspended = true;
                $suspension->suspend();
            } else {
                $this->driveInPlace($done, $nested);
            }
        }

        ActivatingDriver::activate(); // the in-place path returns without a decorated suspend()
        [$ok, $value] = $result;
        if (!$ok) {
            throw $value;
        }
        return $value;
    }

    /**
     * Wait in place from a fiber nobody owns (same approach as fiber-interop/revolt).
     *  - Loop fiber not running: resume it through the closure DriverSuspension uses for {main}.
     *  - Loop fiber running (the foreign fiber was started from a task, so the loop fiber and the current callback
     *    fiber are ancestors on the stack and cannot be resumed): run the driver's own tick()/invokeCallbacks() by
     *    hand from here, on a fresh callback fiber. Other tasks keep running meanwhile.
     * Uses Revolt internals via reflection; a public re-entrant "run until" in Revolt would remove it.
     */
    private function driveInPlace(bool &$done, bool &$nested): void
    {
        $driver = ActivatingDriver::unwrap(EventLoop::getDriver());
        $ref = new \ReflectionClass(AbstractDriver::class);
        if ($ref->getProperty('fiber')->getValue($driver)->isRunning()) {
            $nested = true;
            $prop = static fn(string $n) => $ref->getProperty($n)->getValue($driver);
            $tick = new \ReflectionMethod($driver, 'tick');
            $ref->getMethod('createCallbackFiber')->invoke($driver);
            while (!$done) {
                $ref->getMethod('invokeCallbacks')->invoke($driver);
                if ($done) {
                    return;
                }
                if ($driver->getIdentifiers() === []) {
                    throw new \LogicException('Deadlock: waiting with nothing scheduled');
                }
                $tick->invoke($driver, $prop('microtaskQueue')->isEmpty() && $prop('callbackQueue')->isEmpty());
            }
            return;
        }
        $suspension = EventLoop::getSuspension();
        $run = (new \ReflectionProperty(DriverSuspension::class, 'run'))->getValue(
            \method_exists($suspension, 'getInner') ? $suspension->getInner() : $suspension,
        );
        while (!$done) {
            $interrupt = $run();
            if ($interrupt !== null) {
                $interrupt(); // an uncaught callback exception surfaces here, as with EventLoop::run()
            }
            if (!$done && $driver->getIdentifiers() === []) {
                throw new \LogicException('Deadlock: waiting with nothing scheduled');
            }
        }
    }
}
