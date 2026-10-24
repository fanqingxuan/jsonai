<?php
declare(strict_types=1);

namespace JsonAI\Storage;

use JsonAI\Support\Json;

/**
 * Storage wire format v1. Container tags preserve PHP maps, lists and stdClass separately.
 * The format identifier remains stable across the package rename to JsonAI.
 * Only this codec owns the envelope: arbitrary user fields cannot masquerade as type tags.
 * No PHP object serialization or application class loading is involved.
 */
final class StateCodec
{
    public static function encode(array $document): string
    {
        return Json::encode(['format' => 'portable-agent/state', 'version' => 1, 'value' => self::pack($document, 0)]);
    }

    public static function decode(string $encoded): array
    {
        $envelope = Json::decodeArray($encoded);
        if (count($envelope) !== 3 || !isset($envelope['format'], $envelope['version'])
            || $envelope['format'] !== 'portable-agent/state' || $envelope['version'] !== 1 || !array_key_exists('value', $envelope)) {
            throw new StorageException('Unsupported or corrupt state encoding; expected format version 1.');
        }
        $document = self::unpack($envelope['value'], 0);
        if (!is_array($document)) {
            throw new StorageException('State document root must be a PHP array.');
        }
        return $document;
    }

    private static function pack($value, int $depth)
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('State nesting exceeds 32 containers or contains a cycle.');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $entries = [];
            $isObject = $value instanceof \stdClass;
            foreach ($value as $key => $item) {
                $entries[] = [$isObject ? (string) $key : $key, self::pack($item, $depth + 1)];
            }
            return ['kind' => $isObject ? 'object' : 'array', 'entries' => $entries];
        }
        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('State accepts JSON scalars, PHP arrays and stdClass only.');
        }
        return $value;
    }

    private static function unpack($value, int $depth)
    {
        if ($depth > 32) {
            throw new StorageException('Stored state nesting exceeds the supported depth.');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (count($value) !== 2 || !isset($value['kind'], $value['entries']) || !is_array($value['entries'])
            || !in_array($value['kind'], ['array', 'object'], true)) {
            throw new StorageException('Invalid state container encoding.');
        }
        $result = $value['kind'] === 'object' ? new \stdClass() : [];
        foreach ($value['entries'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== [0, 1] || (!is_int($entry[0]) && !is_string($entry[0]))) {
                throw new StorageException('Invalid state entry encoding.');
            }
            $key = $entry[0];
            if ($result instanceof \stdClass) {
                if (!is_string($key) || property_exists($result, $key)) {
                    throw new StorageException('Invalid or duplicate state object key.');
                }
                $result->{$key} = self::unpack($entry[1], $depth + 1);
            } else {
                if (array_key_exists($key, $result)) {
                    throw new StorageException('Duplicate state array key.');
                }
                $result[$key] = self::unpack($entry[1], $depth + 1);
            }
        }
        return $result;
    }
}
