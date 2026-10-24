<?php
declare(strict_types=1);
namespace JsonAI\Tests\AgentRuntime;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Harness\RunException;
use JsonAI\Memory\LongTerm\StoreLongTermMemory;
use JsonAI\Memory\ShortTerm\StoreShortTermMemory;
use JsonAI\Message\Message;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\FakeProvider;
use JsonAI\Storage\InMemoryStore;

return function ($suite): void {
    $suite->test('agent runtime requires explicit binding and immutable rebinding isolates stores', function () use ($suite): void {
        $provider = new FakeProvider(['first', 'second', 'third']);
        $definition = Agent::create('bound')->provider($provider, 'fake');
        $context = RequestContext::create('same-thread');
        $noop = function (array $event): void {};
        $operations = [
            function () use ($definition, $context) { $definition->run('one', $context); },
            function () use ($definition, $context, $noop) { $definition->stream('one', $context, $noop); },
            function () use ($definition, $context) { $definition->submit('one', $context); },
            function () use ($definition, $context) { $definition->resume('run', $context); },
            function () use ($definition, $context) { $definition->cancel('run', $context); },
            function () use ($definition, $context, $noop) { $definition->replay('run', $context, 0, $noop); },
            function () use ($definition, $context) { $definition->deleteThread($context); },
            function () use ($definition, $context) { $definition->memoryScope($context); },
            function () use ($definition) { $definition->threads(); },
        ];
        $first = $definition->withHarness(new AgentHarness());
        foreach ($operations as $operation) {
            $error = $suite->throws(\LogicException::class, $operation);
            $suite->true(strpos($error->getMessage(), 'withHarness()') !== false);
        }
        $suite->same(0, count($provider->requests()));
        $second = $first->withHarness(new AgentHarness());
        $suite->same('first', $first->run('one', $context)->text);
        $suite->same('second', $second->run('two', $context)->text);
        $suite->same('third', $first->modelOptions(['temperature' => 0])->run('three', $context)->text);
        $suite->same(4, count($first->threads()->messages('default', 'same-thread')));
        $suite->same(2, count($second->threads()->messages('default', 'same-thread')));
    });

    $suite->test('one agent runs and streams request-local messages and contexts without tenant leakage', function () use ($suite): void {
        $provider = new FakeProvider(['A1', 'B1', 'A2']);
        $agent = Agent::create('shared')->provider($provider, 'fake')
            ->withHarness(new AgentHarness())->prompt(TemplatePrompt::system('Language: {{language}}'));
        $base = RequestContext::create('same-thread');
        $a = $base->withScope('tenant-a')->withIdentity(['user_id' => 'a'])->withVariables(['language' => '中文']);
        $b = $base->withScope('tenant-b')->withIdentity(['user_id' => 'b'])->withVariables(['language' => 'English']);
        $agent->run('tenant A message', $a);
        $events = [];
        $input = Message::user('tenant B message');
        $result = $agent->stream($input, $b, function (array $event) use (&$events): void { $events[] = $event; });
        $suite->same('completed', $result->status);
        $suite->same('run.completed', end($events)['type']);
        $text = '';
        foreach ($events as $event) { if ($event['type'] === 'message.delta') { $text .= $event['data']['delta']; } }
        $suite->same('B1', $text);
        $suite->same('A2', $agent->run('tenant A follow-up', $a)->text);
        $suite->same('Language: English', $provider->requests()[1]->messages[0]->text());
        $suite->same(2, count($provider->requests()[1]->messages));
        $suite->same($input->id(), $agent->threads()->messages('tenant-b', 'same-thread')[0]->id());
        $suite->same(4, count($agent->threads()->messages('tenant-a', 'same-thread')));
        $suite->same([], $base->getIdentity());
        $suite->same(null, $a->getRequestId());
    });

    $suite->test('agent resumes legacy harness checkpoints with a new runtime and validates identity', function () use ($suite): void {
        $store = new InMemoryStore();
        $oldHarness = new AgentHarness($store);
        $definition = Agent::create('recover')->provider(new FakeProvider(), 'fake');
        $context = RequestContext::create('queued')->withScope('tenant')->withIdentity(['user_id' => 'owner']);
        $queued = $oldHarness->submit($definition, 'persisted input', $context);
        $provider = new FakeProvider(['recovered']);
        $agent = $definition->provider($provider, 'fake')->withHarness(new AgentHarness($store));
        $error = $suite->throws(RunException::class, function () use ($agent, $queued, $context): void {
            $agent->resume($queued->runId, $context->withIdentity(['user_id' => 'other']));
        });
        $suite->same('identity_mismatch', $error->reason());
        $suite->same([], $provider->requests());
        $events = [];
        $result = $agent->resume($queued->runId, $context, function (array $event) use (&$events): void { $events[] = $event; });
        $suite->same('recovered', $result->text);
        $suite->same('persisted input', $provider->requests()[0]->messages[0]->text());
        $suite->same('run.resumed', $events[0]['type']);
        $suite->same('run.completed', end($events)['type']);
        $suite->same($result->toArray(), $agent->resume($queued->runId, $context)->toArray());
        $suite->same(1, count($provider->requests()));
    });

    $suite->test('agent submit is idempotent and cancellation and replay never invoke provider', function () use ($suite): void {
        $provider = new FakeProvider();
        $agent = Agent::create('queue')->provider($provider, 'fake')->withHarness(new AgentHarness());
        $context = RequestContext::create('queued')->withScope('tenant')->withRequestId('request-1');
        $queued = $agent->submit('queued input', $context);
        $suite->same($queued->runId, $agent->submit('queued input', $context)->runId);
        $suite->same('queued', $queued->status);
        $agent->cancel($queued->runId, $context);
        $suite->same('cancelled', $agent->resume($queued->runId, $context)->status);
        $events = [];
        $agent->replay($queued->runId, $context, 0, function (array $event) use (&$events): void { $events[] = $event; });
        $suite->same('run.cancelled', end($events)['type']);
        $tail = [];
        $agent->replay($queued->runId, $context, end($events)['sequence'], function (array $event) use (&$tail): void { $tail[] = $event; });
        $suite->same([], $tail);
        $suite->same([], $provider->requests());
        $suite->same([], $agent->threads()->messages('tenant', 'queued'));
    });

    $suite->test('agent thread deletion preserves long memory and other tenant state', function () use ($suite): void {
        $store = new InMemoryStore();
        $short = new StoreShortTermMemory($store);
        $long = new StoreLongTermMemory($store);
        $agent = Agent::create('memory')->provider(new FakeProvider(['ok']), 'fake')
            ->withHarness(new AgentHarness($store, ['short_memory' => $short, 'long_memory' => $long]));
        $context = RequestContext::create('thread')->withScope('tenant')->withIdentity(['user_id' => 'owner']);
        $scope = $agent->memoryScope($context);
        $suite->true($scope !== $agent->memoryScope($context->withIdentity(['user_id' => 'other'])));
        $long->put($scope, 'language', 'English');
        $queued = $agent->submit('input', $context);
        $suite->throws(\LogicException::class, function () use ($agent, $context): void { $agent->deleteThread($context); });
        $agent->resume($queued->runId, $context);
        $short->save('tenant', 'thread', ['summary' => 'summary']);
        $short->save('other-tenant', 'thread', ['summary' => 'keep']);
        $agent->deleteThread($context);
        $suite->same([], $agent->threads()->messages('tenant', 'thread'));
        $suite->same([], $short->load('tenant', 'thread'));
        $suite->same(['summary' => 'keep'], $short->load('other-tenant', 'thread'));
        $suite->same('English', $long->get($scope, 'language')['content']);
    });
};
