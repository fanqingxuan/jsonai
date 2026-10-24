<?php
declare(strict_types=1);

namespace JsonAI\Memory\LongTerm;

use JsonAI\Storage\FileStore;
use JsonAI\Support\ClockInterface;

final class FileLongTermMemory extends StoreLongTermMemory
{
    /** @param FileStore|string $storeOrDirectory */
    public function __construct($storeOrDirectory, ?ClockInterface $clock = null)
    {
        parent::__construct($storeOrDirectory instanceof FileStore ? $storeOrDirectory : new FileStore($storeOrDirectory), $clock);
    }
}
