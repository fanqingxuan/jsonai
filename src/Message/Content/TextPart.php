<?php
namespace JsonAI\Message\Content;

final class TextPart implements ContentPartInterface
{
    private $text;
    public function __construct(string $text) { $this->text = $text; }
    public function toArray(): array { return ['type' => 'text', 'text' => $this->text]; }
}
