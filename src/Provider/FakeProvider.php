<?php
namespace JsonAI\Provider;

use JsonAI\Message\Message;

final class FakeProvider implements StreamingProviderInterface
{
    private $queue;
    private $requests = [];
    public function __construct(array $queue = []) { $this->queue = array_values($queue); }
    public function name(): string { return 'fake'; }
    public function capabilities(): array { return ['tools' => true, 'streaming' => true, 'structured_output' => true, 'images' => true, 'files' => true, 'audio' => true]; }
    public function definitionFingerprint(): string { return \JsonAI\Support\Json::hash(['provider' => $this->name(), 'capabilities' => $this->capabilities()]); }
    public function requests(): array { return $this->requests; }
    public function push($response): void { $this->queue[] = $response; }
    public function complete(ModelRequest $request): ModelResponse { return $this->next($request); }
    public function stream(ModelRequest $request, callable $onEvent): ModelResponse
    {
        $response = $this->next($request);
        // Split by Unicode codepoint, not arbitrary bytes.
        $characters = preg_split('//u', $response->message->text(), -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) { throw new ProviderException('Fake response contains invalid UTF-8.'); }
        foreach ($characters as $character) { $onEvent(['type' => 'text.delta', 'delta' => $character]); }
        return $response;
    }
    private function next(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        if (!$this->queue) { throw new ProviderException('FakeProvider queue exhausted.'); }
        $entry = array_shift($this->queue);
        // Text such as "next" is a response, even when it names a PHP function.
        if (!is_string($entry) && is_callable($entry)) { $entry = $entry($request); }
        if ($entry instanceof \Throwable) { throw $entry; }
        if (is_string($entry)) { $entry = new ModelResponse(Message::assistant($entry)); }
        if (!$entry instanceof ModelResponse) { throw new \InvalidArgumentException('FakeProvider expects ModelResponse, string, Throwable or callable.'); }
        return $entry;
    }
}
