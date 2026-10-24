<?php
declare(strict_types=1);

namespace JsonAI\Tool;

/** Application explicitly asserts that failure is known and no business side effect committed. */
final class ToolFailureException extends \RuntimeException
{
    private $errorCode;
    private $details;

    public function __construct(string $errorCode, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    public function errorCode(): string { return $this->errorCode; }
    public function details(): array { return $this->details; }
}
