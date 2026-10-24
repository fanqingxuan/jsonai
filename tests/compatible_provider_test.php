<?php
declare(strict_types=1);
namespace JsonAI\Tests\CompatibleProvider;

use JsonAI\Agent\Agent;
use JsonAI\Context\ContextBuilder;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\OpenAICompatibleProvider;
use JsonAI\Provider\ProviderException;
use JsonAI\Schema\Schema;
use JsonAI\Storage\InMemoryStore;
use JsonAI\Storage\StateStoreInterface;
use JsonAI\Support\FakeClock;
use JsonAI\Support\Json;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ExecutionAwareToolInterface;
use JsonAI\Tool\ToolContext;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;

final class LookupTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'lookup';
    protected $description = 'Read a fixture quantity.';
    public static $calls = 0;
    public function isReadOnly(): bool { return true; }
    public function inputSchema(): Schema { return Schema::object(['sku' => Schema::string()->required()]); }
    public function outputSchema(): Schema { return Schema::object(['quantity' => Schema::integer()->required()]); }
    public function handle(array $input, ToolContext $context): array { self::$calls++; return ['quantity' => 12]; }
}
final class InterruptedStore implements StateStoreInterface
{
    private $inner;
    private $interrupted = false;
    public function __construct() { $this->inner = new InMemoryStore(); }
    public function get(string $namespace, string $key): ?array { return $this->inner->get($namespace, $key); }
    public function delete(string $namespace, string $key): void { $this->inner->delete($namespace, $key); }
    public function scan(string $namespace): array { return $this->inner->scan($namespace); }
    public function transact(string $namespace, string $key, callable $mutator): ?array
    {
        return $this->inner->transact($namespace, $key, function (?array $document) use ($mutator): ?array {
            $next = $mutator($document);
            foreach ($next['runs'] ?? [] as $run) {
                $events = $run['events']; $last = end($events);
                if (!$this->interrupted && $last && $last['type'] === 'message.completed') {
                    $this->interrupted = true; throw new \RuntimeException('Simulated interrupted checkpoint');
                }
            }
            return $next;
        });
    }
}
function response(array $message = [], string $finish = 'stop'): HttpResponse
{
    return new HttpResponse(200, [], Json::encode(['id' => 'fixture', 'choices' => [['index' => 0,
        'message' => array_replace(['role' => 'assistant', 'content' => 'done'], $message), 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30]]));
}
function call(): array { return ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"sku":"tea"}']]; }
function definitions(): array { return [['name' => 'lookup', 'description' => 'Read a fixture quantity.', 'parameters' => (new LookupTool())->inputSchema()->toArray()]]; }
function stream(array $chunks): array
{
    $wire = ": keep-alive\n\n";
    foreach ($chunks as $chunk) { $wire .= 'data: ' . Json::encode($chunk) . "\n\n"; }
    return ['response' => new HttpResponse(200), 'chunks' => str_split($wire . "data: [DONE]\n\n", 3)];
}
function chunk(array $delta, ?string $finish = null, ?array $usage = null): array
{
    return ['id' => 'fixture', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]], 'usage' => $usage];
}

