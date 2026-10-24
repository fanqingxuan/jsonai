<?php
namespace JsonAI\Log;

use Psr\Log\AbstractLogger;

/** JSON-lines logger. File location is explicit; nothing is written to HTTP output. */
final class FileLogger extends AbstractLogger
{
    private $directory;
    private $minimum;
    private $retention;
    private $createdAt;
    private $instanceId;
    private $nextRotation = 0;
    private $levels = ['debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300, 'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600];
    public function __construct(string $directory, string $minimum = 'info', int $retention = 14)
    {
        if (!isset($this->levels[$minimum]) || $retention < 1 || $directory === '') { throw new \InvalidArgumentException('Invalid logger configuration'); }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create log directory'); }
        if (!is_writable($directory)) { throw new \RuntimeException('Log directory is not writable'); }
        $this->directory = realpath($directory); $this->minimum = $this->levels[$minimum]; $this->retention = $retention;
        $this->createdAt = microtime(true); $this->instanceId = bin2hex(random_bytes(16));
    }
    public function log($level, $message, array $context = [])
    {
        if (!is_string($level) || !isset($this->levels[$level])) { throw new \Psr\Log\InvalidArgumentException('Unknown log level'); }
        if ($this->levels[$level] < $this->minimum) { return; }
        $now = time();
        // Logging diagnostics must remain encodable even for invalid external bytes or INF/NAN.
        $json = json_encode(['time' => gmdate('c', $now), 'level' => $level, 'message' => (string) $message,
            'context' => (object) $this->normalize($context, 0)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) { throw new \RuntimeException('Cannot encode log record'); }
        $line = $json . "\n";
        $path = $this->path($context);
        // Appends share the directory lock; rotation exclusively guards file removal.
        $guard = @fopen($this->directory . '/.agent-logger.lock', 'c+b');
        if ($guard === false) { throw new \RuntimeException('Cannot open log directory lock'); }
        try {
            if (!flock($guard, LOCK_SH)) { throw new \RuntimeException('Cannot lock log directory'); }
            clearstatcache(true, $path);
            $rotate = !is_file($path) || $now >= $this->nextRotation;
            if ($rotate) {
                // Release before upgrading so concurrent writers cannot deadlock on an upgrade.
                flock($guard, LOCK_UN);
                if (!flock($guard, LOCK_EX)) { throw new \RuntimeException('Cannot lock log directory'); }
            }
            $this->append($path, $line);
            if ($rotate) {
                $this->rotate($path);
                $this->nextRotation = $now + 60;
            }
        } finally { fclose($guard); }
    }
    private function append(string $path, string $line): void
    {
        $file = @fopen($path, 'c+b');
        if ($file === false) { throw new \RuntimeException('Cannot open log file'); }
        try {
            if (!flock($file, LOCK_EX)) { throw new \RuntimeException('Cannot lock log file'); }
            $start = $this->repairTail($file);
            try {
                $offset = 0;
                while ($offset < strlen($line)) {
                    $bytes = @fwrite($file, substr($line, $offset));
                    if ($bytes === false || $bytes === 0) { throw new \RuntimeException('Cannot write log file'); }
                    $offset += $bytes;
                }
                if (!@fflush($file)) { throw new \RuntimeException('Cannot flush log file'); }
            } catch (\Throwable $error) {
                // Keep the preceding JSONL records usable after a partial write, e.g. a full disk.
                @ftruncate($file, $start); @fflush($file);
                throw $error;
            }
            flock($file, LOCK_UN);
        } finally { fclose($file); }
    }
    private function path(array $context): string
    {
        $createdAt = $context['run_created_at'] ?? $this->createdAt;
        if ((!is_int($createdAt) && !is_float($createdAt)) || !is_finite((float) $createdAt) || $createdAt < 0 || $createdAt > 253402300799) {
            throw new \InvalidArgumentException('Invalid run log timestamp');
        }
        $time = explode('.', sprintf('%.6F', $createdAt));
        $timestamp = gmdate('Ymd\THis', (int) $time[0]) . '.' . $time[1] . 'Z';
        $thread = $context['thread_id'] ?? 'unscoped';
        $run = $context['run_id'] ?? $this->instanceId;
        if (!is_string($thread) || !is_string($run) || $thread === '' || $run === '') { throw new \InvalidArgumentException('Invalid log identifiers'); }
        // Keep ordinary IDs readable; hash unsafe or long IDs without allowing path traversal.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,47}$/D', $thread) !== 1) { $thread = 'thread_' . hash('sha256', $thread); }
        $ipHash = $context['client_ip_hash'] ?? 'unknown';
        if (!is_string($ipHash) || ($ipHash !== 'unknown' && preg_match('/^[a-f0-9]{64}$/D', $ipHash) !== 1)) {
            throw new \InvalidArgumentException('Invalid client IP hash');
        }
        // Persisted time and run ID make this stable across workers, midnight and resume, without a per-run cache.
        return $this->directory . '/agent-' . $timestamp . '-' . $thread . '-' . $ipHash . '-' . substr(hash('sha256', $run), 0, 32) . '.jsonl';
    }
    private function rotate(string $current): void
    {
        $files = glob($this->directory . '/agent-*.jsonl');
        if ($files === false) { return; }
        $files = array_values(array_filter($files, function (string $file): bool {
            return preg_match('/^agent-\d{8}T\d{6}\.\d{6}Z-[A-Za-z0-9_-]+-(?:[a-f0-9]{64}|unknown)-[a-f0-9]{32}\.jsonl$/D', basename($file)) === 1
                || preg_match('/^agent-\d{4}-\d{2}-\d{2}\.jsonl$/D', basename($file)) === 1;
        }));
        rsort($files, SORT_STRING);
        // Never unlink the file being appended, including an older run being resumed.
        $files = array_values(array_filter($files, function (string $file) use ($current): bool { return $file !== $current; }));
        foreach (array_slice($files, $this->retention - 1) as $old) {
            if (is_link($old)) { continue; }
            $file = @fopen($old, 'r+b');
            if ($file === false) { continue; }
            try { if (flock($file, LOCK_EX | LOCK_NB)) { @unlink($old); flock($file, LOCK_UN); } }
            finally { fclose($file); }
        }
    }
    private function normalize($value, int $depth)
    {
        if ($depth > 4) { return '[truncated]'; }
        if (is_float($value) && !is_finite($value)) { return '[non-finite]'; }
        if ($value instanceof \Throwable) { return ['class' => get_class($value), 'code' => $value->getCode()]; }
        if (is_object($value) || is_resource($value)) { return '[' . (is_object($value) ? get_class($value) : 'resource') . ']'; }
        if (is_array($value)) { foreach ($value as &$item) { $item = $this->normalize($item, $depth + 1); } }
        return $value;
    }
    /** Under the exclusive lock, discard only a crash-truncated, unterminated last record. */
    private function repairTail($file): int
    {
        if (fseek($file, 0, SEEK_END) !== 0 || ($size = ftell($file)) === false) { throw new \RuntimeException('Cannot seek log file'); }
        $position = $size; $end = 0;
        while ($position > 0) {
            $length = min(4096, $position); $position -= $length;
            if (fseek($file, $position) !== 0) { throw new \RuntimeException('Cannot seek log file'); }
            $chunk = fread($file, $length);
            if ($chunk === false || strlen($chunk) !== $length) { throw new \RuntimeException('Cannot read log tail'); }
            $newline = strrpos($chunk, "\n");
            if ($newline !== false) { $end = $position + $newline + 1; break; }
        }
        if ($end !== $size && !ftruncate($file, $end)) { throw new \RuntimeException('Cannot repair incomplete log tail'); }
        if (fseek($file, $end) !== 0) { throw new \RuntimeException('Cannot position log append'); }
        return $end;
    }
}
