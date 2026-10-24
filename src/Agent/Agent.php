<?php
declare(strict_types=1);
namespace JsonAI\Agent;

use JsonAI\Provider\ProviderInterface;
use JsonAI\Prompt\PromptInterface;
use JsonAI\Schema\Schema;
use JsonAI\Harness\RunOptions;

final class Agent
{
    private $name;
    private $provider;
    private $model;
    private $tools = [];
    private $prompt;
    private $options = [];
    private $modelOptions = [];
    private $version = '1';
    private $outputSchema;

    private function __construct(string $name)
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $name)) { throw new \InvalidArgumentException('Invalid agent name'); }
        $this->name = $name;
    }
    public static function create(string $name): self { return new self($name); }
    public function provider(ProviderInterface $provider, string $model): self
    {
        if (trim($model) === '') { throw new \InvalidArgumentException('Model is required'); }
        $copy = clone $this; $copy->provider = $provider; $copy->model = $model; return $copy;
    }
    public function tools(array $classes): self
    {
        foreach ($classes as $class) { if (!is_string($class) || $class === '') { throw new \InvalidArgumentException('Register tools with ToolClass::class'); } }
        $copy = clone $this; $copy->tools = array_values($classes); return $copy;
    }
    public function prompt(PromptInterface $prompt): self { $copy = clone $this; $copy->prompt = $prompt; return $copy; }
    public function options(array $options): self { new RunOptions($options); $copy = clone $this; $copy->options = $options; return $copy; }
    public function modelOptions(array $options): self
    {
        // Protocol-specific options belong to the provider. Only harness-owned fields are reserved here.
        $reserved = ['model', 'messages', 'input', 'instructions', 'tools', 'stream', 'stream_options', 'n',
            'timeout', 'max_output_tokens', 'max_tokens', 'max_completion_tokens', 'response_schema', 'response_format',
            'previous_response_id', 'conversation', 'api_key', 'base_url', 'headers'];
        foreach ($options as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $reserved, true)) {
                throw new \InvalidArgumentException('Invalid or reserved agent model option: ' . $key);
            }
        }
        \JsonAI\Support\Json::encode($options);
        $copy = clone $this; $copy->modelOptions = $options; return $copy;
    }
    public function version(string $version): self
    {
        if ($version === '') { throw new \InvalidArgumentException('Version is required'); }
        $copy = clone $this; $copy->version = $version; return $copy;
    }
    public function outputSchema(Schema $schema): self { $copy = clone $this; $copy->outputSchema = $schema; return $copy; }
    public function getName(): string { return $this->name; }
    public function getProvider(): ProviderInterface
    {
        if (!$this->provider) { throw new \LogicException('Configure an agent provider and model'); }
        return $this->provider;
    }
    public function getModel(): string { $this->getProvider(); return $this->model; }
    public function getTools(): array { return $this->tools; }
    public function getPrompt(): ?PromptInterface { return $this->prompt; }
    public function getOptions(): array { return $this->options; }
    public function getModelOptions(): array { return $this->modelOptions; }
    public function getVersion(): string { return $this->version; }
    public function getOutputSchema(): ?Schema { return $this->outputSchema; }
}
