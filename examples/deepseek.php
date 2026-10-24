<?php
/**
 * Offline protocol fixture: php examples/deepseek.php [--stream]
 * Real requests: set DEEPSEEK_API_KEY and DEEPSEEK_MODEL, then add --live.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Log\FileLogger;
use JsonAI\Provider\OpenAICompatibleProvider;
use JsonAI\Schema\Schema;
use JsonAI\Storage\FileStore;
use JsonAI\Support\Json;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ExecutionAwareToolInterface;
use JsonAI\Tool\ToolContext;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;

final class DeepSeekExampleInventoryTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'inventory_lookup';
    protected $description = 'Read offline demonstration inventory. The example quantity is always 12.';
    public function isReadOnly(): bool { return true; }
    public function inputSchema(): Schema
    {
        return Schema::object([
            'sku' => Schema::string()->description('Product SKU to look up, for example tea.')->required(),
        ]);
    }
    public function outputSchema(): Schema
    {
        return Schema::object([
            'quantity' => Schema::integer()->description('Available inventory quantity in this demonstration.')->required(),
        ]);
    }
    public function handle(array $input, ToolContext $context): array { return ['quantity' => 12]; }
}

$options = getopt('', ['live', 'stream', 'data-dir:', 'thread:', 'model:', 'base-url:', 'message:']);
$live = array_key_exists('live', $options); $stream = array_key_exists('stream', $options);
$model = $options['model'] ?? (getenv('DEEPSEEK_MODEL') ?: '');
$key = $live ? (getenv('DEEPSEEK_API_KEY') ?: '') : 'offline-fixture';
$baseUrl = $options['base-url'] ?? 'https://api.deepseek.com';
$directory = $options['data-dir'] ?? sys_get_temp_dir() . '/portable-agent-deepseek-example';
$transport = null;
if ($live && ($key === '' || $model === '')) {
    fwrite(STDERR, "使用 --live 时需设置 DEEPSEEK_API_KEY，以及 DEEPSEEK_MODEL 或 --model。\n"); exit(2);
}
if (!$live) {
    $model = 'offline-deepseek-fixture';
    $call = ['id' => 'call_' . bin2hex(random_bytes(8)), 'type' => 'function', 'function' => ['name' => 'inventory_lookup', 'arguments' => '{"sku":"tea"}']];
    $messages = [
        ['role' => 'assistant', 'content' => '', 'reasoning_content' => 'opaque_fixture_continuation', 'tool_calls' => [$call]],
        ['role' => 'assistant', 'content' => '演示库存：tea 为 12。此结果来自离线工具，未调用 DeepSeek。', 'reasoning_content' => 'opaque_fixture_final'],
    ];
    $queue = [];
    foreach ($messages as $index => $message) {
        $finish = $index === 0 ? 'tool_calls' : 'stop';
        $data = ['id' => 'fixture_' . $index, 'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30]];
        if ($stream) {
            if (isset($message['tool_calls'])) { $message['tool_calls'][0]['index'] = 0; }
            $data['choices'][0] = ['index' => 0, 'delta' => $message, 'finish_reason' => $finish];
            $wire = 'data: ' . Json::encode($data) . "\n\ndata: [DONE]\n\n";
            $queue[] = ['response' => new HttpResponse(200), 'chunks' => str_split($wire, 5)];
        } else { $queue[] = new HttpResponse(200, [], Json::encode($data)); }
    }
    $transport = new FakeTransport($queue);
}
$provider = OpenAICompatibleProvider::deepSeek($key, $transport, $baseUrl);
$agent = Agent::create('deepseek-example')->provider($provider, $model)->tools([DeepSeekExampleInventoryTool::class])
    ->modelOptions(['thinking' => ['type' => 'enabled']]);
$harness = new AgentHarness(new FileStore($directory . '/threads'), ['logger' => new FileLogger($directory . '/logs')]);
$message = $options['message'] ?? '使用工具查询 tea 库存，说明这是演示数据。';
$context = RequestContext::create($options['thread'] ?? 'deepseek-demo')->withScope('example');
if ($stream) {
    $result = $harness->stream($agent, $message, $context, function (array $event): void {
        if ($event['type'] === 'message.delta') { echo $event['data']['delta']; }
    });
    echo "\n";
} else { $result = $harness->run($agent, $message, $context); echo $result->text . "\n"; }
echo 'run_id=' . $result->runId . ' status=' . $result->status . "\n";
if (!$result->isSuccessful()) { fwrite(STDERR, '结束原因：' . $result->finishReason . "\n"); exit(1); }
