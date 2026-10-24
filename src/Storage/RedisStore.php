<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/** Requires a dedicated phpredis connection without client prefix, serializer or compression. */
final class RedisStore implements StateStoreInterface
{
    private $redis;
    private $prefix;
    private $maxRetries;
    private $active = [];

    public function __construct(\Redis $redis, string $prefix = 'portable_agent:', int $maxRetries = 8)
    {
        if (!preg_match('/\A[A-Za-z0-9:_-]{1,128}\z/', $prefix) || $maxRetries < 1 || $maxRetries > 100) {
            throw new \InvalidArgumentException('Invalid Redis prefix or CAS retry limit.');
        }
        if ($redis->getOption(\Redis::OPT_SERIALIZER) !== \Redis::SERIALIZER_NONE || $redis->getOption(\Redis::OPT_PREFIX)) {
            throw new \InvalidArgumentException('RedisStore needs a dedicated connection with no serializer or client prefix.');
        }
        if (defined('Redis::OPT_COMPRESSION') && $redis->getOption(constant('Redis::OPT_COMPRESSION')) !== 0) {
            throw new \InvalidArgumentException('RedisStore requires Redis client compression to be disabled.');
        }
        $this->redis = $redis;
        $this->prefix = $prefix;
        $this->maxRetries = $maxRetries;
    }

    public function get(string $namespace, string $key): ?array
    {
        $raw = $this->redis->get($this->storageKey($namespace, $key));
        return $raw === false ? null : $this->decode($raw, $namespace, $key)['document'];
    }

    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        $storageKey = $this->storageKey($namespace, $key);
        if (isset($this->active[$storageKey])) {
            throw new StorageException('Nested mutation of the same document is not supported.');
        }
        $this->active[$storageKey] = true;
        // A random revision prevents an ABA update from comparing equal to an old document.
        $script = "local current = redis.call('GET', KEYS[1]); "
            . "if ARGV[1] == 'missing' then if current then return 0 end "
            . "else if current ~= ARGV[2] then return 0 end end; "
            . "if ARGV[3] == 'delete' then redis.call('DEL', KEYS[1]) "
            . "else redis.call('SET', KEYS[1], ARGV[4]) end; return 1";
        try {
            for ($attempt = 0; $attempt < $this->maxRetries; ++$attempt) {
                $raw = $this->redis->get($storageKey);
                $current = $raw === false ? null : $this->decode($raw, $namespace, $key)['document'];
                $next = StoreSupport::document($mutator($current));
                $encoded = $next === null ? '' : StateCodec::encode([
                    'namespace' => $namespace, 'key' => $key,
                    'revision' => bin2hex(random_bytes(16)), 'document' => $next,
                ]);
                $result = $this->redis->eval($script, [$storageKey, $raw === false ? 'missing' : 'existing', $raw === false ? '' : $raw, $next === null ? 'delete' : 'set', $encoded], 1);
                if ($result === 1) {
                    return $next;
                }
                if ($result !== 0) {
                    throw new StorageException('Redis atomic mutation failed.');
                }
            }
            throw new ConflictException('Redis document changed too often; CAS retry budget exhausted.');
        } finally {
            unset($this->active[$storageKey]);
        }
    }

    public function delete(string $namespace, string $key): void
    {
        $this->transact($namespace, $key, function () { return null; });
    }

    public function scan(string $namespace): array
    {
        StoreSupport::identifiers($namespace);
        $iterator = null;
        $result = [];
        $pattern = $this->prefix . hash('sha256', $namespace) . ':*';
        do {
            $keys = $this->redis->scan($iterator, $pattern, 100);
            foreach ($keys ?: [] as $storageKey) {
                $raw = $this->redis->get($storageKey);
                if ($raw === false) {
                    continue;
                }
                $record = $this->decode($raw, $namespace);
                if ($storageKey !== $this->storageKey($namespace, $record['key'])) {
                    throw new StorageException('Redis document identity mismatch.');
                }
                $result[$record['key']] = $record['document'];
            }
        } while ($iterator !== 0 && $iterator !== '0');
        ksort($result, SORT_STRING);
        return $result;
    }

    private function storageKey(string $namespace, string $key): string
    {
        return $this->prefix . hash('sha256', $namespace) . ':' . StoreSupport::id($namespace, $key);
    }

    private function decode(string $raw, string $namespace, ?string $key = null): array
    {
        $record = StoreSupport::decode($raw);
        if (!isset($record['namespace'], $record['key'], $record['revision'], $record['document']) || !is_string($record['key']) || !is_array($record['document']) || $record['namespace'] !== $namespace || ($key !== null && $record['key'] !== $key)) {
            throw new StorageException('Invalid Redis document envelope.');
        }
        return $record;
    }
}
