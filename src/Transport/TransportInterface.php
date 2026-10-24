<?php
namespace JsonAI\Transport;

interface TransportInterface
{
    /** onData receives raw bytes only after successful HTTP response headers. No retries. */
    public function request(string $method, string $url, array $headers, string $body, float $timeout, ?callable $onData = null): HttpResponse;
}
