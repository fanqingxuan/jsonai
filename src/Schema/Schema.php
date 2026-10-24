<?php
declare(strict_types=1);

namespace JsonAI\Schema;

/** Immutable, deliberately finite JSON Schema subset. Unsupported keywords fail at definition time. */
final class Schema
{
    private $definition;
    private $requiredProperty = false;

    private function __construct(array $definition)
    {
        self::checkDefinition($definition, 0);
        $this->definition = $definition;
    }

    public static function object(array $properties = []): self
    {
        // PHP accepts extra arguments for user functions; reject the old form instead of silently weakening validation.
        if (func_num_args() > 1) { throw new \InvalidArgumentException('Mark required object properties with Schema::string()->required(), not a second object() argument.'); }
        $definition = ['type' => 'object', 'additionalProperties' => false];
        $required = [];
        foreach ($properties as $name => $schema) {
            if (!$schema instanceof self) {
                throw new \InvalidArgumentException('Every object property must be a Schema.');
            }
            $definition['properties'][(string) $name] = $schema->toArray();
            if ($schema->requiredProperty) { $required[] = (string) $name; }
        }
        if ($required) {
            $definition['required'] = array_values($required);
        }
        return new self($definition);
    }

    public static function string(): self { return new self(['type' => 'string']); }
    public static function integer(): self { return new self(['type' => 'integer']); }
    public static function number(): self { return new self(['type' => 'number']); }
    public static function boolean(): self { return new self(['type' => 'boolean']); }
    public static function arrayOf(self $items): self { return new self(['type' => 'array', 'items' => $items->toArray()]); }

    public static function fromArray(array $definition): self
    {
        return new self($definition);
    }

    /** Require this field when placed in an object; it does not imply a non-null or nonempty value. */
    public function required(bool $required = true): self
    {
        $copy = clone $this; $copy->requiredProperty = $required; return $copy;
    }

    public function nullable(): self
    {
        return $this->with('type', [$this->type(), 'null']);
    }

    public function description(string $description): self { return $this->with('description', $description); }
    public function enum(array $values): self { return $this->with('enum', array_values($values)); }
    public function additionalProperties(bool $allowed): self { return $this->with('additionalProperties', $allowed); }

    public function min($minimum): self
    {
        return $this->with($this->boundKeyword('min'), $minimum);
    }

    public function max($maximum): self
    {
        return $this->with($this->boundKeyword('max'), $maximum);
    }

    public function type(): string
    {
        $type = $this->definition['type'];
        return is_array($type) ? $type[0] : $type;
    }

    public function toArray(): array { return $this->definition; }

    /** Normalize PHP map arrays returned by tools to JSON objects, without coercing scalar types. */
    public function normalize($value)
    {
        return self::normalizeValue($value, $this->definition, 0);
    }

    private function with(string $key, $value): self
    {
        $definition = $this->definition;
        $definition[$key] = $value;
        $copy = new self($definition);
        $copy->requiredProperty = $this->requiredProperty;
        return $copy;
    }

    private function boundKeyword(string $bound): string
    {
        switch ($this->type()) {
            case 'string': return $bound . 'Length';
            case 'array': return $bound . 'Items';
            case 'integer':
            case 'number': return $bound === 'min' ? 'minimum' : 'maximum';
        }
        throw new \InvalidArgumentException('Bounds are supported only for numbers, strings and arrays.');
    }

