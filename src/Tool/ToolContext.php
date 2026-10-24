<?php
declare(strict_types=1);

namespace JsonAI\Tool;

use Psr\Log\LoggerInterface;

final class ToolContext
{
    private $identity;
    private $threadId;
    private $runId;
    private $callId;
    private $idempotencyKey;
    private $logger;
    private $progress;
    private $cancelled;

    public function __construct(array $identity, string $threadId, string $runId, string $callId, string $idempotencyKey, LoggerInterface $logger, ?callable $progress = null, ?callable $cancelled = null)
    {
        $this->identity = $identity;
        $this->threadId = $threadId;
        $this->runId = $runId;
        $this->callId = $callId;
        $this->idempotencyKey = $idempotencyKey;
        $this->logger = $logger;
        $this->progress = $progress;
        $this->cancelled = $cancelled;
    }

    public function identity(): array { return $this->identity; }
    public function threadId(): string { return $this->threadId; }
    public function runId(): string { return $this->runId; }
    public function callId(): string { return $this->callId; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function logger(): LoggerInterface { return $this->logger; }

    public function progress(array $progress): void
    {
        if ($this->progress) { call_user_func($this->progress, $progress); }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled ? (bool) call_user_func($this->cancelled) : false;
    }
}
