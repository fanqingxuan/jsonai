<?php
namespace JsonAI\Message\Content;

final class FilePart implements ContentPartInterface
{
    private $fileId;
    public function __construct(string $fileId)
    {
        if ($fileId === '') { throw new \InvalidArgumentException('File ID cannot be empty.'); }
        $this->fileId = $fileId;
    }
    public function toArray(): array { return ['type' => 'file', 'file_id' => $this->fileId]; }
}
