<?php
namespace JsonAI\Transport;

class TransportException extends \RuntimeException
{
    private $retryable;
    public function __construct(string $message, bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->retryable = $retryable;
    }
    public function retryable(): bool { return $this->retryable; }
}
