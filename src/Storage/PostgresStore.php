<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/**
 * Default schema (also sql/postgresql.sql). initialize() is explicit; constructors never issue DDL.
 * CREATE TABLE IF NOT EXISTS agent_state (
 *   id CHAR(64) NOT NULL PRIMARY KEY,
 *   namespace VARCHAR(128) NOT NULL,
 *   state_key VARCHAR(512) NOT NULL,
 *   payload TEXT NULL
 * );
 * CREATE INDEX IF NOT EXISTS agent_state_namespace_idx ON agent_state (namespace);
 */
final class PostgresStore extends PdoStore
{
    protected function driver(): string { return 'pgsql'; }

    protected function insertPlaceholderSql(): string
    {
        return 'INSERT INTO ' . $this->tableSql() . ' (id, namespace, state_key, payload) VALUES (?, ?, ?, NULL) ON CONFLICT (id) DO NOTHING';
    }

    public function schemaSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $this->tableSql() . ' ('
            . 'id CHAR(64) NOT NULL PRIMARY KEY, namespace VARCHAR(128) NOT NULL, '
            . 'state_key VARCHAR(512) NOT NULL, payload TEXT NULL); '
            . 'CREATE INDEX IF NOT EXISTS "' . $this->table . '_namespace_idx" ON ' . $this->tableSql() . ' (namespace)';
    }
}
