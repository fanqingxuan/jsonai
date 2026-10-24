<?php
declare(strict_types=1);

namespace JsonAI\Memory\ShortTerm;

use JsonAI\Storage\FileStore;

final class FileShortTermMemory extends StoreShortTermMemory
{
    /** @param FileStore|string $storeOrDirectory */
    public function __construct($storeOrDirectory)
    {
        parent::__construct($storeOrDirectory instanceof FileStore ? $storeOrDirectory : new FileStore($storeOrDirectory));
    }
}
