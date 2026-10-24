<?php
declare(strict_types=1);
namespace JsonAI\Bridge;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RunResult;

/** Adapter for caller-owned queues. Jobs carry identifiers, never serialized executable classes. */
final class Worker
{
    private $harness;
    private $resolveAgent;
    public function __construct(AgentHarness $harness, callable $resolveAgent)
    {
        $this->harness = $harness; $this->resolveAgent = $resolveAgent;
    }
    public function handle(string $scope, string $threadId, string $runId, array $identity = []): RunResult
    {
        $run = $this->harness->threads()->run($scope, $threadId, $runId);
        $agent = call_user_func($this->resolveAgent, $run['snapshot']['agent']);
        if (!$agent instanceof Agent) { throw new \InvalidArgumentException('Worker registry must return an Agent definition'); }
        return $this->harness->resume($agent, $scope, $threadId, $runId, $identity);
    }
}
