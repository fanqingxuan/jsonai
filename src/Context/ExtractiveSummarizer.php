<?php
declare(strict_types=1);

namespace JsonAI\Context;

use JsonAI\Message\Message;

/** Deterministic bounded excerpts; no hidden model requests or inferred facts. */
final class ExtractiveSummarizer implements SummarizerInterface
{
    private $maxBytes;
    private $excerptBytes;

    public function __construct(int $maxBytes = 2048, int $excerptBytes = 384)
    {
        if ($maxBytes < 128 || $excerptBytes < 16) { throw new \InvalidArgumentException('Summary and excerpt limits must be at least 128 and 16 bytes respectively.'); }
        $this->maxBytes = $maxBytes;
        $this->excerptBytes = $excerptBytes;
    }

    public function summarize(array $messages, string $previous = ''): string
    {
        $lines = [];
        foreach ($messages as $message) {
            if (!$message instanceof Message) { throw new \InvalidArgumentException('Summarization expects Message objects.'); }
            if ($message->role() !== 'user' && $message->role() !== 'assistant') { continue; }
            $text = trim(preg_replace('/\s+/u', ' ', $message->text()));
            if ($text !== '') { $lines[] = $message->role() . ': ' . $this->clip($text, $this->excerptBytes); }
            if ($message->toolCalls()) {
                $names = [];
                foreach ($message->toolCalls() as $call) { $names[] = $call->name(); }
                $lines[] = 'assistant requested tools: ' . $this->clip(implode(', ', $names), $this->excerptBytes);
            }
        }
        if (!$lines) { $lines[] = '[Earlier complete interactions omitted; refer to persisted thread for exact data.]'; }
        $new = implode("\n", $lines);
        if ($previous === '') { return $this->clip($new, $this->maxBytes); }
        // Retain bounded prior context and new context instead of allowing old text to consume the whole budget.
        $oldBudget = min(strlen($previous), (int) floor($this->maxBytes / 2));
        $old = $this->clip($previous, $oldBudget);
        return $old . "\n" . $this->clip($new, $this->maxBytes - strlen($old) - 1);
    }

    private function clip(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) { return $text; }
        $marker = ' [excerpt]';
        $prefix = substr($text, 0, max(0, $bytes - strlen($marker)));
        while ($prefix !== '' && preg_match('//u', $prefix) !== 1) { $prefix = substr($prefix, 0, -1); }
        return $prefix . $marker;
    }
}
