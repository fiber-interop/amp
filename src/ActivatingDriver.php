<?php

declare(strict_types=1);

namespace FiberInterop\Amp;

use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\Suspension;

/**
 * Decorates a Revolt driver so the active \Scope (and its superglobals) always equals the current scope when user
 * code runs: every callback is wrapped as `activate(); $cb(...)`, a suspension's suspend() activates when it returns,
 * and run() activates when the loop returns to its caller ({main} or the enclosing fiber).
 * Amp\async(), Future::await() and Amp\delay() all go through EventLoop, so they are covered.
 *
 * @internal
 */
final class ActivatingDriver implements Driver, ScopeActivatingDriver
{
    /** @var \WeakMap<Suspension, Suspension> */
    private \WeakMap $suspensions;

    public function __construct(private readonly Driver $inner)
    {
        $this->suspensions = new \WeakMap();
    }

    public static function activate(): void
    {
        if (\function_exists('fiber_interop\\scope\\activate')) {
            \fiber_interop\scope\activate();
        }
    }

    /** Is the current Revolt driver already one that activates scopes (this class or another adapter's)? */
    public static function isInstalled(Driver $driver): bool
    {
        foreach (\class_implements($driver) ?: [] as $interface) {
            if (\str_ends_with($interface, '\\ScopeActivatingDriver')) {
                return true;
            }
        }
        return false;
    }

    /** Install the decorator unless one is installed or the loop is running. Idempotent. */
    public static function install(): void
    {
        $driver = EventLoop::getDriver();
        if (!self::isInstalled($driver) && !$driver->isRunning()) {
            EventLoop::setDriver(new self($driver));
        }
    }

    /** Unwrap decorators down to Revolt's own driver. */
    public static function unwrap(Driver $driver): Driver
    {
        while ($driver instanceof ScopeActivatingDriver) {
            $driver = $driver->getInner();
        }
        return $driver;
    }

    public function getInner(): Driver
    {
        return $this->inner;
    }

    private static function wrap(\Closure $closure): \Closure
    {
        // A callback runs in the Scope that was current when it was registered (Scope::run() activates it).
        $scope = \Scope::getCurrent();
        return static fn(mixed ...$args) => $scope->run($closure, ...$args);
    }

    public function run(): void
    {
        $this->inner->run();
        self::activate();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function getSuspension(): Suspension
    {
        $inner = $this->inner->getSuspension();
        return $this->suspensions[$inner] ??= new class ($inner) implements Suspension {
            public function __construct(private readonly Suspension $inner) {}

            public function getInner(): Suspension
            {
                return $this->inner;
            }

            public function resume(mixed $value = null): void
            {
                $this->inner->resume($value);
            }

            public function suspend(): mixed
            {
                try {
                    return $this->inner->suspend();
                } finally {
                    ActivatingDriver::activate();
                }
            }

            public function throw(\Throwable $throwable): void
            {
                $this->inner->throw($throwable);
            }
        };
    }

    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    public function queue(\Closure $closure, mixed ...$args): void
    {
        $this->inner->queue(self::wrap($closure), ...$args);
    }

    public function defer(\Closure $closure): string
    {
        return $this->inner->defer(self::wrap($closure));
    }

    public function delay(float $delay, \Closure $closure): string
    {
        return $this->inner->delay($delay, self::wrap($closure));
    }

    public function repeat(float $interval, \Closure $closure): string
    {
        return $this->inner->repeat($interval, self::wrap($closure));
    }

    public function onReadable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onReadable($stream, self::wrap($closure));
    }

    public function onWritable(mixed $stream, \Closure $closure): string
    {
        return $this->inner->onWritable($stream, self::wrap($closure));
    }

    public function onSignal(int $signal, \Closure $closure): string
    {
        return $this->inner->onSignal($signal, self::wrap($closure));
    }

    public function enable(string $callbackId): string
    {
        return $this->inner->enable($callbackId);
    }

    public function cancel(string $callbackId): void
    {
        $this->inner->cancel($callbackId);
    }

    public function disable(string $callbackId): string
    {
        return $this->inner->disable($callbackId);
    }

    public function reference(string $callbackId): string
    {
        return $this->inner->reference($callbackId);
    }

    public function unreference(string $callbackId): string
    {
        return $this->inner->unreference($callbackId);
    }

    public function setErrorHandler(?\Closure $errorHandler): void
    {
        $this->inner->setErrorHandler($errorHandler);
    }

    public function getErrorHandler(): ?\Closure
    {
        return $this->inner->getErrorHandler();
    }

    public function getHandle(): mixed
    {
        return $this->inner->getHandle();
    }

    public function getIdentifiers(): array
    {
        return $this->inner->getIdentifiers();
    }

    public function getType(string $callbackId): CallbackType
    {
        return $this->inner->getType($callbackId);
    }

    public function isEnabled(string $callbackId): bool
    {
        return $this->inner->isEnabled($callbackId);
    }

    public function isReferenced(string $callbackId): bool
    {
        return $this->inner->isReferenced($callbackId);
    }

    public function __debugInfo(): array
    {
        return $this->inner->__debugInfo();
    }
}
