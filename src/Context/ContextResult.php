<?php
declare(strict_types=1);

namespace JsonAI\Context;

final class ContextResult
{
    public $messages;
    public $memory;
    public $estimatedTokens;

    public function __construct(array $messages, array $memory, int $estimatedTokens)
    {
        $this->messages = $messages;
        $this->memory = $memory;
        $this->estimatedTokens = $estimatedTokens;
    }
}
