<?php
declare(strict_types=1);

namespace JsonAI\Tool;

interface ExecutionAwareToolInterface extends ToolInterface
{
    /** True only when the tool cannot make any external business-state changes. */
    public function isReadOnly(): bool;
}
