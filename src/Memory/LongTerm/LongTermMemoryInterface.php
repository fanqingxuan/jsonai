<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

interface LongTermMemoryInterface
{
    /** expectedVersion: null = unconditional upsert, 0 = absent, positive = exact current version. */
    public function put(string $scope, string $key, string $content, array $metadata = [], ?int $ttl = null, ?int $expectedVersion = null): array;
    public function search(string $scope, string $query, int $limit = 5): array;
    public function get(string $scope, string $key): ?array;
    public function delete(string $scope, string $key): void;
}
