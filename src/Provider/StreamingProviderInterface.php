<?php
namespace JsonAI\Provider;

interface StreamingProviderInterface extends ProviderInterface
{
    /** Emit canonical text.delta events. Return only after a validated terminal response. */
    public function stream(ModelRequest $request, callable $onEvent): ModelResponse;
}
