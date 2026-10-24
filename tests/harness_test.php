<?php
namespace JsonAI\Tests;

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RunException;
use JsonAI\Harness\RequestContext;
use JsonAI\Log\FileLogger;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Schema\Schema;
use JsonAI\Storage\FileStore;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Support\Json;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ToolContext;
use Psr\Log\AbstractLogger;

class HarnessWriteTool extends AbstractTool
{
    protected $name = 'write_record';
    protected $description = 'Write one test record';
    public static $writes = 0;
    public static $identities = [];
    public function inputSchema(): Schema { return Schema::object(['value' => Schema::integer()->required()]); }
    public function outputSchema(): Schema { return Schema::object(['count' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array
    {
        self::$writes++; self::$identities[] = $context->identity();
        return ['count' => self::$writes];
    }
}
class HarnessUnknownTool extends HarnessWriteTool
{
    protected $name = 'unknown_write';
    public function handle(array $input, ToolContext $context): array { self::$writes++; throw new \RuntimeException('lost after commit'); }
}
class HarnessBrokenLogger extends AbstractLogger
{
    public function log($level, $message, array $context = []) { throw new \RuntimeException('disk full'); }
}

return function (TestSuite $t): void {
    $toolResponse = function (string $id = 'call1', string $name = 'write_record', string $arguments = '{"value":1}'): ModelResponse {
        return new ModelResponse(Message::assistant('', [new ToolCall($id, $name, $arguments)]), ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15], 'tool_calls');
    };
    $t->test('harness runs tools by class, preserves trusted identity and continues the conversation', function () use ($t, $toolResponse): void {
        HarnessWriteTool::$writes = 0; HarnessWriteTool::$identities = [];
        $provider = new FakeProvider([$toolResponse(), 'done', 'second turn']);
        $agent = Agent::create('test')->provider($provider, 'fake')->tools([HarnessWriteTool::class]);
        $harness = new AgentHarness();
        $requestMessage = \JsonAI\Message\Message::user('write');
        $requestContext = RequestContext::create('thread')->withScope('tenant')->withIdentity(['user_id' => 'u1'])->withRequestId('request1');
        $result = $harness->run($agent, $requestMessage, $requestContext);
        $t->same('completed', $result->status); $t->same('done', $result->text);
        $t->same(1, HarnessWriteTool::$writes); $t->same([['user_id' => 'u1']], HarnessWriteTool::$identities);
        $t->same(15, $result->usage['total_tokens']);
        $messages = $harness->threads()->messages('tenant', 'thread');
        $t->same(['user', 'assistant', 'tool', 'assistant'], array_map(function (Message $m) { return $m->role(); }, $messages));
        $t->same('call1', $messages[2]->toolCallId());
        $again = $harness->run($agent, $requestMessage, $requestContext);
        $t->same($result->runId, $again->runId); $t->same(2, count($provider->requests()));
        $next = $harness->run($agent, 'next', RequestContext::create('thread')->withScope('tenant')->withIdentity(['user_id' => 'u1']));
        $t->same('second turn', $next->text); $t->same(6, count($harness->threads()->messages('tenant', 'thread')));
    });
    $t->test('harness rejects identity reuse and request ID payload changes before side effects', function () use ($t): void {
        $agent = Agent::create('test')->provider(new FakeProvider(['done']), 'fake'); $h = new AgentHarness();
        $h->run($agent, 'hello', RequestContext::create('t')->withScope('scope')->withIdentity(['user' => 'a'])->withRequestId('r'));
        $t->throws(RunException::class, function () use ($h, $agent): void { $h->run($agent, 'changed', RequestContext::create('t')->withScope('scope')->withIdentity(['user' => 'a'])->withRequestId('r')); });
        $t->throws(RunException::class, function () use ($h, $agent): void { $h->run($agent, 'hello', RequestContext::create('t')->withScope('scope')->withIdentity(['user' => 'b'])); });
    });
    $t->test('harness does not execute unknown tools or invalid arguments and reports structured errors', function () use ($t, $toolResponse): void {
        HarnessWriteTool::$writes = 0;
        $provider = new FakeProvider([$toolResponse('one', 'write_record', '{"value":"not integer"}'), $toolResponse('two', 'unregistered'), 'fixed']);
        $agent = Agent::create('test')->provider($provider, 'fake')->tools([HarnessWriteTool::class]); $h = new AgentHarness();
        $result = $h->run($agent, 'test', RequestContext::create('t'));
        $t->same('completed', $result->status); $t->same(0, HarnessWriteTool::$writes);
        $messages = $h->threads()->messages('default', 't');
        $t->same('invalid_arguments', Json::decodeArray($messages[2]->text())['error']['code']);
        $t->same('unknown_tool', Json::decodeArray($messages[4]->text())['error']['code']);
    });
    $t->test('unknown write outcome stops the loop without model or tool retry', function () use ($t, $toolResponse): void {
        HarnessWriteTool::$writes = 0;
        $provider = new FakeProvider([$toolResponse('a', 'unknown_write'), 'must not happen']);
        $agent = Agent::create('test')->provider($provider, 'fake')->tools([HarnessUnknownTool::class]); $h = new AgentHarness();
        $result = $h->run($agent, 'write', RequestContext::create('t'));
        $t->same('outcome_unknown', $result->finishReason); $t->same(1, HarnessWriteTool::$writes); $t->same(1, count($provider->requests()));
        $t->same(3, count($h->threads()->messages('default', 't')));
    });
    $t->test('identical repeated writes with new call IDs do not repeat the business operation', function () use ($t, $toolResponse): void {
        HarnessWriteTool::$writes = 0;
        $provider = new FakeProvider([$toolResponse('a'), $toolResponse('b'), 'done']);
        $agent = Agent::create('test')->provider($provider, 'fake')->tools([HarnessWriteTool::class]); $h = new AgentHarness();
        $t->same('completed', $h->run($agent, 'write', RequestContext::create('t'))->status);
        $t->same(1, HarnessWriteTool::$writes);
        $t->same('repeated_write', Json::decodeArray($h->threads()->messages('default', 't')[4]->text())['error']['code']);
    });
    $t->test('tool limit closes every pending call with a result and leaves a valid next turn', function () use ($t): void {
        HarnessWriteTool::$writes = 0;
        $response = new ModelResponse(Message::assistant('', [new ToolCall('a', 'write_record', '{"value":1}'), new ToolCall('b', 'write_record', '{"value":2}')]), [], 'tool_calls');
        $provider = new FakeProvider([$response, 'next']);
        $agent = Agent::create('test')->provider($provider, 'fake')->tools([HarnessWriteTool::class])->options(['max_tool_calls' => 1]); $h = new AgentHarness();
        $result = $h->run($agent, 'write', RequestContext::create('t'));
        $t->same('limited', $result->status); $t->same('tool_limit', $result->finishReason); $t->same(1, HarnessWriteTool::$writes);
        $history = $h->threads()->messages('default', 't'); $t->same(4, count($history));
        $t->same('not_executed', Json::decodeArray($history[3]->text())['error']['code']);
        $t->same('completed', $h->run($agent, 'continue', RequestContext::create('t'))->status);
    });
    $t->test('stream and replay deliver ordered durable events and completed output only once', function () use ($t): void {
        $provider = new FakeProvider(['你好']); $agent = Agent::create('test')->provider($provider, 'fake'); $h = new AgentHarness(); $events = [];
        $result = $h->stream($agent, 'hello', RequestContext::create('t'), function (array $e) use (&$events): void { $events[] = $e; });
        $t->same('completed', $result->status); $t->same('run.completed', end($events)['type']);
        $text = ''; foreach ($events as $event) { if ($event['type'] === 'message.delta') { $text .= $event['data']['delta']; } }
        $t->same('你好', $text);
        $replayed = []; $h->replay('default', 't', $result->runId, 2, function (array $e) use (&$replayed): void { $replayed[] = $e; });
        $t->same(array_slice($events, 2), $replayed);
    });
    $t->test('queued jobs survive file store recreation and explicit worker resume', function () use ($t): void {
        $dir = sys_get_temp_dir() . '/agent-queue-' . bin2hex(random_bytes(4)); $store = new FileStore($dir);
        $agent = Agent::create('test')->provider(new FakeProvider(['worker done']), 'fake');
        $queued = (new AgentHarness($store))->submit($agent, 'hello', RequestContext::create('t')->withScope('scope')->withIdentity(['user' => 'one']));
        $t->same('queued', $queued->status);
        $h = new AgentHarness(new FileStore($dir)); $result = $h->resume($agent, 'scope', 't', $queued->runId, ['user' => 'one']);
        $t->same('worker done', $result->text); $t->same('completed', $result->status);
        $h->deleteThread('scope', 't'); $t->same(null, $h->threads()->get('scope', 't'));
    });
    $t->test('cancelled queued runs never invoke the provider', function () use ($t): void {
        $provider = new FakeProvider(['no']); $agent = Agent::create('test')->provider($provider, 'fake'); $h = new AgentHarness();
        $queued = $h->submit($agent, 'hello', RequestContext::create('t')); $h->cancel('default', 't', $queued->runId);
        $t->same('cancelled', $h->resume($agent, 'default', 't', $queued->runId)->status); $t->same([], $provider->requests());
    });
    $t->test('structured response validation distinguishes valid object output from invalid output', function () use ($t): void {
        $schema = Schema::object(['name' => Schema::string()->required()]); $provider = new FakeProvider(['{"name":"Ada"}', '{"name":12}']);
        $agent = Agent::create('test')->provider($provider, 'fake')->outputSchema($schema); $h = new AgentHarness();
        $ok = $h->run($agent, 'name', RequestContext::create('a')); $t->same('Ada', $ok->output->name);
        $bad = $h->run($agent, 'name', RequestContext::create('b')); $t->same('invalid_output', $bad->finishReason);
    });
    $t->test('logging failure does not alter a successful run and file logs redact secret fields', function () use ($t): void {
        $agent = Agent::create('test')->provider(new FakeProvider(['done']), 'fake');
        $result = (new AgentHarness(null, ['logger' => new HarnessBrokenLogger()]))->run($agent, 'hello', RequestContext::create('t'));
        $t->same('completed', $result->status); $t->true(in_array('logger_write_failed', $result->warnings, true));
        $dir = sys_get_temp_dir() . '/agent-log-' . bin2hex(random_bytes(4));
        $logger = new \JsonAI\Log\SafeLogger(new FileLogger($dir), ['run_id' => 'run1']);
        $logger->info('test', ['api_key' => 'secret', 'arguments' => ['password' => 'hidden']]);
        $line = file_get_contents(glob($dir . '/*.jsonl')[0]);
        $t->true(strpos($line, 'secret') === false); $t->true(strpos($line, 'hidden') === false); $t->true(strpos($line, 'run1') !== false);
    });
};
