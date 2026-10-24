<?php
namespace JsonAI\Harness;

final class RunResult
{
    public $runId;
    public $threadId;
    public $status;
    public $finishReason;
    public $text;
    public $output;
    public $usage;
    public $error;
    public $warnings;
    public function __construct(array $data)
    {
        $this->runId = $data['id']; $this->threadId = $data['request']['thread_id'];
        $this->status = $data['status']; $this->finishReason = $data['finish_reason'] ?? null;
        $this->text = $data['text'] ?? ''; $this->output = $data['output'] ?? null;
        $this->usage = $data['usage']; $this->error = $data['error'] ?? null; $this->warnings = $data['warnings'] ?? [];
    }
    public function isSuccessful(): bool { return $this->status === 'completed'; }
    public function toArray(): array { return get_object_vars($this); }
}
