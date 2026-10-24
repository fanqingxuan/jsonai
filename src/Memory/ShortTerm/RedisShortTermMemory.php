<?php
declare(strict_types=1);

namespace JsonAI\Memory\ShortTerm;

use JsonAI\Storage\RedisStore;

final class RedisShortTermMemory extends StoreShortTermMemory
{
    /** @param RedisStore|\Redis $storeOrConnection */
    public function __construct($storeOrConnection, string $prefix = 'portable_agent:')
    {
        parent::__construct($storeOrConnection instanceof RedisStore ? $storeOrConnection : new RedisStore($storeOrConnection, $prefix));
    }
}
