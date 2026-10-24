<?php
declare(strict_types=1);
namespace JsonAI\Harness;

final class RunOptions
{
    private $values;
    public function __construct(array $values = [])
    {
        $defaults = ['max_iterations' => 12, 'max_tool_calls' => 24, 'max_model_attempts' => 2,
            'timeout' => 120, 'model_timeout' => 60, 'lease_seconds' => 180, 'context_tokens' => 16000,
            'max_output_tokens' => 1024, 'max_total_tokens' => 64000, 'max_tool_result_bytes' => 65536,
            'max_input_bytes' => 1048576, 'max_events' => 10000, 'recent_messages' => 40];
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $defaults)) { throw new \InvalidArgumentException('Unknown run option: ' . $key); }
            if (!is_int($value) || $value < 1) { throw new \InvalidArgumentException('Run option must be a positive integer: ' . $key); }
        }
        $this->values = array_replace($defaults, $values);
        if ($this->get('lease_seconds') <= $this->get('model_timeout')) { throw new \InvalidArgumentException('Lease must exceed a model request timeout'); }
        if ($this->get('max_output_tokens') >= $this->get('context_tokens')) { throw new \InvalidArgumentException('Context budget must reserve room for input'); }
    }
    public function get(string $key): int { return $this->values[$key]; }
    public function toArray(): array { return $this->values; }
}
