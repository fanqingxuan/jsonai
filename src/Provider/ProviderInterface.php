<?php
namespace JsonAI\Provider;

interface ProviderInterface
{
    public function name(): string;
    public function capabilities(): array;
    public function complete(ModelRequest $request): ModelResponse;
}
