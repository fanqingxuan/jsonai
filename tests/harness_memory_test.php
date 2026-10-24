<?php
declare(strict_types=1);

namespace JsonAI\Tests\HarnessMemory;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\MemoryWritePolicyInterface;
use JsonAI\Harness\RunContext;
use JsonAI\Harness\RequestContext;
use JsonAI\Memory\LongTerm\StoreLongTermMemory;
use JsonAI\Memory\ShortTerm\StoreShortTermMemory;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Schema\Schema;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Support\FakeClock;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ToolContext;

final class ExplicitPreferencePolicy implements MemoryWritePolicyInterface
{
    public function extract(array $messages, RunContext $context): array
    {
        return [['key' => 'tea-preference', 'content' => 'tea preference recorded by the application', 'metadata' => ['source_thread_id' => 'must-be-overwritten', 'source' => 'explicit application policy'], 'ttl' => 60]];
    }
}

final class FailingShortMemory extends StoreShortTermMemory
{
    public $failRead = false;
    public $failWrite = false;
    public $failForget = false;
    public function load(string $scope, string $threadId): array
    {
        if ($this->failRead) { throw new \RuntimeException('Short memory temporarily unavailable.'); }
        return parent::load($scope, $threadId);
    }
    public function save(string $scope, string $threadId, array $state): void
    {
        if ($this->failWrite) { throw new \RuntimeException('Summary save temporarily unavailable.'); }
        parent::save($scope, $threadId, $state);
    }
    public function forget(string $scope, string $threadId): void
    {
        if ($this->failForget) { throw new \RuntimeException('Memory erase temporarily unavailable.'); }
        parent::forget($scope, $threadId);
    }
}

final class FailingLongMemory extends StoreLongTermMemory
{
    public $failRead = false;
    public $failWrite = false;
    public function search(string $scope, string $query, int $limit = 5): array
    {
        if ($this->failRead) { throw new \RuntimeException('Long memory retrieval temporarily unavailable.'); }
        return parent::search($scope, $query, $limit);
    }
    public function put(string $scope, string $key, string $content, array $metadata = [], ?int $ttl = null, ?int $expectedVersion = null): array
    {
        if ($this->failWrite) { throw new \RuntimeException('Long memory write temporarily unavailable.'); }
        return parent::put($scope, $key, $content, $metadata, $ttl, $expectedVersion);
    }
}

final class MemoryBusinessTool extends AbstractTool
{
    protected $name = 'memory_business';
    protected $description = 'Business operation independent of optional memory persistence.';
    public static $commits = 0;
    public function inputSchema(): Schema { return Schema::object(); }
    public function outputSchema(): Schema { return Schema::object(['committed' => Schema::boolean()->required()]); }
    public function handle(array $input, ToolContext $context): array { self::$commits++; return ['committed' => true]; }
}

