<?php
namespace JsonAI\Message;

use JsonAI\Message\Content\ContentPartInterface;

class Message
{
    private $role;
    private $content;
    private $toolCalls;
    private $toolCallId;
    private $metadata;
    private $id;

    public function __construct(string $role, $content = '', array $toolCalls = [], ?string $toolCallId = null, array $metadata = [], ?string $id = null)
    {
        if (!in_array($role, ['system', 'developer', 'user', 'assistant', 'tool'], true)) {
            throw new \InvalidArgumentException('Unsupported message role.');
        }
        if (!is_string($content) && !is_array($content)) {
            throw new \InvalidArgumentException('Message content must be text or content parts.');
        }
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if ($part instanceof ContentPartInterface) { $part = $part->toArray(); }
                if ($part instanceof \stdClass) { $part = (array) $part; }
                if (!is_array($part) || !isset($part['type']) || !in_array($part['type'], ['text', 'image', 'file', 'audio'], true)) {
                    throw new \InvalidArgumentException('Unsupported message content part.');
                }
                $required = ['text' => ['text'], 'image' => ['url'], 'file' => ['file_id'], 'audio' => ['data', 'format']];
                foreach ($required[$part['type']] as $field) {
                    if (!isset($part[$field]) || !is_string($part[$field])) { throw new \InvalidArgumentException('Missing or invalid content part field: ' . $field); }
                }
                if ($part['type'] === 'image' && ($part['url'] === '' || (isset($part['detail']) && !in_array($part['detail'], ['auto', 'low', 'high'], true)))) { throw new \InvalidArgumentException('Invalid image part.'); }
                if ($part['type'] === 'file' && $part['file_id'] === '') { throw new \InvalidArgumentException('Invalid file part.'); }
                if ($part['type'] === 'audio' && (!in_array($part['format'], ['wav', 'mp3'], true) || $part['data'] === '' || base64_decode($part['data'], true) === false)) { throw new \InvalidArgumentException('Invalid audio part.'); }
                $parts[] = $part;
            }
            $content = $parts;
        }
        $ids = [];
        foreach ($toolCalls as $call) {
            if (!$call instanceof ToolCall || isset($ids[$call->id()])) {
                throw new \InvalidArgumentException('Tool calls require unique ToolCall objects.');
            }
            $ids[$call->id()] = true;
        }
        if ($toolCalls && $role !== 'assistant') { throw new \InvalidArgumentException('Only assistant messages may call tools.'); }
        if (($role === 'tool' && ($toolCallId === null || $toolCallId === '')) || ($role !== 'tool' && $toolCallId !== null)) {
            throw new \InvalidArgumentException('Only tool messages require a tool call ID.');
        }
        if ($id === '') { throw new \InvalidArgumentException('Message ID cannot be empty.'); }
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = array_values($toolCalls);
        $this->toolCallId = $toolCallId;
        $this->metadata = $metadata;
        $this->id = $id === null ? 'msg_' . bin2hex(random_bytes(16)) : $id;
    }
    public function role(): string { return $this->role; }
    public function content() { return $this->content; }
    public function text(): string
    {
        if (is_string($this->content)) { return $this->content; }
        $text = '';
        foreach ($this->content as $part) {
            if ($part['type'] === 'text') { $text .= $part['text']; }
        }
        return $text;
    }
    public function toolCalls(): array { return $this->toolCalls; }
    public function toolCallId(): ?string { return $this->toolCallId; }
    public function metadata(): array { return $this->metadata; }
    public function id(): string { return $this->id; }
    public function withMetadata(array $metadata): self
    {
        return new self($this->role, $this->content, $this->toolCalls, $this->toolCallId, array_replace($this->metadata, $metadata), $this->id);
    }
    public function toArray(): array
    {
        return ['id' => $this->id, 'role' => $this->role, 'content' => $this->content,
            'tool_calls' => array_map(function (ToolCall $call) { return $call->toArray(); }, $this->toolCalls),
            'tool_call_id' => $this->toolCallId, 'metadata' => $this->metadata];
    }
    public static function fromArray(array $data): self
    {
        $calls = [];
        foreach (isset($data['tool_calls']) ? $data['tool_calls'] : [] as $call) { $calls[] = ToolCall::fromArray((array) $call); }
        return new self($data['role'], isset($data['content']) ? $data['content'] : '', $calls,
            isset($data['tool_call_id']) ? $data['tool_call_id'] : null,
            isset($data['metadata']) ? (array) $data['metadata'] : [], isset($data['id']) ? $data['id'] : null);
    }
    public static function user(string $text): self { return new self('user', $text); }
    public static function system(string $text): self { return new self('system', $text); }
    public static function assistant(string $text, array $calls = []): self { return new self('assistant', $text, $calls); }
    public static function tool(string $callId, string $content): self { return new self('tool', $content, [], $callId); }
}
