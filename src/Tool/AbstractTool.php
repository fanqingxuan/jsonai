<?php
declare(strict_types=1);

namespace JsonAI\Tool;

abstract class AbstractTool implements ToolInterface
{
    protected $name = '';
    protected $description = '';

    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
}
