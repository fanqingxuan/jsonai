<?php
declare(strict_types=1);

namespace JsonAI\Support;

final class Json
{
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \InvalidArgumentException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    public static function decode(string $json)
    {
        $value = json_decode($json, false, 128, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON decoding failed: ' . json_last_error_msg());
        }
        return $value;
    }

    public static function decodeArray(string $json): array
    {
        $value = json_decode($json, true, 128, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) {
            throw new \InvalidArgumentException('Expected a JSON document: ' . json_last_error_msg());
        }
        return $value;
    }

    public static function toArray($value): array
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Expected an object or array');
        }
        return (array) $value;
    }

    public static function hash($value): string
    {
        return hash('sha256', self::encode(self::sort($value)));
    }

    private static function sort($value)
    {
        if ($value instanceof \stdClass) {
            $data = (array) $value;
            ksort($data);
            foreach ($data as &$item) { $item = self::sort($item); }
            return (object) $data;
        }
        if (is_array($value)) {
            if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) { ksort($value); }
            foreach ($value as &$item) { $item = self::sort($item); }
        }
        return $value;
    }
}