return function ($suite): void {
    $suite->test('compatible provider sends the configured endpoint auth and DeepSeek token/thinking fields', function () use ($suite): void {
        $transport = new FakeTransport([response()]);
        $provider = OpenAICompatibleProvider::deepSeek('fixture-key', $transport, 'https://gateway.example/deepseek/v1/');
        $result = $provider->complete(new ModelRequest('application-selected-model', [new Message('developer', 'Answer briefly'),
            new Message('user', [['type' => 'text', 'text' => 'hello']])], definitions(),
            ['max_output_tokens' => 123, 'thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']));
        $sent = $transport->requests()[0]; $body = Json::decode($sent['body']);
        $suite->same('https://gateway.example/deepseek/v1/chat/completions', $sent['url']);
        $suite->same('Bearer fixture-key', $sent['headers']['Authorization']);
        $suite->same('application-selected-model', $body->model); $suite->same(123, $body->max_tokens);
        $suite->true(!isset($body->max_completion_tokens) && !isset($body->n) && !isset($body->tools[0]->function->strict));
        $suite->same('system', $body->messages[0]->role); $suite->same('hello', $body->messages[1]->content);
        $suite->same('enabled', $body->thinking->type); $suite->same('high', $body->reasoning_effort);
        $suite->same('done', $result->message->text()); $suite->same(30, $result->usage['total_tokens']);
    });
    $suite->test('DeepSeek tool turns and later user turns replay complete reasoning metadata', function () use ($suite): void {
        LookupTool::$calls = 0;
        $transport = new FakeTransport([
            response(['content' => null, 'reasoning_content' => 'opaque_tool_state', 'tool_calls' => [call()]], 'tool_calls'),
            response(['content' => '库存 12', 'reasoning_content' => 'opaque_answer_state']), response(['content' => '上一轮查 tea'])]);
        $provider = OpenAICompatibleProvider::deepSeek('fixture-key', $transport);
        $agent = Agent::create('compatible')->provider($provider, 'fixture-model')->tools([LookupTool::class])
            ->modelOptions(['thinking' => ['type' => 'enabled'], 'reasoning_effort' => 'high']);
        $harness = new AgentHarness(); $context = RequestContext::create('thread');
        $first = $harness->run($agent, '查询 tea', $context);
        $suite->same('completed', $first->status); $suite->same('库存 12', $first->text); $suite->same(1, LookupTool::$calls);
        $secondBody = Json::decode($transport->requests()[1]['body']);
        $suite->same('opaque_tool_state', $secondBody->messages[1]->reasoning_content);
        $suite->same($secondBody->messages[1]->tool_calls[0]->id, $secondBody->messages[2]->tool_call_id);
        $second = $harness->run($agent, '上一轮查询什么', $context); $suite->same('completed', $second->status);
        $thirdBody = Json::decode($transport->requests()[2]['body']);
        $suite->same('opaque_tool_state', $thirdBody->messages[1]->reasoning_content);
        $suite->same('opaque_answer_state', $thirdBody->messages[3]->reasoning_content);
        $events = Json::encode($harness->threads()->events('default', 'thread', $first->runId));
        $suite->true(strpos($events, 'opaque_') === false);
        $suite->true(strpos(Json::encode($first->toArray()), 'opaque_') === false);
    });
    $suite->test('DeepSeek streaming joins reasoning and tool fragments without exposing continuation events', function () use ($suite): void {
        LookupTool::$calls = 0;
        $transport = new FakeTransport([
            stream([chunk(['role' => 'assistant', 'reasoning_content' => 'opaque_']), chunk(['reasoning_content' => 'stream_state']),
                chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"sku":']]]]),
                chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"tea"}']]]]),
                chunk([], 'tool_calls', ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30])]),
            stream([chunk(['role' => 'assistant', 'reasoning_content' => 'opaque_final']), chunk(['content' => '库存']),
                chunk(['content' => ' 12']), chunk([], 'stop', ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40])])]);
        $agent = Agent::create('stream')->provider(OpenAICompatibleProvider::deepSeek('key', $transport), 'fixture')->tools([LookupTool::class]);
        $events = []; $result = (new AgentHarness())->stream($agent, '查询', RequestContext::create('thread'),
            function (array $event) use (&$events): void { $events[] = $event; });
        $suite->same('completed', $result->status); $suite->same('库存 12', $result->text); $suite->same(70, $result->usage['total_tokens']);
        $suite->same(1, LookupTool::$calls); $suite->true(strpos(Json::encode($events), 'opaque_') === false);
        $body = Json::decode($transport->requests()[1]['body']);
        $suite->same('opaque_stream_state', $body->messages[1]->reasoning_content);
        $suite->same(true, $body->stream_options->include_usage);
    });
    $suite->test('DeepSeek reasoning continuation survives checkpoint interruption and provider recreation', function () use ($suite): void {
        LookupTool::$calls = 0; $store = new InterruptedStore(); $clock = new FakeClock();
        $transport = new FakeTransport([response(['content' => null, 'reasoning_content' => 'opaque_checkpoint', 'tool_calls' => [call()]], 'tool_calls')]);
        $agent = Agent::create('resume')->provider(OpenAICompatibleProvider::deepSeek('old-key', $transport), 'fixture')->tools([LookupTool::class]);
        $first = (new AgentHarness($store, ['clock' => $clock]))->run($agent, '查询', RequestContext::create('thread'));
        $suite->same('interrupted', $first->status); $suite->same(0, LookupTool::$calls);
        $clock->advance(181); $nextTransport = new FakeTransport([response()]);
        $nextAgent = $agent->provider(OpenAICompatibleProvider::deepSeek('rotated-key', $nextTransport), 'fixture');
        $next = (new AgentHarness($store, ['clock' => $clock]))->resume($nextAgent, 'default', 'thread', $first->runId);
        $suite->same('completed', $next->status); $suite->same(1, LookupTool::$calls);
        $suite->same('opaque_checkpoint', Json::decode($nextTransport->requests()[0]['body'])->messages[1]->reasoning_content);
    });
    $suite->test('DeepSeek JSON object mode adds schema instructions and validates the final output locally', function () use ($suite): void {
        $transport = new FakeTransport([response(['content' => '{"answer":"ok"}']), response(['content' => '{"answer":12}'])]);
        $agent = Agent::create('json')->provider(OpenAICompatibleProvider::deepSeek('key', $transport), 'fixture')
            ->outputSchema(Schema::object(['answer' => Schema::string()->required(), 'optional_note' => Schema::string()]));
        $harness = new AgentHarness();
        $good = $harness->run($agent, 'answer', RequestContext::create('good'));
        $suite->same('completed', $good->status); $suite->same('ok', $good->output->answer);
        $bad = $harness->run($agent, 'answer', RequestContext::create('bad'));
        $suite->same('invalid_output', $bad->finishReason);
        $body = Json::decode($transport->requests()[0]['body']);
        $suite->same('json_object', $body->response_format->type);
        $suite->true(strpos($body->messages[0]->content, 'JSON') !== false && strpos($body->messages[0]->content, 'optional_note') !== false);
        $suite->true(!isset($body->response_format->json_schema));
    });
    $suite->test('compatible profile supports explicit token and stream usage variants', function () use ($suite): void {
        $transport = new FakeTransport([stream([chunk(['content' => 'done'], 'stop')])]);
        $provider = new OpenAICompatibleProvider('', 'http://localhost:9999/v1', $transport,
            ['max_tokens_parameter' => 'max_completion_tokens', 'stream_usage' => false]);
        $provider->stream(new ModelRequest('local', [Message::user('hello')], [], ['max_output_tokens' => 12]), function (array $event): void {});
        $sent = $transport->requests()[0]; $body = Json::decode($sent['body']);
        $suite->same(12, $body->max_completion_tokens); $suite->true(!isset($body->max_tokens) && !isset($body->stream_options));
        $suite->true(!isset($sent['headers']['Authorization']));
    });
    $suite->test('compatible structured formats and disabled media are enforced before HTTP', function () use ($suite): void {
        $transport = new FakeTransport(); $schema = Schema::object(['answer' => Schema::string()->required()])->toArray();
        $provider = new OpenAICompatibleProvider('key', 'https://example.test/v1', $transport, ['response_format' => 'none']);
        $suite->throws(\InvalidArgumentException::class, function () use ($provider, $schema): void { $provider->complete(new ModelRequest('fixture', [], [], ['response_schema' => $schema])); });
        $suite->throws(\InvalidArgumentException::class, function () use ($provider): void { $provider->complete(new ModelRequest('fixture', [new Message('user', [['type' => 'image', 'url' => 'https://example.test/image.png']])])); });
        $suite->same([], $transport->requests());
        $transport->push(response());
        $enabled = new OpenAICompatibleProvider('key', 'https://example.test/v1', $transport, ['response_format' => 'json_schema', 'images' => true]);
        $enabled->complete(new ModelRequest('fixture', [new Message('user', [['type' => 'image', 'url' => 'https://example.test/image.png']])], [], ['response_schema' => $schema]));
        $body = Json::decode($transport->requests()[0]['body']);
        $suite->same('json_schema', $body->response_format->type); $suite->same('image_url', $body->messages[0]->content[0]->type);
    });
    $suite->test('continuation is opt-in and is omitted on requests without tools', function () use ($suite): void {
        $transport = new FakeTransport([response(['reasoning_content' => 'opaque_default'])]);
        $generic = new OpenAICompatibleProvider('key', 'https://example.test', $transport);
        $result = $generic->complete(new ModelRequest('fixture', [Message::user('hello')]));
        $suite->true(!isset($result->message->metadata()['openai_compatible']['reasoning_content']));
        $transport = new FakeTransport([response(['reasoning_content' => 'opaque_no_tools']), response()]);
        $provider = OpenAICompatibleProvider::deepSeek('key', $transport);
        $result = $provider->complete(new ModelRequest('fixture', [Message::user('hello')]));
        $provider->complete(new ModelRequest('fixture', [$result->message, Message::user('next')]));
        $suite->true(!isset(Json::decode($transport->requests()[1]['body'])->messages[0]->reasoning_content));
    });
    $suite->test('provider failures and interrupted DeepSeek completions are not reported as success', function () use ($suite): void {
        foreach (['insufficient_system_resource', 'aborted'] as $finish) {
            $transport = new FakeTransport([response(['content' => 'partial'], $finish)]);
            $agent = Agent::create('incomplete')->provider(OpenAICompatibleProvider::deepSeek('key', $transport), 'fixture');
            $result = (new AgentHarness())->run($agent, 'hello', RequestContext::create($finish));
            $suite->same('model_incomplete', $result->finishReason); $suite->same(30, $result->usage['total_tokens']);
        }
        $transport = new FakeTransport([new HttpResponse(429, [], 'private upstream detail')]);
        $provider = OpenAICompatibleProvider::deepSeek('key', $transport);
        $error = $suite->throws(ProviderException::class, function () use ($provider): void { $provider->complete(new ModelRequest('fixture', [])); });
        $suite->same(true, $error->retryable()); $suite->true(strpos($error->getMessage(), 'private') === false);
        $malformed = OpenAICompatibleProvider::deepSeek('key', new FakeTransport([response(['reasoning_content' => ['bad']])]));
        $suite->throws(ProviderException::class, function () use ($malformed): void { $malformed->complete(new ModelRequest('fixture', [])); });
    });
    $suite->test('compatible configuration is validated and included in safe recovery fingerprints', function () use ($suite): void {
        $a = OpenAICompatibleProvider::deepSeek('first', new FakeTransport());
        $b = OpenAICompatibleProvider::deepSeek('second', new FakeTransport());
        $suite->same($a->definitionFingerprint(), $b->definitionFingerprint());
        $suite->true($a->definitionFingerprint() !== (new OpenAICompatibleProvider('first', 'https://api.deepseek.com', new FakeTransport()))->definitionFingerprint());
        foreach ([['unknown' => true], ['max_tokens_parameter' => 'bad'], ['response_format' => 'bad'], ['stream_usage' => 1]] as $config) {
            $suite->throws(\InvalidArgumentException::class, function () use ($config): void { new OpenAICompatibleProvider('', 'https://example.test', new FakeTransport(), $config); });
        }
        $transport = new FakeTransport(); $provider = OpenAICompatibleProvider::deepSeek('key', $transport);
        foreach ([['type' => 'maybe'], ['type' => 'enabled', 'tools' => []], null] as $thinking) {
            $suite->throws(\InvalidArgumentException::class, function () use ($provider, $thinking): void { $provider->complete(new ModelRequest('fixture', [], [], ['thinking' => $thinking])); });
        }
        $suite->same([], $transport->requests());
    });
    $suite->test('context trimming keeps reasoning attached to complete retained tool exchanges', function () use ($suite): void {
        $assistant = Message::assistant('', [new ToolCall('call_1', 'lookup', '{"sku":"tea"}')])
            ->withMetadata(['openai_compatible' => ['reasoning_content' => 'opaque_context']]);
        $current = Message::user('next');
        $history = [Message::user('old'), $assistant, Message::tool('call_1', '{"quantity":12}'), $current];
        $builder = new ContextBuilder();
        $retained = $builder->build($history, [], definitions(), 20000, 100, 10, [], $current->id());
        $suite->same('opaque_context', $retained->messages[1]->metadata()['openai_compatible']['reasoning_content']);
        $trimmed = $builder->build($history, [], definitions(), 20000, 100, 1, [], $current->id());
        $suite->true(strpos(Json::encode($trimmed->memory), 'opaque_context') === false);
        foreach ($trimmed->messages as $message) { $suite->same([], $message->toolCalls()); $suite->true($message->role() !== 'tool'); }
    });
};
