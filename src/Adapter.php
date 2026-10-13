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
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
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
    public function go(\Closure $f): object
    {
        // The api has already wrapped $f so it runs in the scope captured at go().
        return $this->onDriver(static fn() => \Amp\async($f));
    }

    public function service(\Closure $f): object
    {
        // A plain Amp\async() task. The api's run() waits only for its own (non-service) tasks, so a sleeping service
        // does not hold run() open and no unreference() of its watchers is needed (example 20).
        return $this->onDriver(static fn() => \Amp\async($f));
    }

    public function canAwait(object $o): bool
    {
        return $o instanceof Future || \method_exists($o, 'then');
    }

    public function await(object $o, float $timeout): mixed
    {
        return $this->onDriver(fn() => $this->doAwait($o, $timeout));
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
        $this->onDriver(fn() => $this->doSleep($seconds));
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
        $this->onDriver(fn() => $this->doWaitStream($method, $resource, $timeout));
    }

    private function doWaitStream(string $method, mixed $resource, float $timeout): void
    {
        $this->wait(static function (\Closure $settle) use ($method, $resource) {
            $id = EventLoop::$method($resource, static fn() => $settle(true, null));
            return static fn() => EventLoop::cancel($id);
        }, $timeout);
    }

    /** @var \WeakMap<Driver, Driver> running driver => the private driver used for in-place waiting inside it */
    private ?\WeakMap $nested = null;

    /**
     * Run $f against the driver that fits the calling context.
     *
     * A foreign fiber (one nobody's loop owns) that is started from inside a running Revolt loop cannot drive that
     * loop in place: the loop's own fiber is on the call stack and is not suspended, so resuming it throws
     * FiberError ("Cannot resume a fiber that is not suspended"). Such a fiber gets a private nested driver instead,
     * swapped in as EventLoop's driver for the duration of the adapter call: go(), await(), sleep() ... made by the
     * foreign fiber then run on, and are driven in place by, that nested driver. Limitation: the outer loop is
     * blocked meanwhile (it is, anyway: the foreign fiber is running on top of it), and a task started this way is
     * only advanced while some foreign fiber waits in place.
     */
    private function onDriver(\Closure $f): mixed
    {
        if ($this->canSuspend()) {
            return $f();
        }
        $current = EventLoop::getDriver();
        if (!$current->isRunning()) {
            return $f();
        }
        $this->nested ??= new \WeakMap();
        $nested = $this->nested[$current] ??= (new DriverFactory())->create();
        $prop = new \ReflectionProperty(EventLoop::class, 'driver');
        $prop->setValue(null, $nested);
        try {
            return $f();
        } finally {
            $prop->setValue(null, $current);
        }
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
        $suspended = false;
        $result = null;
        $cancel = null;
        $timer = null;

        $settle = static function (bool $ok, mixed $value) use (&$done, &$suspended, &$result, &$cancel, &$timer, $suspension, $driver): void {
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
                $driver->stop(); // let the in-place driver loop return
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
                $this->driveInPlace($done);
            }
        }

        [$ok, $value] = $result;
        if (!$ok) {
            throw $value;
        }
        return $value;
    }

    /**
     * Run the loop from inside a fiber nobody owns. EventLoop::run() refuses to run inside a fiber, so
     * resume the driver's loop fiber through the same closure DriverSuspension uses for {main}.
     * (The only use of Revolt internals; a public "run in place" in Revolt would make it go away.)
     */
    private function driveInPlace(bool &$done): void
    {
        $driver = EventLoop::getDriver();
        $run = (new \ReflectionProperty(DriverSuspension::class, 'run'))->getValue(EventLoop::getSuspension());
        while (!$done) {
            try {
                $interrupt = $run();
            } catch (\FiberError $e) {
                throw new \LogicException('fiber-interop/amp: cannot wait in place from a fiber nobody owns while the Revolt loop is running it', 0, $e);
            }
            if ($interrupt !== null) {
                $interrupt(); // an uncaught callback exception surfaces here, as with EventLoop::run()
            }
            if (!$done && $driver->getIdentifiers() === []) {
                throw new \LogicException('Deadlock: waiting with nothing scheduled');
            }
        }
    }
}
