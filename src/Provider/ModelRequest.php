<?php
namespace JsonAI\Provider;

use JsonAI\Message\Message;

final class ModelRequest
{
    public $model;
    public $messages;
    public $tools;
    public $options;
    public function __construct(string $model, array $messages, array $tools = [], array $options = [])
    {
        if ($model === '') { throw new \InvalidArgumentException('Model cannot be empty.'); }
        foreach ($messages as $message) {
            if (!$message instanceof Message) { throw new \InvalidArgumentException('Messages must be Message objects.'); }
        }
        foreach (['model', 'messages', 'tools', 'input', 'stream', 'previous_response_id', 'conversation', 'instructions'] as $reserved) {
            if (array_key_exists($reserved, $options)) { throw new \InvalidArgumentException('Reserved model option: ' . $reserved); }
        }
        $names = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !isset($tool['name'], $tool['description'], $tool['parameters']) || !is_array($tool['parameters']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tool['name']) || isset($names[$tool['name']])) {
                throw new \InvalidArgumentException('Tools require unique valid names, descriptions and parameter schemas.');
            }
            $names[$tool['name']] = true;
        }
        $this->model = $model;
        $this->messages = array_values($messages);
        $this->tools = array_values($tools);
        $this->options = $options;
    }
}
