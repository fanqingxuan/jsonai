<?php
namespace JsonAI\Support;

final class SystemClock implements ClockInterface
{
    public function now(): float { return microtime(true); }
}