    private static function checkDefinition(array $definition, int $depth): void
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('Schema nesting exceeds 64 levels.');
        }
        if (!isset($definition['type'])) {
            throw new \InvalidArgumentException('Every schema must declare a type.');
        }
        $type = $definition['type'];
        if (is_array($type)) {
            if (array_keys($type) !== [0, 1] || $type[1] !== 'null') {
                throw new \InvalidArgumentException('Only [baseType, "null"] unions are supported.');
            }
            $type = $type[0];
        }
        if (!is_string($type) || !in_array($type, ['object', 'array', 'string', 'integer', 'number', 'boolean'], true)) {
            throw new \InvalidArgumentException('Unsupported schema type.');
        }
        $allowed = ['type', 'description', 'enum'];
        $specific = [
            'object' => ['properties', 'required', 'additionalProperties'],
            'array' => ['items', 'minItems', 'maxItems'],
            'string' => ['minLength', 'maxLength'],
            'integer' => ['minimum', 'maximum'],
            'number' => ['minimum', 'maximum'],
            'boolean' => [],
        ];
        foreach ($definition as $keyword => $value) {
            if (!in_array($keyword, array_merge($allowed, $specific[$type]), true)) {
                throw new \InvalidArgumentException('Unsupported schema keyword for ' . $type . ': ' . $keyword);
            }
        }
        if (array_key_exists('description', $definition) && !is_string($definition['description'])) {
            throw new \InvalidArgumentException('Schema description must be a string.');
        }
        if (array_key_exists('enum', $definition)) {
            if (!is_array($definition['enum']) || !$definition['enum'] || array_keys($definition['enum']) !== range(0, count($definition['enum']) - 1)) {
                throw new \InvalidArgumentException('Enum must be a nonempty list.');
            }
            json_encode($definition['enum']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \InvalidArgumentException('Enum values must be JSON serializable.');
            }
        }
        if ($type === 'object') {
            $properties = array_key_exists('properties', $definition) ? $definition['properties'] : [];
            if (!is_array($properties)) {
                throw new \InvalidArgumentException('Object properties must be a schema map.');
            }
            foreach ($properties as $property) {
                if (!is_array($property)) {
                    throw new \InvalidArgumentException('Property definitions must be schema arrays.');
                }
                self::checkDefinition($property, $depth + 1);
            }
            if (array_key_exists('additionalProperties', $definition) && !is_bool($definition['additionalProperties'])) {
                throw new \InvalidArgumentException('Only boolean additionalProperties is supported.');
            }
            if (array_key_exists('required', $definition)) {
                if (!is_array($definition['required']) || ($definition['required'] && array_keys($definition['required']) !== range(0, count($definition['required']) - 1))) {
                    throw new \InvalidArgumentException('Required must be a list of property names.');
                }
                $seen = [];
                foreach ($definition['required'] as $name) {
                    if (!is_string($name) || !array_key_exists($name, $properties) || isset($seen[$name])) {
                        throw new \InvalidArgumentException('Required names must be unique declared properties.');
                    }
                    $seen[$name] = true;
                }
            }
        }
        if ($type === 'array') {
            if (!isset($definition['items']) || !is_array($definition['items'])) {
                throw new \InvalidArgumentException('Array schema must declare one items schema.');
            }
            self::checkDefinition($definition['items'], $depth + 1);
        }
        foreach ([['minLength', 'maxLength'], ['minItems', 'maxItems'], ['minimum', 'maximum']] as $pair) {
            foreach ($pair as $keyword) {
                if (!array_key_exists($keyword, $definition)) {
                    continue;
                }
                $bound = $definition[$keyword];
                if ($keyword === 'minimum' || $keyword === 'maximum') {
                    if ((!is_int($bound) && !is_float($bound)) || !is_finite((float) $bound)) {
                        throw new \InvalidArgumentException('Numeric bounds must be finite numbers.');
                    }
                } elseif (!is_int($bound) || $bound < 0) {
                    throw new \InvalidArgumentException('Length and count bounds must be nonnegative integers.');
                }
            }
            if (isset($definition[$pair[0]], $definition[$pair[1]]) && $definition[$pair[0]] > $definition[$pair[1]]) {
                throw new \InvalidArgumentException('Minimum cannot exceed maximum.');
            }
        }
    }

    private static function normalizeValue($value, array $schema, int $depth)
    {
        if ($depth > 64) {
            throw new \InvalidArgumentException('Value nesting exceeds 64 levels.');
        }
        $type = is_array($schema['type']) ? $schema['type'][0] : $schema['type'];
        if ($type === 'object' && (is_array($value) || $value instanceof \stdClass)) {
            $output = new \stdClass();
            $properties = isset($schema['properties']) ? $schema['properties'] : [];
            foreach ($value as $name => $item) {
                $output->{(string) $name} = isset($properties[$name]) ? self::normalizeValue($item, $properties[$name], $depth + 1) : $item;
            }
            return $output;
        }
        if ($type === 'array' && is_array($value)) {
            $output = [];
            foreach ($value as $key => $item) {
                $output[$key] = self::normalizeValue($item, $schema['items'], $depth + 1);
            }
            return $output;
        }
        return $value;
    }
}
