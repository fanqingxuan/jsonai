<?php
use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RunException;
use JsonAI\Harness\RequestContext;
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Schema\Schema;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Support\Json;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ExecutionAwareToolInterface;
use JsonAI\Tool\ToolContext;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;

final class HarnessProviderLookupTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'inventory_lookup';
    protected $description = 'Read fixture inventory for a SKU.';
    public static $calls = 0;
    public static $identity = [];
    public function isReadOnly(): bool { return true; }
    public function inputSchema(): Schema { return Schema::object(['sku' => Schema::string()->required()]); }
    public function outputSchema(): Schema { return Schema::object(['sku' => Schema::string()->required(), 'quantity' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array
    {
        self::$calls++;
        self::$identity = $context->identity();
        return ['sku' => $input['sku'], 'quantity' => 12];
    }
}

return function ($suite) {
    $nativeMessage = function (string $text): array { return ['type' => 'message', 'id' => 'msg_native', 'role' => 'assistant', 'status' => 'completed', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]]; };
    $nativeResponse = function (array $output): array { return ['id' => 'resp_native', 'status' => 'completed', 'output' => $output, 'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30]]; };
    $nativeCall = ['type' => 'function_call', 'id' => 'fc_native', 'call_id' => 'call_native', 'name' => 'inventory_lookup', 'arguments' => '{"sku":"tea"}', 'status' => 'completed'];
    $reasoning = ['type' => 'reasoning', 'id' => 'rs_native', 'summary' => [], 'encrypted_content' => 'opaque_fixture_secret'];
    $frame = function (array $event): string { return 'data: ' . Json::encode($event) . "\n\n"; };
    $chat = function (array $message, string $finish): array { return ['id' => 'chat_123', 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30]]; };

    $suite->test('Harness Responses tool loop preserves opaque state, server identity and request idempotence', function () use ($suite, $nativeMessage, $nativeResponse, $nativeCall, $reasoning) {
        HarnessProviderLookupTool::$calls = 0;
        $transport = new FakeTransport([
            new HttpResponse(200, [], Json::encode($nativeResponse([$reasoning, $nativeCall]))),
            new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('库存为 12')]))),
            new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('上一轮查询 tea')]))),
        ]);
        $provider = new ResponsesProvider('test-secret', $transport);
        $agent = Agent::create('fixture')->provider($provider, 'fixture-model')->tools([HarnessProviderLookupTool::class]);
        $harness = new AgentHarness(new InMemoryStore());
        $requestMessage = \JsonAI\Message\Message::user('查询 tea');
        $requestContext = RequestContext::create('thread_native')->withScope('tenant_a')->withIdentity(['user_id' => 7])->withRequestId('same-request');
        $result = $harness->run($agent, $requestMessage, $requestContext);
        $suite->same('completed', $result->status);
        $suite->same('库存为 12', $result->text);
        $suite->same(1, HarnessProviderLookupTool::$calls);
        $suite->same(['user_id' => 7], HarnessProviderLookupTool::$identity);
        $suite->same(60, $result->usage['total_tokens']);
        $body = Json::decode($transport->requests()[1]['body']);
        $suite->same('opaque_fixture_secret', $body->input[1]->encrypted_content);
        $suite->same('function_call_output', $body->input[3]->type);
        $suite->same(12, Json::decode($body->input[3]->output)->data->quantity);
        $suite->same($result->runId, $harness->run($agent, $requestMessage, $requestContext)->runId);
        $suite->same(2, count($transport->requests()));
        $follow = $harness->run($agent, '上一轮查的什么', RequestContext::create('thread_native')->withScope('tenant_a')->withIdentity(['user_id' => 7]));
        $suite->same('completed', $follow->status);
        $third = Json::decode($transport->requests()[2]['body']);
        $suite->same('final_answer', $third->input[4]->phase);
        $suite->same('opaque_fixture_secret', $third->input[1]->encrypted_content);
        $events = Json::encode($harness->threads()->events('tenant_a', 'thread_native', $result->runId));
        $suite->true(strpos($events, 'opaque_fixture_secret') === false);
        $suite->true(strpos($events, 'test-secret') === false);
    });
    $suite->test('Harness Chat tool loop keeps assistant calls and tool results paired', function () use ($suite, $chat) {
        HarnessProviderLookupTool::$calls = 0;
        $call = ['id' => 'call_chat', 'type' => 'function', 'function' => ['name' => 'inventory_lookup', 'arguments' => '{"sku":"rice"}']];
        $transport = new FakeTransport([
            new HttpResponse(200, [], Json::encode($chat(['role' => 'assistant', 'content' => null, 'tool_calls' => [$call]], 'tool_calls'))),
            new HttpResponse(200, [], Json::encode($chat(['role' => 'assistant', 'content' => '米库存 12'], 'stop')))
        ]);
        $agent = Agent::create('chat_fixture')->provider(new ChatCompletionsProvider('test', $transport), 'fixture')->tools([HarnessProviderLookupTool::class]);
        $result = (new AgentHarness())->run($agent, '查米', RequestContext::create('thread_chat'));
        $suite->same('completed', $result->status);
        $suite->same(1, HarnessProviderLookupTool::$calls);
        $body = Json::decode($transport->requests()[1]['body']);
        $suite->same('call_chat', $body->messages[1]->tool_calls[0]->id);
        $suite->same('call_chat', $body->messages[2]->tool_call_id);
        $suite->same('米库存 12', $result->text);
    });
    $suite->test('Harness native streaming produces ordered events across tool rounds and replay', function () use ($suite, $frame, $nativeMessage, $nativeResponse, $nativeCall, $reasoning) {
        HarnessProviderLookupTool::$calls = 0;
        $stream1 = $frame(['type' => 'response.reasoning_text.delta', 'delta' => 'never public']) . $frame(['type' => 'response.completed', 'response' => $nativeResponse([$reasoning, $nativeCall])]);
        $stream2 = $frame(['type' => 'response.output_text.delta', 'delta' => '有']) . $frame(['type' => 'response.output_text.delta', 'delta' => '库存']) . $frame(['type' => 'response.completed', 'response' => $nativeResponse([$nativeMessage('有库存')])]);
        $transport = new FakeTransport([['response' => new HttpResponse(200), 'chunks' => str_split($stream1, 5)], ['response' => new HttpResponse(200), 'chunks' => str_split($stream2, 3)]]);
        $agent = Agent::create('stream_fixture')->provider(new ResponsesProvider('test', $transport), 'fixture')->tools([HarnessProviderLookupTool::class]);
        $harness = new AgentHarness();
        $events = [];
        $result = $harness->stream($agent, '查', RequestContext::create('thread_stream'), function ($event) use (&$events) { $events[] = $event; });
        $suite->same('completed', $result->status);
        $suite->same('run.started', $events[0]['type']);
        $suite->same('run.completed', $events[count($events) - 1]['type']);
        $suite->same(1, HarnessProviderLookupTool::$calls);
        $tokens = '';
        $sequence = 0;
        foreach ($events as $event) {
            $suite->same(++$sequence, $event['sequence']);
            if ($event['type'] === 'message.delta') { $tokens .= $event['data']['delta']; }
        }
        $suite->same('有库存', $tokens);
        $suite->true(strpos(Json::encode($events), 'opaque_fixture_secret') === false);
        $suite->true(strpos(Json::encode($events), 'never public') === false);
        $replayed = [];
        $harness->replay('default', 'thread_stream', $result->runId, 2, function ($event) use (&$replayed) { $replayed[] = $event; });
        $suite->same(array_slice($events, 2), $replayed);
    });
    $suite->test('Harness truncated provider stream commits failure, preserves partial text and notifies live listener', function () use ($suite, $frame) {
        $wire = $frame(['type' => 'response.output_text.delta', 'delta' => '未完成']);
        $transport = new FakeTransport([['response' => new HttpResponse(200), 'chunks' => [$wire]]]);
        $agent = Agent::create('broken')->provider(new ResponsesProvider('test', $transport), 'fixture');
        $harness = new AgentHarness();
        $events = [];
        $result = $harness->stream($agent, 'test', RequestContext::create('broken'), function ($event) use (&$events) { $events[] = $event; });
        $suite->same('failed', $result->status);
        $suite->same('provider_error', $result->finishReason);
        $suite->same(1, count($transport->requests()));
        $run = $harness->threads()->run('default', 'broken', $result->runId);
        $suite->same('未完成', $run['partial_messages'][0]['text']);
        $suite->same('incomplete', $run['partial_messages'][0]['status']);
        $suite->same('run.failed', $events[count($events) - 1]['type']);
    });
    $suite->test('Harness cancellation stops stream before tool execution and delivers terminal cancellation', function () use ($suite, $frame, $nativeResponse, $nativeCall, $nativeMessage) {
        HarnessProviderLookupTool::$calls = 0;
        $wire = $frame(['type' => 'response.output_text.delta', 'delta' => '准备查询']) . $frame(['type' => 'response.completed', 'response' => $nativeResponse([$nativeMessage('准备查询'), $nativeCall])]);
        $transport = new FakeTransport([['response' => new HttpResponse(200), 'chunks' => [$wire]]]);
        $agent = Agent::create('cancel')->provider(new ResponsesProvider('test', $transport), 'fixture')->tools([HarnessProviderLookupTool::class]);
        $harness = new AgentHarness();
        $events = [];
        $result = $harness->stream($agent, 'test', RequestContext::create('cancel'), function ($event) use (&$events, $harness) {
            $events[] = $event;
            if ($event['type'] === 'message.delta') { $harness->cancel('default', 'cancel', $event['run_id']); }
        });
        $suite->same('cancelled', $result->status);
        $suite->same(0, HarnessProviderLookupTool::$calls);
        $suite->same('run.cancelled', $events[count($events) - 1]['type']);
    });
    $suite->test('Harness output schema is sent to provider and invalid final output fails locally', function () use ($suite, $nativeResponse, $nativeMessage) {
        $schema = Schema::object(['quantity' => Schema::integer()->required()]);
        $transport = new FakeTransport([
            new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('{"quantity":12}')]))),
            new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('{"quantity":"bad"}')]))),
            new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('{"metadata":{},"items":[]}')]))),
        ]);
        $agent = Agent::create('structured')->provider(new ResponsesProvider('test', $transport), 'fixture')->outputSchema($schema);
        $harness = new AgentHarness();
        $success = $harness->run($agent, 'valid', RequestContext::create('structured1'));
        $suite->same('completed', $success->status);
        $suite->same(12, $success->output->quantity);
        $suite->same('json_schema', Json::decode($transport->requests()[0]['body'])->text->format->type);
        $failure = $harness->run($agent, 'invalid', RequestContext::create('structured2'));
        $suite->same('failed', $failure->status);
        $suite->same('invalid_output', $failure->finishReason);
        $emptyAgent = $agent->outputSchema(Schema::object(['metadata' => Schema::object()->required(), 'items' => Schema::arrayOf(Schema::string())->required()]));
        $requestMessage = \JsonAI\Message\Message::user('empty containers');
        $requestContext = RequestContext::create('structured3')->withRequestId('same-empty-request');
        $empty = $harness->run($emptyAgent, $requestMessage, $requestContext);
        $suite->same('completed', $empty->status);
        $suite->same('{"metadata":{},"items":[]}', Json::encode($empty->output));
        $suite->same('{"metadata":{},"items":[]}', Json::encode($harness->run($emptyAgent, $requestMessage, $requestContext)->output));
    });
    $suite->test('Harness checks token budget before HTTP and retry attempts remain bounded', function () use ($suite, $nativeResponse, $nativeMessage) {
        $transport = new FakeTransport();
        $agent = Agent::create('budget')->provider(new ResponsesProvider('test', $transport), 'fixture')->options(['max_total_tokens' => 1]);
        $limited = (new AgentHarness())->run($agent, 'test', RequestContext::create('budget'));
        $suite->same('limited', $limited->status);
        $suite->same('token_limit', $limited->finishReason);
        $suite->same(0, count($transport->requests()));
        $retryTransport = new FakeTransport([new HttpResponse(429), new HttpResponse(200, [], Json::encode($nativeResponse([$nativeMessage('ok')])))]);
        $retryAgent = Agent::create('retry')->provider(new ResponsesProvider('test', $retryTransport), 'fixture');
        $harness = new AgentHarness();
        $success = $harness->run($retryAgent, 'retry', RequestContext::create('retry'));
        $suite->same('completed', $success->status);
        $suite->same(2, count($retryTransport->requests()));
        $suite->same(2, $harness->threads()->run('default', 'retry', $success->runId)['model_attempts']);
    });
    $suite->test('Provider fingerprints ignore credentials and queued resume rejects endpoint drift', function () use ($suite) {
        $a = new ResponsesProvider('one', new FakeTransport(), 'https://one.example/v1');
        $aOtherKey = new ResponsesProvider('two', new FakeTransport(), 'https://one.example/v1');
        $b = new ResponsesProvider('one', new FakeTransport(), 'https://two.example/v1');
        $suite->same($a->definitionFingerprint(), $aOtherKey->definitionFingerprint());
        $suite->true($a->definitionFingerprint() !== $b->definitionFingerprint());
        $agent = Agent::create('resume')->provider($a, 'fixture');
        $harness = new AgentHarness();
        $queued = $harness->submit($agent, 'test', RequestContext::create('resume'));
        $suite->same('queued', $queued->status);
        $error = $suite->throws(RunException::class, function () use ($harness, $agent, $b, $queued) { $harness->resume($agent->provider($b, 'fixture'), 'default', 'resume', $queued->runId); });
        $suite->same('definition_mismatch', $error->reason());
    });
};
