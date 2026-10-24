<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

use JsonAI\Storage\ConflictException;
use JsonAI\Storage\StateStoreInterface;
use JsonAI\Storage\StorageException;
use JsonAI\Support\ClockInterface;
use JsonAI\Support\SystemClock;

/** Explicit fact writes. Keyword retrieval is intentionally independent of embedding vendors. */
class StoreLongTermMemory implements LongTermMemoryInterface
{
    protected $store;
    private $clock;

    public function __construct(StateStoreInterface $store, ?ClockInterface $clock = null)
    {
        $this->store = $store;
        $this->clock = $clock ?: new SystemClock();
    }

    public function put(string $scope, string $key, string $content, array $metadata = [], ?int $ttl = null, ?int $expectedVersion = null): array
    {
        if ($ttl !== null && $ttl < 1) {
            throw new \InvalidArgumentException('Memory TTL must be positive seconds or null.');
        }
        if ($expectedVersion !== null && $expectedVersion < 0) {
            throw new \InvalidArgumentException('Expected version must be nonnegative or null.');
        }
        $now = $this->clock->now();
        return $this->store->transact($this->namespaceFor($scope), $key, function (?array $previous) use ($key, $content, $metadata, $ttl, $expectedVersion, $now) {
            if ($previous !== null) { $this->validateRecord($previous); }
            $active = $previous !== null && empty($previous['_deleted']) && !$this->expired($previous, $now);
            $currentVersion = $active ? $previous['version'] : 0;
            if ($expectedVersion !== null && $currentVersion !== $expectedVersion) {
                throw new ConflictException('Long-term memory version does not match.');
            }
            return [
                'key' => $key, 'content' => $content, 'metadata' => $metadata,
                'version' => $previous === null ? 1 : $previous['version'] + 1,
                'created_at' => $active ? $previous['created_at'] : $now,
                'updated_at' => $now, 'expires_at' => $ttl === null ? null : $now + $ttl,
            ];
        });
    }

    public function get(string $scope, string $key): ?array
    {
        $record = $this->store->get($this->namespaceFor($scope), $key);
        if ($record === null) { return null; }
        $this->validateRecord($record);
        if ($record['key'] !== $key) { throw new StorageException('Memory key does not match stored record.'); }
        return !empty($record['_deleted']) || $this->expired($record, $this->clock->now()) ? null : $record;
    }

    public function search(string $scope, string $query, int $limit = 5): array
    {
        if ($limit < 1 || $limit > 100 || strlen($query) > 4096) {
            throw new \InvalidArgumentException('Search requires limit 1-100 and at most 4096 query bytes.');
        }
        $terms = preg_split('/[\s\p{P}\p{Z}]+/u', $this->lower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false) { throw new \InvalidArgumentException('Search query must be valid UTF-8.'); }
        $terms = array_values(array_unique($terms));
        $now = $this->clock->now();
        $matches = [];
        foreach ($this->store->scan($this->namespaceFor($scope)) as $key => $record) {
            $this->validateRecord($record);
            if ((string) $key !== $record['key']) { throw new StorageException('Memory key does not match stored record.'); }
            if (!empty($record['_deleted']) || $this->expired($record, $now)) { continue; }
            $haystack = $this->lower($record['key'] . ' ' . $record['content']);
            $score = 0;
            foreach ($terms as $term) {
                $count = substr_count($haystack, $term);
                if ($count === 0) { continue 2; }
                $score += $count;
            }
            $matches[] = ['score' => $score, 'record' => $record];
        }
        usort($matches, function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) { return $right['score'] <=> $left['score']; }
            if ($left['record']['updated_at'] !== $right['record']['updated_at']) { return $right['record']['updated_at'] <=> $left['record']['updated_at']; }
            return strcmp($left['record']['key'], $right['record']['key']);
        });
        return array_map(function (array $match): array { return $match['record']; }, array_slice($matches, 0, $limit));
    }

    public function delete(string $scope, string $key): void
    {
        $now = $this->clock->now();
        $this->store->transact($this->namespaceFor($scope), $key, function (?array $previous) use ($key, $now) {
            if ($previous === null) { return null; }
            $this->validateRecord($previous);
            if (!empty($previous['_deleted'])) { return $previous; }
            // Erase content/provenance while retaining a revision tombstone to prevent version ABA.
            return [
                'key' => $key, 'content' => '', 'metadata' => [], 'version' => $previous['version'] + 1,
                'created_at' => $now, 'updated_at' => $now, 'expires_at' => null, '_deleted' => true,
            ];
        });
    }

    private function namespaceFor(string $scope): string
    {
        if ($scope === '' || strlen($scope) > 4096 || strpos($scope, "\0") !== false) {
            throw new \InvalidArgumentException('A nonempty bounded memory scope is required.');
        }
        return 'memory:long:' . hash('sha256', $scope);
    }

    private function expired(array $record, float $now): bool
    {
        return $record['expires_at'] !== null && $record['expires_at'] <= $now;
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function validateRecord(array $record): void
    {
        if (!isset($record['key'], $record['content'], $record['metadata'], $record['version'], $record['created_at'], $record['updated_at'])
            || !array_key_exists('expires_at', $record) || !is_string($record['key']) || !is_string($record['content'])
            || !is_array($record['metadata']) || !is_int($record['version']) || $record['version'] < 1
            || !is_numeric($record['created_at']) || !is_numeric($record['updated_at'])
            || ($record['expires_at'] !== null && !is_numeric($record['expires_at']))) {
            throw new StorageException('Invalid long-term memory record.');
        }
    }
}
