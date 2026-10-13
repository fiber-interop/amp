<?php

declare(strict_types=1);

namespace FiberInterop\Amp\Tests;

use FiberInterop\Amp\ActivatingDriver;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

use function async\run;

/** The Revolt driver installed by the adapter keeps the active \Scope (and its $_GET) equal to the current one. */
final class ScopeActivationTest extends TestCase
{
    protected function setUp(): void
    {
        run(fn() => null); // makes sure the adapter (and its driver) is installed
    }

    public function testDriverIsInstalledOnceAndNotStacked(): void
    {
        $driver = EventLoop::getDriver();
        $this->assertInstanceOf(ActivatingDriver::class, $driver);
        new \FiberInterop\Amp\Adapter();
        $this->assertSame($driver, EventLoop::getDriver());
    }

    public function testActivatesBeforeCallbacks(): void
    {
        $seen = new \ArrayObject();
        run(function () use ($seen) {
            $scope = new \Scope();
            $task = \Amp\async(fn() => $scope->run(function () { $_GET = ['s' => 'a']; \Amp\delay(0.02); }));
            // fires while the task is suspended: the invariant is "live $_GET is the current scope's"
            foreach (['queue' => EventLoop::queue(...), 'defer' => EventLoop::defer(...), 'delay' => fn($cb) => EventLoop::delay(0.01, $cb)] as $kind => $register) {
                $register(function () use ($kind, $seen) {
                    $seen[$kind] = $_GET === \Scope::getCurrent()->run(fn() => $_GET);
                });
            }
            $task->await();
        });
        $this->assertSame(['queue' => true, 'defer' => true, 'delay' => true], $seen->getArrayCopy());
    }

    public function testActivatesOnResumeAfterSuspension(): void
    {
        $seen = new \ArrayObject(); // an object: the arrow fn below would capture a reference by value
        run(function () use ($seen) {
            $futures = [];
            foreach (['a' => 0.01, 'b' => 0.02] as $name => $delay) {
                $scope = new \Scope();
                $futures[] = \Amp\async(fn() => $scope->run(function () use ($name, $delay, $seen) {
                    $_GET = ['s' => $name];
                    \Amp\delay($delay);
                    $seen[$name] = $_GET['s'] ?? null;
                    $suspension = EventLoop::getSuspension();
                    EventLoop::delay(0.01, $suspension->resume(...));
                    $suspension->suspend();
                    $seen[$name . '2'] = $_GET['s'] ?? null;
                }));
            }
            \Amp\Future\await($futures);
        });
        $this->assertSame(['a' => 'a', 'b' => 'b', 'a2' => 'a', 'b2' => 'b'], $seen->getArrayCopy());
    }

    public function testRestoresRootWhenRunReturnsToMain(): void
    {
        $_GET = ['root' => '1'];
        run(function () {
            $scope = new \Scope();
            \Amp\async(fn() => $scope->run(function () { $_GET = ['s' => 'x']; \Amp\delay(0.01); }))->await();
        });
        $this->assertSame(['root' => '1'], $_GET);
        $_GET = [];
    }

    public function testUnchangedScopeKeepsValuesAcrossCallbacks(): void
    {
        $_GET = ['k' => 'v'];
        run(function () {
            \Amp\delay(0.005);
            EventLoop::queue(function () { $_GET['k2'] = 'w'; });
            \Amp\delay(0.005);
            $this->assertSame(['k' => 'v', 'k2' => 'w'], $_GET);
        });
        $_GET = [];
    }
}
