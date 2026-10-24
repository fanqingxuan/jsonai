<?php
namespace JsonAI\Provider\OpenAI;

use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\ModelResponse;
use JsonAI\Provider\ProviderException;
use JsonAI\Transport\SseDecoder;

/** Chat Completions protocol adapter; model-specific feature support remains the caller's choice. */
class ChatCompletionsProvider extends AbstractHttpProvider
{
    public function name(): string { return 'openai.chat_completions'; }
    public function capabilities(): array { return ['tools' => true, 'streaming' => true, 'structured_output' => true, 'images' => true, 'files' => true, 'audio' => true]; }

    public function complete(ModelRequest $request): ModelResponse
    {
        $response = $this->send('/chat/completions', $this->payload($request, false), $request);
        return $this->parse($this->object($response->body));
    }

    public function stream(ModelRequest $request, callable $onEvent): ModelResponse
    {
        $decoder = new SseDecoder();
        $done = false;
        $finish = null;
        $message = ['role' => 'assistant', 'content' => ''];
        $calls = [];
        $usage = [];
        $id = null;
        $consume = function (array $sse) use (&$done, &$finish, &$message, &$calls, &$usage, &$id, $onEvent) {
            if ($done) { throw new ProviderException('Unexpected data after Chat Completions terminal marker.'); }
            if ($sse['data'] === '[DONE]') { $done = true; return; }
            $chunk = $this->object($sse['data']);
            if (isset($chunk['error'])) { throw new ProviderException('Model stream failed.'); }
            if (isset($chunk['id'])) {
                if ($id !== null && $id !== $chunk['id']) { throw new ProviderException('Mixed response IDs in model stream.'); }
                $id = $chunk['id'];
            }
            if (isset($chunk['usage']) && is_array($chunk['usage'])) { $usage = $chunk['usage']; }
            if (!isset($chunk['choices']) || !is_array($chunk['choices']) || count($chunk['choices']) > 1) { throw new ProviderException('Malformed model choices.'); }
            if (!$chunk['choices']) { return; }
            $choice = $chunk['choices'][0];
            if (isset($choice['index']) && $choice['index'] !== 0) { throw new ProviderException('Only one completion choice is supported.'); }
            if ($finish !== null) { throw new ProviderException('Model emitted choice data after its finish reason.'); }
            $delta = isset($choice['delta']) ? $choice['delta'] : [];
            if (!is_array($delta)) { throw new ProviderException('Malformed model delta.'); }
            if (isset($delta['role']) && $delta['role'] !== 'assistant') { throw new ProviderException('Unexpected model role.'); }
            if (isset($delta['content'])) {
                if (!is_string($delta['content'])) { throw new ProviderException('Unsupported streamed output content.'); }
                $message['content'] .= $delta['content'];
                $onEvent(['type' => 'text.delta', 'delta' => $delta['content']]);
            }
            if (isset($delta['refusal'])) {
                if (!is_string($delta['refusal'])) { throw new ProviderException('Malformed refusal delta.'); }
                $message['refusal'] = (isset($message['refusal']) ? $message['refusal'] : '') . $delta['refusal'];
                $onEvent(['type' => 'text.delta', 'delta' => $delta['refusal']]);
            }
            if (isset($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $call) {
                    if (!isset($call['index']) || !is_int($call['index']) || $call['index'] < 0) { throw new ProviderException('Streamed tool call requires an index.'); }
                    $index = $call['index'];
                    if (!isset($calls[$index])) { $calls[$index] = ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']]; }
                    if (isset($call['type']) && $call['type'] !== 'function') { throw new ProviderException('Unsupported streamed tool type.'); }
                    if (isset($call['id'])) { $calls[$index]['id'] .= $call['id']; }
                    foreach (['name', 'arguments'] as $key) {
                        if (isset($call['function'][$key])) {
                            if (!is_string($call['function'][$key])) { throw new ProviderException('Malformed tool call fragment.'); }
                            $calls[$index]['function'][$key] .= $call['function'][$key];
                        }
                    }
                }
            }
            if (isset($choice['finish_reason'])) { $finish = $choice['finish_reason']; }
            // Provider-specific continuation data never becomes a public text delta.
            $this->collectReasoning($message, $delta);
        };
        $this->send('/chat/completions', $this->payload($request, true), $request, function (string $bytes) use ($decoder, $consume) { $decoder->push($bytes, $consume); });
        $decoder->finish($consume);
        if (!$done || $finish === null) { throw new ProviderException('Model stream ended without finish_reason and [DONE].'); }
        ksort($calls, SORT_NUMERIC);
        $message['tool_calls'] = array_values($calls);
        return $this->parse(['id' => $id, 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]], 'usage' => $usage]);
    }

    protected function payload(ModelRequest $request, bool $stream): array
    {
        $options = $this->chatOptions($request);
        if (isset($options['max_output_tokens'])) {
            if (isset($options['max_tokens'])) { throw new \InvalidArgumentException('Specify max_output_tokens or max_tokens, not both.'); }
            $options['max_completion_tokens'] = $options['max_output_tokens'];
            unset($options['max_output_tokens']);
        }
        $messages = array_map([$this, 'encodeMessage'], $request->messages);
        $payload = array_merge($options, ['model' => $request->model, 'messages' => $messages, 'stream' => $stream, 'n' => 1]);
        if ($stream) { $payload['stream_options'] = ['include_usage' => true]; }
        if ($request->tools) { $payload['tools'] = $this->encodeTools($request->tools, false); }
        if (isset($request->options['response_schema'])) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => ['name' => 'agent_response', 'strict' => true, 'schema' => $this->schema($request->options['response_schema'], true)]];
        }
        return $payload;
    }

    protected function chatOptions(ModelRequest $request, array $extra = []): array
    {
        return $this->options($request, array_merge([
            'temperature', 'top_p', 'max_output_tokens', 'max_tokens', 'tool_choice', 'parallel_tool_calls',
            'reasoning_effort', 'service_tier', 'store', 'metadata', 'seed', 'stop', 'presence_penalty', 'frequency_penalty',
        ], $extra));
    }

    protected function encodeMessage(Message $message): array
    {
        $entry = ['role' => $message->role(), 'content' => $this->content($message)];
        if ($message->toolCallId() !== null) { $entry['tool_call_id'] = $message->toolCallId(); }
        if ($message->toolCalls()) {
            if ($message->text() === '') { $entry['content'] = null; }
            foreach ($message->toolCalls() as $call) {
                $entry['tool_calls'][] = ['id' => $call->id(), 'type' => 'function', 'function' => [
                    'name' => $call->name(), 'arguments' => $call->arguments(),
                ]];
            }
        }
        return $entry;
    }

    protected function encodeTools(array $tools, ?bool $strict): array
    {
        $encoded = [];
        foreach ($tools as $tool) {
            $function = ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $this->schema($tool['parameters'])];
            if ($strict !== null) { $function['strict'] = $strict; }
            $encoded[] = ['type' => 'function', 'function' => $function];
        }
        return $encoded;
    }

    private function content(Message $message)
    {
        if (is_string($message->content())) { return $message->content(); }
        $parts = [];
        foreach ($message->content() as $part) {
            if ($part['type'] === 'text') { $parts[] = ['type' => 'text', 'text' => $part['text']]; }
            elseif ($message->role() !== 'user') { throw new \InvalidArgumentException('Multimodal input is supported only for user messages.'); }
            elseif ($part['type'] === 'image') { $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $part['url'], 'detail' => isset($part['detail']) ? $part['detail'] : 'auto']]; }
            elseif ($part['type'] === 'file') { $parts[] = ['type' => 'file', 'file' => ['file_id' => $part['file_id']]]; }
            elseif ($part['type'] === 'audio') { $parts[] = ['type' => 'input_audio', 'input_audio' => ['data' => $part['data'], 'format' => $part['format']]]; }
        }
        return $parts;
    }

    protected function parse(array $data): ModelResponse
    {
        if (!isset($data['choices']) || !is_array($data['choices']) || count($data['choices']) !== 1) { throw new ProviderException('Model response requires exactly one choice.'); }
        $choice = $data['choices'][0];
        if (!isset($choice['message'], $choice['finish_reason']) || !is_array($choice['message'])) { throw new ProviderException('Model response is incomplete.'); }
        $entry = $choice['message'];
        if (!isset($entry['role']) || $entry['role'] !== 'assistant') { throw new ProviderException('Unexpected model response role.'); }
        $text = isset($entry['content']) ? $entry['content'] : '';
        if (!is_string($text)) { throw new ProviderException('Only textual assistant output is supported.'); }
        if (isset($entry['refusal']) && $entry['refusal'] !== '') { $text .= $entry['refusal']; }
        $calls = [];
        foreach (isset($entry['tool_calls']) ? $entry['tool_calls'] : [] as $call) {
            if (!isset($call['id'], $call['type'], $call['function']['name'], $call['function']['arguments']) || $call['type'] !== 'function' || !is_string($call['function']['arguments'])) { throw new ProviderException('Malformed model tool call.'); }
            $calls[] = new ToolCall($call['id'], $call['function']['name'], $call['function']['arguments']);
        }
        $finish = $this->finishReason($choice['finish_reason']);
        if (($finish === 'tool_calls' && !$calls) || ($finish === 'stop' && $calls)) { throw new ProviderException('Model finish reason conflicts with tool calls.'); }
        if (!empty($entry['refusal'])) { $finish = 'content_filter'; }
        $state = ['response_id' => isset($data['id']) ? $data['id'] : null];
        return new ModelResponse(new Message('assistant', $text, $calls, null, ['openai_chat' => $state]), $this->usage(isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : []), $finish, $state);
    }
    protected function collectReasoning(array &$message, array $delta): void {}
    protected function finishReason($reason): string
    {
        if (!in_array($reason, ['stop', 'tool_calls', 'length', 'content_filter'], true)) { throw new ProviderException('Unsupported model finish reason.'); }
        return $reason;
    }
}
