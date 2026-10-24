<?php
namespace JsonAI\Transport;

/** Incremental byte-oriented SSE parser. UTF-8 is decoded only after a full event. */
final class SseDecoder
{
    private $buffer = '';
    private $data = [];
    private $event = 'message';
    private $id = null;
    private $bytes = 0;
    private $limit;
    private $started = false;

    public function __construct(int $maxEventBytes = 4194304)
    {
        if ($maxEventBytes < 1) { throw new \InvalidArgumentException('SSE limit must be positive.'); }
        $this->limit = $maxEventBytes;
    }

    public function push(string $bytes, callable $onEvent): void
    {
        if ($bytes === '') { return; }
        $this->buffer .= $bytes;
        if (!$this->started) {
            if (strlen($this->buffer) < 3 && strncmp("\xEF\xBB\xBF", $this->buffer, strlen($this->buffer)) === 0) { return; }
            if (substr($this->buffer, 0, 3) === "\xEF\xBB\xBF") { $this->buffer = substr($this->buffer, 3); }
            $this->started = true;
        }
        while (preg_match('/[\r\n]/', $this->buffer, $match, PREG_OFFSET_CAPTURE)) {
            $position = $match[0][1];
            if ($this->buffer[$position] === "\r" && $position === strlen($this->buffer) - 1) { break; }
            $length = ($this->buffer[$position] === "\r" && isset($this->buffer[$position + 1]) && $this->buffer[$position + 1] === "\n") ? 2 : 1;
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + $length);
            $this->line($line, $onEvent);
        }
        if ($this->bytes + strlen($this->buffer) > $this->limit) { throw new TransportException('SSE event exceeds the configured byte limit.'); }
    }

    public function finish(callable $onEvent): void
    {
        if (substr($this->buffer, -1) === "\r") {
            $line = substr($this->buffer, 0, -1);
            $this->buffer = '';
            $this->line($line, $onEvent);
        }
        // An event without its terminating blank line is incomplete, never dispatched.
        if ($this->buffer !== '' || $this->data) { throw new TransportException('Truncated SSE event.'); }
    }

    private function line(string $line, callable $onEvent): void
    {
        $this->bytes += strlen($line) + 1;
        if ($this->bytes > $this->limit) { throw new TransportException('SSE event exceeds the configured byte limit.'); }
        if ($line === '') {
            if ($this->data) {
                $onEvent(['event' => $this->event, 'data' => implode("\n", $this->data), 'id' => $this->id]);
            }
            $this->data = [];
            $this->event = 'message';
            $this->bytes = 0;
            return;
        }
        if ($line[0] === ':') { return; }
        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (isset($value[0]) && $value[0] === ' ') { $value = substr($value, 1); }
        if ($field === 'data') { $this->data[] = $value; }
        elseif ($field === 'event') { $this->event = $value === '' ? 'message' : $value; }
        elseif ($field === 'id' && strpos($value, "\0") === false) { $this->id = $value; }
    }
}
