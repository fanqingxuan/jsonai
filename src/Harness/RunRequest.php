<?php
declare(strict_types=1);
namespace JsonAI\Harness;

use JsonAI\Message\Message;
use JsonAI\Support\Id;

/** @internal Input and context snapshot persisted by the Harness. */
final class RunRequest
{
    public $input;
    /** @var RequestContext */
    public $context;
    public function __construct($input, RequestContext $context)
    {
        $this->input = is_string($input) ? Message::user($input) : $input;
        if (!$this->input instanceof Message || $this->input->role() !== 'user') { throw new \InvalidArgumentException('Run input must be a user message'); }
        // Reusing a base context for the next message generates a fresh request ID.
        $this->context = $context->withRequestId($context->getRequestId() ?? Id::generate('request_'));
    }
    public function toArray(): array
    {
        // Keep the flat checkpoint representation readable by existing persisted runs.
        return array_merge(['input' => $this->input->toArray()], $this->context->toArray());
    }
    public static function fromArray(array $data): self
    {
        return new self(Message::fromArray($data['input']), RequestContext::fromArray($data));
    }
}
