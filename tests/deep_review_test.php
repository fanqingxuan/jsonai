<?php
declare(strict_types=1);
namespace JsonAI\Tests\DeepReview;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Schema\Schema;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Storage\StateStoreInterface;
use JsonAI\Support\FakeClock;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ToolContext;
use JsonAI\Tool\DefaultToolResolver;
use JsonAI\Tool\ExecutionAwareToolInterface;

final class ConfiguredTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'configured_tool';
    protected $description = 'Configurable fixture to exercise safe recovery snapshots.';
    private $readOnly;
    public static $calls = 0;
    public function __construct(bool $readOnly) { $this->readOnly = $readOnly; }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function inputSchema(): Schema { return Schema::object(['amount' => Schema::number()->required()]); }
    public function outputSchema(): Schema { return Schema::object(); }
    public function handle(array $input, ToolContext $context): array { self::$calls++; return []; }
}

final class UnknownWrite extends AbstractTool
{
    protected $name = 'unknown_write';
    protected $description = 'A write whose acknowledgement is lost.';
    public static $calls = 0;
    public function inputSchema(): Schema { return Schema::object(); }
    public function outputSchema(): Schema { return Schema::object(); }
    public function handle(array $input, ToolContext $context): array { self::$calls++; throw new \RuntimeException('Business committed before acknowledgement was lost'); }
}
final class LargeWrite extends AbstractTool
{
    protected $name = 'large_write';
    protected $description = 'A committed write returning a large result.';
    public static $calls = 0;
    public function inputSchema(): Schema { return Schema::object(); }
    public function outputSchema(): Schema { return Schema::object(['text' => Schema::string()->required()]); }
    public function handle(array $input, ToolContext $context): array { self::$calls++; return ['text' => str_repeat('x', 200)]; }
}
final class CapturingLogger extends \Psr\Log\AbstractLogger
{
    public $messages = [];
    public function log($level, $message, array $context = []) { $this->messages[] = (string) $message; }
}

/** Lose one chosen event transaction after its preceding checkpoint has committed. */
final class LoseEventStore implements StateStoreInterface
{
    private $inner;
    private $type;
    private $lost = false;
    public function __construct(string $type) { $this->type = $type; $this->inner = new InMemoryStore(); }
    public function get(string $namespace, string $key): ?array { return $this->inner->get($namespace, $key); }
    public function delete(string $namespace, string $key): void { $this->inner->delete($namespace, $key); }
    public function scan(string $namespace): array { return $this->inner->scan($namespace); }
    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        return $this->inner->transact($namespace, $key, function (?array $doc) use ($mutator): ?array {
            $next = $mutator($doc);
            foreach ($next['runs'] ?? [] as $run) {
                $events = $run['events']; $last = end($events);
                if (!$this->lost && $last && $last['type'] === $this->type) {
                    $this->lost = true;
                    throw new \RuntimeException('Lost event checkpoint');
                }
            }
            return $next;
        });
    }
}

