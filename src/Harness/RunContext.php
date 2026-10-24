<?php
namespace JsonAI\Harness;

use JsonAI\Support\ClockInterface;

final class RunContext
{
    public $request;
    public $options;
    public $runId;
    public $owner;
    private $clock;
    private $deadline;
    public function __construct(RunRequest $request, RunOptions $options, string $runId, string $owner, float $startedAt, ClockInterface $clock)
    {
        $this->request = $request; $this->options = $options; $this->runId = $runId; $this->owner = $owner;
        $this->clock = $clock; $this->deadline = $startedAt + $options->get('timeout');
    }
    public function remaining(): float { return max(0.0, $this->deadline - $this->clock->now()); }
}
