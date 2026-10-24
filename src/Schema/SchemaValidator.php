<?php
declare(strict_types=1);

namespace JsonAI\Schema;

final class SchemaValidator
{
    public function validate($value, Schema $schema): void
    {
        $errors = [];
        $this->inspect($value, $schema->toArray(), '$', $errors, 0);
        if ($errors) {
            throw new ValidationException($errors);
        }
    }

    private function inspect($value, array $schema, string $path, array &$errors, int $depth): void
    {
        if ($depth > 64 || count($errors) >= 100) {
            if ($depth > 64) { $this->error($errors, $path, 'Value nesting exceeds 64 levels.'); }
            return;
        }
        $nullable = is_array($schema['type']);
        $type = $nullable ? $schema['type'][0] : $schema['type'];
        $valid = $value === null && $nullable;
        if (!$valid) {
            switch ($type) {
                case 'object': $valid = $value instanceof \stdClass; break;
                case 'array': $valid = is_array($value) && (!$value || array_keys($value) === range(0, count($value) - 1)); break;
                case 'string': $valid = is_string($value) && preg_match('//u', $value) === 1; break;
                case 'integer': $valid = is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value); break;
                case 'number': $valid = (is_int($value) || is_float($value)) && is_finite((float) $value); break;
                case 'boolean': $valid = is_bool($value); break;
            }
        }
        if (!$valid) {
            $this->error($errors, $path, 'Expected ' . $type . ($nullable ? ' or null' : '') . '.');
            return;
        }
        if (isset($schema['enum'])) {
            $matched = false;
            foreach ($schema['enum'] as $candidate) {
                if ($this->equal($value, $candidate)) { $matched = true; break; }
            }
            if (!$matched) { $this->error($errors, $path, 'Value is not in the allowed enum.'); }
        }
        if ($value === null) { return; }
        if ($type === 'object') {
            $properties = isset($schema['properties']) ? $schema['properties'] : [];
            foreach (isset($schema['required']) ? $schema['required'] : [] as $required) {
                if (!property_exists($value, $required)) {
                    $this->error($errors, $this->propertyPath($path, $required), 'Required property is missing.');
                }
            }
            foreach (get_object_vars($value) as $name => $item) {
                $itemPath = $this->propertyPath($path, (string) $name);
                if (array_key_exists($name, $properties)) {
                    $this->inspect($item, $properties[$name], $itemPath, $errors, $depth + 1);
                } elseif (isset($schema['additionalProperties']) && !$schema['additionalProperties']) {
                    $this->error($errors, $itemPath, 'Additional property is not allowed.');
                }
            }
        } elseif ($type === 'array') {
            $this->bounds(count($value), $schema, 'minItems', 'maxItems', $path, $errors);
            foreach ($value as $index => $item) {
                $this->inspect($item, $schema['items'], $path . '[' . $index . ']', $errors, $depth + 1);
            }
        } elseif ($type === 'string') {
            preg_match_all('/./us', $value, $matches);
            $this->bounds(count($matches[0]), $schema, 'minLength', 'maxLength', $path, $errors);
        } elseif ($type === 'number' || $type === 'integer') {
            $this->bounds($value, $schema, 'minimum', 'maximum', $path, $errors);
        }
    }

    private function bounds($value, array $schema, string $min, string $max, string $path, array &$errors): void
    {
        if (isset($schema[$min]) && $value < $schema[$min]) { $this->error($errors, $path, $min . ' is ' . $schema[$min] . '.'); }
        if (isset($schema[$max]) && $value > $schema[$max]) { $this->error($errors, $path, $max . ' is ' . $schema[$max] . '.'); }
    }

    private function propertyPath(string $path, string $name): string
    {
        return $path . '[' . json_encode($name, JSON_UNESCAPED_UNICODE) . ']';
    }

    private function error(array &$errors, string $path, string $message): void
    {
        if (count($errors) < 100) { $errors[] = ['path' => $path, 'message' => $message]; }
    }

    private function equal($left, $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) { return $left == $right; }
        if ($left instanceof \stdClass && $right instanceof \stdClass) {
            $left = get_object_vars($left);
            $right = get_object_vars($right);
            if (count($left) !== count($right)) { return false; }
            foreach ($left as $key => $value) {
                if (!array_key_exists($key, $right) || !$this->equal($value, $right[$key])) { return false; }
            }
            return true;
        }
        if (is_array($left) && is_array($right)) {
            if (array_keys($left) !== array_keys($right)) { return false; }
            foreach ($left as $key => $value) { if (!$this->equal($value, $right[$key])) { return false; } }
            return true;
        }
        return $left === $right;
    }
}
