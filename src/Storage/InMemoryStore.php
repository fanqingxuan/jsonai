<?php
declare(strict_types=1);

namespace JsonAI\Storage;

final class InMemoryStore implements StateStoreInterface
{
    private $documents = [];
    private $active = [];

    public function get(string $namespace, string $key): ?array
    {
        $id = StoreSupport::id($namespace, $key);
        return isset($this->documents[$id]) ? StoreSupport::document($this->documents[$id]['document']) : null;
    }

    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        $id = StoreSupport::id($namespace, $key);
        if (isset($this->active[$id])) {
            throw new StorageException('Nested mutation of the same document is not supported.');
        }
        $this->active[$id] = true;
        try {
            $next = StoreSupport::document($mutator($this->get($namespace, $key)));
            if ($next === null) {
                unset($this->documents[$id]);
            } else {
                $this->documents[$id] = ['namespace' => $namespace, 'key' => $key, 'document' => $next];
            }
            return StoreSupport::document($next);
        } finally {
            unset($this->active[$id]);
        }
    }

    public function delete(string $namespace, string $key): void
    {
        $this->transact($namespace, $key, function () { return null; });
    }

    public function scan(string $namespace): array
    {
        StoreSupport::identifiers($namespace);
        $result = [];
        foreach ($this->documents as $record) {
            if ($record['namespace'] === $namespace) {
                $result[$record['key']] = StoreSupport::document($record['document']);
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }
}
