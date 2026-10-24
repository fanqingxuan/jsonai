<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

use JsonAI\Storage\MySqlStore;
use JsonAI\Support\ClockInterface;

/**
 * Shares the state schema; initialize explicitly through MySqlStore or sql/mysql.sql.
 * CREATE TABLE IF NOT EXISTS agent_state (
 *   id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 *   namespace VARBINARY(128) NOT NULL,
 *   state_key VARBINARY(512) NOT NULL,
 *   payload LONGTEXT NULL,
 *   INDEX agent_state_namespace_idx (namespace)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
 */
final class MySqlLongTermMemory extends StoreLongTermMemory
{
    /** @param MySqlStore|\PDO $storeOrConnection */
    public function __construct($storeOrConnection, ?ClockInterface $clock = null, string $table = 'agent_state')
    {
        parent::__construct($storeOrConnection instanceof MySqlStore ? $storeOrConnection : new MySqlStore($storeOrConnection, $table), $clock);
    }
}
