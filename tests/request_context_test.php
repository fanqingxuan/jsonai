<?php
declare(strict_types=1);
namespace JsonAI\Tests\RequestContext;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Harness\RunRequest;
use JsonAI\Message\Message;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\FakeProvider;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Storage\StateCodec;

return function ($suite): void {
    $suite->test('run and stream accept messages directly with reusable immutable context settings', function () use ($suite): void {
        $provider = new FakeProvider(['first', 'second', 'third']);
        $agent = Agent::create('direct-message')->provider($provider, 'fake')
            ->prompt(TemplatePrompt::system('Language: {{language}}'));
        $base = RequestContext::create('direct');
        $context = $base->withVariables(['language' => '中文'])->withOptions(['max_iterations' => 1]);
        $harness = new AgentHarness();
        $first = $harness->run($agent, 'one', $context);
        $message = new Message('user', [
            ['type' => 'text', 'text' => 'two'],
            ['type' => 'image', 'url' => 'https://example.invalid/image.png'],
        ]);
        $events = [];
        $second = $harness->stream($agent, $message, $context, function (array $event) use (&$events): void { $events[] = $event; });
        $suite->same('completed', $first->status);
        $suite->same('completed', $second->status);
        $suite->true($first->runId !== $second->runId);
        $suite->same(null, $context->getRequestId());
        $suite->same([], $base->getVariables());
        $suite->same([], $base->getOptions());
        $suite->same('Language: 中文', $provider->requests()[0]->messages[0]->text());
        $suite->same($message->id(), $harness->threads()->messages('default', 'direct')[2]->id());
        $sentMessages = $provider->requests()[1]->messages;
        $suite->same($message->content(), end($sentMessages)->content());
        $suite->same('run.completed', end($events)['type']);
        $queued = $harness->submit($agent, 'three', $context);
        $suite->same(2, count($provider->requests()));
        $stored = $harness->threads()->run('default', 'direct', $queued->runId);
        $suite->same(['max_iterations' => 1], $stored['request']['options']);
        $suite->same(['language' => '中文'], $stored['request']['variables']);
        $suite->same('third', $harness->resume($agent, 'default', 'direct', $queued->runId)->text);
        foreach ([Message::system('wrong role'), 123, ['text' => 'wrong type']] as $invalid) {
            $suite->throws(\InvalidArgumentException::class, function () use ($harness, $agent, $invalid, $context): void {
                $harness->run($agent, $invalid, $context);
            });
        }
    });
    $suite->test('context variables and restored snapshots do not expose caller-owned nested values', function () use ($suite): void {
        $value = (object) ['language' => '中文'];
        $context = RequestContext::create('variables')->withVariables(['settings' => $value]);
        $value->language = 'changed';
        $variables = $context->getVariables();
        $variables['settings']->language = 'also changed';
        $restored = RequestContext::fromArray($context->toArray());
        $suite->same('中文', $restored->getVariables()['settings']->language);
    });
    $suite->test('reusable request contexts generate fresh IDs and support explicit idempotency', function () use ($suite): void {
        $base = RequestContext::create('thread');
        $context = $base->withScope('tenant')->withIdentity(['user_id' => 7]);
        $suite->same('default', $base->getScope()); $suite->same([], $base->getIdentity());
        $first = new RunRequest('first', $context); $second = new RunRequest('second', $context);
        $suite->same(null, $context->getRequestId());
        $suite->true($first->context->getRequestId() !== $second->context->getRequestId());
        $fixed = $context->withRequestId('0');
        $suite->same('0', (new RunRequest('retry', $fixed))->context->getRequestId());
        $suite->same('0', (new RunRequest('retry', $fixed))->context->getRequestId());
        $suite->same(null, $fixed->withRequestId(null)->getRequestId());
    });
    $suite->test('context identity does not share mutable nested objects with callers', function () use ($suite): void {
        $user = (object) ['id' => 7];
        $context = RequestContext::create('thread')->withIdentity(['user' => $user]);
        $user->id = 8;
        $identity = $context->getIdentity(); $identity['user']->id = 9;
        $serialized = $context->toArray(); $serialized['identity']['user']->id = 10;
        $suite->same(7, $context->getIdentity()['user']->id);
    });
    $suite->test('request fluent variables and limits round trip without changing the base request', function () use ($suite): void {
        $base = RequestContext::create('thread')->withScope('tenant')->withRequestId('request');
        $request = new RunRequest('hello', $base->withVariables(['language' => '中文'])->withOptions(['max_iterations' => 1]));
        $suite->same([], $base->getVariables()); $suite->same([], $base->getOptions());
        $restored = RunRequest::fromArray(StateCodec::decode(StateCodec::encode($request->toArray())));
        $suite->same($request->toArray(), $restored->toArray());
        $suite->same('request', $restored->context->getRequestId());
    });
    $suite->test('client IP hashing accepts canonical IPv4 and IPv6 without storing the address', function () use ($suite): void {
        $base = RequestContext::create('thread');
        $ipv4 = $base->withClientIp('192.0.2.1');
        $suite->same(hash('sha256', '192.0.2.1'), $ipv4->getClientIpHash());
        $ipv6 = $base->withClientIp('2001:db8::1');
        $suite->same($ipv6->getClientIpHash(), $base->withClientIp('2001:0db8:0:0:0:0:0:1')->getClientIpHash());
        $suite->same(null, $base->getClientIpHash());
        $suite->same(null, $ipv6->withClientIp(null)->getClientIpHash());
        $request = new RunRequest('hello', $ipv4);
        $serialized = StateCodec::encode($request->toArray());
        $suite->true(strpos($serialized, '192.0.2.1') === false);
        $suite->same($ipv4->getClientIpHash(), RunRequest::fromArray(StateCodec::decode($serialized))->context->getClientIpHash());
        foreach (['', 'invalid', '192.0.2.1:80', '192.0.2.1, 192.0.2.2'] as $invalid) {
            $suite->throws(\InvalidArgumentException::class, function () use ($base, $invalid): void { $base->withClientIp($invalid); });
        }
        $bad = $ipv4->toArray(); $bad['client_ip_hash'] = '../../bad';
        $suite->throws(\InvalidArgumentException::class, function () use ($bad): void { RequestContext::fromArray($bad); });
    });
    $suite->test('context identifier validation rejects empty and overlong values', function () use ($suite): void {
        foreach (['', str_repeat('a', 201)] as $id) {
            $suite->throws(\InvalidArgumentException::class, function () use ($id): void { RequestContext::create($id); });
            $suite->throws(\InvalidArgumentException::class, function () use ($id): void { RequestContext::create('t')->withScope($id); });
            $suite->throws(\InvalidArgumentException::class, function () use ($id): void { RequestContext::create('t')->withRequestId($id); });
        }
    });
    $suite->test('IP changes do not change thread identity memory scope or idempotency', function () use ($suite): void {
        $provider = new FakeProvider(['first', 'second']);
        $agent = Agent::create('context')->provider($provider, 'fake'); $harness = new AgentHarness();
        $base = RequestContext::create('thread')->withScope('tenant')->withIdentity(['user_id' => 7]);
        $one = $base->withClientIp('192.0.2.1')->withRequestId('once');
        $retry = $base->withClientIp('192.0.2.2')->withRequestId('once');
        $suite->same($harness->memoryScope($agent, $one), $harness->memoryScope($agent, $retry));
        $first = $harness->run($agent, 'hello', $one); $again = $harness->run($agent, 'hello', $retry);
        $suite->same($first->runId, $again->runId);
        $next = $harness->run($agent, 'next', $base->withClientIp('192.0.2.2'));
        $suite->same('completed', $next->status); $suite->same(2, count($provider->requests()));
    });
    $suite->test('legacy flat checkpoints without IP metadata remain resumable', function () use ($suite): void {
        $store = new InMemoryStore(); $harness = new AgentHarness($store);
        $agent = Agent::create('legacy-context')->provider(new FakeProvider(['done']), 'fake');
        $queued = $harness->submit($agent, 'hello', RequestContext::create('thread'));
        $harness->threads()->transact('default', 'thread', function (array $document) use ($queued): array {
            unset($document['runs'][$queued->runId]['request']['client_ip_hash']); return $document;
        });
        $result = (new AgentHarness($store))->resume($agent, 'default', 'thread', $queued->runId);
        $suite->same('completed', $result->status); $suite->same('done', $result->text);
    });
};
