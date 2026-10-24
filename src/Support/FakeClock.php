<?php
namespace JsonAI\Support;

final class FakeClock implements ClockInterface
{
    private $time;
    public function __construct(float $time = 1000.0) { $this->time = $time; }
    public function now(): float { return $this->time; }
    public function advance(float $seconds): void { $this->time += $seconds; }
}
