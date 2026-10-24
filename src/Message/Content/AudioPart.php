<?php
namespace JsonAI\Message\Content;

final class AudioPart implements ContentPartInterface
{
    private $data;
    private $format;
    public function __construct(string $base64Data, string $format = 'wav')
    {
        if ($base64Data === '' || base64_decode($base64Data, true) === false || !in_array($format, ['wav', 'mp3'], true)) {
            throw new \InvalidArgumentException('Audio requires base64 data and wav or mp3 format.');
        }
        $this->data = $base64Data;
        $this->format = $format;
    }
    public function toArray(): array { return ['type' => 'audio', 'data' => $this->data, 'format' => $this->format]; }
}
