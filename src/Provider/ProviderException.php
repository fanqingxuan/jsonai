<?php
namespace JsonAI\Provider;

class ProviderException extends \RuntimeException
{
    private $retryable;
    private $statusCode;
    public function __construct(string $message, bool $retryable = false, ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
        $this->statusCode = $statusCode;
    }
    public function retryable(): bool { return $this->retryable; }
    public function statusCode(): ?int { return $this->statusCode; }
}
