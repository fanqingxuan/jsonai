<?php
declare(strict_types=1);

namespace JsonAI\Context;

interface TokenCounterInterface
{
    /** Count or conservatively estimate request tokens, including tool definitions and framing. */
    public function count(array $messages, array $tools): int;
}
