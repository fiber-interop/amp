<?php
// Portable: a plain Fiber with bare Fiber::suspend() sees its creator's $_GET before and after.
require __DIR__ . '/../vendor/autoload.php';

use function async\run;

$bad = [];
$check = function (string $label, array $expect) use (&$bad) {
    $seen = [];
    $f = new Fiber(function () use (&$seen) {
        $seen[] = $_GET['req'] ?? null;
        Fiber::suspend();
        $seen[] = $_GET['req'] ?? null;
    });
    $f->start();
    $f->resume();
    foreach ($seen as $v) {
        if ($v !== $expect['req']) {
            $bad[] = "$label: fiber saw " . json_encode($v) . ", expected " . json_encode($expect['req']);
        }
    }
};
run(function () use ($check) {
    $_GET = ['req' => 'run'];
    $check('in run()', $_GET);
    (new Scope())->run(function () use ($check) {
        $_GET = ['req' => 'scope'];
        $check('in scope', $_GET);
    });
});
echo $bad ? implode("\n", $bad) . "\n" : "ok\n";
exit($bad ? 1 : 0);
