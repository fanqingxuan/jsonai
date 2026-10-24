<?php
namespace JsonAI\Harness;

use JsonAI\Tool\ToolInterface;
use JsonAI\Tool\ToolContext;
use JsonAI\Tool\ToolResult;

/** Query an existing business operation by idempotency key. Must NOT execute it again. */
interface ToolReconcilerInterface
{
    public function reconcile(ToolInterface $tool, string $arguments, ToolContext $context): ?ToolResult;
}
