<?php
declare(strict_types=1);

namespace JsonAI\Tool;

/** Harness first restricts resolution to the Agent's explicit tool registry. */
final class AllowRegisteredToolsPolicy implements ToolPolicyInterface
{
    public function authorize(ToolInterface $tool, array $input, ToolContext $context): void {}
}
