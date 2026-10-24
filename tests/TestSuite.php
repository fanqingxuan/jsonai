<?php
namespace JsonAI\Tests;

final class TestSuite
{
    public $passed = 0;
    public $failed = 0;
    public $skipped = 0;
    public function test(string $name, callable $test): void
    {
        try { $test(); $this->passed++; echo "PASS " . $name . "\n"; }
        catch (\Throwable $error) { $this->failed++; fwrite(STDERR, "FAIL " . $name . ': ' . get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); }
    }
    public function same($expected, $actual, string $message = ''): void
    {
        if ($expected !== $actual) { throw new \RuntimeException($message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
    }
    public function true($condition, string $message = ''): void
    {
        if ($condition !== true) { throw new \RuntimeException($message ?: 'Condition is not true'); }
    }
    public function throws(string $class, callable $fn): \Throwable
    {
        try { $fn(); } catch (\Throwable $error) {
            if (!$error instanceof $class) { throw new \RuntimeException('Expected ' . $class . ', got ' . get_class($error) . ': ' . $error->getMessage(), 0, $error); }
            return $error;
        }
        throw new \RuntimeException('Expected exception ' . $class);
    }
    public function skip(string $message): void { $this->skipped++; echo "SKIP " . $message . "\n"; }
}
