<?php
declare(strict_types=1);

namespace JsonAI\Memory\ShortTerm;

interface ShortTermMemoryInterface
{
    public function load(string $scope, string $threadId): array;
    public function save(string $scope, string $threadId, array $state): void;
    public function forget(string $scope, string $threadId): void;
}
