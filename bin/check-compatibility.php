<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/** Static guardrail only. Real PHP 7.2.6 execution remains mandatory in CI. */
final class Php72CompatibilityCheck
{
    private $tokens = [];
    private $issues = [];
    private $newFunctions = [
        'array_key_first', 'array_key_last', 'is_countable', 'hrtime', 'net_get_interfaces',
        'mb_str_split', 'get_mangled_object_vars', 'password_algos', 'str_contains',
        'str_starts_with', 'str_ends_with', 'get_debug_type', 'get_resource_id', 'fdiv',
        'preg_last_error_msg', 'array_is_list', 'fsync', 'fdatasync', 'enum_exists',
        'json_validate', 'mb_str_pad', 'str_increment', 'str_decrement',
    ];

    public function inspect(string $source): array
    {
        $this->issues = [];
        $lexer = new PhpParser\Lexer\Emulative(['usedAttributes' => ['startLine', 'endLine', 'startTokenPos', 'endTokenPos']]);
        $parser = (new PhpParser\ParserFactory())->create(PhpParser\ParserFactory::PREFER_PHP7, $lexer);
        try {
            $nodes = $parser->parse($source);
        } catch (PhpParser\Error $error) {
            return [['line' => $error->getStartLine(), 'message' => 'Parser rejected source: ' . $error->getRawMessage()]];
        }
        $this->tokens = $lexer->getTokens();
        foreach ($this->tokens as $token) {
            if (!is_array($token)) { continue; }
            if (($token[0] === T_LNUMBER || $token[0] === T_DNUMBER) && strpos($token[1], '_') !== false) {
                $this->issues[] = ['line' => $token[2], 'message' => 'Numeric separators require PHP 7.4.'];
            }
            if ($token[0] === T_LNUMBER && preg_match('/\A0[oO]/', $token[1])) {
                $this->issues[] = ['line' => $token[2], 'message' => 'Explicit octal literals require PHP 8.1.'];
            }
            if ($token[0] === T_END_HEREDOC && preg_match('/\A[ \t]/', $token[1])) {
                $this->issues[] = ['line' => $token[2], 'message' => 'Indented heredoc closing labels require PHP 7.3.'];
            }
        }
        $this->visit($nodes ?: [], []);
        return $this->issues;
    }

    private function visit($value, array $available): void
    {
        if (is_array($value)) {
            foreach ($value as $item) { $this->visit($item, $available); }
            return;
        }
        if (!$value instanceof PhpParser\Node) { return; }
        $node = $value;
        $unsupported = [
            'Expr_ArrowFunction' => 'Arrow functions require PHP 7.4.',
            'Expr_AssignOp_Coalesce' => 'Null coalescing assignment requires PHP 7.4.',
            'Expr_Match' => 'Match expressions require PHP 8.0.',
            'Expr_NullsafeMethodCall' => 'Nullsafe calls require PHP 8.0.',
            'Expr_NullsafePropertyFetch' => 'Nullsafe properties require PHP 8.0.',
            'Stmt_Enum' => 'Enums require PHP 8.1.',
            'UnionType' => 'Union types require PHP 8.0.',
            'IntersectionType' => 'Intersection types require PHP 8.1.',
            'Expr_Throw' => 'Throw expressions require PHP 8.0.',
            'VariadicPlaceholder' => 'First-class callable syntax requires PHP 8.1.',
        ];
        if (isset($unsupported[$node->getType()])) { $this->report($node, $unsupported[$node->getType()]); }
        if (property_exists($node, 'attrGroups') && $node->attrGroups) { $this->report($node, 'Attributes require PHP 8.0.'); }
        if ($node instanceof PhpParser\Node\Stmt\Property && $node->type !== null) { $this->report($node, 'Typed properties require PHP 7.4.'); }
        if (($node instanceof PhpParser\Node\Stmt\Property || $node instanceof PhpParser\Node\Stmt\Class_) && ($node->flags & 64)) {
            $this->report($node, 'Readonly properties/classes require PHP 8.1/8.2.');
        }
        if ($node instanceof PhpParser\Node\Param && $node->flags !== 0) { $this->report($node, 'Promoted properties require PHP 8.0.'); }
        if ($node instanceof PhpParser\Node\Arg && $node->name !== null) { $this->report($node, 'Named arguments require PHP 8.0.'); }
        if ($node instanceof PhpParser\Node\Expr\ArrayItem && $node->unpack) { $this->report($node, 'Array unpacking requires PHP 7.4.'); }
        if ($node instanceof PhpParser\Node\Stmt\Catch_ && $node->var === null) { $this->report($node, 'Catch without a variable requires PHP 8.0.'); }
        foreach (['type', 'returnType'] as $field) {
            if (property_exists($node, $field) && ($node->{$field} instanceof PhpParser\Node\Identifier || $node->{$field} instanceof PhpParser\Node\Name)) {
                if (in_array(strtolower($node->{$field}->toString()), ['mixed', 'never', 'static', 'false', 'true', 'null'], true)) {
                    $this->report($node, 'This type declaration requires PHP 8.0 or newer.');
                }
            }
        }
        if ($node instanceof PhpParser\Node\Expr\ConstFetch && in_array(strtoupper($node->name->toString()), ['JSON_THROW_ON_ERROR', 'PASSWORD_ARGON2ID'], true)) {
            $this->report($node, $node->name->toString() . ' is unavailable on PHP 7.2.6.');
        }
        if ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name) {
            $name = strtolower($node->name->toString());
            if (in_array($name, $this->newFunctions, true) && !in_array($name, $available, true)) {
                $this->report($node, $name . ' requires a newer PHP version or an explicit function_exists guard.');
            }
        }
        if (property_exists($node, 'args') && is_array($node->args)) { $this->checkTrailingComma($node->args); }
        if (property_exists($node, 'params') && is_array($node->params)) { $this->checkTrailingComma($node->params); }

