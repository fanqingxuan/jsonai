<?php
declare(strict_types=1);

namespace JsonAI\Prompt;

use JsonAI\Message\Message;
use JsonAI\Schema\Schema;
use JsonAI\Schema\SchemaValidator;
use JsonAI\Support\Json;

final class TemplatePrompt implements PromptInterface
{
    private $templates;
    private $variables;
    private $names;

    /** Templates are trusted ['role' => ..., 'content' => ...] entries; only content is interpolated. */
    public function __construct(array $templates, ?Schema $variables = null)
    {
        $names = [];
        if (!$templates || array_keys($templates) !== range(0, count($templates) - 1)) {
            throw new \InvalidArgumentException('Prompt templates must be a nonempty list.');
        }
        foreach ($templates as $template) {
            if (!is_array($template) || count($template) !== 2 || !isset($template['role'], $template['content']) || !is_string($template['role']) || !in_array($template['role'], ['system', 'developer', 'user', 'assistant'], true) || !is_string($template['content']) || preg_match('//u', $template['content']) !== 1) {
                throw new \InvalidArgumentException('Prompt entries require a fixed supported role and UTF-8 text content.');
            }
            preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $template['content'], $matches);
            foreach ($matches[1] as $name) { $names[$name] = true; }
            $remaining = preg_replace('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', '', $template['content']);
            if (strpos($remaining, '{{') !== false || strpos($remaining, '}}') !== false) {
                throw new \InvalidArgumentException('Malformed prompt placeholder; use {{variable_name}}.');
            }
        }
        if ($variables === null) {
            $properties = [];
            foreach ($names as $name => $unused) { $properties[$name] = Schema::string()->required(); }
            $variables = Schema::object($properties);
        }
        if ($variables->type() !== 'object' || is_array($variables->toArray()['type'])) {
            throw new \InvalidArgumentException('Prompt variable schema must be a non-nullable object.');
        }
        $definition = $variables->toArray();
        $properties = isset($definition['properties']) ? $definition['properties'] : [];
        foreach ($names as $name => $unused) {
            if (!array_key_exists($name, $properties)) {
                throw new \InvalidArgumentException('Prompt placeholder is not declared in variable schema: ' . $name);
            }
        }
        $this->templates = $templates;
        $this->variables = $variables;
        $this->names = array_keys($names);
    }

    public static function system(string $template, ?Schema $variables = null): self
    {
        return new self([['role' => 'system', 'content' => $template]], $variables);
    }

    public function render(array $variables = []): array
    {
        $normalized = $this->variables->normalize($variables);
        (new SchemaValidator())->validate($normalized, $this->variables);
        foreach ($this->names as $name) {
            if (!property_exists($normalized, $name)) {
                throw new \InvalidArgumentException('Prompt variable is missing: ' . $name);
            }
        }
        $messages = [];
        foreach ($this->templates as $template) {
            $content = preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', function (array $match) use ($normalized): string {
                $value = $normalized->{$match[1]};
                return is_string($value) ? $value : Json::encode($value);
            }, $template['content']);
            $messages[] = new Message($template['role'], $content);
        }
        return $messages;
    }

    public function snapshot(): array
    {
        return ['type' => 'template', 'version' => 1, 'templates' => $this->templates, 'variables' => $this->variables->toArray()];
    }
}
