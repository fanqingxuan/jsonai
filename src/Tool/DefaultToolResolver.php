<?php
declare(strict_types=1);

namespace JsonAI\Tool;

final class DefaultToolResolver implements ToolResolverInterface
{
    private $factory;

    /** A factory may delegate to any host container; this package never discovers one globally. */
    public function __construct(?callable $factory = null) { $this->factory = $factory; }

    public function resolve(string $toolClass): ToolInterface
    {
        if (!class_exists($toolClass) || !is_subclass_of($toolClass, ToolInterface::class)) {
            throw new \InvalidArgumentException('Registered tool must be an existing ToolInterface class: ' . $toolClass);
        }
        if ($this->factory) {
            $tool = call_user_func($this->factory, $toolClass);
        } else {
            $reflection = new \ReflectionClass($toolClass);
            $constructor = $reflection->getConstructor();
            if (!$reflection->isInstantiable() || ($constructor && $constructor->getNumberOfRequiredParameters() > 0)) {
                throw new \InvalidArgumentException('Tool requires an explicit dependency factory: ' . $toolClass);
            }
            $tool = new $toolClass();
        }
        if (!$tool instanceof ToolInterface || !$tool instanceof $toolClass) {
            throw new \InvalidArgumentException('Tool factory returned an incompatible instance for ' . $toolClass);
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $tool->name()) !== 1 || trim($tool->description()) === '') {
            throw new \InvalidArgumentException('Tool requires a nonempty description and a 1-64 character portable name.');
        }
        if ($tool->inputSchema()->type() !== 'object' || is_array($tool->inputSchema()->toArray()['type'])) {
            throw new \InvalidArgumentException('Tool input schema must have non-nullable object type.');
        }
        $tool->outputSchema();
        return $tool;
    }
}
