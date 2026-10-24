<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

use JsonAI\Storage\PostgresStore;
use JsonAI\Support\ClockInterface;

/**
 * Shares the state schema; initialize explicitly through PostgresStore or sql/postgresql.sql.
 * CREATE TABLE IF NOT EXISTS agent_state (
 *   id CHAR(64) NOT NULL PRIMARY KEY,
 *   namespace VARCHAR(128) NOT NULL,
 *   state_key VARCHAR(512) NOT NULL,
 *   payload TEXT NULL
 * );
 * CREATE INDEX IF NOT EXISTS agent_state_namespace_idx ON agent_state (namespace);
 */
final class PostgresLongTermMemory extends StoreLongTermMemory
{
    /** @param PostgresStore|\PDO $storeOrConnection */
    public function __construct($storeOrConnection, ?ClockInterface $clock = null, string $table = 'agent_state')
    {
        parent::__construct($storeOrConnection instanceof PostgresStore ? $storeOrConnection : new PostgresStore($storeOrConnection, $table), $clock);
    }
}
