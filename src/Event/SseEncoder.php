<?php
namespace JsonAI\Event;

use JsonAI\Support\Json;

/** Returns bytes only. HTTP headers, flushing and disconnection policy belong to the caller. */
final class SseEncoder
{
    public function encode(array $event): string
    {
        if (!isset($event['run_id'], $event['sequence'], $event['type']) || !preg_match('/^[a-z0-9_.-]+$/D', $event['type']) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $event['run_id'])) {
            throw new \InvalidArgumentException('Invalid SSE event');
        }
        return 'id: ' . $event['run_id'] . ':' . (int) $event['sequence'] . "\n" . 'event: ' . $event['type'] . "\n" . 'data: ' . Json::encode($event) . "\n\n";
    }
    public function heartbeat(): string { return ": heartbeat\n\n"; }
}
