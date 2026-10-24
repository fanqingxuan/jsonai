<?php
namespace JsonAI\Provider\OpenAI;

use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\ModelResponse;
use JsonAI\Provider\ProviderException;
use JsonAI\Transport\SseDecoder;

/** Native Responses API. Replays full output items, retaining encrypted reasoning and phase. */
final class ResponsesProvider extends AbstractHttpProvider
{
    public function name(): string { return 'openai.responses'; }
    public function capabilities(): array { return ['tools' => true, 'streaming' => true, 'structured_output' => true, 'images' => true, 'files' => true, 'audio' => false]; }

    public function complete(ModelRequest $request): ModelResponse
    {
        $response = $this->send('/responses', $this->payload($request, false), $request);
        return $this->parse($this->object($response->body));
    }

    public function stream(ModelRequest $request, callable $onEvent): ModelResponse
    {
        $decoder = new SseDecoder();
        $terminal = null;
        $calls = [];
        $text = '';
        $consume = function (array $sse) use (&$terminal, &$calls, &$text, $onEvent) {
            if ($sse['data'] === '[DONE]') { return; }
            $event = $this->object($sse['data']);
            if (!isset($event['type'])) { throw new ProviderException('Responses stream event is missing its type.'); }
            $type = $event['type'];
            if ($terminal !== null) { throw new ProviderException('Unexpected data after terminal response.'); }
            if ($type === 'error' || $type === 'response.failed') { throw new ProviderException('Model stream failed.'); }
            if ($type === 'response.output_text.delta' || $type === 'response.refusal.delta') {
                if (!isset($event['delta']) || !is_string($event['delta'])) { throw new ProviderException('Invalid model text delta.'); }
                $text .= $event['delta'];
                $onEvent(['type' => 'text.delta', 'delta' => $event['delta']]);
            } elseif ($type === 'response.output_item.added' && isset($event['item']['type']) && $event['item']['type'] === 'function_call') {
                if (!isset($event['output_index'], $event['item']['id'], $event['item']['call_id'], $event['item']['name'])) { throw new ProviderException('Invalid streamed tool call.'); }
                $index = $event['output_index'];
                if (isset($calls[$index])) { throw new ProviderException('Duplicate streamed tool call.'); }
                $calls[$index] = $event['item'];
                $calls[$index]['arguments'] = isset($event['item']['arguments']) ? $event['item']['arguments'] : '';
            } elseif ($type === 'response.function_call_arguments.delta') {
                $index = isset($event['output_index']) ? $event['output_index'] : -1;
                if (!isset($calls[$index], $event['delta'], $event['item_id']) || $calls[$index]['id'] !== $event['item_id'] || !is_string($event['delta'])) { throw new ProviderException('Tool argument delta has no matching call.'); }
                $calls[$index]['arguments'] .= $event['delta'];
            } elseif ($type === 'response.function_call_arguments.done') {
                $index = isset($event['output_index']) ? $event['output_index'] : -1;
                if (!isset($calls[$index], $event['arguments']) || $calls[$index]['arguments'] !== $event['arguments']) { throw new ProviderException('Completed tool arguments do not match their deltas.'); }
            } elseif ($type === 'response.completed' || $type === 'response.incomplete') {
                if (!isset($event['response']) || !is_array($event['response'])) { throw new ProviderException('Terminal event has no response.'); }
                $terminal = $event['response'];
                $expected = $type === 'response.completed' ? 'completed' : 'incomplete';
                if (!isset($terminal['status']) || $terminal['status'] !== $expected) { throw new ProviderException('Terminal event status does not match response.'); }
            }
            // Reasoning, reasoning summaries and raw tool argument fragments are never public events.
        };
        $this->send('/responses', $this->payload($request, true), $request, function (string $bytes) use ($decoder, $consume) { $decoder->push($bytes, $consume); });
        $decoder->finish($consume);
        if ($terminal === null) { throw new ProviderException('Model stream ended without a terminal response.'); }
        $response = $this->parse($terminal);
        $finalCalls = [];
        foreach ($response->message->toolCalls() as $call) { $finalCalls[$call->id()] = $call; }
        foreach ($calls as $call) {
            if (!isset($finalCalls[$call['call_id']]) || $finalCalls[$call['call_id']]->arguments() !== $call['arguments'] || $finalCalls[$call['call_id']]->name() !== $call['name']) { throw new ProviderException('Terminal response does not match streamed tool calls.'); }
        }
        $finalText = $response->message->text();
        if (strncmp($finalText, $text, strlen($text)) !== 0) { throw new ProviderException('Terminal response does not match streamed text.'); }
        if (strlen($finalText) > strlen($text)) { $onEvent(['type' => 'text.delta', 'delta' => substr($finalText, strlen($text))]); }
        return $response;
    }

