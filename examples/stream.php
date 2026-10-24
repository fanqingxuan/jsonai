<?php
/**
 * php examples/stream.php --data-dir=/tmp/agent-example
 * --sse prints SSE frames; otherwise prints text deltas. HTTP headers belong to the application.
 * Online requests additionally require --live, OPENAI_API_KEY and OPENAI_MODEL/--model.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Log\FileLogger;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Storage\FileStore;
use JsonAI\Support\Json;

$options = getopt('', ['live', 'sse', 'data-dir:', 'thread:', 'message:', 'model:', 'base-url:']);
$config = [
    'data_dir' => isset($options['data-dir']) ? $options['data-dir'] : sys_get_temp_dir() . '/portable-agent-examples',
    'thread' => isset($options['thread']) ? $options['thread'] : 'stream-demo',
    'message' => isset($options['message']) ? $options['message'] : '请用一句话介绍自己。',
    'model' => isset($options['model']) ? $options['model'] : (getenv('OPENAI_MODEL') ?: ''),
    'base_url' => isset($options['base-url']) ? $options['base-url'] : 'https://api.openai.com/v1',
];
if (array_key_exists('live', $options)) {
    $key = getenv('OPENAI_API_KEY') ?: '';
    if ($key === '' || $config['model'] === '') {
        fwrite(STDERR, "使用 --live 时需设置 OPENAI_API_KEY，以及 OPENAI_MODEL 或 --model。\n");
        exit(2);
    }
    $provider = new ResponsesProvider($key, null, $config['base_url']);
} else {
    $provider = new FakeProvider(['这是独立 Agent 框架的离线流式输出示例。']);
    $config['model'] = 'offline-demo';
}
$agent = Agent::create('stream-example')->provider($provider, $config['model'])->prompt(TemplatePrompt::system('请用简洁中文回答。'));
$harness = new AgentHarness(new FileStore($config['data_dir'] . '/threads'), ['logger' => new FileLogger($config['data_dir'] . '/logs')]);
$agent = $agent->withHarness($harness);
$sse = array_key_exists('sse', $options);
$result = $agent->stream($config['message'], RequestContext::create($config['thread'])->withScope('example'), function (array $event) use ($sse): void {
    if ($sse) {
        echo 'id: ' . $event['sequence'] . "\n";
        echo 'event: ' . $event['type'] . "\n";
        echo 'data: ' . Json::encode($event) . "\n\n";
    } elseif ($event['type'] === 'message.delta') {
        echo $event['data']['delta'];
    }
    // HTTP/SSE adapters may call flush() here; the core never writes or sets headers itself.
});
if (!$sse) { echo "\n" . 'run_id=' . $result->runId . ' status=' . $result->status . "\n"; }
if (!$result->isSuccessful()) { fwrite(STDERR, '结束原因：' . $result->finishReason . "\n"); exit(1); }
