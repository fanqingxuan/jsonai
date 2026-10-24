<?php
declare(strict_types=1);

namespace JsonAI\Tool;

interface ToolResolverInterface
{
    public function resolve(string $toolClass): ToolInterface;
}
