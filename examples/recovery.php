<?php
/**
 * Offline checkpoint example:
 *   php examples/recovery.php --data-dir=/tmp/agent-recovery
 * Two processes:
 *   php examples/recovery.php --data-dir=/tmp/agent-recovery --submit-only
 *   php examples/recovery.php --data-dir=/tmp/agent-recovery --run-id=<printed-run-id>
 * Queued runs resume immediately. Interrupted running runs resume only after their lease expires.
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

$options = getopt('', ['live', 'submit-only', 'run-id:', 'data-dir:', 'thread:', 'model:', 'base-url:']);
$config = [
    'data_dir' => isset($options['data-dir']) ? $options['data-dir'] : sys_get_temp_dir() . '/portable-agent-recovery-example',
    'thread' => isset($options['thread']) ? $options['thread'] : 'recovery-demo',
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
    $provider = new FakeProvider(['已从持久化的待执行检查点恢复，并完成本次运行。']);
    $config['model'] = 'offline-demo';
}
$agent = Agent::create('recovery-example')->provider($provider, $config['model'])->prompt(TemplatePrompt::system('请用一句中文回复。'));
$identity = ['user_id' => 'demo-user'];
$services = ['logger' => new FileLogger($config['data_dir'] . '/logs')];
$harness = new AgentHarness(new FileStore($config['data_dir'] . '/threads'), $services);
if (isset($options['run-id'])) {
    $runId = $options['run-id'];
} else {
    $queued = $harness->submit($agent, '从检查点恢复后回复。', RequestContext::create($config['thread'])->withScope('example')->withIdentity($identity));
    $runId = $queued->runId;
    echo 'queued_run_id=' . $runId . "\n";
    if (array_key_exists('submit-only', $options)) { exit(0); }
}
// A different process constructs the same definition, storage configuration and trusted identity.
$workerHarness = new AgentHarness(new FileStore($config['data_dir'] . '/threads'), $services);
$result = $workerHarness->resume($agent, 'example', $config['thread'], $runId, $identity);
echo $result->text . "\n";
echo 'run_id=' . $result->runId . ' status=' . $result->status . "\n";
if (!$result->isSuccessful()) { fwrite(STDERR, '结束原因：' . $result->finishReason . "\n"); exit(1); }
