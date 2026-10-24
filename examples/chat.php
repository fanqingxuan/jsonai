<?php
/**
 * Offline by default:
 *   php examples/chat.php --data-dir=/tmp/agent-example --message='查询茶叶库存'
 * Real model requests require explicit --live, OPENAI_API_KEY and --model or OPENAI_MODEL.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Log\FileLogger;
use JsonAI\Memory\LongTerm\FileLongTermMemory;
use JsonAI\Memory\ShortTerm\FileShortTermMemory;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Schema\Schema;
use JsonAI\Storage\FileStore;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ExecutionAwareToolInterface;
use JsonAI\Tool\ToolContext;

final class ExampleInventoryTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'inventory_lookup';
    protected $description = 'Read example inventory. Values are demonstration data.';
    public function inputSchema(): Schema
    {
        return Schema::object([
            'sku' => Schema::string()->description('Product SKU to look up, for example tea.')->required(),
        ]);
    }
    public function outputSchema(): Schema
    {
        return Schema::object([
            'sku' => Schema::string()->description('Product SKU used for the lookup.')->required(),
            'quantity' => Schema::integer()->description('Available inventory quantity in this demonstration.')->required(),
        ]);
    }
    public function isReadOnly(): bool { return true; }
    public function handle(array $input, ToolContext $context): array
    {
        // A real application injects its own business service through DefaultToolResolver.
        $context->logger()->info('example.inventory_read');
        return ['sku' => $input['sku'], 'quantity' => 12];
    }
}

$options = getopt('', ['live', 'data-dir:', 'thread:', 'message:', 'model:', 'base-url:', 'request-id:']);
$config = [
    'data_dir' => isset($options['data-dir']) ? $options['data-dir'] : sys_get_temp_dir() . '/portable-agent-examples',
    'thread' => isset($options['thread']) ? $options['thread'] : 'chat-demo',
    'message' => isset($options['message']) ? $options['message'] : '查询茶叶（sku=tea）的库存。',
    'model' => isset($options['model']) ? $options['model'] : (getenv('OPENAI_MODEL') ?: ''),
    'base_url' => isset($options['base-url']) ? $options['base-url'] : 'https://api.openai.com/v1',
];
$live = array_key_exists('live', $options);
if ($live) {
    $key = getenv('OPENAI_API_KEY') ?: '';
    if ($key === '' || $config['model'] === '') {
        fwrite(STDERR, "使用 --live 时需设置 OPENAI_API_KEY，以及 OPENAI_MODEL 或 --model。\n");
        exit(2);
    }
    $provider = new ResponsesProvider($key, null, $config['base_url']);
} else {
    $provider = new FakeProvider([
        new ModelResponse(Message::assistant('', [new ToolCall('call_' . bin2hex(random_bytes(8)), 'inventory_lookup', '{"sku":"tea"}')]), [], 'tool_calls'),
        '演示库存：tea 为 12。此结果来自离线示例工具，未调用在线模型。',
    ]);
    $config['model'] = 'offline-demo';
}

$agent = Agent::create('inventory-example')
    ->provider($provider, $config['model'])
    ->prompt(TemplatePrompt::system('你是库存查询助手。使用 inventory_lookup 获取示例数据，明确结果属于演示数据。'))
    ->tools([ExampleInventoryTool::class]);
$harness = new AgentHarness(new FileStore($config['data_dir'] . '/threads'), [
    'logger' => new FileLogger($config['data_dir'] . '/logs'),
    'short_memory' => new FileShortTermMemory($config['data_dir'] . '/short-memory'),
    'long_memory' => new FileLongTermMemory($config['data_dir'] . '/long-memory'),
]);
$agent = $agent->withHarness($harness);
$context = RequestContext::create($config['thread'])
    ->withScope('example')
    ->withIdentity(['user_id' => 'demo-user'])
    ->withRequestId(isset($options['request-id']) ? $options['request-id'] : null);
$result = $agent->run($config['message'], $context);
echo $result->text . "\n";
echo 'run_id=' . $result->runId . ' status=' . $result->status . "\n";
if (!$result->isSuccessful()) {
    fwrite(STDERR, '结束原因：' . $result->finishReason . "\n");
    exit(1);
}
