<?php
declare(strict_types=1);

namespace JsonAI\Context;

use JsonAI\Message\Message;
use JsonAI\Support\Json;

/** UTF-8 byte-based estimate, not a model tokenizer. Replace for provider-specific accounting. */
final class ConservativeTokenCounter implements TokenCounterInterface
{
    public function count(array $messages, array $tools): int
    {
        $total = 32 + strlen(Json::encode($tools)) + 32 * count($tools);
        foreach ($messages as $message) {
            if (!$message instanceof Message) { throw new \InvalidArgumentException('Token counting expects Message objects.'); }
            $total += 16 + strlen(Json::encode($message->toArray()));
        }
        return $total;
    }
}
