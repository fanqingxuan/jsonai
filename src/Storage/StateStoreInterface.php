<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/** Atomic single-document storage. Mutators must be pure: CAS retries can invoke them again. */
interface StateStoreInterface
{
    public function get(string $namespace, string $key): ?array;
    public function transact(string $namespace, string $key, callable $mutator): ?array;
    public function delete(string $namespace, string $key): void;
    /** @return array<string,array> Intended for bounded maintenance/memory workloads. */
    public function scan(string $namespace): array;
}
