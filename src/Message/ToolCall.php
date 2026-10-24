<?php
namespace JsonAI\Message;

final class ToolCall
{
    private $id;
    private $name;
    private $arguments;

    public function __construct(string $id, string $name, string $arguments)
    {
        if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) {
            throw new \InvalidArgumentException('A tool call requires an ID and a valid registered tool name.');
        }
        $this->id = $id;
        $this->name = $name;
        $this->arguments = $arguments;
    }

    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function arguments(): string { return $this->arguments; }
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['arguments']);
    }
}
