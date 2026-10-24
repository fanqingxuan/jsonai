<?php
declare(strict_types=1);
namespace JsonAI\Tests\SchemaRequired;

use JsonAI\Message\Message;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\ModelRequest;
use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Schema\Schema;
use JsonAI\Schema\SchemaValidator;
use JsonAI\Schema\ValidationException;
use JsonAI\Support\Json;
use JsonAI\Transport\FakeTransport;
use JsonAI\Transport\HttpResponse;

return function ($suite): void {
    $suite->test('field required declarations produce standard parent required lists', function () use ($suite): void {
        $schema = Schema::object([
            'name' => Schema::string()->required(),
            'count' => Schema::integer()->required(),
            'price' => Schema::number()->required(),
            'enabled' => Schema::boolean()->required(),
            'tags' => Schema::arrayOf(Schema::string())->required(),
            'details' => Schema::object()->required(),
            'note' => Schema::string(),
        ]);
        $definition = $schema->toArray();
        $suite->same(['name', 'count', 'price', 'enabled', 'tags', 'details'], $definition['required']);
        foreach ($definition['properties'] as $property) { $suite->true(!array_key_exists('required', $property)); }
        $suite->same(['type' => 'string'], Schema::string()->required()->toArray());
        $validator = new SchemaValidator();
        // Required means presence; zero, false, empty text, arrays and objects are legitimate values.
        $valid = ['name' => '', 'count' => 0, 'price' => 0.0, 'enabled' => false, 'tags' => [], 'details' => new \stdClass()];
        $validator->validate((object) $valid, $schema);
        foreach ($definition['required'] as $name) {
            $missing = $valid; unset($missing[$name]);
            $suite->throws(ValidationException::class, function () use ($validator, $schema, $missing): void { $validator->validate((object) $missing, $schema); });
        }
    });
    $suite->test('required markers survive all fluent constraints regardless of call order', function () use ($suite): void {
        $first = Schema::string()->required()->nullable()->description('Name')->enum(['ok', null])->min(1)->max(10);
        $last = Schema::string()->nullable()->description('Name')->enum(['ok', null])->min(1)->max(10)->required();
        $suite->same(Schema::object(['name' => $first])->toArray(), Schema::object(['name' => $last])->toArray());
        $schema = Schema::object(['name' => $first]); $validator = new SchemaValidator();
        $validator->validate((object) ['name' => null], $schema);
        $validator->validate((object) ['name' => 'ok'], $schema);
        $suite->throws(ValidationException::class, function () use ($validator, $schema): void { $validator->validate(new \stdClass(), $schema); });
        $object = Schema::object(['name' => Schema::string()->required()])->required()->additionalProperties(true)->description('Nested');
        $suite->same(['object'], Schema::object(['object' => $object])->toArray()['required']);
        $suite->same(['name'], $object->toArray()['required']);
    });
    $suite->test('required markers can be removed without mutating reused schemas or their children', function () use ($suite): void {
        $base = Schema::string(); $required = $base->required(); $optional = $required->required(false);
        $suite->true(!isset(Schema::object(['name' => $base])->toArray()['required']));
        $suite->true(!isset(Schema::object(['name' => $optional])->toArray()['required']));
        $suite->same(['name'], Schema::object(['name' => $required])->toArray()['required']);
        $nested = Schema::object(['code' => Schema::string()->required()])->required();
        $schema = Schema::object(['optional' => $nested->required(false), 'required' => $nested]);
        $suite->same(['required'], $schema->toArray()['required']);
        $suite->same(['code'], $schema->toArray()['properties']['optional']['required']);
    });
    $suite->test('nested object and array item requirements apply only at their own level', function () use ($suite): void {
        $item = Schema::object(['sku' => Schema::string()->required(), 'note' => Schema::string()]);
        $schema = Schema::object([
            'optional_profile' => Schema::object(['name' => Schema::string()->required()]),
            'items' => Schema::arrayOf($item)->required()->min(1),
        ]);
        $definition = $schema->toArray();
        $suite->same(['items'], $definition['required']);
        $suite->same(['sku'], $definition['properties']['items']['items']['required']);
        $suite->true(!isset($definition['properties']['items']['required']));
        $validator = new SchemaValidator();
        $validator->validate(Json::decode('{"items":[{"sku":"tea"}]}'), $schema);
        foreach (['{}', '{"items":[]}', '{"items":[{}]}', '{"items":[{"sku":"tea"}],"optional_profile":{}}'] as $invalid) {
            $suite->throws(ValidationException::class, function () use ($invalid, $validator, $schema): void { $validator->validate(Json::decode($invalid), $schema); });
        }
    });
    $suite->test('standard raw schemas remain readable and round trip with required children intact', function () use ($suite): void {
        $raw = ['type' => 'object', 'additionalProperties' => false, 'properties' => ['name' => ['type' => ['string', 'null']]], 'required' => ['name']];
        $imported = Schema::fromArray($raw);
        $suite->same($raw, $imported->toArray());
        $wrapped = Schema::object(['profile' => $imported->required()]);
        $suite->same(['profile'], $wrapped->toArray()['required']);
        $suite->same(['name'], $wrapped->toArray()['properties']['profile']['required']);
        $suite->same($wrapped->toArray(), Schema::fromArray($wrapped->toArray())->toArray());
        $suite->throws(\InvalidArgumentException::class, function (): void { Schema::fromArray(['type' => 'string', 'required' => true]); });
    });
    $suite->test('removed object second argument fails explicitly rather than losing required validation', function () use ($suite): void {
        $error = $suite->throws(\InvalidArgumentException::class, function (): void { Schema::object(['name' => Schema::string()], ['name']); });
        $suite->true(strpos($error->getMessage(), '->required()') !== false);
        $suite->same(1, (new \ReflectionMethod(Schema::class, 'object'))->getNumberOfParameters());
    });
    $suite->test('automatic prompt variable schemas still require all unique placeholders', function () use ($suite): void {
        $prompt = TemplatePrompt::system('{{language}}: {{topic}} / {{topic}}');
        $suite->same(['language', 'topic'], $prompt->snapshot()['variables']['required']);
        $suite->same('中文: 库存 / 库存', $prompt->render(['language' => '中文', 'topic' => '库存'])[0]->text());
        $suite->throws(ValidationException::class, function () use ($prompt): void { $prompt->render(['topic' => '库存']); });
    });
    $suite->test('field required syntax serializes correctly in provider tools and strict structured output', function () use ($suite): void {
        $transport = new FakeTransport([new HttpResponse(200, [], Json::encode(['choices' => [['message' => ['role' => 'assistant', 'content' => '{"answer":"ok"}'], 'finish_reason' => 'stop']]]))]);
        $provider = new ChatCompletionsProvider('fixture', $transport);
        $tools = [['name' => 'lookup', 'description' => 'Lookup fixture', 'parameters' => Schema::object(['sku' => Schema::string()->required()])->toArray()]];
        $provider->complete(new ModelRequest('fixture', [Message::user('hello')], $tools,
            ['response_schema' => Schema::object(['answer' => Schema::string()->required()])->toArray()]));
        $body = Json::decode($transport->requests()[0]['body']);
        $suite->same(['sku'], $body->tools[0]->function->parameters->required);
        $suite->same(['answer'], $body->response_format->json_schema->schema->required);
        $suite->true(!isset($body->tools[0]->function->parameters->properties->sku->required));
        $suite->true(!isset($body->response_format->json_schema->schema->properties->answer->required));
    });
};
