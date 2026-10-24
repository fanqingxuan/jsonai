<?php
declare(strict_types=1);

namespace JsonAI\Tests\HarnessEdge;

use JsonAI\Agent\Agent;
use JsonAI\Bridge\Worker;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Harness\ToolReconcilerInterface;
use JsonAI\Log\FileLogger;
use JsonAI\Log\SafeLogger;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Schema\Schema;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Storage\StateStoreInterface;
use JsonAI\Support\FakeClock;
use JsonAI\Support\Json;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ToolContext;
use JsonAI\Tool\ToolInterface;
use JsonAI\Tool\ToolResult;
use JsonAI\Transport\FakeTransport;

final class EdgeWriteTool extends AbstractTool
{
    protected $name = 'edge_write';
    protected $description = 'A deterministic write fixture with an externally observable commit count.';
    public static $commits = 0;
    public function inputSchema(): Schema { return Schema::object(); }
    public function outputSchema(): Schema { return Schema::object(['value' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array { self::$commits++; return ['value' => self::$commits]; }
}

final class EdgeReconciler implements ToolReconcilerInterface
{
    public $queries = 0;
    public function reconcile(ToolInterface $tool, string $arguments, ToolContext $context): ?ToolResult
    {
        $this->queries++;
        return ToolResult::success(['value' => EdgeWriteTool::$commits], $tool->outputSchema());
    }
}

final class InvalidEdgeReconciler implements ToolReconcilerInterface
{
    public function reconcile(ToolInterface $tool, string $arguments, ToolContext $context): ?ToolResult
    {
        return ToolResult::success(['value' => 'invalid']);
    }
}

final class CanonicalWriteTool extends AbstractTool
{
    protected $name = 'canonical_write';
    protected $description = 'Write with structured parameters that have equivalent JSON representations.';
    public static $commits = 0;
    public function inputSchema(): Schema { return Schema::object(['a' => Schema::integer()->required(), 'b' => Schema::integer()->required()]); }
    public function outputSchema(): Schema { return Schema::object(['value' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array { self::$commits++; return ['value' => self::$commits]; }
}

final class ProgressWriteTool extends AbstractTool
{
    protected $name = 'progress_write';
    protected $description = 'Write then publish progress to exercise delivery failures after commit.';
    public static $commits = 0;
    public function inputSchema(): Schema { return Schema::object(); }
    public function outputSchema(): Schema { return Schema::object(['value' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array
    {
        self::$commits++;
        $context->progress(['phase' => 'committed']);
        return ['value' => self::$commits];
    }
}

/** Lose one checkpoint transaction after a successful business write, then become healthy again. */
final class LoseToolResultOnceStore implements StateStoreInterface
{
    private $inner;
    private $lost = false;
    public function __construct() { $this->inner = new InMemoryStore(); }
    public function get(string $namespace, string $key): ?array { return $this->inner->get($namespace, $key); }
    public function delete(string $namespace, string $key): void { $this->inner->delete($namespace, $key); }
    public function scan(string $namespace): array { return $this->inner->scan($namespace); }
    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        return $this->inner->transact($namespace, $key, function (?array $document) use ($mutator): ?array {
            $next = $mutator($document);
            if (!$this->lost && $next !== null && isset($next['runs'])) {
                foreach ($next['runs'] as $run) {
                    foreach ($run['ledger'] as $entry) {
                        if ($entry['status'] === 'succeeded') {
                            $this->lost = true;
                            throw new \RuntimeException('Simulated temporary storage outage after business commit.');
                        }
                    }
                }
            }
            return $next;
        });
    }
}

final class LoseCompletionOnceStore implements StateStoreInterface
{
    private $inner;
    private $lost = false;
    public function __construct() { $this->inner = new InMemoryStore(); }
    public function get(string $namespace, string $key): ?array { return $this->inner->get($namespace, $key); }
    public function delete(string $namespace, string $key): void { $this->inner->delete($namespace, $key); }
    public function scan(string $namespace): array { return $this->inner->scan($namespace); }
    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        return $this->inner->transact($namespace, $key, function (?array $document) use ($mutator): ?array {
            $next = $mutator($document);
            if (!$this->lost && $next !== null && isset($next['runs'])) {
                foreach ($next['runs'] as $run) {
                    if ($run['status'] === 'completed') { $this->lost = true; throw new \RuntimeException('Simulated loss of terminal status commit.'); }
                }
            }
            return $next;
        });
    }
}

final class FailReadOnceStore implements StateStoreInterface
{
    private $inner;
    private $failed = false;
    public function __construct() { $this->inner = new InMemoryStore(); }
    public function get(string $namespace, string $key): ?array
    {
        if (!$this->failed) { $this->failed = true; throw new \RuntimeException('Simulated transient checkpoint read outage.'); }
        return $this->inner->get($namespace, $key);
    }
    public function transact(string $namespace, string $key, callable $mutator): ?array { return $this->inner->transact($namespace, $key, $mutator); }
    public function delete(string $namespace, string $key): void { $this->inner->delete($namespace, $key); }
    public function scan(string $namespace): array { return $this->inner->scan($namespace); }
}

return function ($suite): void {
    $suite->test('stream provider failure delivers failure and terminal events to a healthy subscriber', function () use ($suite): void {
        $events = [];
        $agent = Agent::create('stream-failure')->provider(new FakeProvider([new \RuntimeException('network failure')]), 'fake');
        $result = (new AgentHarness())->stream($agent, 'hello', RequestContext::create('stream-failure'), function (array $event) use (&$events): void { $events[] = $event['type']; });
        $suite->same('failed', $result->status);
        $suite->true(in_array('message.failed', $events, true), 'Healthy subscriber must learn the model message failed.');
        $suite->same('run.failed', $events[count($events) - 1]);
    });
    foreach ([24, 1] as $limit) {
        $suite->test('recovering committed tool after checkpoint loss reconciles once at tool limit ' . $limit, function () use ($suite, $limit): void {
            EdgeWriteTool::$commits = 0;
            $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('write-1', 'edge_write', '{}')]), [], 'tool_calls'), 'done']);
            $clock = new FakeClock();
            $reconciler = new EdgeReconciler();
            $harness = new AgentHarness(new LoseToolResultOnceStore(), ['clock' => $clock, 'reconciler' => $reconciler]);
            $agent = Agent::create('recover-write')->provider($provider, 'fake')->tools([EdgeWriteTool::class])->options(['timeout' => 1000, 'model_timeout' => 1, 'lease_seconds' => 2, 'max_tool_calls' => $limit]);
            $initial = $harness->run($agent, 'write once', RequestContext::create('recover-' . $limit));
            $suite->same(1, EdgeWriteTool::$commits);
            $clock->advance(3);
            $result = $harness->resume($agent, 'default', 'recover-' . $limit, $initial->runId);
            $suite->same('completed', $result->status);
            $suite->same('done', $result->text);
            $suite->same(1, EdgeWriteTool::$commits);
            $suite->same(1, $reconciler->queries);
            $state = $harness->threads()->run('default', 'recover-' . $limit, $result->runId);
            $suite->same(1, $state['tool_calls'], 'Reconciliation is not a new execution attempt.');
        });
    }
    $suite->test('default timeout and lease settings permit checkpoint recovery after owner expiry', function () use ($suite): void {
        EdgeWriteTool::$commits = 0;
        $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('default-recover-1', 'edge_write', '{}')]), [], 'tool_calls'), 'recovered']);
        $clock = new FakeClock();
        $reconciler = new EdgeReconciler();
        $harness = new AgentHarness(new LoseToolResultOnceStore(), ['clock' => $clock, 'reconciler' => $reconciler]);
        $agent = Agent::create('default-recover')->provider($provider, 'fake')->tools([EdgeWriteTool::class]);
        $initial = $harness->run($agent, 'write once', RequestContext::create('default-recover'));
        $clock->advance(181);
        $recovered = $harness->resume($agent, 'default', 'default-recover', $initial->runId);
        $suite->same('completed', $recovered->status);
        $suite->same(1, EdgeWriteTool::$commits);
        $suite->same(1, $reconciler->queries);
    });
    $suite->test('structured object output preserves empty objects after request replay', function () use ($suite): void {
        $provider = new FakeProvider(['{"details":{}}']);
        $harness = new AgentHarness();
        $schema = Schema::object(['details' => Schema::object()->required()]);
        $agent = Agent::create('structured-replay')->provider($provider, 'fake')->outputSchema($schema);
        $first = $harness->run($agent, 'give details', RequestContext::create('structured-replay')->withRequestId('request-1'));
        $second = $harness->run($agent, 'give details', RequestContext::create('structured-replay')->withRequestId('request-1'));
        $suite->same('completed', $first->status);
        $suite->true($first->output instanceof \stdClass);
        $suite->true($first->output->details instanceof \stdClass);
        $suite->true($second->output instanceof \stdClass);
        $suite->true($second->output->details instanceof \stdClass);
        $suite->same(1, count($provider->requests()));
    });
    $suite->test('duplicate tool call IDs from previous runs are rejected before another write', function () use ($suite): void {
        EdgeWriteTool::$commits = 0;
        $call = function (): ModelResponse { return new ModelResponse(Message::assistant('', [new ToolCall('reused-id', 'edge_write', '{}')]), [], 'tool_calls'); };
        $provider = new FakeProvider([$call(), 'first done', $call(), 'must not execute']);
        $harness = new AgentHarness();
        $agent = Agent::create('duplicate-calls')->provider($provider, 'fake')->tools([EdgeWriteTool::class]);
        $first = $harness->run($agent, 'first', RequestContext::create('duplicate-calls'));
        $suite->same('completed', $first->status);
        $second = $harness->run($agent, 'second', RequestContext::create('duplicate-calls'));
        $suite->same('provider_protocol', $second->finishReason);
        $suite->same(1, EdgeWriteTool::$commits);
    });
    $suite->test('cancelling a queued run persists a replayable terminal event', function () use ($suite): void {
        $harness = new AgentHarness();
        $agent = Agent::create('queued-cancel')->provider(new FakeProvider(['must not run']), 'fake');
        $queued = $harness->submit($agent, 'queued', RequestContext::create('queued-cancel'));
        $harness->cancel('default', 'queued-cancel', $queued->runId);
        $events = $harness->threads()->events('default', 'queued-cancel', $queued->runId);
        $suite->true(count($events) > 0, 'Queued cancellation must be observable to event replay subscribers.');
        $suite->same('run.cancelled', $events[count($events) - 1]['type']);
    });
    $suite->test('reconciled tool results still obey the declared output schema', function () use ($suite): void {
        EdgeWriteTool::$commits = 0;
        $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('write-1', 'edge_write', '{}')]), [], 'tool_calls'), 'must not accept invalid tool data']);
        $clock = new FakeClock();
        $harness = new AgentHarness(new LoseToolResultOnceStore(), ['clock' => $clock, 'reconciler' => new InvalidEdgeReconciler()]);
        $agent = Agent::create('invalid-reconcile')->provider($provider, 'fake')->tools([EdgeWriteTool::class])->options(['timeout' => 1000, 'model_timeout' => 1, 'lease_seconds' => 2]);
        $initial = $harness->run($agent, 'write', RequestContext::create('invalid-reconcile'));
        $clock->advance(3);
        $recovered = $harness->resume($agent, 'default', 'invalid-reconcile', $initial->runId);
        $suite->same('outcome_unknown', $recovered->finishReason);
        $suite->same(1, EdgeWriteTool::$commits);
        $suite->same(1, count($provider->requests()));
    });
    $suite->test('equivalent JSON whitespace and object key order cannot bypass write deduplication', function () use ($suite): void {
        CanonicalWriteTool::$commits = 0;
        $provider = new FakeProvider([
            new ModelResponse(Message::assistant('', [new ToolCall('canonical-1', 'canonical_write', '{"a":1,"b":2}')]), [], 'tool_calls'),
            new ModelResponse(Message::assistant('', [new ToolCall('canonical-2', 'canonical_write', '{ "b": 2, "a": 1 }')]), [], 'tool_calls'),
            'done',
        ]);
        $agent = Agent::create('canonical')->provider($provider, 'fake')->tools([CanonicalWriteTool::class]);
        $harness = new AgentHarness();
        $result = $harness->run($agent, 'write once', RequestContext::create('canonical'));
        $suite->same('completed', $result->status);
        $suite->same(1, CanonicalWriteTool::$commits);
        $run = $harness->threads()->run('default', 'canonical', $result->runId);
        $suite->same('repeated_write', $run['ledger']['canonical-2']['result']['error']['code']);
    });
    $suite->test('run options can lower but cannot raise the Agent execution limit', function () use ($suite): void {
        $provider = new FakeProvider(['ok']);
        $agent = Agent::create('bounded-options')->provider($provider, 'fake')->options(['max_iterations' => 2]);
        $harness = new AgentHarness();
        $suite->throws(\InvalidArgumentException::class, function () use ($harness, $agent): void {
            $harness->run($agent, 'raise limit', RequestContext::create('raised')->withOptions(['max_iterations' => 3]));
        });
        $suite->same(0, count($provider->requests()));
        $result = $harness->run($agent, 'lower limit', RequestContext::create('lowered')->withOptions(['max_iterations' => 1]));
        $suite->same('completed', $result->status);
    });
    $suite->test('truncated Chinese log messages remain valid UTF-8 and are written', function () use ($suite): void {
        $directory = sys_get_temp_dir() . '/portable-agent-log-utf8-' . bin2hex(random_bytes(8));
        try {
            $logger = new SafeLogger(new FileLogger($directory));
            $logger->info(str_repeat('中', 1000));
            $suite->same(0, $logger->failures());
            $files = glob($directory . '/*.jsonl');
            $suite->same(1, count($files));
            $record = Json::decode(trim(file_get_contents($files[0])));
            $suite->same(1, preg_match('//u', $record->message));
            $suite->true(strpos($record->message, '[truncated]') !== false);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
            if (is_file($directory . '/.agent-logger.lock')) { unlink($directory . '/.agent-logger.lock'); }
            if (is_dir($directory)) { rmdir($directory); }
        }
    });
    $suite->test('tool progress delivery failure after business commit records unknown outcome without retry', function () use ($suite): void {
        ProgressWriteTool::$commits = 0;
        $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('progress-1', 'progress_write', '{}')]), [], 'tool_calls'), 'must not continue']);
        $agent = Agent::create('progress')->provider($provider, 'fake')->tools([ProgressWriteTool::class]);
        $harness = new AgentHarness();
        $requestMessage = \JsonAI\Message\Message::user('write');
        $requestContext = RequestContext::create('progress')->withRequestId('progress-once');
        $result = $harness->stream($agent, $requestMessage, $requestContext, function (array $event): void {
            if ($event['type'] === 'tool.progress') { throw new \RuntimeException('Subscriber disconnected after commit.'); }
        });
        $suite->same(1, ProgressWriteTool::$commits);
        $suite->same('failed', $result->status);
        $run = $harness->threads()->run('default', 'progress', $result->runId);
        $suite->same('outcome_unknown', $run['ledger']['progress-1']['result']['error']['code']);
        $harness->run($agent, $requestMessage, $requestContext);
        $suite->same(1, ProgressWriteTool::$commits);
        $suite->same(1, count($provider->requests()));
    });
    $suite->test('queued definition snapshots exclude provider API credentials', function () use ($suite): void {
        $credential = 'sk-private-snapshot-fixture';
        $agent = Agent::create('snapshot-secret')->provider(new ResponsesProvider($credential, new FakeTransport()), 'model');
        $harness = new AgentHarness();
        $queued = $harness->submit($agent, 'hello', RequestContext::create('snapshot-secret'));
        $run = $harness->threads()->run('default', 'snapshot-secret', $queued->runId);
        $suite->true(strpos(Json::encode($run['snapshot']), $credential) === false);
        $suite->true(strpos(Json::encode($run), $credential) === false);
    });
    $suite->test('recovery reuses a persisted final answer after terminal checkpoint failure', function () use ($suite): void {
        $clock = new FakeClock();
        $provider = new FakeProvider([function () use ($clock): string { $clock->advance(10); return 'already generated final answer'; }]);
        $agent = Agent::create('final-recovery')->provider($provider, 'fake');
        $harness = new AgentHarness(new LoseCompletionOnceStore(), ['clock' => $clock]);
        $initial = $harness->run($agent, 'answer once', RequestContext::create('final-recovery'));
        $suite->same('interrupted', $initial->status);
        $clock->advance(181);
        $resumed = $harness->resume($agent, 'default', 'final-recovery', $initial->runId);
        $suite->same('completed', $resumed->status);
        $suite->same('already generated final answer', $resumed->text);
        $suite->same(1, count($provider->requests()));
        $history = $harness->threads()->messages('default', 'final-recovery');
        $suite->same(2, count($history));
    });
    $suite->test('transient checkpoint read failure preserves a resumable run and its original input', function () use ($suite): void {
        $clock = new FakeClock();
        $provider = new FakeProvider(['completed after read recovery']);
        $agent = Agent::create('read-recovery')->provider($provider, 'fake');
        $harness = new AgentHarness(new FailReadOnceStore(), ['clock' => $clock]);
        $requestMessage = \JsonAI\Message\Message::user('preserve this input');
        $requestContext = RequestContext::create('read-recovery');
        $initial = $harness->run($agent, $requestMessage, $requestContext);
        $suite->same('interrupted', $initial->status);
        $suite->same('persistence_error', $initial->finishReason);
        $suite->same(0, count($provider->requests()));
        $checkpoint = $harness->threads()->run('default', 'read-recovery', $initial->runId);
        $suite->same('running', $checkpoint['status']);
        $history = $harness->threads()->messages('default', 'read-recovery');
        $suite->same(1, count($history));
        $suite->same($requestMessage->id(), $history[0]->id());
        $clock->advance(181);
        $resumed = $harness->resume($agent, 'default', 'read-recovery', $initial->runId);
        $suite->same('completed', $resumed->status);
        $suite->same('completed after read recovery', $resumed->text);
        $suite->same(1, count($provider->requests()));
        $history = $harness->threads()->messages('default', 'read-recovery');
        $suite->same(2, count($history));
        $suite->same($requestMessage->id(), $history[0]->id());
    });
    $suite->test('model options reach the provider and cannot override Harness-owned fields', function () use ($suite): void {
        $provider = new FakeProvider(['ok']);
        $base = Agent::create('model-options')->provider($provider, 'fake');
        $agent = $base->modelOptions(['temperature' => 0.25, 'top_p' => 0.8, 'reasoning' => ['effort' => 'low']]);
        $suite->same([], $base->getModelOptions());
        (new AgentHarness())->run($agent, 'hello', RequestContext::create('model-options'));
        $sent = $provider->requests()[0]->options;
        $suite->same(0.25, $sent['temperature']);
        $suite->same(0.8, $sent['top_p']);
        $suite->same(['effort' => 'low'], $sent['reasoning']);
        $suite->true(isset($sent['max_output_tokens'], $sent['timeout']));
        foreach (['messages', 'tools', 'model', 'timeout', 'max_output_tokens', 'response_schema', 'api_key'] as $reserved) {
            $suite->throws(\InvalidArgumentException::class, function () use ($base, $reserved): void { $base->modelOptions([$reserved => 'override']); });
        }
    });
    $suite->test('worker bridge resolves stored agent names and safely replays completed jobs', function () use ($suite): void {
        $provider = new FakeProvider(['worker completed']);
        $agent = Agent::create('worker-agent')->provider($provider, 'fake')->modelOptions(['temperature' => 0.1]);
        $harness = new AgentHarness();
        $queued = $harness->submit($agent, 'do job', RequestContext::create('worker-job')->withScope('tenant')->withIdentity(['user_id' => 7]));
        $suite->same(0, count($provider->requests()));
        $registryCalls = [];
        $worker = new Worker($harness, function (string $name) use ($agent, &$registryCalls) {
            $registryCalls[] = $name;
            return $name === 'worker-agent' ? $agent : null;
        });
        $suite->throws(\JsonAI\Harness\RunException::class, function () use ($worker, $queued): void { $worker->handle('tenant', 'worker-job', $queued->runId, ['user_id' => 8]); });
        $suite->same(0, count($provider->requests()));
        $result = $worker->handle('tenant', 'worker-job', $queued->runId, ['user_id' => 7]);
        $suite->same('completed', $result->status);
        $suite->same('worker completed', $result->text);
        $again = $worker->handle('tenant', 'worker-job', $queued->runId, ['user_id' => 7]);
        $suite->same($result->runId, $again->runId);
        $suite->same(1, count($provider->requests()));
        $suite->same(['worker-agent', 'worker-agent', 'worker-agent'], $registryCalls);
    });
    $suite->test('queued jobs reject changed model options and invalid worker registry definitions', function () use ($suite): void {
        $provider = new FakeProvider(['must not call']);
        $agent = Agent::create('worker-config')->provider($provider, 'fake')->modelOptions(['temperature' => 0.1]);
        $harness = new AgentHarness();
        $queued = $harness->submit($agent, 'hello', RequestContext::create('worker-config'));
        $changed = new Worker($harness, function () use ($agent) { return $agent->modelOptions(['temperature' => 0.5]); });
        $suite->throws(\JsonAI\Harness\RunException::class, function () use ($changed, $queued): void { $changed->handle('default', 'worker-config', $queued->runId); });
        $invalid = new Worker($harness, function () { return new \stdClass(); });
        $suite->throws(\InvalidArgumentException::class, function () use ($invalid, $queued): void { $invalid->handle('default', 'worker-config', $queued->runId); });
        $suite->same(0, count($provider->requests()));
    });
};
