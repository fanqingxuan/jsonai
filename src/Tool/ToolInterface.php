<?php
declare(strict_types=1);

namespace JsonAI\Tool;

use JsonAI\Schema\Schema;

interface ToolInterface
{
    public function name(): string;
    public function description(): string;
    public function inputSchema(): Schema;
    public function outputSchema(): Schema;
    public function handle(array $input, ToolContext $context): array;
}
