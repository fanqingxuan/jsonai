<?php
declare(strict_types=1);
namespace JsonAI\Tests\Logger;

use JsonAI\Log\FileLogger;
use JsonAI\Log\SafeLogger;

function directory(): string
{
    $path = sys_get_temp_dir() . '/agent-jsonl-test-' . bin2hex(random_bytes(8));
    mkdir($path, 0700); return $path;
}
function cleanup(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    if (is_file($directory . '/.agent-logger.lock')) { unlink($directory . '/.agent-logger.lock'); }
    rmdir($directory);
}
function records(string $file, $suite): array
{
    $content = file_get_contents($file);
    $suite->true(substr($content, -1) === "\n");
    $suite->true(substr($content, 0, 3) !== "\xef\xbb\xbf");
    $records = [];
    foreach (explode("\n", rtrim($content, "\n")) as $line) {
        $record = json_decode($line);
        $suite->same(JSON_ERROR_NONE, json_last_error());
        $suite->true($record instanceof \stdClass);
        foreach (['time', 'level', 'message', 'context'] as $field) { $suite->true(property_exists($record, $field)); }
        $suite->true($record->context instanceof \stdClass);
        $records[] = $record;
    }
    return $records;
}

return function ($suite): void {
    $suite->test('JSONL rotation is skipped on repeated appends and triggered by a new run file', function () use ($suite): void {
        $dir = directory();
        try {
            $logger = new FileLogger($dir, 'info', 2);
            $context = ['thread_id' => 'thread', 'run_id' => 'one', 'run_created_at' => 1792828800.125];
            $logger->info('first', $context);
            $legacy = $dir . '/agent-2020-01-01.jsonl';
            file_put_contents($legacy, "{}\n");
            file_put_contents($dir . '/agent-2020-01-02.jsonl', "{}\n");
            $logger->info('append without directory cleanup', $context);
            $suite->true(is_file($legacy));
            $logger->info('new run triggers cleanup', array_replace($context, ['run_id' => 'two']));
            $suite->true(!is_file($legacy));
            $suite->same(2, count(glob($dir . '/*.jsonl')));
        } finally { cleanup($dir); }
    });
    $suite->test('JSONL names contain microsecond time thread IP hash and a distinct run suffix', function () use ($suite): void {
        $dir = directory();
        try {
            $context = ['thread_id' => 'thread-001', 'client_ip_hash' => hash('sha256', '192.0.2.1'),
                'run_created_at' => 1792828800.125, 'run_id' => 'one'];
            $logger = new FileLogger($dir); $logger->info('first', $context);
            $path = glob($dir . '/*.jsonl')[0];
            $suite->true(strpos(basename($path), 'agent-' . gmdate('Ymd\THis', 1792828800) . '.125000Z-thread-001-' . $context['client_ip_hash'] . '-') === 0);
            $suite->true(strpos($path, '192.0.2.1') === false);
            (new FileLogger($dir))->info('recreated', $context);
            $suite->same(1, count(glob($dir . '/*.jsonl'))); $suite->same(2, count(records($path, $suite)));
            $logger->info('another run at the same time', array_replace($context, ['run_id' => 'two']));
            $logger->info('different client', array_replace($context, ['client_ip_hash' => hash('sha256', '192.0.2.2')]));
            $suite->same(3, count(glob($dir . '/*.jsonl')));
        } finally { cleanup($dir); }
    });
    $suite->test('standalone logger instances do not collide and unsafe thread IDs stay inside the directory', function () use ($suite): void {
        $dir = directory();
        try {
            (new FileLogger($dir))->info('one'); (new FileLogger($dir))->info('two');
            $logger = new FileLogger($dir);
            foreach (['../../会话', str_repeat('a', 200)] as $thread) { $logger->info('safe path', ['thread_id' => $thread]); }
            $files = glob($dir . '/*.jsonl'); $suite->same(4, count($files));
            foreach ($files as $file) {
                $suite->same(realpath($dir), dirname(realpath($file)));
                $suite->true(strlen(basename($file)) <= 255);
                $suite->true(strpos(basename($file), '-unknown-') !== false);
            }
        } finally { cleanup($dir); }
    });
    $suite->test('rotation handles new and legacy names while preserving unrelated files and the current run', function () use ($suite): void {
        $dir = directory();
        try {
            file_put_contents($dir . '/agent-2020-01-01.jsonl', "{}\n");
            file_put_contents($dir . '/agent-custom.jsonl', "keep\n");
            $logger = new FileLogger($dir, 'info', 2);
            $context = ['thread_id' => 'thread', 'run_id' => 'one', 'run_created_at' => 1792828800.125];
            $logger->info('first', $context);
            $oldPath = glob($dir . '/agent-*Z-*.jsonl')[0];
            $logger->info('second', array_replace($context, ['run_id' => 'two', 'run_created_at' => 1792828801.125]));
            $suite->true(!file_exists($dir . '/agent-2020-01-01.jsonl'));
            $logger->info('old current', $context);
            $suite->same(2, count(records($oldPath, $suite)));
            $suite->same("keep\n", file_get_contents($dir . '/agent-custom.jsonl'));
            $suite->same(3, count(glob($dir . '/*.jsonl')));
            $single = new FileLogger($dir, 'info', 1); $single->info('keep older current', $context);
            $suite->true(is_file($oldPath)); $suite->same(1, count(glob($dir . '/agent-*Z-*.jsonl')));
        } finally { cleanup($dir); }
    });
    $suite->test('JSONL writes exactly one UTF-8 JSON object per log record without stdout', function () use ($suite): void {
        $dir = directory();
        try {
            $logger = new FileLogger($dir);
            ob_start();
            try {
                $logger->info("中文\nnext\r\nline\t\"quoted\"", ['nested' => ['message' => "a\nb"]]);
                $logger->warning('second');
            } finally { $output = ob_get_clean(); }
            $suite->same('', $output);
            $files = glob($dir . '/*.jsonl');
            $suite->same(1, count($files));
            $rows = records($files[0], $suite);
            $suite->same(2, count($rows));
            $suite->same("中文\nnext\r\nline\t\"quoted\"", $rows[0]->message);
        } finally { cleanup($dir); }
    });
    $suite->test('JSONL tolerates invalid UTF-8 and nonfinite diagnostic values', function () use ($suite): void {
        $dir = directory();
        try {
            $safe = new SafeLogger(new FileLogger($dir));
            $safe->error("bad\xFFtext", ['ratio' => INF, 'metric' => NAN, 'nested' => ["bad\xFFkey" => "bad\xFFvalue"]]);
            $suite->same(0, $safe->failures());
            $files = glob($dir . '/*.jsonl');
            $suite->same(1, count($files));
            $suite->same(1, count(records($files[0], $suite)));
        } finally { cleanup($dir); }
    });
    $suite->test('JSONL repairs an interrupted last record before append', function () use ($suite): void {
        $dir = directory();
        try {
            $logger = new FileLogger($dir);
            $logger->info('before', ['stage' => 1]);
            $path = glob($dir . '/*.jsonl')[0];
            file_put_contents($path, '{"time":"unfinished', FILE_APPEND);
            $logger->info('after', ['stage' => 2]);
            $rows = records($path, $suite);
            $suite->same(['before', 'after'], array_map(function ($row): string { return $row->message; }, $rows));
        } finally { cleanup($dir); }
    });
    if (function_exists('pcntl_fork')) {
        $suite->test('concurrent JSONL writers preserve every complete record', function () use ($suite): void {
            $dir = directory(); $pids = [];
            try {
                for ($worker = 0; $worker < 3; $worker++) {
                    $pid = pcntl_fork();
                    if ($pid === -1) { throw new \RuntimeException('fork failed'); }
                    if ($pid === 0) {
                        try {
                            $logger = new FileLogger($dir);
                            for ($i = 0; $i < 20; $i++) {
                                $logger->info(str_repeat('中文', 1500), ['id' => $worker . ':' . $i,
                                    'thread_id' => 'shared', 'run_id' => 'same-run', 'run_created_at' => 1792828800.125]);
                            }
                            exit(0);
                        } catch (\Throwable $error) { exit(1); }
                    }
                    $pids[] = $pid;
                }
                foreach ($pids as $pid) { pcntl_waitpid($pid, $status); $suite->same(0, pcntl_wexitstatus($status)); }
                $files = glob($dir . '/*.jsonl'); $suite->same(1, count($files));
                $rows = records($files[0], $suite);
                $suite->same(60, count($rows));
                $suite->same(60, count(array_unique(array_map(function ($row): string { return $row->context->id; }, $rows))));
            } finally { cleanup($dir); }
        });
    }
};
