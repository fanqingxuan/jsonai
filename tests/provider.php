<?php
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Message\Content\TextPart;
use JsonAI\Message\Content\ImagePart;
use JsonAI\Message\Content\FilePart;
use JsonAI\Message\Content\AudioPart;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\ModelResponse;
use JsonAI\Provider\ProviderException;
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Support\Json;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;
use JsonAI\Transport\SseDecoder;
use JsonAI\Transport\TransportException;
use JsonAI\Transport\CurlTransport;

return function ($suite) {
    $frame = function (array $event): string { return 'data: ' . Json::encode($event) . "\n\n"; };
    $response = function (array $output = [], string $status = 'completed'): array {
        return ['id' => 'resp_fixture', 'status' => $status, 'output' => $output, 'usage' => ['input_tokens' => 11, 'output_tokens' => 7, 'total_tokens' => 18]];
    };
    $nativeText = function (string $text): array {
        return ['type' => 'message', 'id' => 'msg_fixture', 'status' => 'completed', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    };
    $chat = function (string $text = 'ok', array $calls = [], string $finish = 'stop'): array {
        return ['id' => 'chat_fixture', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text, 'tool_calls' => $calls], 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18]];
    };
    $suite->test('message roundtrip preserves IDs, raw tool arguments, metadata and multimodal content', function () use ($suite) {
        $message = new Message('assistant', '查库存', [new ToolCall('call_a', 'inventory', '{"id":"9223372036854775808"}')], null, ['native' => ['encrypted' => 'opaque']]);
        $copy = Message::fromArray((array) Json::decode(Json::encode($message->toArray())));
        $suite->same($message->id(), $copy->id());
        $suite->same($message->toolCalls()[0]->arguments(), $copy->toolCalls()[0]->arguments());
        $suite->same(Json::encode($message->metadata()), Json::encode($copy->metadata()));
        $multi = new Message('user', [new TextPart('看图'), new ImagePart('https://example.test/image.png'), new FilePart('file_123'), new AudioPart(base64_encode('audio'))]);
        $suite->same('看图', $multi->text());
        $suite->same(4, count(Message::fromArray((array) Json::decode(Json::encode($multi->toArray())))->content()));
        $suite->throws(InvalidArgumentException::class, function () { new Message('user', '', [new ToolCall('x', 'tool', '{}')]); });
        $suite->throws(InvalidArgumentException::class, function () { new Message('tool', 'unlinked'); });
        $suite->throws(InvalidArgumentException::class, function () { new Message('user', [['type' => 'text']]); });
    });
    $suite->test('SSE handles every byte boundary, BOM, comments, CRLF, CR and multiline data', function () use ($suite) {
        $decoder = new SseDecoder();
        $events = [];
        $consume = function ($event) use (&$events) { $events[] = $event; };
        $wire = "\xEF\xBB\xBF: heartbeat\r\nevent: token\r\nid: 1\r\ndata: 中文\r\ndata: next\r\n\r\ndata: final\r\r";
        foreach (str_split($wire) as $byte) { $decoder->push($byte, $consume); }
        $decoder->finish($consume);
        $suite->same(2, count($events));
        $suite->same("中文\nnext", $events[0]['data']);
        $suite->same('token', $events[0]['event']);
        $suite->same('1', $events[1]['id']);
        $suite->same('final', $events[1]['data']);
    });
    $suite->test('SSE rejects truncated events and bounded oversized events', function () use ($suite) {
        $consume = function () {};
        $suite->throws(TransportException::class, function () use ($consume) { $decoder = new SseDecoder(); $decoder->push("data: partial\n", $consume); $decoder->finish($consume); });
        $suite->throws(TransportException::class, function () use ($consume) { (new SseDecoder(8))->push('data: overflow', $consume); });
    });
    $suite->test('Responses maps registered tools and preserves native encrypted state across rounds', function () use ($suite, $response, $nativeText) {
        $native = [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'opaque'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'inventory', 'arguments' => '{"sku":"A"}', 'status' => 'completed']
        ];
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode($response($native))), new HttpResponse(200, [], Json::encode($response([$nativeText('有货')])))]);
        $provider = new ResponsesProvider('fixture-key', $transport);
        $tool = ['name' => 'inventory', 'description' => '查询库存', 'parameters' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false]];
        $first = $provider->complete(new ModelRequest('fixture-model', [Message::user('查库存')], [$tool], ['timeout' => 2]));
        $suite->same('tool_calls', $first->finishReason);
        $suite->same('call_1', $first->message->toolCalls()[0]->id());
        $copy = Message::fromArray((array) Json::decode(Json::encode($first->message->toArray())));
        $second = $provider->complete(new ModelRequest('fixture-model', [Message::user('查库存'), $copy, Message::tool('call_1', '{"available":true}')], [$tool]));
        $suite->same('有货', $second->message->text());
        $suite->same(['input_tokens' => 11, 'output_tokens' => 7, 'total_tokens' => 18], $first->usage);
        $requests = $transport->requests();
        $body = Json::decode($requests[0]['body']);
        $suite->same('https://api.openai.com/v1/responses', $requests[0]['url']);
        $suite->same(2.0, $requests[0]['timeout']);
        $suite->true($body->tools[0]->parameters->properties instanceof stdClass);
        $suite->same(false, $body->tools[0]->strict);
        $replay = Json::decode($requests[1]['body']);
        $suite->same('opaque', $replay->input[1]->encrypted_content);
        $suite->same('call_1', $replay->input[3]->call_id);
        $suite->same(false, $replay->store);
    });
    $suite->test('Responses streaming accumulates split tools, outputs Unicode and ignores reasoning', function () use ($suite, $response, $frame, $nativeText) {
        $call = ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'inventory', 'arguments' => ''];
        $finalCall = $call;
        $finalCall['arguments'] = '{"sku":"茶"}';
        $wire = $frame(['type' => 'response.output_item.added', 'output_index' => 1, 'item' => $call])
            . $frame(['type' => 'response.reasoning_text.delta', 'delta' => 'private reasoning'])
            . $frame(['type' => 'response.output_text.delta', 'delta' => '查询'])
            . $frame(['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'item_id' => 'fc_1', 'delta' => '{"sku":'])
            . $frame(['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'item_id' => 'fc_1', 'delta' => '"茶"}'])
            . $frame(['type' => 'response.function_call_arguments.done', 'output_index' => 1, 'item_id' => 'fc_1', 'arguments' => $finalCall['arguments']])
            . $frame(['type' => 'response.completed', 'response' => $response([$nativeText('查询'), $finalCall])]);
        $provider = new ResponsesProvider('fixture', new FakeTransport([['response' => new HttpResponse(200), 'chunks' => str_split($wire)]]));
        $events = [];
        $result = $provider->stream(new ModelRequest('fixture', [Message::user('查')]), function ($event) use (&$events) { $events[] = $event; });
        $suite->same([['type' => 'text.delta', 'delta' => '查询']], $events);
        $suite->same('{"sku":"茶"}', $result->message->toolCalls()[0]->arguments());
    });
    $suite->test('Responses rejects missing terminals, failed streams and inconsistent argument completion', function () use ($suite, $frame) {
        $call = ['type' => 'function_call', 'id' => 'fc', 'call_id' => 'call', 'name' => 'lookup', 'arguments' => ''];
        $wires = [
            $frame(['type' => 'response.output_text.delta', 'delta' => 'partial']),
            $frame(['type' => 'response.failed']),
            $frame(['type' => 'response.output_item.added', 'output_index' => 0, 'item' => $call]) . $frame(['type' => 'response.function_call_arguments.done', 'output_index' => 0, 'arguments' => '{}'])
        ];
        foreach ($wires as $wire) {
            $provider = new ResponsesProvider('fixture', new FakeTransport([['response' => new HttpResponse(200), 'chunks' => [$wire]]]));
            $suite->throws(ProviderException::class, function () use ($provider) { $provider->stream(new ModelRequest('fixture', []), function () {}); });
        }
    });
    $suite->test('Responses incomplete response is explicit and unsupported output is rejected', function () use ($suite, $response, $nativeText) {
        $data = $response([$nativeText('partial')], 'incomplete');
        $data['incomplete_details'] = ['reason' => 'max_output_tokens'];
        $provider = new ResponsesProvider('fixture', new FakeTransport([new HttpResponse(200, [], Json::encode($data)), new HttpResponse(200, [], Json::encode($response([['type' => 'web_search_call']])))]));
        $suite->same('length', $provider->complete(new ModelRequest('fixture', []))->finishReason);
        $suite->throws(ProviderException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [])); });
    });
    $suite->test('Responses multimodal mapping rejects unsupported audio before HTTP', function () use ($suite, $response) {
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode($response()))]);
        $provider = new ResponsesProvider('fixture', $transport);
        $provider->complete(new ModelRequest('fixture', [new Message('user', [new TextPart('read'), new ImagePart('data:image/png;base64,AA=='), new FilePart('file_1')])]));
        $body = Json::decode($transport->requests()[0]['body']);
        $suite->same('input_image', $body->input[0]->content[1]->type);
        $suite->same('file_1', $body->input[0]->content[2]->file_id);
        $suite->throws(InvalidArgumentException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [new Message('user', [new AudioPart('YQ==')])])); });
        $suite->same(1, count($transport->requests()));
    });
    $suite->test('Chat maps endpoint, role-linked tool messages, token options and nested tools', function () use ($suite, $chat) {
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode($chat()))]);
        $provider = new ChatCompletionsProvider('fixture', $transport, 'http://localhost:9999/api/v1/');
        $request = new ModelRequest('fixture', [Message::assistant('', [new ToolCall('call1', 'lookup', '{}')]), Message::tool('call1', '{"ok":true}')], [['name' => 'lookup', 'description' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => []]]], ['max_output_tokens' => 123]);
        $result = $provider->complete($request);
        $body = Json::decode($transport->requests()[0]['body']);
        $suite->same('http://localhost:9999/api/v1/chat/completions', $transport->requests()[0]['url']);
        $suite->same('lookup', $body->tools[0]->function->name);
        $suite->same('call1', $body->messages[1]->tool_call_id);
        $suite->same(123, $body->max_completion_tokens);
        $suite->same(null, $body->messages[0]->content);
        $suite->same(18, $result->usage['total_tokens']);
    });
    $suite->test('Chat streaming accumulates parallel tool indices without exposing argument fragments', function () use ($suite, $frame) {
        $chunk = function (array $delta, $finish = null): array { return ['id' => 'chat_1', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]]; };
        $wire = $frame($chunk(['role' => 'assistant', 'reasoning_content' => 'private', 'tool_calls' => [
            ['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{']],
            ['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"id":']]
        ]])) . $frame($chunk(['tool_calls' => [
            ['index' => 1, 'function' => ['arguments' => '2}']], ['index' => 0, 'function' => ['arguments' => '"id":1}']]
        ]])) . $frame($chunk([], 'tool_calls')) . $frame(['id' => 'chat_1', 'choices' => [], 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3, 'total_tokens' => 5]]) . "data: [DONE]\n\n";
        $provider = new ChatCompletionsProvider('fixture', new FakeTransport([['response' => new HttpResponse(200), 'chunks' => str_split($wire, 7)]]));
        $events = [];
        $result = $provider->stream(new ModelRequest('fixture', []), function ($event) use (&$events) { $events[] = $event; });
        $suite->same([], $events);
        $suite->same('{"id":1}', $result->message->toolCalls()[0]->arguments());
        $suite->same('{"id":2}', $result->message->toolCalls()[1]->arguments());
        $suite->same(5, $result->usage['total_tokens']);
    });
    $suite->test('Chat requires both finish reason and DONE, rejects malformed stream JSON', function () use ($suite, $frame) {
        $wires = ["data: [DONE]\n\n", $frame(['choices' => [['index' => 0, 'delta' => ['content' => 'partial'], 'finish_reason' => 'stop']]]), "data: {bad}\n\n"];
        foreach ($wires as $wire) {
            $provider = new ChatCompletionsProvider('fixture', new FakeTransport([['response' => new HttpResponse(200), 'chunks' => [$wire]]]));
            $suite->throws(ProviderException::class, function () use ($provider) { $provider->stream(new ModelRequest('fixture', []), function () {}); });
        }
    });
    $suite->test('HTTP provider errors classify retries without leaking response body or retrying', function () use ($suite) {
        $transport = new FakeTransport([new HttpResponse(429, [], 'secret-credential'), new HttpResponse(400, [], 'private-prompt')]);
        $provider = new ResponsesProvider('secret', $transport);
        $first = $suite->throws(ProviderException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [])); });
        $suite->same(true, $first->retryable());
        $suite->same(429, $first->statusCode());
        $suite->true(strpos($first->getMessage(), 'secret') === false);
        $suite->same(1, count($transport->requests()));
        $second = $suite->throws(ProviderException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [])); });
        $suite->same(false, $second->retryable());
    });
    $suite->test('Structured output schema requires closed required objects and preserves empty maps', function () use ($suite, $response) {
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode($response()))]);
        $provider = new ResponsesProvider('fixture', $transport);
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => ['string', 'null']]], 'required' => ['name'], 'additionalProperties' => false];
        $provider->complete(new ModelRequest('fixture', [], [], ['response_schema' => $schema]));
        $suite->same('json_schema', Json::decode($transport->requests()[0]['body'])->text->format->type);
        unset($schema['required']);
        $suite->throws(InvalidArgumentException::class, function () use ($provider, $schema) { $provider->complete(new ModelRequest('fixture', [], [], ['response_schema' => $schema])); });
        $suite->same(1, count($transport->requests()));
    });
    $suite->test('Reserved and unsupported options cannot bypass canonical request construction', function () use ($suite) {
        $suite->throws(InvalidArgumentException::class, function () { new ModelRequest('fixture', [], [], ['tools' => []]); });
        $provider = new ResponsesProvider('fixture', new FakeTransport());
        $suite->throws(InvalidArgumentException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [], [], ['truncation' => 'auto'])); });
        $suite->throws(InvalidArgumentException::class, function () use ($provider) { $provider->complete(new ModelRequest('fixture', [], [], ['unknown' => true])); });
    });
    $suite->test('Stream consumer exception propagates without wrapping or retrying', function () use ($suite, $frame) {
        $transport = new FakeTransport([['response' => new HttpResponse(200), 'chunks' => [$frame(['type' => 'response.output_text.delta', 'delta' => 'text'])]]]);
        $provider = new ResponsesProvider('fixture', $transport);
        $expected = new LogicException('sink failed');
        $actual = $suite->throws(LogicException::class, function () use ($provider, $expected) { $provider->stream(new ModelRequest('fixture', []), function () use ($expected) { throw $expected; }); });
        $suite->true($actual === $expected);
        $suite->same(1, count($transport->requests()));
    });
    $suite->test('FakeProvider captures requests and streams Unicode codepoints deterministically', function () use ($suite) {
        $fake = new FakeProvider(['中文', function (ModelRequest $request) { return new ModelResponse(Message::assistant($request->model)); }, 'next']);
        $events = [];
        $fake->stream(new ModelRequest('fixture', []), function ($event) use (&$events) { $events[] = $event['delta']; });
        $suite->same(['中', '文'], $events);
        $suite->same('next', $fake->complete(new ModelRequest('next', []))->message->text());
        $suite->same(2, count($fake->requests()));
        $suite->same('next', $fake->complete(new ModelRequest('fixture', []))->message->text());
    });
    if (!extension_loaded('curl') || !function_exists('proc_open')) {
        $suite->skip('loopback cURL integration requires ext-curl and proc_open');
    } else {
        $suite->test('CurlTransport loopback verifies HTTP status, streaming, callback failure and timeout', function () use ($suite) {
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            if ($socket === false) { throw new RuntimeException('Cannot reserve a loopback port for transport tests.'); }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $path = tempnam(sys_get_temp_dir(), 'agent-http-');
            $router = <<<'ROUTER'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/error') { http_response_code(429); echo 'private-error'; return; }
if ($path === '/redirect') { header('Location: /json', true, 302); return; }
if ($path === '/slow') { usleep(250000); echo 'late'; return; }
if ($path === '/stream') { header('Content-Type: text/event-stream'); echo "data: 中文\n\n"; flush(); echo "data: end\n\n"; return; }
header('Content-Type: application/json');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'body' => file_get_contents('php://input')]);
ROUTER;
            file_put_contents($path, $router);
            $log = tmpfile();
            $process = proc_open(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' ' . escapeshellarg($path), [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes);
            if (!is_resource($process)) { unlink($path); fclose($log); throw new RuntimeException('Cannot start loopback PHP server.'); }
            fclose($pipes[0]);
            try {
                $ready = false;
                for ($attempt = 0; $attempt < 100; $attempt++) {
                    $probe = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.02);
                    if ($probe !== false) { fclose($probe); $ready = true; break; }
                    usleep(10000);
                }
                if (!$ready) { throw new RuntimeException('Loopback server failed to start.'); }
                $base = 'http://' . $address;
                $transport = new CurlTransport();
                $reply = $transport->request('POST', $base . '/json', ['Content-Type' => 'application/json'], '{"test":true}', 2.0);
                $suite->same(200, $reply->status);
                $suite->same('{"test":true}', Json::decode($reply->body)->body);
                $events = [];
                $decoder = new SseDecoder();
                $consume = function ($event) use (&$events) { $events[] = $event['data']; };
                $reply = $transport->request('POST', $base . '/stream', [], '', 2.0, function ($bytes) use ($decoder, $consume) { $decoder->push($bytes, $consume); });
                $decoder->finish($consume);
                $suite->same(['中文', 'end'], $events);
                $suite->same('', $reply->body);
                $called = false;
                $reply = $transport->request('POST', $base . '/error', [], '', 2.0, function () use (&$called) { $called = true; });
                $suite->same(429, $reply->status);
                $suite->same(false, $called);
                $suite->same('private-error', $reply->body);
                $suite->same(302, $transport->request('POST', $base . '/redirect', [], '', 2.0)->status);
                $expected = new LogicException('stream sink stopped');
                $actual = $suite->throws(LogicException::class, function () use ($transport, $base, $expected) {
                    $transport->request('POST', $base . '/stream', [], '', 2.0, function () use ($expected) { throw $expected; });
                });
                $suite->true($actual === $expected);
                $suite->throws(TransportException::class, function () use ($base) { (new CurlTransport(1.0, 5))->request('POST', $base . '/json', [], '', 2.0); });
                $timeout = $suite->throws(TransportException::class, function () use ($transport, $base) { $transport->request('POST', $base . '/slow', [], '', 0.02); });
                $suite->same(true, $timeout->retryable());
            } finally {
                proc_terminate($process);
                proc_close($process);
                fclose($log);
                unlink($path);
            }
        });
    }
};
