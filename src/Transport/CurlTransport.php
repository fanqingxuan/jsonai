<?php
namespace JsonAI\Transport;

final class CurlTransport implements TransportInterface
{
    private $connectTimeout;
    private $maxResponseBytes;
    public function __construct(float $connectTimeout = 10.0, int $maxResponseBytes = 16777216)
    {
        if ($connectTimeout <= 0 || $maxResponseBytes < 1) { throw new \InvalidArgumentException('Invalid HTTP transport limits.'); }
        $this->connectTimeout = $connectTimeout;
        $this->maxResponseBytes = $maxResponseBytes;
    }
    public function request(string $method, string $url, array $headers, string $body, float $timeout, ?callable $onData = null): HttpResponse
    {
        if (!extension_loaded('curl')) { throw new TransportException('The selected CurlTransport requires ext-curl.'); }
        if ($timeout <= 0 || !is_finite($timeout)) { throw new \InvalidArgumentException('HTTP timeout must be finite and positive.'); }
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('HTTP transport requires an http(s) URL without embedded credentials.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]+$/D', $name) || !is_string($value) || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('Invalid HTTP header.');
            }
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        if ($handle === false) { throw new TransportException('Failed to initialize cURL.'); }
        $response = new HttpResponse(0);
        $failure = null;
        $receivedBytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) (min($timeout, $this->connectTimeout) * 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)), CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use ($response) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
                    $response->status = (int) $match[1];
                    $response->headers = [];
                } elseif (strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $response->headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use ($response, $onData, &$failure, &$receivedBytes) {
                try {
                    $receivedBytes += strlen($chunk);
                    if ($receivedBytes > $this->maxResponseBytes) { throw new TransportException('HTTP response exceeds the configured byte limit.'); }
                    if ($onData !== null && $response->status >= 200 && $response->status < 300) { $onData($chunk); }
                    else { $response->body .= $chunk; }
                    return strlen($chunk);
                } catch (\Throwable $error) {
                    $failure = $error;
                    return 0;
                }
            }
        ]);
        try {
            $ok = curl_exec($handle);
            $number = curl_errno($handle);
            if ($failure !== null) { throw $failure; }
            if ($ok === false) {
                // Avoid leaking server URLs, request content or headers in error strings.
                throw new TransportException('HTTP transport failed (cURL error ' . $number . ').', in_array($number, [6, 7, 18, 28, 52, 55, 56], true));
            }
            return $response;
        } finally {
            // PHP 7 uses a resource; PHP 8 frees CurlHandle objects automatically.
            if (is_resource($handle)) { curl_close($handle); }
        }
    }
}
