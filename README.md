# JsonAI

一个可以独立迁移、通过 Composer 接入的 PHP 单 Agent 框架，最低运行版本为 **PHP 7.2.6**。

应用定义 Agent、Prompt 和 Tool，通过 Agent 的 run/stream 等方法调用；Harness 作为执行引擎，负责模型与工具循环、会话、上下文、流式事件、运行限制及检查点。工具通过 `ToolClass::class` 注册。框架不引用 SCM、Laravel、宿主容器、Session、业务数据库类或固定项目目录，也不会在安装时建表、启动 Worker 或修改宿主配置。

当前代码尚未发布到 Packagist。包名为 `fanqingxuan/jsonai`，命名空间为 `JsonAI`。不包含多 Agent 编排、Supervisor、Human-in-the-Loop 或独立设计模式模块。

从旧名称迁移时，将应用的 Composer 依赖 `portable-agent/agent` 替换为 `fanqingxuan/jsonai`，并将 PHP 中的 `PortableAgent\` 引用改为 `JsonAI\`，重新生成 Composer 自动加载文件。升级前先完成已有活跃 Run；命名空间及工具类文件变化会使旧 Run 的定义快照校验不通过。存储格式标识 `portable-agent/state`、Redis 默认前缀 `portable_agent:` 及示例默认数据目录保留，已有会话和记忆数据仍可读取；已终结 Run 的幂等重提仍需通过定义快照校验。

## 安装与运行要求

核心依赖 `php >=7.2.6`、`ext-json` 和 `psr/log:^1.1`。默认 HTTP 适配需要 `ext-curl`；Redis、MySQL、PostgreSQL 分别需要 `ext-redis`、`ext-pdo_mysql`、`ext-pdo_pgsql`，仅安装实际使用的驱动。文件存储和离线测试不需要数据库。

`psr/log` 选择 1.x 是为了兼容 PHP 7.2.6；没有强制引入 Guzzle 或完整应用框架。若宿主已限制为 `psr/log` 2.x/3.x，需要先统一依赖约束，Composer 不会同时安装多个主版本。

### 本地 path 安装

将整个包目录复制到独立位置，例如与应用目录平级的 `agent/`，在**应用**的 `composer.json` 中加入：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../agent",
      "options": {
        "symlink": false,
        "versions": {"fanqingxuan/jsonai": "0.1.x-dev"}
      }
    }
  ],
  "require": {
    "fanqingxuan/jsonai": "0.1.x-dev"
  }
}
```

随后执行 `composer update fanqingxuan/jsonai`。`symlink: false` 将包复制到 `vendor`，便于验证应用并不依赖原 SCM 目录。

### 自有 VCS 安装

先把包单独放入自己的 Git 仓库。下面地址是占位示例，假设该仓库存在 `main` 分支：

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://git.example.com/your-team/agent.git"}
  ],
  "require": {
    "fanqingxuan/jsonai": "dev-main as 0.1.x-dev"
  }
}
```

包自身开发与测试：

```bash
composer install
composer test
composer check
```

## 一个完整的离线 Agent

将以下内容保存到安装了本包的应用入口。`FakeProvider` 不发送网络请求，演示一次模型工具调用和最终回答。

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Log\FileLogger;
use JsonAI\Message\Message;
use JsonAI\Message\ToolCall;
use JsonAI\Prompt\TemplatePrompt;
use JsonAI\Provider\FakeProvider;
use JsonAI\Provider\ModelResponse;
use JsonAI\Schema\Schema;
use JsonAI\Storage\FileStore;
use JsonAI\Tool\AbstractTool;
use JsonAI\Tool\ExecutionAwareToolInterface;
use JsonAI\Tool\ToolContext;

final class InventoryTool extends AbstractTool implements ExecutionAwareToolInterface
{
    protected $name = 'inventory_lookup';
    protected $description = '查询指定 SKU 的示例库存。';

    public function inputSchema(): Schema
    {
        return Schema::object([
            'sku' => Schema::string()->description('待查询的商品 SKU，例如 tea')->required()->min(1),
        ]);
    }

    public function outputSchema(): Schema
    {
        return Schema::object([
            'sku' => Schema::string()->description('本次查询的商品 SKU')->required(),
            'quantity' => Schema::integer()->description('示例库存的可用数量')->required()->min(0),
        ]);
    }

    public function isReadOnly(): bool { return true; }

    public function handle(array $input, ToolContext $context): array
    {
        // 实际业务通过依赖注入访问应用服务；身份由服务端提供。
        $identity = $context->identity();
        $context->logger()->info('inventory.read', [
            'tenant_id' => $identity['tenant_id'],
        ]);
        return ['sku' => $input['sku'], 'quantity' => 12];
    }
}

$provider = new FakeProvider([
    new ModelResponse(
        Message::assistant('', [
            new ToolCall('call_inventory_1', 'inventory_lookup', '{"sku":"tea"}'),
        ]),
        [],
        'tool_calls'
    ),
    '示例库存：tea 为 12。',
]);

$agent = Agent::create('inventory')
    ->provider($provider, 'offline-demo')
    ->prompt(TemplatePrompt::system('使用工具查询库存，明确数据属于演示数据。'))
    ->tools([InventoryTool::class])
    ->version('1');

$harness = new AgentHarness(new FileStore(__DIR__ . '/var/agent/state'), [
    'logger' => new FileLogger(__DIR__ . '/var/agent/logs', 'info', 14),
]);
$agent = $agent->withHarness($harness);

$context = RequestContext::create('thread-001')
    ->withScope('tenant-001')
    ->withIdentity(['tenant_id' => 'tenant-001', 'user_id' => 'user-001'])
    ->withRequestId('request-001');
$result = $agent->run('查询 tea 的库存', $context);
echo $result->text . PHP_EOL;
```

