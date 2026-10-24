<?php
declare(strict_types=1);

namespace JsonAI\Storage;

final class StoreSupport
{
    public static function identifiers(string $namespace, string $key = 'scan'): void
    {
        if ($namespace === '' || strlen($namespace) > 128 || strpos($namespace, "\0") !== false) {
            throw new \InvalidArgumentException('Namespace must contain 1-128 bytes and no NUL.');
        }
        if ($key === '' || strlen($key) > 512 || strpos($key, "\0") !== false) {
            throw new \InvalidArgumentException('Key must contain 1-512 bytes and no NUL.');
        }
    }

    public static function id(string $namespace, string $key): string
    {
        self::identifiers($namespace, $key);
        return hash('sha256', $namespace . "\0" . $key);
    }

    public static function document($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('A store mutator must return an array or null.');
        }
        return StateCodec::decode(StateCodec::encode($value));
    }

    public static function decode(string $json): array
    {
        try {
            return StateCodec::decode($json);
        } catch (\Throwable $error) {
            throw new StorageException('Stored document has invalid or unsupported state encoding.', 0, $error);
        }
    }
}
