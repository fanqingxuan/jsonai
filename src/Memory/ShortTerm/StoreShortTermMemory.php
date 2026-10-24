<?php
declare(strict_types=1);

namespace JsonAI\Memory\ShortTerm;

use JsonAI\Storage\StateStoreInterface;

/** Stores summary and coverage/working-state metadata, never a second conversation history. */
class StoreShortTermMemory implements ShortTermMemoryInterface
{
    protected $store;

    public function __construct(StateStoreInterface $store)
    {
        $this->store = $store;
    }

    public function load(string $scope, string $threadId): array
    {
        return $this->store->get($this->namespaceFor($scope), $threadId) ?: [];
    }

    public function save(string $scope, string $threadId, array $state): void
    {
        if (array_key_exists('messages', $state) || array_key_exists('history', $state)) {
            throw new \InvalidArgumentException('Conversation messages belong in Thread storage, not short-term memory.');
        }
        $this->store->transact($this->namespaceFor($scope), $threadId, function () use ($state) { return $state; });
    }

    public function forget(string $scope, string $threadId): void
    {
        $this->store->delete($this->namespaceFor($scope), $threadId);
    }

    private function namespaceFor(string $scope): string
    {
        if ($scope === '' || strlen($scope) > 4096 || strpos($scope, "\0") !== false) {
            throw new \InvalidArgumentException('A nonempty bounded memory scope is required.');
        }
        return 'memory:short:' . hash('sha256', $scope);
    }
}