Agent 的链式配置方法（包括 `withHarness()`）返回新实例，应像上面一样链式调用或接住返回值。应用启动或工厂中绑定一次 Harness，业务代码通过 Agent 调用。未绑定就调用运行接口会抛出 `LogicException`，不会隐式创建存储。显式使用 `new AgentHarness()` 时默认是进程内 `InMemoryStore` 和 `NullLogger`；需要跨请求恢复时，必须注入持久化 Store。

Agent 只保存可复用的定义和运行依赖，不保存本次 message、RequestContext 或 RunResult。克隆后的 Agent 保留原 Harness 引用；`withHarness($otherHarness)` 返回绑定另一引擎的新 Agent，不改变原对象。

`$agent->run($message, $context)` 直接接收用户输入；`$message` 可以是字符串或 user 角色的 `Message`。`$agent->stream($message, $context, $onEvent)` 与 `$agent->submit($message, $context)` 使用相同参数约定。`RequestContext` 集中管理会话、scope/identity、可选 requestId、客户端 IP 哈希，并通过 `withVariables()` / `withOptions()` 设置提示词变量和本次运行限制；这些链式方法均返回新实例。内部 `RunRequest` 仅用于请求快照，`RunContext` 管理租约、Run ID 和截止时间。

未显式设置 `requestId` 时，每次 run/stream/submit 调用都生成新 ID，基础上下文可以复用于连续对话。幂等重试需通过 `withRequestId()` 显式指定相同 ID，并保持输入和配置相同；复用不带 requestId 的 context 不代表重试。旧平铺检查点仍可读取，`resume()` 从检查点恢复输入。已有 `$harness->run($agent, $message, $context)` 等底层接口保持兼容；迁移时先绑定 Harness，再改用 Agent 入口即可。更早的 RunRequest 调用形式需把 message 和 context 拆开，原 Request 上的变量和限制移到 context。

## Agent、Provider、Prompt 和 Message

### 连接真实模型

内置 OpenAI Responses 与 Chat Completions 协议适配器：

```php
use JsonAI\Provider\OpenAI\ResponsesProvider;
use JsonAI\Provider\OpenAI\ChatCompletionsProvider;
use JsonAI\Transport\CurlTransport;

$provider = new ResponsesProvider(
    getenv('OPENAI_API_KEY'),
    new CurlTransport(10.0),
    'https://api.openai.com/v1'
);
// 可替换为 new ChatCompletionsProvider($apiKey, $transport, $baseUrl)。
$agent = $agent->provider($provider, getenv('OPENAI_MODEL'));
```

模型名、密钥和 API 根地址由应用显式提供。自定义 OpenAI 兼容地址需要真实支持所选协议，不能仅凭相同 URL 结构假设所有能力相同。框架检查工具、流式、结构化输出等能力；提供商不支持的选项会报错，不会静默丢弃。Responses 保留加密的推理续接数据，但不会把原始推理过程输出为用户事件。

模型生成参数通过 `modelOptions()` 配置，与 Harness 执行限制分开：

```php
$agent = $agent->modelOptions([
    'temperature' => 0.2,
    'top_p' => 1.0,
]);
```

Agent 仅检查公共约束和框架保留参数，Provider 负责具体选项白名单。例如 Chat Completions 支持 `stop`，自定义 Provider 可以自行支持 `top_k`；内置 Provider 仍在发送 HTTP 前拒绝不支持的选项。`model`、消息、工具、流开关、请求超时、输出预算、输出格式及 API 连接凭据由框架或 Provider 配置，不能通过 modelOptions 覆盖。

实现 `ProviderInterface` 可以接入其他模型；需要原生流式时同时实现 `StreamingProviderInterface`。HTTP 可通过 `TransportInterface` 替换，不要求宿主升级到某个 Guzzle 主版本。默认 Transport 验证 TLS、不跟随重定向，并分别设置连接与请求超时。

### DeepSeek 与其他 OpenAI 兼容服务

`OpenAICompatibleProvider` 使用 `/chat/completions` 协议。DeepSeek 提供预设：

```php
use JsonAI\Agent\Agent;
use JsonAI\Harness\AgentHarness;
use JsonAI\Harness\RequestContext;
use JsonAI\Provider\OpenAICompatibleProvider;

$apiKey = getenv('DEEPSEEK_API_KEY') ?: '';
$model = getenv('DEEPSEEK_MODEL') ?: ''; // 填写账户实际可用的模型名，框架不固定型号。
if ($apiKey === '' || $model === '') {
    throw new RuntimeException('Configure DEEPSEEK_API_KEY and DEEPSEEK_MODEL');
}
$provider = OpenAICompatibleProvider::deepSeek($apiKey);
$agent = Agent::create('assistant')
    ->provider($provider, $model)
    ->modelOptions(['thinking' => ['type' => 'enabled']]);
// 按需追加 ->tools([YourTool::class]) 或 ->outputSchema($schema)。
$context = RequestContext::create('thread-001')->withScope('tenant-001');
$agent = $agent->withHarness(new AgentHarness()); // 仅用于单进程示例；跨请求会话请注入持久化 Store。
$result = $agent->run('你好', $context);
echo $result->text;
```

