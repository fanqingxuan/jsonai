<?php
namespace JsonAI\Harness;

/** Explicit opt-in extraction. Records: key,content,metadata(optional),ttl(optional). */
interface MemoryWritePolicyInterface
{
    public function extract(array $messages, RunContext $context): array;
}
