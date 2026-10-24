<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$suite = new \JsonAI\Tests\TestSuite();
foreach (glob(__DIR__ . '/*_test.php') as $file) { (require $file)($suite); }
foreach (['tools.php', 'provider.php', 'storage.php'] as $file) {
    if (is_file(__DIR__ . '/' . $file)) { (require __DIR__ . '/' . $file)($suite); }
}
echo sprintf("\n%d passed, %d failed, %d skipped\n", $suite->passed, $suite->failed, $suite->skipped);
exit($suite->failed > 0 ? 1 : 0);
