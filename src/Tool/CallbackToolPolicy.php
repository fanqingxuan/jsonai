<?php
declare(strict_types=1);

namespace JsonAI\Tool;

final class CallbackToolPolicy implements ToolPolicyInterface
{
    private $authorize;

    /** Callback must return true to allow; false/null deny. It may also throw to deny. */
    public function __construct(callable $authorize) { $this->authorize = $authorize; }

    public function authorize(ToolInterface $tool, array $input, ToolContext $context): void
    {
        if (call_user_func($this->authorize, $tool, $input, $context) !== true) {
            throw new \RuntimeException('Tool execution is not authorized.');
        }
    }
}
