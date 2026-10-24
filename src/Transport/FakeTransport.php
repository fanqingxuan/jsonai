<?php
namespace JsonAI\Transport;

final class FakeTransport implements TransportInterface
{
    private $queue;
    private $requests = [];
    /** Entries: HttpResponse, Throwable, callable(request), or ['response'=>HttpResponse,'chunks'=>string[]]. */
    public function __construct(array $queue = []) { $this->queue = array_values($queue); }
    public function requests(): array { return $this->requests; }
    public function push($response): void { $this->queue[] = $response; }
    public function request(string $method, string $url, array $headers, string $body, float $timeout, ?callable $onData = null): HttpResponse
    {
        $request = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout, 'stream' => $onData !== null];
        $this->requests[] = $request;
        if (!$this->queue) { throw new TransportException('FakeTransport queue exhausted.'); }
        $entry = array_shift($this->queue);
        if (is_callable($entry)) { $entry = $entry($request); }
        if ($entry instanceof \Throwable) { throw $entry; }
        if ($entry instanceof HttpResponse) { return $entry; }
        if (!is_array($entry) || !isset($entry['response']) || !$entry['response'] instanceof HttpResponse) { throw new \InvalidArgumentException('Invalid FakeTransport fixture.'); }
        $response = $entry['response'];
        if ($onData !== null && $response->status >= 200 && $response->status < 300) {
            foreach ($entry['chunks'] as $chunk) { $onData($chunk); }
        }
        return $response;
    }
}
