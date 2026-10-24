<?php
declare(strict_types=1);

namespace JsonAI\Storage;

/**
 * Default schema (also sql/mysql.sql). initialize() is explicit; constructors never issue DDL.
 * CREATE TABLE IF NOT EXISTS agent_state (
 *   id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 *   namespace VARBINARY(128) NOT NULL,
 *   state_key VARBINARY(512) NOT NULL,
 *   payload LONGTEXT NULL,
 *   INDEX agent_state_namespace_idx (namespace)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
 */
final class MySqlStore extends PdoStore
{
    protected function driver(): string { return 'mysql'; }

    protected function insertPlaceholderSql(): string
    {
        return 'INSERT INTO ' . $this->tableSql() . ' (id, namespace, state_key, payload) VALUES (?, ?, ?, NULL) ON DUPLICATE KEY UPDATE id = id';
    }

    public function schemaSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS ' . $this->tableSql() . ' ('
            . 'id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
            . 'namespace VARBINARY(128) NOT NULL, state_key VARBINARY(512) NOT NULL, payload LONGTEXT NULL, '
            . 'INDEX agent_state_namespace_idx (namespace)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    }
}
