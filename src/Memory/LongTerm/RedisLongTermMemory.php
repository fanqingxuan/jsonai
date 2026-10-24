<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

use JsonAI\Storage\RedisStore;
use JsonAI\Support\ClockInterface;

final class RedisLongTermMemory extends StoreLongTermMemory
{
    /** @param RedisStore|\Redis $storeOrConnection */
    public function __construct($storeOrConnection, ?ClockInterface $clock = null, string $prefix = 'portable_agent:')
    {
        parent::__construct($storeOrConnection instanceof RedisStore ? $storeOrConnection : new RedisStore($storeOrConnection, $prefix), $clock);
    }
}
