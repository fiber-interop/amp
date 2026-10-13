<?php
// Portable: two scopes interleave via async\sleep(); each must keep its own $_GET.
require __DIR__ . '/../vendor/autoload.php';

use function async\{await_all, go, run, sleep};

$root = $_GET;
$bad = new ArrayObject(); // an object: arrow fns capture by value
run(function () use ($bad) {
    $task = function (string $name, float $delay) use ($bad) {
        return (new Scope())->run(fn() => go(function () use ($name, $delay, $bad) {
            $_GET = ['req' => $name];
            for ($i = 0; $i < 2; $i++) {
                sleep($delay);
                if (($_GET['req'] ?? null) !== $name) {
                    $bad[] = "$name after sleep $i saw " . json_encode($_GET);
                }
            }
        }));
    };
    await_all([$task('a', 0.01), $task('b', 0.015)]);
});
if ($_GET !== $root) {
    $bad[] = 'root $_GET not restored: ' . json_encode($_GET);
}
echo count($bad) ? implode("\n", $bad->getArrayCopy()) . "\n" : "ok\n";
exit(count($bad) ? 1 : 0);
