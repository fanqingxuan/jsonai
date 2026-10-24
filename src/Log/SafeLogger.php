<?php
namespace JsonAI\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class SafeLogger extends AbstractLogger
{
    private $logger;
    private $context;
    private $failures = 0;
    public function __construct(LoggerInterface $logger, array $context = []) { $this->logger = $logger; $this->context = $context; }
    public function log($level, $message, array $context = [])
    {
        try {
            $this->logger->log($level, self::clean((string) $message), $this->sanitize(array_replace($context, $this->context), 0));
        } catch (\Throwable $error) { $this->failures++; }
    }
    public function failures(): int { return $this->failures; }
    private function sanitize(array $context, int $depth): array
    {
        $result = [];
        foreach (array_slice($context, 0, 100, true) as $key => $value) {
            if (preg_match('/password|secret|token|authorization|api.?key|cookie|credential|prompt|arguments|content/i', (string) $key) && !preg_match('/^(input_tokens|output_tokens|total_tokens)$/D', (string) $key)) { $value = '[redacted]'; }
            elseif ($value instanceof \Throwable) { $value = ['class' => get_class($value), 'code' => $value->getCode()]; }
            elseif (is_array($value)) { $value = $depth >= 3 ? '[truncated]' : $this->sanitize($value, $depth + 1); }
            elseif (is_string($value)) { $value = self::clean($value); }
            elseif (is_object($value) || is_resource($value)) { $value = '[' . (is_object($value) ? get_class($value) : 'resource') . ']'; }
            $result[$key] = $value;
        }
        return $result;
    }
    private static function clean(string $value): string
    {
        $value = preg_replace('/Bearer\s+[^\s]+|\bsk-[A-Za-z0-9_-]+/i', '[redacted]', $value);
        if (preg_match('//u', $value) !== 1) { $value = json_decode(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE)); }
        if (strlen($value) > 2048) {
            $value = substr($value, 0, 2048);
            while ($value !== '' && preg_match('//u', $value) !== 1) { $value = substr($value, 0, -1); }
            $value .= '[truncated]';
        }
        return $value;
    }
}
