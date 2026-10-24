<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/** Single-host storage; lock files are deliberately retained to keep lock identities stable. */
final class FileStore implements StateStoreInterface
{
    private $directory;
    private $active = [];

    public function __construct(string $directory)
    {
        if ($directory === '' || strpos($directory, "\0") !== false) {
            throw new \InvalidArgumentException('A nonempty storage directory is required.');
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($this->directory === '') {
            throw new \InvalidArgumentException('The filesystem root cannot be a storage directory.');
        }
        $this->ensureDirectory($this->directory);
    }

    public function get(string $namespace, string $key): ?array
    {
        $path = $this->path($namespace, $key);
        return $this->locked($path, LOCK_SH, function () use ($path, $namespace, $key) {
            return $this->read($path, $namespace, $key);
        });
    }

    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        $path = $this->path($namespace, $key);
        return $this->locked($path, LOCK_EX, function () use ($path, $namespace, $key, $mutator) {
            $next = StoreSupport::document($mutator($this->read($path, $namespace, $key)));
            if ($next === null) {
                if (is_file($path) && !@unlink($path)) {
                    throw new StorageException('Cannot remove state document.');
                }
                return null;
            }
            $json = StateCodec::encode(['namespace' => $namespace, 'key' => $key, 'document' => $next]);
            $temporary = @tempnam(dirname($path), '.state-');
            if ($temporary === false) {
                throw new StorageException('Cannot create temporary state document.');
            }
            try {
                @chmod($temporary, 0600);
                $handle = @fopen($temporary, 'wb');
                if ($handle === false) {
                    throw new StorageException('Cannot open temporary state document.');
                }
                try {
                    $offset = 0;
                    $length = strlen($json);
                    while ($offset < $length) {
                        $written = fwrite($handle, substr($json, $offset));
                        if ($written === false || $written === 0) {
                            throw new StorageException('Cannot write state document.');
                        }
                        $offset += $written;
                    }
                    if (!fflush($handle)) {
                        throw new StorageException('Cannot flush state document.');
                    }
                    if (function_exists('fsync') && !fsync($handle)) {
                        throw new StorageException('Cannot sync state document.');
                    }
                } finally {
                    fclose($handle);
                }
                if (!@rename($temporary, $path)) {
                    throw new StorageException('Cannot atomically replace state document.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
            return $next;
        });
    }

    public function delete(string $namespace, string $key): void
    {
        $this->transact($namespace, $key, function () { return null; });
    }

    public function scan(string $namespace): array
    {
        StoreSupport::identifiers($namespace);
        $directory = $this->namespaceDirectory($namespace);
        $result = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $record = $this->locked($path, LOCK_SH, function () use ($path) {
                return $this->readEnvelope($path);
            });
            if ($record === null) {
                continue;
            }
            if ($record['namespace'] !== $namespace || basename($path) !== StoreSupport::id($namespace, $record['key']) . '.json') {
                throw new StorageException('Stored document identity does not match its location.');
            }
            $result[$record['key']] = $record['document'];
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function path(string $namespace, string $key): string
    {
        $id = StoreSupport::id($namespace, $key);
        return $this->namespaceDirectory($namespace) . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function namespaceDirectory(string $namespace): string
    {
        $directory = $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $namespace);
        $this->ensureDirectory($directory);
        return $directory;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new StorageException('Cannot create storage directory.');
        }
        if (!is_writable($directory)) {
            throw new StorageException('Storage directory is not writable.');
        }
    }

    private function locked(string $path, int $mode, callable $operation)
    {
        if (isset($this->active[$path])) {
            throw new StorageException('Nested access to the same locked document is not supported.');
        }
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            throw new StorageException('Cannot open state lock.');
        }
        @chmod($path . '.lock', 0600);
        try {
            if (!flock($handle, $mode)) {
                throw new StorageException('Cannot acquire state lock.');
            }
            $this->active[$path] = true;
            try {
                return $operation();
            } finally {
                unset($this->active[$path]);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function read(string $path, string $namespace, string $key): ?array
    {
        $record = $this->readEnvelope($path);
        if ($record === null) {
            return null;
        }
        if ($record['namespace'] !== $namespace || $record['key'] !== $key) {
            throw new StorageException('Stored document identity mismatch.');
        }
        return $record['document'];
    }

    private function readEnvelope(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new StorageException('Cannot read state document.');
        }
        $record = StoreSupport::decode($json);
        if (!isset($record['namespace'], $record['key'], $record['document']) || !is_string($record['namespace']) || !is_string($record['key']) || !is_array($record['document'])) {
            throw new StorageException('Invalid state document envelope.');
        }
        return $record;
    }
}
