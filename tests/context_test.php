<?php
declare(strict_types=1);

namespace JsonAI\Tests\Context;

use JsonAI\Context\ConservativeTokenCounter;
use JsonAI\Context\ContextBuilder;
use JsonAI\Context\ContextLimitException;
use JsonAI\Context\ExtractiveSummarizer;
use JsonAI\Context\SummarizerInterface;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;

final class RecordingSummarizer implements SummarizerInterface
{
    public $calls = [];
    public function summarize(array $messages, string $previous = ''): string
    {
        $ids = array_map(function (Message $message): string { return $message->id(); }, $messages);
        $this->calls[] = ['ids' => $ids, 'previous' => $previous];
        return ($previous === '' ? '' : $previous . ';') . implode(',', $ids);
    }
}

return function ($suite): void {
    $message = function (string $id, string $role, string $text = ''): Message { return new Message($role, $text, [], null, [], $id); };
    $calls = function (): Message { return new Message('assistant', '', [new ToolCall('call-a', 'a', '{}'), new ToolCall('call-b', 'b', '{}')], null, [], 'm-calls'); };
    $results = function (): array { return [new Message('tool', '{}', [], 'call-b', [], 'result-b'), new Message('tool', '{}', [], 'call-a', [], 'result-a')]; };
    $suite->test('context trims multi-tool calls and all results as one complete group', function () use ($suite, $message, $calls, $results): void {
        $history = array_merge([$message('m-old', 'user', 'old request'), $calls()], $results(), [$message('m-answer', 'assistant', 'answer'), $message('m-current', 'user', 'current request')]);
        $result = (new ContextBuilder())->build($history, [], [], 10000, 100, 2, [], 'm-current');
        $suite->same('result-a', $result->memory['covered_message_id']);
        $suite->same(3, count($result->messages));
        $suite->same('user', $result->messages[0]->role());
        $suite->same('summary', $result->messages[0]->metadata()['context_source']);
        $suite->same('m-answer', $result->messages[1]->id());
        $suite->same('m-current', $result->messages[2]->id());
        $suite->same(6, count($history));
        foreach ($result->messages as $item) { $suite->same([], $item->toolCalls()); }
    });
    $suite->test('context accepts reordered complete tool results and retains whole groups', function () use ($suite, $message, $calls, $results): void {
        $history = array_merge([$message('old', 'user', 'old'), $calls()], $results());
        $result = (new ContextBuilder())->build($history, [], [], 10000, 100, 3);
        $suite->same('old', $result->memory['covered_message_id']);
        $suite->same(4, count($result->messages));
        $suite->same('m-calls', $result->messages[1]->id());
        $suite->same('call-b', $result->messages[2]->toolCallId());
        $suite->same('call-a', $result->messages[3]->toolCallId());
    });
    $suite->test('context rejects orphan missing duplicate and interrupted tool results before requests', function () use ($suite, $message, $calls, $results): void {
        $toolResults = $results();
        $invalid = [[$toolResults[0]], [$calls()], [$calls(), $toolResults[0]], [$calls(), $message('interruption', 'user', 'new')], [$calls(), $toolResults[0], new Message('tool', '{}', [], 'call-b', [], 'duplicate-result')]];
        foreach ($invalid as $history) {
            $suite->throws(\InvalidArgumentException::class, function () use ($history): void { (new ContextBuilder())->build($history, [], [], 10000, 100, 2); });
        }
    });
    $suite->test('context applies summary coverage exactly once and only summarizes new prefixes', function () use ($suite, $message): void {
        $summarizer = new RecordingSummarizer();
        $builder = new ContextBuilder(null, $summarizer);
        $history = [$message('one', 'user', '1'), $message('two', 'assistant', '2'), $message('three', 'user', '3')];
        $first = $builder->build($history, [], [], 10000, 100, 1, [], 'three');
        $suite->same(['one', 'two'], $summarizer->calls[0]['ids']);
        $same = $builder->build($history, [], [], 10000, 100, 1, $first->memory, 'three');
        $suite->same(1, count($summarizer->calls));
        $suite->same($first->memory, $same->memory);
        $history[] = $message('four', 'assistant', '4');
        $history[] = $message('five', 'user', '5');
        $next = $builder->build($history, [], [], 10000, 100, 1, $same->memory, 'five');
        $suite->same(['three', 'four'], $summarizer->calls[1]['ids']);
        $suite->same('one,two', $summarizer->calls[1]['previous']);
        $suite->same('four', $next->memory['covered_message_id']);
    });
    $suite->test('context ignores stale or mid-tool summary markers', function () use ($suite, $message, $calls, $results): void {
        $history = array_merge([$message('old', 'user', 'old'), $calls()], $results());
        foreach (['missing', 'result-b'] as $marker) {
            $result = (new ContextBuilder())->build($history, [], [], 10000, 100, 20, ['summary' => 'stale', 'covered_message_id' => $marker]);
            $suite->same([], $result->memory);
            $suite->same(4, count($result->messages));
            $suite->same('old', $result->messages[0]->id());
        }
    });
    $suite->test('context preserves all current-run interactions despite recent count', function () use ($suite, $message, $calls, $results): void {
        $history = array_merge([$message('old', 'user', 'old'), $message('current', 'user', 'current'), $calls()], $results(), [$message('answer', 'assistant', 'final')]);
        $result = (new ContextBuilder())->build($history, [], [], 10000, 100, 1, [], 'current');
        $suite->same('old', $result->memory['covered_message_id']);
        $suite->same(6, count($result->messages));
        $suite->same('current', $result->messages[1]->id());
        $suite->same('answer', $result->messages[5]->id());
    });
    $suite->test('context refuses to discard protected input to make a request fit', function () use ($suite, $message): void {
        $suite->throws(ContextLimitException::class, function () use ($message): void {
            (new ContextBuilder())->build([$message('current', 'user', str_repeat('x', 4000))], [], [], 1000, 100, 1, [], 'current');
        });
        $suite->throws(\InvalidArgumentException::class, function () use ($message): void {
            (new ContextBuilder())->build([$message('current', 'user', 'text')], [], [], 1000, 100, 1, [], 'missing');
        });
    });
    $suite->test('context token accounting includes tools prefixes and output reserve', function () use ($suite, $message): void {
        $counter = new ConservativeTokenCounter();
        $history = [$message('current', 'user', 'x')];
        $prefix = [Message::system('Trusted application instructions.')];
        $tools = [['name' => 'tool', 'description' => str_repeat('d', 200), 'parameters' => ['type' => 'object']]];
        $expected = $counter->count(array_merge($prefix, $history), $tools);
        $result = (new ContextBuilder($counter))->build($history, $prefix, $tools, $expected + 100, 100, 10, [], 'current');
        $suite->same($expected, $result->estimatedTokens);
        $suite->throws(ContextLimitException::class, function () use ($history, $prefix, $tools, $expected): void {
            (new ContextBuilder())->build($history, $prefix, $tools, $expected + 99, 100, 10, [], 'current');
        });
    });
    $suite->test('extractive summaries are bounded valid UTF-8 and retain previous plus new reference data', function () use ($suite, $message): void {
        $summarizer = new ExtractiveSummarizer(128, 64);
        $summary = $summarizer->summarize([$message('new', 'user', str_repeat('中文', 200))], 'Previous user preference.');
        $suite->true(strlen($summary) <= 128);
        $suite->same(1, preg_match('//u', $summary));
        $suite->true(strpos($summary, 'Previous user preference.') !== false);
        $suite->true(strpos($summary, 'user:') !== false);
        $suite->same($summary, $summarizer->summarize([$message('new', 'user', str_repeat('中文', 200))], 'Previous user preference.'));
    });
};
