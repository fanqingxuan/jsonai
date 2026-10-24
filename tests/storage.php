<?php
declare(strict_types=1);

use JsonAI\Memory\LongTerm\FileLongTermMemory;
use JsonAI\Memory\LongTerm\StoreLongTermMemory;
use JsonAI\Memory\ShortTerm\FileShortTermMemory;
use JsonAI\Memory\ShortTerm\StoreShortTermMemory;
use JsonAI\Storage\ConflictException;
use JsonAI\Storage\FileStore;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Storage\MySqlStore;
use JsonAI\Storage\PostgresStore;
use JsonAI\Storage\RedisStore;
use JsonAI\Storage\StateCodec;
use JsonAI\Storage\StateStoreInterface;
use JsonAI\Storage\StorageException;
use JsonAI\Support\FakeClock;

return function ($suite): void {
    $directory = sys_get_temp_dir() . '/portable-agent-storage-' . bin2hex(random_bytes(8));
    $cleanup = function (string $path): void {
        if (!is_dir($path)) { return; }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($path);
    };

    $conformance = function (StateStoreInterface $store, string $name) use ($suite): void {
        $suite->test($name . ' atomic mutation, rollback, isolation, ordering, and deletion', function () use ($suite, $store): void {
            $suite->same(null, $store->get('contract', '../key'));
            $suite->same(['counter' => 1, 'nested' => ['ok' => true]], $store->transact('contract', '../key', function ($current) use ($suite) {
                $suite->same(null, $current);
                return ['counter' => 1, 'nested' => ['ok' => true]];
            }));
            $store->transact('other', '../key', function () { return ['counter' => 999]; });
            $suite->throws(RuntimeException::class, function () use ($store): void {
                $store->transact('contract', '../key', function ($current) { $current['counter'] = 99; throw new RuntimeException('abort'); });
            });
            $suite->same(1, $store->get('contract', '../key')['counter']);
            $suite->throws(InvalidArgumentException::class, function () use ($store): void {
                $store->transact('contract', '../key', function () { return 'invalid document'; });
            });
            $store->transact('contract', '../key', function ($current) { ++$current['counter']; return $current; });
            $store->transact('contract', 'a', function () { return []; });
            $suite->same(['../key', 'a'], array_keys($store->scan('contract')));
            $suite->same(2, $store->get('contract', '../key')['counter']);
            $suite->same(999, $store->get('other', '../key')['counter']);
            $store->delete('contract', '../key');
            $store->delete('contract', '../key');
            $suite->same(null, $store->get('contract', '../key'));
            $suite->same([], $store->get('contract', 'a'));
            $suite->throws(InvalidArgumentException::class, function () use ($store) { $store->get('', 'key'); });
            $suite->throws(InvalidArgumentException::class, function () use ($store) { $store->get('ns', str_repeat('a', 513)); });
            $store->delete('contract', 'a');
            $store->delete('other', '../key');
        });

        $suite->test($name . ' scoped long memory TTL, provenance, version conflicts, retrieval and deletion', function () use ($suite, $store): void {
            $clock = new FakeClock(1000.0);
            $memory = new StoreLongTermMemory($store, $clock);
            $first = $memory->put('tenant-a:user-1', 'preference', '喜欢 清淡 饮食', ['source_thread' => 't-1', 'source_message' => 'm-1'], 10, 0);
            $suite->same(1, $first['version']);
            $suite->same('t-1', $first['metadata']['source_thread']);
            $suite->same(null, $memory->get('tenant-b:user-1', 'preference'));
            $suite->throws(ConflictException::class, function () use ($memory) { $memory->put('tenant-a:user-1', 'preference', 'stale', [], null, 0); });
            $clock->advance(1.0);
            $second = $memory->put('tenant-a:user-1', 'preference', '喜欢 清淡 饮食', ['source_thread' => 't-2'], 10, 1);
            $suite->same(2, $second['version']);
            $suite->same($first['created_at'], $second['created_at']);
            $memory->put('tenant-a:user-1', 'other', '清淡 清淡 午餐');
            $suite->same('other', $memory->search('tenant-a:user-1', '清淡', 1)[0]['key']);
            $suite->same(['preference'], array_column($memory->search('tenant-a:user-1', '清淡 饮食'), 'key'));
            $suite->same([], $memory->search('tenant-b:user-1', '清淡'));
            $suite->throws(InvalidArgumentException::class, function () use ($memory) { $memory->search('tenant-a:user-1', '', 101); });
            $clock->advance(10.0);
            $suite->same(null, $memory->get('tenant-a:user-1', 'preference'));
            $suite->same(['other'], array_column($memory->search('tenant-a:user-1', ''), 'key'));
            $renewed = $memory->put('tenant-a:user-1', 'preference', 'new fact', [], null, 0);
            $suite->same(3, $renewed['version']);
            $suite->same(1011.0, $renewed['created_at']);
            $memory->delete('tenant-a:user-1', 'preference');
            $memory->delete('tenant-a:user-1', 'other');
            $suite->same([], $memory->search('tenant-a:user-1', ''));
            $recreated = $memory->put('tenant-a:user-1', 'preference', 'replacement', [], null, 0);
            $suite->true($recreated['version'] > $renewed['version']);
            $suite->throws(ConflictException::class, function () use ($memory, $renewed) { $memory->put('tenant-a:user-1', 'preference', 'stale overwrite', [], null, $renewed['version']); });
            $memory->delete('tenant-a:user-1', 'preference');
        });

        $suite->test($name . ' short memory stores summary coverage without duplicate message history', function () use ($suite, $store): void {
            $memory = new StoreShortTermMemory($store);
            $suite->same([], $memory->load('tenant-a', 'thread'));
            $state = ['summary' => 'User prefers tea', 'covered_ids' => ['m1', 'm2'], 'working_state' => ['topic' => 'lunch']];
            $memory->save('tenant-a', 'thread', $state);
            $suite->same($state, $memory->load('tenant-a', 'thread'));
            $suite->same([], $memory->load('tenant-b', 'thread'));
            $suite->throws(InvalidArgumentException::class, function () use ($memory) { $memory->save('tenant-a', 'thread', ['messages' => []]); });
            $memory->forget('tenant-a', 'thread');
            $suite->same([], $memory->load('tenant-a', 'thread'));
        });

        $suite->test($name . ' preserves maps, stdClass, lists, null, large keys and nested empty objects', function () use ($suite, $store): void {
            $object = new stdClass();
            $object->{'18446744073709551616'} = (object) ['empty_object' => new stdClass(), 'empty_list' => []];
            $document = [
                'events' => [['type' => 'tool.completed', 'data' => ['empty' => new stdClass(), 'null' => null]]],
                'ledger' => ['call' => ['args' => $object, 'result' => ['value' => null]]],
                'map' => ['kind' => 'object', 'entries' => [['user', 'data']]],
                'sparse' => [7 => 'seven', '18446744073709551616' => 'large'],
                'empty_object' => new stdClass(), 'empty_list' => [], 'float' => 1.0,
            ];
            $store->transact('types', 'state', function () use ($document) { return $document; });
            $actual = $store->get('types', 'state');
            $suite->same(serialize($document), serialize($actual));
            $suite->same(serialize($document), serialize($store->scan('types')['state']));
            $actual['empty_object']->changed = true;
            $suite->same(false, property_exists($store->get('types', 'state')['empty_object'], 'changed'));
            $memory = new StoreLongTermMemory($store);
            $memory->put('types', 'meta', 'fact', ['source' => new stdClass(), 'refs' => [$object]]);
            $suite->true($memory->get('types', 'meta')['metadata']['source'] instanceof stdClass);
            $suite->true($memory->search('types', 'fact')[0]['metadata']['refs'][0] instanceof stdClass);
            $store->delete('types', 'state');
            $memory->delete('types', 'meta');
        });
    };

    try {
        $suite->test('State codec rejects cycles, application objects and unknown wire versions', function () use ($suite): void {
            $recursive = [];
            $recursive['self'] = &$recursive;
            $suite->throws(InvalidArgumentException::class, function () use (&$recursive) { StateCodec::encode($recursive); });
            $object = new stdClass();
            $object->self = $object;
            $suite->throws(InvalidArgumentException::class, function () use ($object) { StateCodec::encode(['object' => $object]); });
            $suite->throws(InvalidArgumentException::class, function () { StateCodec::encode(['object' => new DateTimeImmutable()]); });
            $suite->throws(StorageException::class, function () { StateCodec::decode('{"format":"portable-agent/state","version":2,"value":{"kind":"array","entries":[]}}'); });
            $suite->throws(StorageException::class, function () { StateCodec::decode('{"format":"portable-agent/state","version":1,"value":{"kind":"array","entries":[["same",1],["same",2]]}}'); });
        });
        $conformance(new InMemoryStore(), 'InMemoryStore');
        $fileStore = new FileStore($directory);
        $conformance($fileStore, 'FileStore');

        $suite->test('File store survives recreation and memory convenience adapters share data', function () use ($suite, $directory): void {
            (new FileShortTermMemory($directory))->save('a', 't', ['summary' => 'persistent']);
            $suite->same('persistent', (new FileShortTermMemory(new FileStore($directory)))->load('a', 't')['summary']);
            (new FileLongTermMemory($directory))->put('a', 'fact', 'persistent fact');
            $suite->same('persistent fact', (new FileLongTermMemory(new FileStore($directory)))->get('a', 'fact')['content']);
        });

        $suite->test('File store reports corruption without replacing the corrupt original', function () use ($suite, $directory): void {
            $store = new FileStore($directory);
            $store->transact('corrupt', 'key', function () { return ['v' => 1]; });
            $path = $directory . '/' . hash('sha256', 'corrupt') . '/' . hash('sha256', "corrupt\0key") . '.json';
            file_put_contents($path, '{broken');
            $suite->throws(StorageException::class, function () use ($store) { $store->get('corrupt', 'key'); });
            $suite->throws(StorageException::class, function () use ($store) { $store->transact('corrupt', 'key', function () { return []; }); });
            $suite->same('{broken', file_get_contents($path));
        });

        if (function_exists('pcntl_fork')) {
            $suite->test('File store concurrent processes preserve all increments across atomic rename', function () use ($suite, $directory): void {
                $store = new FileStore($directory);
                $store->transact('concurrent', 'counter', function () { return ['value' => 0]; });
                $children = [];
                for ($process = 0; $process < 4; ++$process) {
                    $pid = pcntl_fork();
                    if ($pid === -1) { throw new RuntimeException('Cannot fork test worker'); }
                    if ($pid === 0) {
                        try {
                            $childStore = new FileStore($directory);
                            for ($i = 0; $i < 25; ++$i) {
                                $childStore->transact('concurrent', 'counter', function ($current) { usleep(100); ++$current['value']; return $current; });
                            }
                            exit(0);
                        } catch (Throwable $error) { fwrite(STDERR, $error->getMessage()); exit(1); }
                    }
                    $children[] = $pid;
                }
                foreach ($children as $pid) { pcntl_waitpid($pid, $status); $suite->same(0, pcntl_wexitstatus($status)); }
                $suite->same(100, $store->get('concurrent', 'counter')['value']);
            });
        } else { $suite->skip('File concurrent-process test requires pcntl'); }

        if (getenv('PORTABLE_AGENT_TEST_REDIS') === '1') {
            $suite->test('Redis isolated local integration setup', function () use ($suite, $conformance): void {
                if (!extension_loaded('redis')) { throw new RuntimeException('Redis test explicitly enabled but phpredis is missing.'); }
                $redis = new Redis();
                $port = (int) (getenv('PORTABLE_AGENT_TEST_REDIS_PORT') ?: 6389);
                $redis->connect('127.0.0.1', $port, 2.0);
                $prefix = 'portable_agent_test_' . bin2hex(random_bytes(8)) . ':';
                $store = new RedisStore($redis, $prefix);
                $conformance($store, 'RedisStore');
                $suite->test('Redis CAS retries against the new revision instead of overwriting concurrent writes', function () use ($suite, $redis, $prefix, $store, $port): void {
                    $other = new Redis();
                    $other->connect('127.0.0.1', $port, 2.0);
                    try {
                        $secondStore = new RedisStore($other, $prefix);
                        $store->transact('cas', 'counter', function () { return ['value' => 0]; });
                        $attempts = 0;
                        $result = $store->transact('cas', 'counter', function ($current) use (&$attempts, $secondStore) {
                            if (++$attempts === 1) {
                                // Inject a concurrent committed write before the first CAS attempt.
                                $secondStore->transact('cas', 'counter', function () { return ['value' => 50]; });
                            }
                            ++$current['value'];
                            return $current;
                        });
                        $suite->same(2, $attempts);
                        $suite->same(51, $result['value']);
                        $suite->same(51, $secondStore->get('cas', 'counter')['value']);
                        $store->delete('cas', 'counter');
                    } finally { $other->close(); }
                });
                $redis->close();
                $suite->true(true);
            });
        } else { $suite->skip('Redis integration: provision disposable local Redis, set PORTABLE_AGENT_TEST_REDIS=1 and PORTABLE_AGENT_TEST_REDIS_PORT'); }

        foreach (['MYSQL' => MySqlStore::class, 'PGSQL' => PostgresStore::class] as $driver => $class) {
            $dsn = getenv('PORTABLE_AGENT_TEST_' . $driver . '_DSN');
            if ($dsn && getenv('PORTABLE_AGENT_TEST_SQL_DISPOSABLE') === '1') {
                $suite->test($driver . ' explicitly disposable local SQL integration', function () use ($suite, $conformance, $dsn, $class, $driver): void {
                    if (!preg_match('/(?:^|;)host=(?:127\.0\.0\.1|localhost|\[?::1\]?)(?:;|$)/', substr($dsn, strpos($dsn, ':') + 1))) {
                        throw new RuntimeException('Integration DSN must explicitly target localhost.');
                    }
                    $pdo = new PDO($dsn, getenv('PORTABLE_AGENT_TEST_' . $driver . '_USER') ?: '', getenv('PORTABLE_AGENT_TEST_' . $driver . '_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                    $table = 'agent_test_' . bin2hex(random_bytes(8));
                    $store = new $class($pdo, $table);
                    try {
                        $store->initialize();
                        $conformance($store, $driver . 'Store');
                        $pdo->beginTransaction();
                        $suite->throws(StorageException::class, function () use ($store) { $store->transact('a', 'b', function () { return []; }); });
                        $pdo->rollBack();
                    } finally {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                    }
                });
            } else { $suite->skip($driver . ' integration requires explicitly provisioned local disposable database and opt-in env'); }
        }
    } finally {
        $cleanup($directory);
    }
};
