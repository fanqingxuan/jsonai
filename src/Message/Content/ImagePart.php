<?php
namespace JsonAI\Message\Content;

final class ImagePart implements ContentPartInterface
{
    private $url;
    private $detail;
    /** URL or data URL; files are never read implicitly from the host filesystem. */
    public function __construct(string $url, string $detail = 'auto')
    {
        if ($url === '' || !in_array($detail, ['auto', 'low', 'high'], true)) {
            throw new \InvalidArgumentException('Invalid image URL or detail.');
        }
        $this->url = $url;
        $this->detail = $detail;
    }
    public function toArray(): array { return ['type' => 'image', 'url' => $this->url, 'detail' => $this->detail]; }
}
