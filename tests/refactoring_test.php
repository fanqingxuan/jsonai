<?php
declare(strict_types=1);

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;
use JsonAI\Schema\Schema;
use JsonAI\Support\Json;
use JsonAI\Tool\ToolResult;

return function ($suite): void {
    $suite->test('tool results have one immutable source for business data and serialized data', function () use ($suite): void {
        $nested = (object) ['count' => 1];
        $result = ToolResult::success(['item' => $nested, 'empty' => []], Schema::object([
            'item' => Schema::object(['count' => Schema::integer()->required()])->required(),
            'empty' => Schema::object()->required(),
        ]));
        $nested->count = 2;
        $data = $result->data();
        $data['item']->count = 3;
        $wire = $result->toArray();
        $wire['data']->item->count = 4;
        $suite->same(1, $result->data()['item']->count);
        $suite->same('{"item":{"count":1},"empty":{}}', Json::encode($result->toArray()['data']));
        $suite->true($result->isSuccessful());
        $suite->throws(\Error::class, function () use ($result): void { $result->data = []; });
        $failure = ToolResult::failure('denied', 'Not allowed', ['nested' => ['reason' => 'policy']]);
        $error = $failure->error();
        $error['details']['nested']['reason'] = 'changed';
        $suite->same('policy', $failure->error()['details']['nested']['reason']);
        $suite->same('{}', Json::encode($failure->toArray()['data']));
    });
    $suite->test('agent accepts provider-specific options while reserving runtime-controlled fields', function () use ($suite): void {
        $agent = Agent::create('options')->modelOptions(['stop' => ['END'], 'top_k' => 8]);
        $suite->same(['stop' => ['END'], 'top_k' => 8], $agent->getModelOptions());
        foreach (['model', 'messages', 'tools', 'stream', 'n', 'timeout', 'max_output_tokens', 'max_tokens', 'max_completion_tokens', 'response_schema', 'response_format'] as $key) {
            $suite->throws(\InvalidArgumentException::class, function () use ($key): void {
                Agent::create('reserved')->modelOptions([$key => 1]);
            });
        }
    });
    $suite->test('provider option ownership works through the public run API', function () use ($suite): void {
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        ]))]);
        $agent = Agent::create('chat-options')->provider(new ChatCompletionsProvider('fixture', $transport), 'fixture')
            ->modelOptions(['stop' => ['END']]);
        $harness = new AgentHarness();
        $suite->same('completed', $harness->run($agent, 'hello', RequestContext::create('supported'))->status);
        $suite->same(['END'], Json::decodeArray($transport->requests()[0]['body'])['stop']);
        $rejected = $harness->run($agent->modelOptions(['top_k' => 8]), 'hello', RequestContext::create('unsupported'));
        $suite->same('provider_error', $rejected->finishReason);
        $suite->same(1, count($transport->requests()));
        $custom = new FakeProvider(['ok']);
        $agent = $agent->provider($custom, 'fixture')->modelOptions(['top_k' => 8]);
        $suite->same('completed', $harness->run($agent, 'hello', RequestContext::create('custom'))->status);
        $suite->same(8, $custom->requests()[0]->options['top_k']);
    });
};
