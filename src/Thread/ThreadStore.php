<?php
declare(strict_types=1);
namespace JsonAI\Thread;

use JsonAI\Storage\StateStoreInterface;
use JsonAI\Message\Message;
use JsonAI\Support\Json;

/** One atomic document owns messages, run checkpoints, tool ledger and replay events. */
final class ThreadStore
{
    private $store;
    public function __construct(StateStoreInterface $store) { $this->store = $store; }
    public function get(string $scope, string $threadId): ?array { return $this->store->get('threads', self::key($scope, $threadId)); }
    public function transact(string $scope, string $threadId, callable $mutator): ?array
    {
        return $this->store->transact('threads', self::key($scope, $threadId), $mutator);
    }
    public function messages(string $scope, string $threadId): array
    {
        $doc = $this->get($scope, $threadId);
        return array_map([Message::class, 'fromArray'], $doc['messages'] ?? []);
    }
    public function run(string $scope, string $threadId, string $runId): array
    {
        $doc = $this->get($scope, $threadId);
        if (!isset($doc['runs'][$runId])) { throw new \OutOfBoundsException('Run not found'); }
        return $doc['runs'][$runId];
    }
    public function events(string $scope, string $threadId, string $runId, int $after = 0): array
    {
        if ($after < 0) { throw new \InvalidArgumentException('Invalid event cursor'); }
        $run = $this->run($scope, $threadId, $runId);
        return array_values(array_filter($run['events'], function (array $event) use ($after) { return $event['sequence'] > $after; }));
    }
    public function delete(string $scope, string $threadId): void
    {
        $this->transact($scope, $threadId, function (?array $doc): ?array {
            if ($doc && $doc['active_run'] !== null) { throw new \LogicException('Cannot delete a thread with an active run'); }
            return null;
        });
    }
    private static function key(string $scope, string $threadId): string { return Json::hash([$scope, $threadId]); }
}
