<?php
declare(strict_types=1);

namespace JsonAI\Context;

interface SummarizerInterface
{
    /** Summarize only the newly covered complete interactions, alongside an existing summary. */
    public function summarize(array $messages, string $previous = ''): string;
}
