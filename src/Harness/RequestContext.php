<?php
declare(strict_types=1);
namespace JsonAI\Harness;

use JsonAI\Storage\StateCodec;

/** Application-supplied context. Fluent methods return a new instance. */
final class RequestContext
{
    private $threadId;
    private $scope = 'default';
    private $identity = [];
    private $requestId;
    private $clientIpHash;
    private $variables = [];
    private $options = [];

    private function __construct(string $threadId)
    {
        self::validateId($threadId); $this->threadId = $threadId;
    }
    public static function create(string $threadId): self { return new self($threadId); }
    public function withScope(string $scope): self
    {
        self::validateId($scope); $copy = clone $this; $copy->scope = $scope; return $copy;
    }
    public function withIdentity(array $identity): self
    {
        $copy = clone $this; $copy->identity = StateCodec::decode(StateCodec::encode($identity)); return $copy;
    }
    public function withRequestId(?string $requestId): self
    {
        if ($requestId !== null) { self::validateId($requestId); }
        $copy = clone $this; $copy->requestId = $requestId; return $copy;
    }
    public function withClientIp(?string $clientIp): self
    {
        $copy = clone $this; $copy->clientIpHash = null;
        if ($clientIp !== null) {
            $packed = @inet_pton($clientIp);
            if ($packed === false) { throw new \InvalidArgumentException('Invalid client IP address'); }
            // Store only the canonical address hash; never read host HTTP globals.
            $copy->clientIpHash = hash('sha256', inet_ntop($packed));
        }
        return $copy;
    }
    public function withVariables(array $variables): self
    {
        $copy = clone $this;
        $copy->variables = StateCodec::decode(StateCodec::encode($variables));
        return $copy;
    }
    public function withOptions(array $options): self
    {
        // Values are checked together with Agent defaults by the Harness.
        $copy = clone $this;
        $copy->options = StateCodec::decode(StateCodec::encode($options));
        return $copy;
    }
    public function getThreadId(): string { return $this->threadId; }
    public function getScope(): string { return $this->scope; }
    public function getIdentity(): array { return StateCodec::decode(StateCodec::encode($this->identity)); }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getClientIpHash(): ?string { return $this->clientIpHash; }
    public function getVariables(): array { return StateCodec::decode(StateCodec::encode($this->variables)); }
    public function getOptions(): array { return StateCodec::decode(StateCodec::encode($this->options)); }
    public function toArray(): array
    {
        return ['thread_id' => $this->threadId, 'scope' => $this->scope, 'identity' => $this->getIdentity(),
            'request_id' => $this->requestId, 'client_ip_hash' => $this->clientIpHash,
            'variables' => $this->getVariables(), 'options' => $this->getOptions()];
    }
    public static function fromArray(array $data): self
    {
        $context = self::create($data['thread_id'])->withScope($data['scope'])->withIdentity($data['identity'])->withRequestId($data['request_id'] ?? null);
        $hash = $data['client_ip_hash'] ?? null;
        if ($hash !== null && (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1)) { throw new \InvalidArgumentException('Invalid client IP hash'); }
        $context->clientIpHash = $hash;
        return $context->withVariables($data['variables'] ?? [])->withOptions($data['options'] ?? []);
    }
    private static function validateId(string $id): void
    {
        if ($id === '' || strlen($id) > 200) { throw new \InvalidArgumentException('Context identifiers must be 1..200 bytes'); }
    }
}