        // A guard is valid only in the true branch/right operand, never in an else branch.
        if ($node instanceof PhpParser\Node\Stmt\If_) {
            $this->visit($node->cond, $available);
            $this->visit($node->stmts, array_merge($available, $this->guards($node->cond)));
            foreach ($node->elseifs as $elseif) {
                $this->visit($elseif->cond, $available);
                $this->visit($elseif->stmts, array_merge($available, $this->guards($elseif->cond)));
            }
            if ($node->else !== null) { $this->visit($node->else->stmts, $available); }
            return;
        }
        if ($node instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd || $node instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd) {
            $this->visit($node->left, $available);
            $this->visit($node->right, array_merge($available, $this->guards($node->left)));
            return;
        }
        foreach ($node->getSubNodeNames() as $name) { $this->visit($node->{$name}, $available); }
    }

    private function guards($node): array
    {
        if ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name
            && strtolower($node->name->toString()) === 'function_exists' && isset($node->args[0])
            && $node->args[0]->value instanceof PhpParser\Node\Scalar\String_) {
            return [strtolower($node->args[0]->value->value)];
        }
        if ($node instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd || $node instanceof PhpParser\Node\Expr\BinaryOp\LogicalAnd) {
            return array_merge($this->guards($node->left), $this->guards($node->right));
        }
        return [];
    }

    private function checkTrailingComma(array $items): void
    {
        if (!$items) { return; }
        $last = end($items);
        if (!$last instanceof PhpParser\Node) { return; }
        for ($position = $last->getAttribute('endTokenPos') + 1, $count = count($this->tokens); $position < $count; ++$position) {
            $token = $this->tokens[$position];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if ($token === ',') { $this->report($last, 'Trailing commas in calls/parameters require PHP 7.3/8.0.'); }
            break;
        }
    }

    private function report(PhpParser\Node $node, string $message): void
    {
        $this->issues[] = ['line' => $node->getStartLine(), 'message' => $message];
    }
}

$checker = new Php72CompatibilityCheck();
if (isset($argv[1]) && $argv[1] === '--self-test') {
    $cases = [
        ['<?php function ok(?string $s): void { $a = [1, 2,]; }', false],
        ['<?php if (function_exists("fsync") && !fsync($f)) { throw new RuntimeException(); }', false],
        ['<?php if (function_exists("hrtime")) { hrtime(); } else { microtime(true); }', false],
        ['<?php $f = fn($v) => $v;', true],
        ['<?php class X { public string $x; }', true],
        ['<?php $x ??= 1;', true],
        ['<?php $x = match ($y) { 1 => 2 };', true],
        ['<?php enum Status { case Ready; }', true],
        ['<?php function x(int|string $x): never {}', true],
        ['<?php function x(A&B $x) {}', true],
        ['<?php #[Example] class X {}', true],
        ['<?php $x?->run();', true],
        ['<?php class X { function __construct(public $x) {} }', true],
        ['<?php json_decode($x, true, 512, JSON_THROW_ON_ERROR);', true],
        ['<?php hrtime();', true],
        ['<?php if (function_exists("hrtime")) {} else { hrtime(); }', true],
        ['<?php call(1, /* trailing */);', true],
        ['<?php function x($a,) {}', true],
        ['<?php $x = 1_000;', true],
        ['<?php $x = [...$y];', true],
        ['<?php call(named: 1);', true],
        ['<?php $x = call(...);', true],
        ['<?php try {} catch (Exception) {}', true],
    ];
    foreach ($cases as $index => $case) {
        if ((count($checker->inspect($case[0])) > 0) !== $case[1]) {
            fwrite(STDERR, 'Compatibility checker self-test failed: ' . ($index + 1) . "\n");
            exit(1);
        }
    }
    echo 'Compatibility checker: ' . count($cases) . " positive/negative fixtures passed.\n";
    exit(0);
}

$packageRoot = dirname(__DIR__);
$files = [];
$paths = array_slice($argv, 1);
if (!$paths) { $paths = [$packageRoot . '/src', $packageRoot . '/tests', $packageRoot . '/examples', __DIR__]; }
foreach ($paths as $path) {
    if (is_file($path)) { $files[] = $path; continue; }
    if (!is_dir($path)) { fwrite(STDERR, 'Missing path: ' . $path . "\n"); exit(1); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') { $files[] = $file->getPathname(); }
    }
}
sort($files);
$failures = 0;
foreach ($files as $file) {
    foreach ($checker->inspect(file_get_contents($file)) as $issue) {
        ++$failures;
        fwrite(STDERR, $file . ':' . $issue['line'] . ': ' . $issue['message'] . "\n");
    }
}
echo count($files) . ' files screened for PHP 7.2 syntax/runtime incompatibilities; ' . $failures . " findings.\n";
echo "Static screening is not a substitute for executing tests on PHP 7.2.6.\n";
exit($failures === 0 ? 0 : 1);
