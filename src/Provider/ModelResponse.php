<?php
namespace JsonAI\Provider;

use JsonAI\Message\Message;

final class ModelResponse
{
    public $message;
    public $usage;
    public $finishReason;
    public $state;
    public function __construct(Message $message, array $usage = [], string $finishReason = 'stop', array $state = [])
    {
        if ($message->role() !== 'assistant') { throw new \InvalidArgumentException('A model response must be an assistant message.'); }
        if (!in_array($finishReason, ['stop', 'tool_calls', 'length', 'content_filter', 'incomplete'], true)) { throw new \InvalidArgumentException('Unsupported finish reason.'); }
        $this->message = $message;
        $this->usage = $usage;
        $this->finishReason = $finishReason;
        $this->state = $state;
    }
}