预设 API 根地址为 `https://api.deepseek.com`。可通过 `OpenAICompatibleProvider::deepSeek($apiKey, $transport, $baseUrl)` 注入 Transport 或修改根地址；传入 API 根地址，末尾不要添加 `/chat/completions`。模型名按当前账户配置，官方接入说明见 [DeepSeek 首次调用](https://api-docs.deepseek.com/)。

DeepSeek 预设将框架的 `max_output_tokens` 转为 `max_tokens`，支持普通请求、工具循环、SSE 流、用量统计和 JSON 输出。根据 [DeepSeek 思考模式协议](https://api-docs.deepseek.com/guides/thinking_mode/)，含工具的后续请求需要保留 `reasoning_content`：框架将它放入消息 metadata/检查点并在后续请求中回传，包括没有调用工具的 assistant 回合；不将其混入最终回答、流式文本事件、默认摘要或运行日志。恢复执行仍保留这些续接字段。

`outputSchema()` 使用 DeepSeek 的 `json_object` 模式，自动加入 JSON Schema 格式指令，并由 Harness 校验最终结果；这不等于服务端严格 Schema 保证。规则不满足时返回 `invalid_output`。可通过 `modelOptions(['thinking' => ['type' => 'disabled']])` 关闭思考，或按模型能力传入 `reasoning_effort`。JSON 模式依据 [DeepSeek JSON Output 文档](https://api-docs.deepseek.com/guides/json_mode/)。

其他兼容服务使用显式根地址和可选能力配置：

```php
$provider = new OpenAICompatibleProvider(
    $apiKey,
    $baseUrl,
    null, // 默认 CurlTransport；也可注入 TransportInterface。
    [
        'max_tokens_parameter' => 'max_tokens',
        'response_format' => 'json_object',
        'stream_usage' => true,
        'preserve_reasoning' => false,
    ]
);
```

| 兼容配置 | 默认值与说明 |
| --- | --- |
| `max_tokens_parameter` | `max_tokens`；也可配置 `max_completion_tokens` |
| `response_format` | `json_object`；也可配置 `json_schema` 或 `none` |
| `stream_usage` | `true`；服务不支持 `stream_options.include_usage` 时设为 `false` |
| `preserve_reasoning` | 通用实例为 `false`，DeepSeek 预设为 `true`；保留推理续接字段 |
| `images` / `files` / `audio` | 默认均为 `false`；确认所选服务和模型支持相应 OpenAI 内容格式后显式开启 |

兼容配置参与恢复指纹；密钥轮换不改变指纹。通用兼容 Provider 将 `developer` 角色映射为 `system`，不发送 `n` 和工具 `strict` 标记。原有 `OpenAI\ChatCompletionsProvider` 与 `ResponsesProvider` 保留自身协议行为。

可运行示例（默认使用离线 HTTP/SSE 协议夹具，不调用在线模型）：

```bash
php examples/deepseek.php
php examples/deepseek.php --stream
# 在应用环境中配置 DEEPSEEK_API_KEY、DEEPSEEK_MODEL 后调用真实接口：
php examples/deepseek.php --live
php examples/deepseek.php --live --stream
```

### Prompt 变量

```php
$prompt = TemplatePrompt::system(
    '你是 {{domain}} 助手，请使用 {{language}} 回答。',
    Schema::object([
        'domain' => Schema::string()->required(),
        'language' => Schema::string()->required(),
    ])
);
$agent = $agent->prompt($prompt);

$context = RequestContext::create('thread-002')
    ->withScope('tenant-001')
    ->withIdentity(['tenant_id' => 'tenant-001', 'user_id' => 'user-001'])
    ->withVariables(['domain' => '供应链', 'language' => '中文'])
    ->withOptions(['max_iterations' => 4]);
$result = $agent->run('查询库存', $context);
```

多角色模板使用 `new TemplatePrompt([['role' => 'system', 'content' => '...'], ...], $schema)`。变量只替换内容，不改变角色，不执行 PHP，也不递归解释变量内容中的模板。模板和身份信息由可信应用配置，普通用户文本直接传给 run/stream/submit 的 message 参数。

### 消息与结构化输出

消息角色为 `system`、`developer`、`user`、`assistant`、`tool`。`ToolCall` 属于 Assistant 消息；Tool 消息用 `tool_call_id` 关联调用。流式事件不是额外的消息角色。

`Message` 支持文本以及 `TextPart`、`ImagePart`、`FilePart`、`AudioPart` 内容块。图片接收 URL/data URL，文件接收已上传的提供商 `file_id`，音频接收 base64；框架不会自行读取宿主文件或替应用上传附件。Responses 适配器不支持音频输入，Chat 协议的多模态支持还取决于实际模型。

```php
$agent = $agent->outputSchema(Schema::object([
    'answer' => Schema::string()->description('面向用户的最终回答')->required(),
    'reference' => Schema::string()->description('回答的参考来源，无来源时为 null')->nullable()->required(),
]));
```

最终文本仍在 `$result->text`；通过校验的结构化值在 `$result->output`，其中 JSON 对象保留为 `stdClass`。严格结构化输出要求所有属性列为 `required`，且 `additionalProperties=false`；可用 nullable 表达空值。

Schema 支持 object、array、string、integer、number、boolean、nullable、enum、description、required、additionalProperties 和数值/字符串长度/数组数量边界。`required` 与 `nullable` 含义独立，不自动补默认值或转换类型；`$ref`、`oneOf`、任意 JSON Schema 方言等未实现特性在定义时明确拒绝。工具入参根必须为非空类型的 object；无参数工具仍使用 `Schema::object()`，实际 JSON 入参为 `{}`。

参数说明在字段上声明，例如 `Schema::string()->description('待查询的商品 SKU')->required()`。工具输入、输出以及嵌套对象属性、数组元素均可使用 `description()`，建议说明字段含义、单位和取值约定。它会导出为对应节点的标准 JSON Schema `description`；工具输入和 Agent 最终输出 Schema 中的描述会随 Schema 传给 Provider。工具的 `$description` 描述整个工具的用途，字段的 `description()` 描述具体参数；工具输出 Schema 用于本地结果校验，不会自动作为工具调用定义发送给模型。

必填在字段上声明，例如 `Schema::string()->required()`、`Schema::arrayOf($item)->required()` 或 `Schema::object([...])->required()`；未声明时可省略该字段，`required(false)` 可移除标记。链式方法返回新实例，`required()` 放在其他约束之前或之后均有效。它只约束所属对象中的字段是否存在，不等于非空字符串或非空数组；这些限制分别使用 `min(1)`。

`Schema::object()` 只接收属性定义一个参数，旧的第二参数写法会明确报错。导出的 JSON Schema 仍在父对象生成标准 `required: [字段名]`，不会给字符串等子 Schema 写入非标准 `required: true`；`Schema::fromArray()` 继续接受标准 JSON Schema 的 required 数组。

## Tool 的依赖、权限与副作用

每个工具类自行定义名称、描述、输入 Schema、输出 Schema 和 `handle(array $input, ToolContext $context): array`。工具名称在 Agent 内唯一，模型只能调用注册的名称，不能指定任意 PHP 类。默认解析器创建无必填构造参数的工具；有依赖时显式注入工厂：

```php
use JsonAI\Tool\DefaultToolResolver;
use JsonAI\Tool\CallbackToolPolicy;
use JsonAI\Tool\ToolExecutor;

$resolver = new DefaultToolResolver(function (string $class) use ($applicationContainer) {
    return $applicationContainer->get($class);
});
$policy = new CallbackToolPolicy(function ($tool, array $input, ToolContext $context) use ($authorizationService): bool {
    return $authorizationService->allows($context->identity(), $tool->name(), $input);
});
$harness = new AgentHarness($store, [
    'resolver' => $resolver,
    'executor' => new ToolExecutor(null, $policy),
]);
$agent = $agent->withHarness($harness);
```

上面的容器和授权服务属于应用，不是框架依赖。工厂应返回本次运行使用的工具实例，避免共享实例带入其他请求的状态。默认策略允许执行已注册工具，不会代替业务系统判断门店、订单或租户资源权限。`CallbackToolPolicy` 仅在回调明确返回 `true` 时放行。

`ToolContext` 提供 `identity()`、`threadId()`、`runId()`、`callId()`、`idempotencyKey()`、`logger()`、`progress(array)`、`isCancelled()`。身份必须来自服务端，不从模型参数推导；推荐仅存必要身份标识，不把密钥或会话令牌放入 RequestContext。

工具默认视为可能产生副作用；只读工具可实现 `ExecutionAwareToolInterface::isReadOnly()`。执行前会校验输入、授权及取消状态，执行后校验成功结果的结构。明确知道没有提交业务副作用时，可抛 `ToolFailureException` 表达已知失败。普通异常、写工具输出校验失败或进程中断可能产生 `outcome_unknown`，框架不会盲目重做写操作。

`ToolResult` 使用不可变数据：通过 `isSuccessful()`、`data()`、`error()` 读取，`toArray()` 输出协议数据。原公开属性读取需改成对应方法；返回值中的嵌套对象也与内部状态隔离。

同一次 Run 中相同名称、相同参数的成功写工具被再次要求执行时，会返回 `repeated_write`。真正的跨系统业务幂等仍由应用实现：把 `idempotencyKey()` 传给业务事务或下游 API，并提供按键查询既有结果的能力。

## Thread、上下文与记忆

复用相同的 `scope`、`threadId`、Agent 名称和身份即可连续对话；每次用户输入直接传给 `run()`，使用新的 requestId（未指定时由框架生成）。同一个 Thread 只允许一个活跃 Run。身份、Agent 或请求指纹不匹配时拒绝继续。

```php
$history = $agent->threads()->messages('tenant-001', 'thread-001');
$checkpoint = $agent->threads()->run('tenant-001', 'thread-001', $result->runId);
$agent->deleteThread($context);
```

删除活跃 Thread 会被拒绝。`deleteThread()` 删除原始消息、Run 检查点、工具台账、事件，并清理对应短期记忆；长期记忆不自动删除，应用按自己的事实保留规则显式删除。HTTP 接口调用 `threads()`、`cancel()`、`replay()`、`deleteThread()` 前，应校验请求者对该 scope/Thread 的访问权限；这些管理接口不会因为传入 RequestContext 就自动完成业务鉴权。

| 数据 | 实际归属与默认行为 |
|---|---|
| 完整消息、工具调用/结果 | ThreadStore，不因上下文裁剪而删除 |
| Run 状态、检查点、工具台账、事件 | 与 Thread 消息位于同一个原子状态文档 |
| 短期记忆 | 摘要和 `covered_message_id`；不保存第二份完整聊天记录 |
| 长期记忆 | 按 scope 保存可更新事实、版本、来源和有效期；默认只显式写入 |

上下文以 Assistant 工具调用及其全部结果为不可拆分组，禁止孤立调用、缺失结果、重复调用 ID。裁剪只处理完整的历史组，本次输入及之后的交互受保护。摘要以带来源的参考数据进入上下文，不伪装成原始工具结果或高优先级指令。

默认 `ExtractiveSummarizer` 生成有长度限制的历史摘录，不调用额外模型、不推断事实；默认 `ConservativeTokenCounter` 是 UTF-8 字节基础的保守估计，**不是模型的精确 tokenizer**。可通过 `new ContextBuilder($counter, $summarizer)` 注入自定义实现。应用实现会调用模型的摘要器时，需自行纳入对应的调用与成本控制。

### 四种持久化实现

```php
use JsonAI\Memory\ShortTerm\FileShortTermMemory;
use JsonAI\Memory\LongTerm\FileLongTermMemory;

$store = new FileStore(__DIR__ . '/var/agent/state');
$shortMemory = new FileShortTermMemory($store);
$longMemory = new FileLongTermMemory($store);
$harness = new AgentHarness($store, [
    'short_memory' => $shortMemory,
    'long_memory' => $longMemory,
]);
$agent = $agent->withHarness($harness);
```

Store 可以共用同一实例，运行状态和两类记忆通过不同 namespace 隔离；也可以配置不同连接或目录。

| 存储 | StateStore | 短期记忆 | 长期记忆 |
|---|---|---|---|
| File | `FileStore($directory)` | `FileShortTermMemory($storeOrDirectory)` | `FileLongTermMemory($storeOrDirectory)` |
| Redis | `RedisStore($redis, $prefix)` | `RedisShortTermMemory($storeOrRedis)` | `RedisLongTermMemory($storeOrRedis)` |
| MySQL | `MySqlStore($pdo, $table)` | `MySqlShortTermMemory($storeOrPdo)` | `MySqlLongTermMemory($storeOrPdo)` |
| PostgreSQL | `PostgresStore($pdo, $table)` | `PostgresShortTermMemory($storeOrPdo)` | `PostgresLongTermMemory($storeOrPdo)` |

各类命名空间分别为 `JsonAI\Storage`、`JsonAI\Memory\ShortTerm`、`JsonAI\Memory\LongTerm`。长期记忆构造函数的第二参数可注入 `ClockInterface`；需要自定义 Redis prefix 或 SQL table 时，推荐先构造对应 Store 再传入，避免位置参数混淆。

Redis 示例：

```php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$store = new \JsonAI\Storage\RedisStore($redis, 'my_agent:');
$shortMemory = new \JsonAI\Memory\ShortTerm\RedisShortTermMemory($store);
$longMemory = new \JsonAI\Memory\LongTerm\RedisLongTermMemory($store);
```

使用独立 Redis 连接，关闭客户端 serializer、compression 和客户端 prefix；Store 自己管理前缀与类型编码。CAS 使用 Lua 比较版本，冲突时可能重新调用纯状态变更函数；不要在 `StateStore::transact()` 回调里调用模型、执行业务工具或产生其他副作用。Redis 的数据持久性取决于 Redis 服务的持久化和淘汰配置。

SQL 示例：

```php
$pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$store = new \JsonAI\Storage\MySqlStore($pdo, 'agent_state');
// PostgreSQL 改为 new \JsonAI\Storage\PostgresStore($pdo, 'agent_state')。

// 由应用显式执行一次初始化；不要每次请求都执行。
$store->initialize();

$shortMemory = new \JsonAI\Memory\ShortTerm\MySqlShortTermMemory($store);
$longMemory = new \JsonAI\Memory\LongTerm\MySqlLongTermMemory($store);
```

PostgreSQL 对应使用 `PostgresShortTermMemory` / `PostgresLongTermMemory`。连接由应用提供，框架不读取宿主配置。使用不参与应用其他事务的独立 PDO 连接。MySQL 表使用 InnoDB；SQL Store 通过事务、创建占位行和行锁保护首次写入及更新。

`sql/mysql.sql`、`sql/postgresql.sql` 提供可执行初始化 SQL；六个 SQL 具体 Store/Memory 类上方均有对应 `CREATE TABLE` 注释。构造函数和 Composer 安装过程不会自动建表。Thread 和记忆共享 `agent_state` 的 namespace/key/payload 存储结构，不需要分别维护四套聊天记录表。

`StateCodec` 的持久格式版本为 1，保留 PHP 数组和 `stdClass` 的差异，包括嵌套 `[]`/`{}`、null、稀疏索引和超大数字字符串键；只接受标量、数组及 `stdClass`，最多 32 层容器。格式不兼容或数据损坏时明确报错，不静默清空。File 使用独立锁文件和临时文件原子替换，定位为单机本地文件系统存储，不承诺跨主机共享文件系统的锁语义或断电级持久性。

### 长期记忆的写入与检索

```php
// 与 Harness 自动检索一致的作用域：scope + 完整身份 + Agent 名称。
$memoryScope = $agent->memoryScope($context);
$record = $longMemory->put(
    $memoryScope,
    'preferred_language',
    '用户偏好使用中文交流',
    ['source' => 'user_settings'],
    86400,
    0
);
$matches = $longMemory->search($memoryScope, '中文', 5);
$current = $longMemory->get($memoryScope, 'preferred_language');
$updated = $longMemory->put($memoryScope, 'preferred_language', '用户偏好使用英文交流', [], null, $record['version']);
$longMemory->delete($memoryScope, 'preferred_language');
```

`expectedVersion=null` 为无条件 upsert，`0` 要求记录不存在或已过期，正整数要求当前版本匹配。TTL 为正整数秒，`null` 不设置过期。过期数据在读取和搜索时隐藏；删除清除内容和 metadata，保留最小版本墓碑，防止删除重建后旧版本写入覆盖新事实。

默认检索是 key/content 的关键词子串匹配，多个词要求全部匹配，按命中次数、更新时间、key 排序，单次最多返回 100 条；空查询返回最近的有效记录。它不是向量检索或完整 RAG 平台，当前通过扫描该 scope 的记录实现，适合有界记忆规模。

Harness 只在注入长期记忆后自动读取相关记录，**不会默认把模型输出写成事实**。需要自动提取时实现 `MemoryWritePolicyInterface::extract(array $messages, RunContext $context): array`，并通过 `memory_policy` 注入；返回 `key`、`content`、可选 `metadata` 和 `ttl`。策略应只提取应用允许保存的事实，使用确定的 key；Harness 添加 `source_thread_id`/`source_run_id`，同一 Run 已写入的相同 key 在恢复时跳过。策略应可重复执行，且不要包含业务副作用。可选记忆失败进入 warnings，不重做已完成的业务工具。

## 流式输出与事件回放

`run()` 与 `stream()` 使用同一个执行循环。`stream()` 要求 Provider 原生支持流式；工具参数收集完整后才校验和执行，不把参数碎片当成可执行输入。

```php
use JsonAI\Event\SseEncoder;

$encoder = new SseEncoder();
$result = $agent->stream('查询库存', $context, function (array $event) use ($encoder): void {
    echo $encoder->encode($event);
    // HTTP 应用自行设置 Content-Type: text/event-stream，并决定何时 flush()。
});
```

核心不会调用 `echo`、`header()` 或 `exit()`；上面的输出属于应用适配代码。`SseEncoder` 只返回 SSE 字节，事件 ID 为 `run_id:sequence`，`heartbeat()` 返回注释心跳字节。

事件字段包括 `type`、`sequence`、`run_id`、`thread_id`、`time` 和 `data`。主要事件：

- `run.started`、`run.resumed`、`run.completed`、`run.failed`、`run.cancelled`、`run.limited`。
- `message.started`、`message.delta`、`message.completed`、`message.failed`。
- `tool.started`、`tool.progress`、`tool.completed`、`tool.failed`、`provider.retrying`。

文本增量位于 `message.delta` 的 `data.delta`；`message.completed.data.text` 是该消息的完整文本，用于校对或替换，不应再次追加到已经累计的增量后面。`message.completed` 后仍可能调用工具；以终态 `run.*` 判断本次运行结束。消息及运行结果先持久化，再交付相应事件。回调失败会按 `delivery_failed` 处理，已发生的业务副作用不会撤销。

```php
$agent->replay($runId, $context, $lastSequence, function (array $event) use ($encoder): void {
    echo $encoder->encode($event);
});
```

回放读取已经保存的事件，不驱动模型或重做工具。浏览器断线不会自动创建后台进程；需要断线后继续运行时，应用应将 Run 交给独立 Worker，并用查询/回放接口取结果。同步 PHP-FPM 请求的存活和缓冲策略由应用及 Web 服务配置决定。

## 运行限制、取消与错误

```php
$agent = $agent->options([
    'max_iterations' => 8,
    'max_tool_calls' => 12,
    'timeout' => 120,
    'model_timeout' => 45,
    'lease_seconds' => 180,
]);
```

配置优先级为框架默认值 → Agent options → RequestContext options。运行级 options 只允许降低或保持 Agent 上限，不能提高；`lease_seconds` 属于 Agent 定义，不允许运行级改变。RequestContext 的 options 是可信应用入口，不应直接透传客户端参数；框架只接受列出的正整数选项，模型无法修改这些限制。

| 选项 | 默认值 | 含义 |
|---|---:|---|
| `max_iterations` | 12 | 模型成功响应轮数上限 |
| `max_tool_calls` | 24 | 工具执行尝试上限 |
| `max_model_attempts` | 2 | 每轮模型请求最大尝试次数，包含首次请求 |
| `timeout` | 120 | Run 累计执行时间预算，秒 |
| `model_timeout` | 60 | 单次模型请求时间上限，秒 |
| `lease_seconds` | 180 | 执行所有权租约，必须大于 model_timeout |
| `context_tokens` | 16000 | 输入加输出预留的上下文预算 |
| `max_output_tokens` | 1024 | 每次响应的输出预留/上限 |
| `max_total_tokens` | 64000 | Run 中模型尝试的保守预算上限 |
| `max_tool_result_bytes` | 65536 | 单个工具结果上限，字节 |
| `max_input_bytes` | 1048576 | RunRequest 序列化大小上限 |
| `max_events` | 10000 | 每 Run 的事件数量上限 |
| `recent_messages` | 40 | 上下文保留近期消息的目标值，完整交互组优先 |

每次模型尝试，包括失败重试，都会计入预算；尝试次数在发送前保存，恢复不会重置当前轮的尝试上限。无法确认是否已发送的尝试也保守计入次数。默认预算使用保守预留值，`RunResult::usage` 则累计提供商已返回的实际用量，包括被截断或拒绝的响应。已向订阅者交付文本增量的模型请求不会自动从头重试。HTTP 层不另行叠加重试。

`timeout` 以检查点记录的累计执行耗时计算，排队及恢复前的等待时间不计入；恢复继续消耗剩余预算。它不是 PHP 进程的强制终止器。同步 Tool 需要在长任务中调用 `isCancelled()` / `progress()` 并设置自身网络、数据库超时；框架不能强杀正在阻塞的普通 PHP 函数。工具阻塞超过租约时，旧执行者不得继续写入检查点，已经提交的外部业务操作仍需幂等或对账处理。

```php
$agent->cancel($runId, $context);
```

排队 Run 可以直接进入 cancelled；运行中 Run 在检查点或合作式检查时停止。取消不撤销已完成工具。

`RunResult` 提供 `runId`、`threadId`、`status`、`finishReason`、`text`、`output`、`usage`、`error`、`warnings` 和 `isSuccessful()`。持久化状态包含 queued、running、completed、failed、cancelled、limited。`completed` 表示框架正常完成本轮，不代表业务目标必然实现；模型请求用户补充信息时，本轮可以正常结束，下一条用户消息开启新 Run。

遇到检查点写入失败或执行所有权丢失时，当前调用还可能返回临时 `interrupted` 结果。它描述本次执行中断，不能当作已经保存的终态；持久化检查点可能仍为 running，应查询既有 Run，并在租约允许时恢复。终态保存失败返回的错误结果同样不证明持久化状态已经变更。

定义、身份或并发冲突通常在执行前抛异常；执行中失败通常返回带明确原因的 RunResult。持久化失败可能保留可恢复的原检查点，调用方应读取既有 Run，而不是换新 requestId 重新执行业务。

## 检查点、恢复与 Worker

```php
$queued = $agent->submit('查询库存', $context);

// 可在另一个进程重建相同 Agent、Store 和服务配置后执行。
$result = $agent->resume($queued->runId, $context);
```

`resume($runId, $context, $onEvent = null)` 仅使用 context 中的 scope、threadId 和可信 identity 定位、校验 Run；输入、提示词变量和运行限制来自原检查点，恢复时不能用新 context 覆盖。绑定另一 Harness 不改变 Agent 定义指纹，但恢复仍须连接同一存储并保持兼容的服务配置。

`submit()` 只持久化待执行 Run，不启动进程、不自动投递消息队列。`Bridge\Worker` 可适配已有队列：

```php
$worker = new \JsonAI\Bridge\Worker($harness, function (string $agentName) use ($agent) {
    if ($agentName !== $agent->getName()) { throw new InvalidArgumentException('Unknown agent'); }
    return $agent;
});
$result = $worker->handle($scope, $threadId, $runId, $trustedIdentity);
```

队列只携带可信标识和业务所需身份信息，通过应用注册表找到 Agent，不反序列化可执行类。排队 Run 可立即恢复；中断的 running Run 只有租约过期后才能由新执行者接管。每次保存都验证所有权，避免旧执行者在新 Run 接管后继续覆盖状态。

恢复会校验 Agent 版本、模型、工具定义/类文件哈希及只读声明、提示词、配置及提供商指纹。凭证不进入提供商指纹；端点或定义变化会拒绝不兼容恢复。自定义 Provider 应提供稳定的定义指纹或由应用提升 Agent version；依赖服务发生语义变化时也应提升版本。

同一 Thread 内相同 requestId 和相同指纹重提时复用已有 Run；已结束的 Run 返回原结果，不再调用模型。请求内容或配置改变必须使用新 requestId。最终回答和工具结果有检查点，恢复时不重复请求已经持久化的最终模型回答。

在“业务已提交、工具结果尚未保存”的窗口中，仅靠框架检查点无法实现外部系统的 exactly-once。可实现 `ToolReconcilerInterface::reconcile(ToolInterface $tool, string $arguments, ToolContext $context): ?ToolResult` 并通过 `reconciler` 注入：根据幂等键查询已存在的结果，**不得重新执行业务**。无法确定的写操作保留未知结果，不会自动重放。已经终结为 outcome_unknown 的 Run 需要应用处理业务对账，不是人工审批暂停流程。

## Logger

默认使用 PSR-3 `NullLogger`。显式启用文件日志：

```php
$harness = new AgentHarness($store, [
    'logger' => new \JsonAI\Log\FileLogger(__DIR__ . '/var/log/agent', 'info', 14),
]);
$agent = $agent->withHarness($harness);

// 客户端 IP 由应用从可信请求信息中提取并显式传入；CLI/后台任务可不设置。
$context = RequestContext::create('thread-001')
    ->withScope('tenant-001')
    ->withIdentity(['user_id' => 'user-001'])
    ->withClientIp('192.0.2.1');
$result = $agent->run('查询库存', $context);
```

`FileLogger` 固定输出 **JSONL**：UTF-8、无 BOM，每行一个完整 JSON 对象，以换行符结束；消息内部的换行和制表符会转义。文件名格式为 `agent-{UTC时间戳含6位微秒}-{thread_id}-{client_ip_sha256}-{run_hash}.jsonl`，例如时间戳部分为 `20261024T080000.125000Z`。

同一 Run 使用检查点中的创建时间、原始 IP 哈希和 Run ID 派生的 32 位十六进制后缀，跨日和跨进程恢复仍追加到同一文件；同一时间戳、Thread、IP 的不同 Run 也不会因这三个字段相同而共用文件。Thread ID 仅含安全 ASCII 字符且不超过 48 字节时直接保留，否则转换为 `thread_` 加 SHA-256。IP 使用规范化 IPv4/IPv6 的完整 SHA-256，原始地址不写入上下文或检查点；未提供 IP 时文件名使用 `unknown`。IP 不参与身份、记忆隔离或幂等指纹。框架不读取 HTTP 全局变量或自动信任代理转发头。

第三参数仍为保留的日志**文件数量**，不是天数；清理兼容旧的日期文件名，跳过无关文件、当前追加文件和被其他程序锁定的文件，所以并发时可能暂时超过数量。数量达到上限时旧文件会被清理，应按运行量配置保留数量。每个 Logger 首次写入、新文件创建或距上次清理达到 60 秒时才扫描目录；普通追加持有目录共享锁和目标文件排他锁，清理持有目录排他锁，避免删除正在追加的文件。直接使用 FileLogger 时，通过日志 context 提供 `thread_id`、`run_id`、`run_created_at`（Unix 秒，支持微秒小数）和可选 `client_ip_hash`；缺省时使用 Logger 实例的时间和随机标识。

记录字段为 `time`、`level`、`message`、`context`，其中 context 始终为对象。以下是未传客户端 IP 的记录示例：

```jsonl
{"time":"2026-10-24T08:00:00+00:00","level":"info","message":"run.completed","context":{"agent_name":"inventory","thread_id":"thread-001","run_id":"run_example","run_created_at":1792828800.125,"client_ip_hash":null,"finish_reason":"stop"}}
```

多个进程通过排他文件锁逐条追加。写入失败会尝试回退本条记录；下次追加前清除上次中断留下的未换行尾部，保留之前的完整行。非法 UTF-8 字节替换为 Unicode 替代字符，INF/NAN 诊断值记为 `[non-finite]`。终态日志在检查点提交成功后记录，存储中断记录为 `run.interrupted`，不会提前记录 `run.completed`。

也可以注入任意兼容 `Psr\Log\LoggerInterface` 1.x 的实现；替换 Logger 后，其输出格式由该实现负责。

Harness/Tool 使用安全包装记录运行关联信息、提供商尝试、工具耗时及错误类别；默认不记录完整提示词、工具参数和结果。敏感键和常见凭证文本会脱敏，长内容截断，不逐个记录文本 token，也不混入 SSE 输出。日志异常不会重做工具或覆盖原始业务异常。直接使用 FileLogger 记录应用自己的日志时，应用仍负责选择和脱敏日志内容。

日志不是消息历史或恢复依据，恢复使用 Thread 检查点及工具台账。

## 目录与测试

```text
src/
  Agent/       Agent 定义
  Harness/     运行循环、请求、限制、结果、恢复接口
  Tool/        工具契约、执行器、解析器、权限策略
  Provider/    模型协议与 FakeProvider
  Transport/   cURL、SSE 解码与 FakeTransport
  Prompt/      角色模板和变量校验
  Schema/      Schema 构建、规范化与校验
  Message/     消息、工具调用、多模态内容块
  Thread/      会话原子状态
  Context/     完整交互组、预算与摘要
  Memory/      短期及长期记忆的四种存储适配
  Storage/     原子存储与版本化类型保真编码
  Event/       事件与 SSE 编码
  Log/         PSR-3 安全包装及文件日志
  Bridge/      调用方 Worker 适配
  Support/     JSON、时钟、标识
examples/      chat.php、stream.php、recovery.php
tests/         核心、协议、故障路径和存储集成测试
sql/           MySQL / PostgreSQL 初始化 SQL
bin/           PHP 7.2 静态兼容检查
.github/       精确 PHP 7.2.6 镜像与 CI 工作流
```

运行离线示例：

```bash
php examples/chat.php --data-dir=/tmp/agent-chat-demo
php examples/stream.php --data-dir=/tmp/agent-stream-demo --sse
php examples/recovery.php --data-dir=/tmp/agent-recovery-demo
```

线上模型示例需显式增加 `--live`，并提供 `OPENAI_API_KEY` 与 `OPENAI_MODEL` / `--model`。默认示例和测试不调用付费模型接口；协议测试使用 FakeTransport 和本机 HTTP 服务，不能替代对实际模型/兼容网关的联调。

```bash
php tests/run.php
php bin/check-compatibility.php --self-test
php bin/check-compatibility.php
```

静态检查使用兼容 PHP 7.2 的 `nikic/php-parser:^4.19`，筛查新语法和部分新运行时 API；`config.platform.php=7.2.6` 只约束 Composer 依赖解析。两者都不能代替真实 PHP 7.2.6 执行。

集成测试只有在**显式准备的本机一次性服务**上启用，不读取宿主数据库配置，不使用生产库：

| 测试 | 开启环境变量 |
|---|---|
| Redis | `PORTABLE_AGENT_TEST_REDIS=1`，`PORTABLE_AGENT_TEST_REDIS_PORT`（默认 6389） |
| MySQL | `PORTABLE_AGENT_TEST_SQL_DISPOSABLE=1`，`PORTABLE_AGENT_TEST_MYSQL_DSN`、`_USER`、`_PASSWORD` |
| PostgreSQL | `PORTABLE_AGENT_TEST_SQL_DISPOSABLE=1`，`PORTABLE_AGENT_TEST_PGSQL_DSN`、`_USER`、`_PASSWORD` |

SQL DSN 必须明确使用 localhost/loopback；测试创建随机表并在结束时删除，只通过 PDO 执行隔离库测试。未启用的外部驱动测试显示为 SKIP。

CI 工作流用于包成为独立仓库后的根目录，矩阵为 PHP 7.2.6、7.4、8.2，附带一次性 Redis、MySQL、PostgreSQL 服务。精确 7.2.6 任务验证实际 `PHP_VERSION`；CI 文件存在不表示已经在 GitHub 运行，具体通过数和跳过项以执行报告为准。

## 当前部署边界

- 当前是单 Agent 的同步执行循环，多工具调用按顺序执行；流式表示逐步交付事件，不表示异步并行。
- Thread、Run 和事件保存在同一个原子文档，避免跨表半提交；文档随会话增长，每个增量事件也会写检查点。当前适合有界会话，应用应设置事件/输出限制并定期删除或归档已结束会话；没有自动历史分片或归档服务。
- 短期和长期记忆是独立辅助存储，其失败不会冒充业务回滚；删除 Thread 与记忆清理不承诺跨存储事务，清理失败会报错以便重试。
- 没有自动向量化、自动知识库摄取、隐藏模型摘要调用或多 Agent 调度；通过现有接口扩展应用需求。
- 运行凭证、网络出口、数据库权限、备份、保留策略和 Worker 进程管理由部署应用配置；核心可以复制到其他项目后按这些显式配置独立运行。