return function ($suite): void {
    $suite->test('resuming with a recreated file logger keeps the same run file across midnight', function () use ($suite): void {
        $dir = sys_get_temp_dir() . '/agent-log-recovery-' . bin2hex(random_bytes(8));
        $clock = new FakeClock(1792828800.125); $store = new LoseEventStore('run.completed');
        $provider = new FakeProvider(['done']); $agent = Agent::create('log-recovery')->provider($provider, 'fake');
        try {
            $harness = new AgentHarness($store, ['logger' => new \JsonAI\Log\FileLogger($dir), 'clock' => $clock]);
            $context = RequestContext::create('thread')->withClientIp('192.0.2.1');
            $initial = $harness->run($agent, 'hello', $context);
            $suite->same('interrupted', $initial->status); $path = glob($dir . '/*.jsonl')[0];
            $clock->advance(86400);
            $resumed = (new AgentHarness($store, ['logger' => new \JsonAI\Log\FileLogger($dir), 'clock' => $clock]))
                ->resume($agent, 'default', 'thread', $initial->runId);
            $suite->same('completed', $resumed->status); $suite->same(1, count($provider->requests()));
            $suite->same([$path], glob($dir . '/*.jsonl'));
            $contents = file_get_contents($path); $suite->true(strpos($contents, '192.0.2.1') === false);
            foreach (['run.started', 'run.interrupted', 'run.resumed', 'run.completed'] as $event) { $suite->true(strpos($contents, $event) !== false); }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            if (is_file($dir . '/.agent-logger.lock')) { unlink($dir . '/.agent-logger.lock'); }
            if (is_dir($dir)) { rmdir($dir); }
        }
    });
    foreach ([['unknown_write', UnknownWrite::class, 'tool.failed', 'outcome_unknown', 65536], ['large_write', LargeWrite::class, 'tool.completed', 'tool_output_limit', 100]] as $case) {
        $suite->test('recovery rechecks saved tool stop condition ' . $case[3], function () use ($suite, $case): void {
            list($name, $class, $event, $reason, $limit) = $case;
            $class::$calls = 0;
            $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('call-1', $name, '{}')]), [], 'tool_calls'), 'must not reach model again']);
            $clock = new FakeClock();
            $harness = new AgentHarness(new LoseEventStore($event), ['clock' => $clock]);
            $agent = Agent::create($name)->provider($provider, 'fake')->tools([$class])->options(['max_tool_result_bytes' => $limit]);
            $initial = $harness->run($agent, 'do once', RequestContext::create($name));
            $suite->same('interrupted', $initial->status);
            $clock->advance(181);
            $resumed = $harness->resume($agent, 'default', $name, $initial->runId);
            $suite->same($reason, $resumed->finishReason);
            $suite->same(1, count($provider->requests()));
            $suite->same(1, $class::$calls);
            $events = $harness->threads()->events('default', $name, $initial->runId);
            $suite->same(1, count(array_filter($events, function (array $saved) use ($event): bool { return $saved['type'] === $event; })));
        });
    }
    $suite->test('incomplete responses preserve reported token usage', function () use ($suite): void {
        $usage = ['input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30];
        $agent = Agent::create('incomplete-usage')->provider(new FakeProvider([new ModelResponse(Message::assistant('partial'), $usage, 'length')]), 'fake');
        $result = (new AgentHarness())->run($agent, 'answer', RequestContext::create('usage'));
        $suite->same('output_limit', $result->finishReason);
        $suite->same($usage, $result->usage);
    });
    $suite->test('recovery retains the per-iteration model attempt cap', function () use ($suite): void {
        $clock = new FakeClock();
        $provider = new FakeProvider(['must not be sent']);
        $agent = Agent::create('attempt-cap')->provider($provider, 'fake')->options(['max_model_attempts' => 1]);
        $harness = new AgentHarness(new LoseEventStore('message.started'), ['clock' => $clock]);
        $first = $harness->run($agent, 'answer', RequestContext::create('attempt-cap'));
        $suite->same('interrupted', $first->status);
        $clock->advance(181);
        $resumed = $harness->resume($agent, 'default', 'attempt-cap', $first->runId);
        $suite->same('model_attempt_limit', $resumed->finishReason);
        $suite->same(0, count($provider->requests()));
    });
    $suite->test('recovery restores the assistant completion event before executing its tools', function () use ($suite): void {
        $clock = new FakeClock();
        $provider = new FakeProvider([new ModelResponse(Message::assistant('', [new ToolCall('c1', 'large_write', '{}')]), [], 'tool_calls'), 'done']);
        $agent = Agent::create('message-event')->provider($provider, 'fake')->tools([LargeWrite::class]);
        $harness = new AgentHarness(new LoseEventStore('message.completed'), ['clock' => $clock]);
        $initial = $harness->run($agent, 'do once', RequestContext::create('message-event'));
        $suite->same('interrupted', $initial->status);
        $clock->advance(181);
        $resumed = $harness->resume($agent, 'default', 'message-event', $initial->runId);
        $suite->same('completed', $resumed->status);
        $types = array_column($harness->threads()->events('default', 'message-event', $initial->runId), 'type');
        $suite->same(2, count(array_filter($types, function (string $type): bool { return $type === 'message.completed'; })));
        $suite->true(array_search('message.completed', $types, true) < array_search('tool.started', $types, true));
    });
    $suite->test('tool side-effect declarations are included in recovery snapshots', function () use ($suite): void {
        $readOnly = false;
        $resolver = new DefaultToolResolver(function (string $class) use (&$readOnly) { return new $class($readOnly); });
        $harness = new AgentHarness(null, ['resolver' => $resolver]);
        $agent = Agent::create('tool-snapshot')->provider(new FakeProvider(['should not run']), 'fake')->tools([ConfiguredTool::class]);
        $queued = $harness->submit($agent, 'answer', RequestContext::create('tool-snapshot'));
        $readOnly = true;
        $error = $suite->throws(\JsonAI\Harness\RunException::class, function () use ($harness, $agent, $queued): void {
            $harness->resume($agent, 'default', 'tool-snapshot', $queued->runId);
        });
        $suite->same('definition_mismatch', $error->reason());
    });
    $suite->test('equivalent integer and floating JSON numbers cannot repeat a write', function () use ($suite): void {
        ConfiguredTool::$calls = 0;
        $provider = new FakeProvider([
            new ModelResponse(Message::assistant('', [new ToolCall('c1', 'configured_tool', '{"amount":1}')]), [], 'tool_calls'),
            new ModelResponse(Message::assistant('', [new ToolCall('c2', 'configured_tool', '{"amount":1.0}')]), [], 'tool_calls'),
            'done',
        ]);
        $resolver = new DefaultToolResolver(function (string $class) { return new $class(false); });
        $harness = new AgentHarness(null, ['resolver' => $resolver]);
        $agent = Agent::create('numeric-write')->provider($provider, 'fake')->tools([ConfiguredTool::class]);
        $result = $harness->run($agent, 'do once', RequestContext::create('numeric-write'));
        $suite->same('completed', $result->status);
        $suite->same(1, ConfiguredTool::$calls);
    });
    $suite->test('completion is logged only after its terminal checkpoint commits', function () use ($suite): void {
        $logger = new CapturingLogger(); $clock = new FakeClock();
        $harness = new AgentHarness(new LoseEventStore('run.completed'), ['logger' => $logger, 'clock' => $clock]);
        $agent = Agent::create('completion-log')->provider(new FakeProvider(['done']), 'fake');
        $initial = $harness->run($agent, 'answer', RequestContext::create('completion-log'));
        $suite->same('interrupted', $initial->status);
        $suite->true(!in_array('run.completed', $logger->messages, true));
        $suite->true(in_array('run.interrupted', $logger->messages, true));
        $clock->advance(181);
        $resumed = $harness->resume($agent, 'default', 'completion-log', $initial->runId);
        $suite->same('completed', $resumed->status);
        $suite->same(1, count(array_filter($logger->messages, function (string $message): bool { return $message === 'run.completed'; })));
    });
};
