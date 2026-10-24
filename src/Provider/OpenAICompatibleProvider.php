<?php
declare(strict_types=1);
namespace JsonAI\Provider;

use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Message\Message;
use JsonAI\Support\Json;
use JsonAI\Transport\TransportInterface;

/** Chat Completions compatibility with explicit endpoint and capability settings. */
final class OpenAICompatibleProvider extends ChatCompletionsProvider
{
    private $compatibility;

    public function __construct(string $apiKey, string $baseUrl, ?TransportInterface $transport = null, array $compatibility = [])
    {
        $defaults = ['max_tokens_parameter' => 'max_tokens', 'response_format' => 'json_object',
            'stream_usage' => true, 'preserve_reasoning' => false, 'images' => false, 'files' => false, 'audio' => false];
        foreach ($compatibility as $key => $value) {
            if (!array_key_exists($key, $defaults)) { throw new \InvalidArgumentException('Unknown compatibility option: ' . $key); }
        }
        $this->compatibility = array_replace($defaults, $compatibility);
        if (!in_array($this->compatibility['max_tokens_parameter'], ['max_tokens', 'max_completion_tokens'], true)
            || !in_array($this->compatibility['response_format'], ['json_object', 'json_schema', 'none'], true)) {
            throw new \InvalidArgumentException('Invalid compatibility format');
        }
        foreach (['stream_usage', 'preserve_reasoning', 'images', 'files', 'audio'] as $key) {
            if (!is_bool($this->compatibility[$key])) { throw new \InvalidArgumentException('Compatibility flag must be boolean: ' . $key); }
        }
        parent::__construct($apiKey, $transport, $baseUrl);
    }

    public static function deepSeek(string $apiKey, ?TransportInterface $transport = null, string $baseUrl = 'https://api.deepseek.com'): self
    {
        return new self($apiKey, $baseUrl, $transport, ['preserve_reasoning' => true]);
    }
    public function name(): string { return 'openai_compatible.chat_completions'; }
    public function capabilities(): array
    {
        return ['tools' => true, 'streaming' => true, 'structured_output' => $this->compatibility['response_format'] !== 'none',
            'images' => $this->compatibility['images'], 'files' => $this->compatibility['files'], 'audio' => $this->compatibility['audio']];
    }
    public function definitionFingerprint(): string
    {
        return Json::hash([parent::definitionFingerprint(), $this->compatibility]);
    }

    protected function payload(ModelRequest $request, bool $stream): array
    {
        $options = $this->chatOptions($request, ['thinking']);
        if (array_key_exists('thinking', $options)) {
            $thinking = $options['thinking'];
            if (!is_array($thinking) || array_keys($thinking) !== ['type'] || !in_array($thinking['type'], ['enabled', 'disabled'], true)) {
                throw new \InvalidArgumentException('thinking must contain type enabled or disabled');
            }
        }
        if (isset($options['max_output_tokens'], $options['max_tokens'])) {
            throw new \InvalidArgumentException('Specify max_output_tokens or max_tokens, not both.');
        }
        if (isset($options['max_output_tokens']) || isset($options['max_tokens'])) {
            $tokens = $options['max_output_tokens'] ?? $options['max_tokens'];
            unset($options['max_output_tokens'], $options['max_tokens']);
            $options[$this->compatibility['max_tokens_parameter']] = $tokens;
        }
        $messages = [];
        foreach ($request->messages as $message) {
            $entry = $this->encodeMessage($message);
            if ($this->compatibility['preserve_reasoning'] && $request->tools && $message->role() === 'assistant') {
                $state = $message->metadata()['openai_compatible'] ?? [];
                if (array_key_exists('reasoning_content', $state)) {
                    if (!is_string($state['reasoning_content'])) { throw new \InvalidArgumentException('Invalid reasoning continuation state'); }
                    $entry['reasoning_content'] = $state['reasoning_content'];
                }
            }
            $messages[] = $entry;
        }
        $payload = array_merge($options, ['model' => $request->model, 'messages' => $messages, 'stream' => $stream]);
        if ($stream && $this->compatibility['stream_usage']) { $payload['stream_options'] = ['include_usage' => true]; }
        if ($request->tools) { $payload['tools'] = $this->encodeTools($request->tools, null); }
        $schema = $request->options['response_schema'] ?? null;
        if ($schema !== null) {
            $format = $this->compatibility['response_format'];
            if ($format === 'none') { throw new \InvalidArgumentException('Provider does not support structured output'); }
            if ($format === 'json_schema') {
                $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'agent_response', 'strict' => true, 'schema' => $this->schema($schema, true)]];
            } else {
                $type = $schema['type'] ?? null;
                if ($type !== 'object' && !(is_array($type) && in_array('object', $type, true))) { throw new \InvalidArgumentException('JSON output requires an object root schema'); }
                $payload['response_format'] = ['type' => 'json_object'];
                array_unshift($payload['messages'], ['role' => 'system', 'content' => 'Use tools when needed. Return the final answer as a JSON object matching this JSON Schema: ' . Json::encode($this->schema($schema))]);
            }
        }
        return $payload;
    }

    protected function encodeMessage(Message $message): array
    {
        $entry = parent::encodeMessage($message);
        if ($message->role() === 'developer') { $entry['role'] = 'system'; }
        if (is_array($message->content())) {
            $textOnly = true;
            foreach ($message->content() as $part) {
                if ($part['type'] === 'text') { continue; }
                $textOnly = false;
                $flag = ['image' => 'images', 'file' => 'files', 'audio' => 'audio'][$part['type']];
                if (!$this->compatibility[$flag]) { throw new \InvalidArgumentException('Provider capability is disabled: ' . $flag); }
            }
            if ($textOnly) { $entry['content'] = $message->text(); }
        }
        return $entry;
    }

    protected function collectReasoning(array &$message, array $delta): void
    {
        if (!$this->compatibility['preserve_reasoning'] || !isset($delta['reasoning_content'])) { return; }
        if (!is_string($delta['reasoning_content'])) { throw new ProviderException('Malformed reasoning continuation delta'); }
        $message['reasoning_content'] = ($message['reasoning_content'] ?? '') . $delta['reasoning_content'];
    }
    protected function parse(array $data): ModelResponse
    {
        $response = parent::parse($data);
        $state = $response->state;
        if ($this->compatibility['preserve_reasoning'] && isset($data['choices'][0]['message']['reasoning_content'])) {
            $reasoning = $data['choices'][0]['message']['reasoning_content'];
            if (!is_string($reasoning)) { throw new ProviderException('Malformed reasoning continuation state'); }
            $state['reasoning_content'] = $reasoning;
        }
        $response->message = $response->message->withMetadata(['openai_compatible' => $state]);
        $response->state = $state;
        return $response;
    }
    protected function finishReason($reason): string
    {
        if (in_array($reason, ['insufficient_system_resource', 'aborted'], true)) { return 'incomplete'; }
        return parent::finishReason($reason);
    }
}
