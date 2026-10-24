<?php
namespace JsonAI\Provider\OpenAI;

use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\ProviderException;
use JsonAI\Provider\StreamingProviderInterface;
use JsonAI\Support\Json;
use JsonAI\Transport\CurlTransport;
use JsonAI\Transport\HttpResponse;
use JsonAI\Transport\TransportInterface;

abstract class AbstractHttpProvider implements StreamingProviderInterface
{
    protected $transport;
    private $apiKey;
    protected $baseUrl;

    public function __construct(string $apiKey, ?TransportInterface $transport = null, string $baseUrl = 'https://api.openai.com/v1')
    {
        if (preg_match('/[\r\n]/', $apiKey)) { throw new \InvalidArgumentException('Invalid API key.'); }
        $url = parse_url($baseUrl);
        if (!$url || !isset($url['scheme'], $url['host']) || !in_array($url['scheme'], ['http', 'https'], true) || isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) {
            throw new \InvalidArgumentException('Base URL must be an HTTP(S) API root without credentials or query parameters.');
        }
        $this->apiKey = $apiKey;
        $this->transport = $transport === null ? new CurlTransport() : $transport;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Safe checkpoint identity: endpoint and protocol capabilities, never credentials. */
    public function definitionFingerprint(): string
    {
        return Json::hash(['provider' => $this->name(), 'base_url' => $this->baseUrl, 'capabilities' => $this->capabilities()]);
    }

    protected function send(string $path, array $payload, ModelRequest $request, ?callable $onData = null): HttpResponse
    {
        $timeout = isset($request->options['timeout']) ? $request->options['timeout'] : 60.0;
        if (!is_numeric($timeout) || $timeout <= 0 || !is_finite((float) $timeout)) { throw new \InvalidArgumentException('Model timeout must be finite and positive.'); }
        $headers = ['Content-Type' => 'application/json', 'Accept' => $onData === null ? 'application/json' : 'text/event-stream'];
        if ($this->apiKey !== '') { $headers['Authorization'] = 'Bearer ' . $this->apiKey; }
        $response = $this->transport->request('POST', $this->baseUrl . $path, $headers, Json::encode($payload), (float) $timeout, $onData);
        if ($response->status < 200 || $response->status >= 300) {
            // Upstream error bodies may contain user prompts or credentials: do not expose them by default.
            throw new ProviderException('Model provider returned HTTP ' . $response->status . '.', $response->status === 429 || $response->status >= 500, $response->status);
        }
        return $response;
    }

    protected function options(ModelRequest $request, array $allowed): array
    {
        $options = $request->options;
        foreach ($options as $key => $value) {
            if (!in_array($key, array_merge($allowed, ['timeout', 'response_schema']), true)) { throw new \InvalidArgumentException('Unsupported option for ' . $this->name() . ': ' . $key); }
        }
        unset($options['timeout'], $options['response_schema']);
        return $options;
    }

    protected function object(string $json): array
    {
        try {
            $value = Json::decode($json);
            if (!$value instanceof \stdClass) { throw new \InvalidArgumentException('Expected JSON object.'); }
            return $this->plain($value);
        } catch (\InvalidArgumentException $error) {
            throw new ProviderException('Malformed JSON from model provider.', false, null, $error);
        }
    }

    /** Provider protocol fields are maps; application JSON values remain inside raw argument strings. */
    protected function plain($value)
    {
        if ($value instanceof \stdClass) { $value = (array) $value; }
        if (is_array($value)) {
            foreach ($value as $key => $item) { $value[$key] = $this->plain($item); }
        }
        return $value;
    }

    protected function schema(array $schema, bool $strict = false): array
    {
        if ($strict && (!isset($schema['type']) || ($schema['type'] !== 'object' && !(is_array($schema['type']) && in_array('object', $schema['type'], true))))) {
            throw new \InvalidArgumentException('Structured output requires an object root schema.');
        }
        return $this->schemaNode($schema, $strict);
    }
    private function schemaNode(array $schema, bool $strict): array
    {
        $type = isset($schema['type']) ? $schema['type'] : null;
        if ($type === 'object' || (is_array($type) && in_array('object', $type, true))) {
            $properties = isset($schema['properties']) ? (array) $schema['properties'] : [];
            if ($strict) {
                $required = isset($schema['required']) ? $schema['required'] : [];
                $missing = array_diff(array_keys($properties), $required);
                if ($missing || !isset($schema['additionalProperties']) || $schema['additionalProperties'] !== false) {
                    throw new \InvalidArgumentException('Strict output schema requires all properties and additionalProperties=false; nullable fields may represent absent values.');
                }
            }
            foreach ($properties as $key => $property) { $properties[$key] = $this->schemaNode((array) $property, $strict); }
            $schema['properties'] = $properties ? $properties : new \stdClass();
        }
        if (isset($schema['items'])) { $schema['items'] = $this->schemaNode((array) $schema['items'], $strict); }
        return $schema;
    }

    protected function usage(array $data): array
    {
        $input = isset($data['input_tokens']) ? $data['input_tokens'] : (isset($data['prompt_tokens']) ? $data['prompt_tokens'] : 0);
        $output = isset($data['output_tokens']) ? $data['output_tokens'] : (isset($data['completion_tokens']) ? $data['completion_tokens'] : 0);
        foreach ([$input, $output] as $count) {
            if (!is_int($count) || $count < 0) { throw new ProviderException('Model usage counters must be nonnegative integers.'); }
        }
        $total = isset($data['total_tokens']) ? $data['total_tokens'] : $input + $output;
        if (!is_int($total) || $total < 0) { throw new ProviderException('Model usage counters must be nonnegative integers.'); }
        if ($total < $input || $total < $output) { throw new ProviderException('Inconsistent model usage counters.'); }
        return ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total];
    }
}
