<?php

declare(strict_types=1);

namespace FiberInterop\Amp;

/**
 * Marker: a Revolt driver that calls fiber_interop\scope\activate() whenever it hands control to user code.
 * Other fiber-interop adapters on Revolt install a decorator with their own interface of the same short name;
 * detection is by that short name, so only one decorator is ever installed.
 * getInner() returns the wrapped driver (needed by code that reflects on Revolt's own driver classes).
 */
interface ScopeActivatingDriver
{
    public function getInner(): \Revolt\EventLoop\Driver;
}
