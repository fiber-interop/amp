<?php

declare(strict_types=1);

namespace FiberInterop\Amp\Tests;

use Amp\CancelledException as AmpCancelled;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use async\CancelledException;
use async\RejectedException;
use async\Timeout;
use async\TimeoutException;
use FiberInterop\Amp\Adapter;
use PHPUnit\Framework\TestCase;

use function async\{await, await_all, go, run, sleep};

final class AdapterTest extends TestCase
{
    public function testGoReturnsRealFuture(): void
    {
        run(function () {
            $task = go(fn() => 7);
            $this->assertInstanceOf(Future::class, $task);
            $this->assertSame(7, await($task));
        });
    }

    public function testApiTaskAwaitedWithAmpsOwnAwait(): void
    {
        run(function () {
            $task = go(function () { sleep(0.01); return 'x'; });
            $this->assertSame('x', $task->await());
            $this->assertSame([1, 2], Future\await([go(fn() => 1), go(fn() => 2)]));
        });
    }

    public function testAmpFutureValueAndError(): void
    {
        run(function () {
            $this->assertSame(1, await(Future::complete(1)));
            $this->expectExceptionMessage('boom');
            await(Future::error(new \RuntimeException('boom')));
        });
    }

    public function testExceptionRethrownUnchanged(): void
    {
        run(function () {
            $e = new \DomainException('same');
            try {
                await(Future::error($e));
                $this->fail();
            } catch (\Throwable $caught) {
                $this->assertSame($e, $caught);
            }
        });
    }

    public function testTimeoutTranslatedAndTaskKeepsRunning(): void
    {
        run(function () {
            $ran = false;
            $future = \Amp\async(function () use (&$ran) { \Amp\delay(0.1); $ran = true; return 1; });
            $start = microtime(true);
            try {
                await($future, 0.02);
                $this->fail('expected timeout');
            } catch (TimeoutException $e) {
                $this->assertInstanceOf(Timeout::class, $e);
                $this->assertInstanceOf(AmpCancelled::class, $e->getPrevious());
                $this->assertLessThan(0.08, microtime(true) - $start);
            }
            $this->assertFalse($ran);
            $this->assertSame(1, $future->await());
            $this->assertTrue($ran);
        });
    }

    public function testOtherCancellationTranslated(): void
    {
        run(function () {
            $source = new DeferredCancellation();
            $future = \Amp\async(function () use ($source) {
                \Amp\delay(1, true, $source->getCancellation());
            });
            \Revolt\EventLoop::delay(0.01, fn() => $source->cancel());
            try {
                await($future);
                $this->fail();
            } catch (CancelledException $e) {
                $this->assertNotInstanceOf(TimeoutException::class, $e);
                $this->assertInstanceOf(AmpCancelled::class, $e->getPrevious());
            }
        });
    }

    public function testFutureOwnTimeoutIsNotOurTimeout(): void
    {
        run(function () {
            $future = \Amp\async(fn() => \Amp\delay(1, true, new TimeoutCancellation(0.01)));
            $this->expectException(CancelledException::class);
            await($future, 1.0);
        });
    }

    public function testAwaitInsideAmpAsync(): void
    {
        run(function () {
            $f = \Amp\async(function () {
                $inner = go(function () { sleep(0.01); return 5; });
                return await($inner) + 1;
            });
            $this->assertSame(6, $f->await());
        });
    }

    public function testSleepIsAmpDelay(): void
    {
        run(function () {
            $start = microtime(true);
            await_all([go(fn() => sleep(0.05)), go(fn() => sleep(0.05))]);
            $this->assertLessThan(0.09, microtime(true) - $start);
        });
    }

    public function testThenableAndRejection(): void
    {
        run(function () {
            $ok = new class { public function then($f, $r) { $f(3); } };
            $bad = new class { public function then($f, $r) { $r('nope'); } };
            $this->assertSame(3, await($ok));
            $this->expectException(RejectedException::class);
            await($bad);
        });
    }

    public function testInPlaceInForeignFiber(): void
    {
        run(function () {
            $fiber = new \Fiber(fn() => await(go(function () { sleep(0.01); return 9; })));
            $fiber->start();
            $this->assertTrue($fiber->isTerminated());
            $this->assertSame(9, $fiber->getReturn());
        });
    }

    public function testCanAwait(): void
    {
        $a = new Adapter();
        $this->assertTrue($a->canAwait(Future::complete(1)));
        $this->assertFalse($a->canAwait(new \stdClass()));
    }
}
