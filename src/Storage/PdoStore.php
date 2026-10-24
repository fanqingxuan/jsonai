<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/** One transaction protects one document. A supplied PDO connection must not have an active transaction. */
abstract class PdoStore implements StateStoreInterface
{
    protected $pdo;
    protected $table;

    public function __construct(\PDO $pdo, string $table = 'agent_state')
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,47}\z/', $table)) {
            throw new \InvalidArgumentException('Invalid state table name.');
        }
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== $this->driver()) {
            throw new \InvalidArgumentException('PDO driver does not match the selected store.');
        }
        $this->pdo = $pdo;
        $this->table = $table;
    }

    abstract protected function driver(): string;
    abstract protected function insertPlaceholderSql(): string;
    abstract public function schemaSql(): string;

    protected function tableSql(): string
    {
        return $this->driver() === 'mysql' ? '`' . $this->table . '`' : '"' . $this->table . '"';
    }

    public function initialize(): void
    {
        $this->assertOutsideTransaction();
        if ($this->pdo->exec($this->schemaSql()) === false) {
            throw new StorageException('Cannot initialize state schema.');
        }
    }

    public function get(string $namespace, string $key): ?array
    {
        $id = StoreSupport::id($namespace, $key);
        $row = $this->query('SELECT namespace, state_key, payload FROM ' . $this->tableSql() . ' WHERE id = ?', [$id])->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->decodeRow($row, $namespace, $key);
    }

    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        $id = StoreSupport::id($namespace, $key);
        $this->assertOutsideTransaction();
        if (!$this->pdo->beginTransaction()) {
            throw new StorageException('Cannot start state transaction.');
        }
        try {
            // Create-if-absent serializes concurrent first writes as well as updates.
            $this->query($this->insertPlaceholderSql(), [$id, $namespace, $key]);
            $row = $this->query('SELECT namespace, state_key, payload FROM ' . $this->tableSql() . ' WHERE id = ? FOR UPDATE', [$id])->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new StorageException('State row disappeared while acquiring its lock.');
            }
            $next = StoreSupport::document($mutator($this->decodeRow($row, $namespace, $key)));
            if ($next === null) {
                $this->query('DELETE FROM ' . $this->tableSql() . ' WHERE id = ?', [$id]);
            } else {
                $this->query('UPDATE ' . $this->tableSql() . ' SET payload = ? WHERE id = ?', [StateCodec::encode($next), $id]);
            }
            if (!$this->pdo->commit()) {
                throw new StorageException('State commit failed; persisted outcome may be unknown.');
            }
            return $next;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (\Throwable $ignored) { /* Preserve the original failure. */ }
            }
            throw $error;
        }
    }

    public function delete(string $namespace, string $key): void
    {
        $this->transact($namespace, $key, function () { return null; });
    }

    public function scan(string $namespace): array
    {
        StoreSupport::identifiers($namespace);
        $statement = $this->query('SELECT namespace, state_key, payload FROM ' . $this->tableSql() . ' WHERE namespace = ? ORDER BY state_key', [$namespace]);
        $result = [];
        while (($row = $statement->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $value = $this->decodeRow($row, $namespace, $row['state_key']);
            if ($value !== null) {
                $result[$row['state_key']] = $value;
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function assertOutsideTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new StorageException('Use a dedicated PDO connection outside any application transaction.');
        }
    }

    private function query(string $sql, array $parameters): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false || !$statement->execute($parameters)) {
            throw new StorageException('State SQL operation failed.');
        }
        return $statement;
    }

    private function decodeRow(array $row, string $namespace, string $key): ?array
    {
        if ($row['namespace'] !== $namespace || $row['state_key'] !== $key) {
            throw new StorageException('SQL state document identity mismatch.');
        }
        return $row['payload'] === null ? null : StoreSupport::decode($row['payload']);
    }
}