    private function payload(ModelRequest $request, bool $stream): array
    {
        $options = $this->options($request, ['temperature', 'top_p', 'max_output_tokens', 'tool_choice', 'parallel_tool_calls', 'reasoning', 'service_tier', 'store', 'metadata', 'truncation', 'text']);
        if (isset($options['truncation']) && $options['truncation'] !== 'disabled') { throw new \InvalidArgumentException('Provider-side truncation is disabled; the framework preserves complete tool exchanges.'); }
        $input = [];
        foreach ($request->messages as $message) {
            $metadata = $this->plain($message->metadata());
            if ($message->role() === 'assistant' && isset($metadata['openai_responses'])) {
                $state = $metadata['openai_responses'];
                if (!isset($state['endpoint'], $state['output']) || $state['endpoint'] !== $this->baseUrl || !is_array($state['output'])) { throw new \InvalidArgumentException('Native response state belongs to a different endpoint or is malformed.'); }
                foreach ($state['output'] as $item) { $input[] = $item; }
                continue;
            }
            if ($message->role() === 'tool') {
                if (!is_string($message->content())) { throw new \InvalidArgumentException('Tool results must be serialized text.'); }
                $input[] = ['type' => 'function_call_output', 'call_id' => $message->toolCallId(), 'output' => $message->text()];
                continue;
            }
            $content = $this->content($message);
            if ($content !== [] || !$message->toolCalls()) {
                // Easy assistant messages use a string; native output_text blocks replay above.
                $input[] = ['role' => $message->role(), 'content' => $message->role() === 'assistant' ? $message->text() : $content];
            }
            foreach ($message->toolCalls() as $call) {
                $input[] = ['type' => 'function_call', 'call_id' => $call->id(), 'name' => $call->name(), 'arguments' => $call->arguments()];
            }
        }
        $payload = array_merge(['store' => false, 'truncation' => 'disabled'], $options, ['model' => $request->model, 'input' => $input, 'stream' => $stream, 'include' => ['reasoning.encrypted_content']]);
        if ($request->tools) {
            $payload['tools'] = [];
            foreach ($request->tools as $tool) { $payload['tools'][] = ['type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $this->schema($tool['parameters']), 'strict' => false]; }
        }
        if (isset($request->options['response_schema'])) {
            if (isset($payload['text']['format'])) { throw new \InvalidArgumentException('Configure either response_schema or native text.format, not both.'); }
            if (!isset($payload['text'])) { $payload['text'] = []; }
            $payload['text']['format'] = ['type' => 'json_schema', 'name' => 'agent_response', 'strict' => true, 'schema' => $this->schema($request->options['response_schema'], true)];
        }
        return $payload;
    }

    private function content(Message $message): array
    {
        if (is_string($message->content())) {
            return $message->content() === '' ? [] : [['type' => $message->role() === 'assistant' ? 'output_text' : 'input_text', 'text' => $message->text()]];
        }
        $content = [];
        foreach ($message->content() as $part) {
            if ($part['type'] === 'text') { $content[] = ['type' => $message->role() === 'assistant' ? 'output_text' : 'input_text', 'text' => $part['text']]; }
            elseif ($message->role() !== 'user') { throw new \InvalidArgumentException('Multimodal input is supported only for user messages.'); }
            elseif ($part['type'] === 'image') { $content[] = ['type' => 'input_image', 'image_url' => $part['url'], 'detail' => isset($part['detail']) ? $part['detail'] : 'auto']; }
            elseif ($part['type'] === 'file') { $content[] = ['type' => 'input_file', 'file_id' => $part['file_id']]; }
            else { throw new \InvalidArgumentException('ResponsesProvider does not support audio input.'); }
        }
        return $content;
    }

    private function parse(array $data): ModelResponse
    {
        if (!isset($data['status'], $data['output']) || !is_array($data['output']) || !in_array($data['status'], ['completed', 'incomplete'], true)) {
            throw new ProviderException('Responses API did not return a completed or explicitly incomplete response.');
        }
        $text = '';
        $calls = [];
        $native = [];
        $refusal = false;
        foreach ($data['output'] as $item) {
            if (!is_array($item) || !isset($item['type'])) { throw new ProviderException('Malformed Responses output item.'); }
            if ($item['type'] === 'message') {
                if (!isset($item['content']) || !is_array($item['content'])) { throw new ProviderException('Malformed Responses message.'); }
                foreach ($item['content'] as $part) {
                    if (isset($part['type']) && $part['type'] === 'output_text' && isset($part['text'])) { $text .= $part['text']; }
                    elseif (isset($part['type']) && $part['type'] === 'refusal' && isset($part['refusal'])) { $text .= $part['refusal']; $refusal = true; }
                    else { throw new ProviderException('Unsupported Responses output content.'); }
                }
            } elseif ($item['type'] === 'function_call') {
                if (!isset($item['call_id'], $item['name'], $item['arguments']) || !is_string($item['arguments'])) { throw new ProviderException('Malformed Responses tool call.'); }
                $calls[] = new ToolCall($item['call_id'], $item['name'], $item['arguments']);
            } elseif ($item['type'] === 'reasoning') {
                // Retain only opaque state and public summary; never persist raw reasoning content.
                unset($item['content']);
            } else { throw new ProviderException('Unsupported native output item; only registered function tools are enabled.'); }
            $native[] = $item;
        }
        $finish = $calls ? 'tool_calls' : 'stop';
        if ($refusal) { $finish = 'content_filter'; }
        if ($data['status'] === 'incomplete') {
            $reason = isset($data['incomplete_details']['reason']) ? $data['incomplete_details']['reason'] : '';
            $finish = $reason === 'max_output_tokens' ? 'length' : ($reason === 'content_filter' ? 'content_filter' : 'incomplete');
        }
        $state = ['response_id' => isset($data['id']) ? $data['id'] : null, 'endpoint' => $this->baseUrl, 'output' => $native];
        $message = new Message('assistant', $text, $calls, null, ['openai_responses' => $state]);
        return new ModelResponse($message, $this->usage(isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : []), $finish, $state);
    }
}