return function ($suite): void {
    $suite->test('Harness short memory persists summary coverage while retaining original messages', function () use ($suite): void {
        $store = new InMemoryStore();
        $short = new StoreShortTermMemory($store);
        $provider = new FakeProvider(['first answer', 'second answer', 'third answer']);
        $harness = new AgentHarness($store, ['short_memory' => $short]);
        $agent = Agent::create('summary')->provider($provider, 'fake')->options(['recent_messages' => 1]);
        $harness->run($agent, 'first question', RequestContext::create('summary')->withScope('tenant-a'));
        $harness->run($agent, 'second question', RequestContext::create('summary')->withScope('tenant-a'));
        $history = $harness->threads()->messages('tenant-a', 'summary');
        $memory = $short->load('tenant-a', 'summary');
        $suite->same($history[1]->id(), $memory['covered_message_id']);
        $suite->true(strpos($memory['summary'], 'first question') !== false);
        $suite->same(4, count($history));
        $suite->same(['summary', 'covered_message_id'], array_keys($memory));
        $secondMessages = $provider->requests()[1]->messages;
        $suite->same('user', $secondMessages[0]->role());
        $suite->same('summary', $secondMessages[0]->metadata()['context_source']);
        $suite->same('second question', $secondMessages[1]->text());
        $harness->run($agent, 'third question', RequestContext::create('summary')->withScope('tenant-a'));
        $updated = $short->load('tenant-a', 'summary');
        $suite->same($history[3]->id(), $updated['covered_message_id']);
        $suite->same(1, substr_count($updated['summary'], 'first question'));
        $suite->same(6, count($harness->threads()->messages('tenant-a', 'summary')));
    });
    $suite->test('Harness long retrieval is reference data scoped by tenant user and agent', function () use ($suite): void {
        $long = new StoreLongTermMemory(new InMemoryStore());
        $provider = new FakeProvider(['answer', 'other user', 'other tenant', 'other agent']);
        $harness = new AgentHarness(null, ['long_memory' => $long]);
        $agent = Agent::create('preferences')->provider($provider, 'fake');
        $requestMessage = \JsonAI\Message\Message::user('tea');
        $requestContext = RequestContext::create('one')->withScope('tenant-a')->withIdentity(['user_id' => 1]);
        $scope = $harness->memoryScope($agent, $requestContext);
        $long->put($scope, 'tea', 'tea PRIVATE_REFERENCE_ONLY');
        $harness->run($agent, $requestMessage, $requestContext);
        $harness->run($agent, 'tea', RequestContext::create('two')->withScope('tenant-a')->withIdentity(['user_id' => 2]));
        $harness->run($agent, 'tea', RequestContext::create('one')->withScope('tenant-b')->withIdentity(['user_id' => 1]));
        $harness->run(Agent::create('another-agent')->provider($provider, 'fake'), 'tea', RequestContext::create('three')->withScope('tenant-a')->withIdentity(['user_id' => 1]));
        $firstMessages = $provider->requests()[0]->messages;
        $suite->same('user', $firstMessages[0]->role());
        $suite->true(strpos($firstMessages[0]->text(), 'untrusted data') !== false);
        $suite->true(strpos($firstMessages[0]->text(), 'PRIVATE_REFERENCE_ONLY') !== false);
        foreach (array_slice($provider->requests(), 1) as $other) {
            foreach ($other->messages as $message) { $suite->true(strpos($message->text(), 'PRIVATE_REFERENCE_ONLY') === false); }
        }
        $suite->same(1, $long->get($scope, 'tea')['version'], 'Normal replies must not automatically write remembered facts.');
    });
    $suite->test('Harness explicit write policy adds trusted provenance TTL and idempotent request replay', function () use ($suite): void {
        $clock = new FakeClock();
        $long = new StoreLongTermMemory(new InMemoryStore(), $clock);
        $provider = new FakeProvider(['saved']);
        $agent = Agent::create('remember')->provider($provider, 'fake');
        $harness = new AgentHarness(null, ['long_memory' => $long, 'memory_policy' => new ExplicitPreferencePolicy(), 'clock' => $clock]);
        $requestMessage = \JsonAI\Message\Message::user('remember tea');
        $requestContext = RequestContext::create('remember')->withScope('tenant')->withIdentity(['user' => 'u'])->withRequestId('remember-once');
        $result = $harness->run($agent, $requestMessage, $requestContext);
        $scope = $harness->memoryScope($agent, $requestContext);
        $record = $long->get($scope, 'tea-preference');
        $suite->same('remember', $record['metadata']['source_thread_id']);
        $suite->same($result->runId, $record['metadata']['source_run_id']);
        $suite->same('explicit application policy', $record['metadata']['source']);
        $suite->same($clock->now() + 60, $record['expires_at']);
        $harness->run($agent, $requestMessage, $requestContext);
        $suite->same(1, $long->get($scope, 'tea-preference')['version']);
        $clock->advance(61);
        $suite->same(null, $long->get($scope, 'tea-preference'));
    });
    $suite->test('optional memory failures warn without replaying a committed business tool', function () use ($suite): void {
        MemoryBusinessTool::$commits = 0;
        $store = new InMemoryStore();
        $short = new FailingShortMemory($store);
        $short->failRead = true;
        $short->failWrite = true;
        $long = new FailingLongMemory($store);
        $long->failRead = true;
        $long->failWrite = true;
        $provider = new FakeProvider(['first answer', new ModelResponse(Message::assistant('', [new ToolCall('memory-write', 'memory_business', '{}')]), [], 'tool_calls'), 'business completed']);
        $agent = Agent::create('memory-failure')->provider($provider, 'fake')->tools([MemoryBusinessTool::class])->options(['recent_messages' => 1]);
        $harness = new AgentHarness($store, ['short_memory' => $short, 'long_memory' => $long, 'memory_policy' => new ExplicitPreferencePolicy()]);
        $harness->run($agent, 'first', RequestContext::create('memory-failure'));
        $result = $harness->run($agent, 'perform business operation', RequestContext::create('memory-failure'));
        $suite->same('completed', $result->status);
        $suite->same(1, MemoryBusinessTool::$commits);
        foreach (['short_memory_read_failed', 'short_memory_write_failed', 'long_memory_read_failed', 'long_memory_write_failed'] as $warning) { $suite->true(in_array($warning, $result->warnings, true), 'Missing warning ' . $warning); }
    });
    $suite->test('thread deletion erases messages checkpoints events and short memory while long facts require explicit deletion', function () use ($suite): void {
        $store = new InMemoryStore();
        $short = new StoreShortTermMemory($store);
        $long = new StoreLongTermMemory($store);
        $provider = new FakeProvider(['answer one', 'answer two']);
        $agent = Agent::create('erase')->provider($provider, 'fake')->options(['recent_messages' => 1]);
        $harness = new AgentHarness($store, ['short_memory' => $short, 'long_memory' => $long, 'memory_policy' => new ExplicitPreferencePolicy()]);
        $requestMessage = \JsonAI\Message\Message::user('tea first');
        $requestContext = RequestContext::create('erase')->withScope('tenant');
        $harness->run($agent, $requestMessage, $requestContext);
        $harness->run($agent, 'tea second', RequestContext::create('erase')->withScope('tenant'));
        $suite->true($short->load('tenant', 'erase') !== []);
        $scope = $harness->memoryScope($agent, $requestContext);
        $harness->deleteThread('tenant', 'erase');
        $suite->same(null, $harness->threads()->get('tenant', 'erase'));
        $suite->same([], $short->load('tenant', 'erase'));
        $suite->true($long->get($scope, 'tea-preference') !== null);
        $long->delete($scope, 'tea-preference');
        $suite->same(null, $long->get($scope, 'tea-preference'));
        $suite->same([], $long->search($scope, 'tea'));
    });
    $suite->test('failed short memory erasure is reported and deletion can be retried', function () use ($suite): void {
        $store = new InMemoryStore();
        $short = new FailingShortMemory($store);
        $harness = new AgentHarness($store, ['short_memory' => $short]);
        $agent = Agent::create('erase-retry')->provider(new FakeProvider(['done']), 'fake');
        $harness->run($agent, 'hello', RequestContext::create('erase-retry'));
        $short->save('default', 'erase-retry', ['summary' => 'old', 'covered_message_id' => 'old']);
        $short->failForget = true;
        $suite->throws(\RuntimeException::class, function () use ($harness): void { $harness->deleteThread('default', 'erase-retry'); });
        $short->failForget = false;
        $harness->deleteThread('default', 'erase-retry');
        $suite->same([], $short->load('default', 'erase-retry'));
        $suite->same(null, $harness->threads()->get('default', 'erase-retry'));
    });
};
