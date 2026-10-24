<?php
declare(strict_types=1);

namespace JsonAI\Tool;

interface ToolPolicyInterface
{
    /** Throw to deny. Identity and resource authorization come from the server-side context. */
    public function authorize(ToolInterface $tool, array $input, ToolContext $context): void;
}
