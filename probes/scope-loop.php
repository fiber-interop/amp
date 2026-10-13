<?php
// Runtime-specific: same scenario with Amp\async + Amp\delay only (async\run() just starts the loop).
require __DIR__ . '/../vendor/autoload.php';

use function Amp\{async, delay};

$root = $_GET;
$bad = new ArrayObject(); // an object: arrow fns capture by value
async\run(function () use ($bad) {
    $task = function (string $name, float $delay) use ($bad) {
        // The scope is entered around Amp\async(); the driver runs the task's start callback in it and keeps
        // it active across Amp\delay().
        return (new Scope())->run(fn() => async(function () use ($name, $delay, $bad) {
            $_GET = ['req' => $name];
            for ($i = 0; $i < 2; $i++) {
                delay($delay);
                if (($_GET['req'] ?? null) !== $name) {
                    $bad[] = "$name after delay $i saw " . json_encode($_GET);
                }
            }
        }));
    };
    Amp\Future\await([$task('a', 0.01), $task('b', 0.015)]);
});
if ($_GET !== $root) {
    $bad[] = 'root $_GET not restored: ' . json_encode($_GET);
}
echo count($bad) ? implode("\n", $bad->getArrayCopy()) . "\n" : "ok\n";
exit(count($bad) ? 1 : 0);
