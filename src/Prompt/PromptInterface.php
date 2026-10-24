<?php
declare(strict_types=1);

namespace JsonAI\Prompt;

interface PromptInterface
{
    /** @return \JsonAI\Message\Message[] */
    public function render(array $variables = []): array;
    public function snapshot(): array;
}
