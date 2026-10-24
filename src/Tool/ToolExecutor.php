<?php
declare(strict_types=1);

namespace JsonAI\Tool;

use JsonAI\Schema\SchemaValidator;
use JsonAI\Schema\ValidationException;
use JsonAI\Support\Json;

final class ToolExecutor
{
    private $validator;
    private $policy;

    public function __construct(?SchemaValidator $validator = null, ?ToolPolicyInterface $policy = null)
    {
        $this->validator = $validator ?: new SchemaValidator();
        $this->policy = $policy ?: new AllowRegisteredToolsPolicy();
    }

    public function execute(ToolInterface $tool, string $arguments, ToolContext $context): ToolResult
    {
        try {
            $inputSchema = $tool->inputSchema();
            $outputSchema = $tool->outputSchema();
            $readOnly = $tool instanceof ExecutionAwareToolInterface && $tool->isReadOnly();
            if ($inputSchema->type() !== 'object' || is_array($inputSchema->toArray()['type'])) {
                throw new \InvalidArgumentException('Tool input schema must have non-nullable object type.');
            }
        } catch (\Throwable $exception) {
            return ToolResult::failure('invalid_tool_definition', 'Tool definition is invalid.', ['exception_class' => get_class($exception)]);
        }
        try {
            $decoded = Json::decode($arguments);
            $this->validator->validate($decoded, $inputSchema);
            $input = Json::toArray($decoded);
        } catch (ValidationException $exception) {
            return ToolResult::failure('invalid_arguments', 'Tool input failed schema validation.', ['errors' => $exception->errors()]);
        } catch (\Throwable $exception) {
            return ToolResult::failure('invalid_arguments', 'Tool input must be a valid JSON object.');
        }
        try {
            $this->policy->authorize($tool, $input, $context);
        } catch (\Throwable $exception) {
            return ToolResult::failure('permission_denied', 'Tool execution is not authorized.');
        }
        try {
            if ($context->isCancelled()) { return ToolResult::failure('cancelled', 'Tool was cancelled before execution.'); }
        } catch (\Throwable $exception) {
            return ToolResult::failure('cancelled', 'Tool cancellation state could not be verified.');
        }
        try {
            $data = $tool->handle($input, $context);
        } catch (ToolFailureException $exception) {
            return ToolResult::failure('execution_failed', $exception->getMessage(), ['tool_error_code' => $exception->errorCode(), 'details' => $exception->details()]);
        } catch (\Throwable $exception) {
            return ToolResult::failure($readOnly ? 'execution_failed' : 'outcome_unknown', $readOnly ? 'Tool execution failed.' : 'Tool execution outcome is unknown; do not repeat the operation without reconciliation.', ['phase' => 'execution', 'exception_class' => get_class($exception)]);
        }
        try {
            $normalized = $outputSchema->normalize($data);
            $this->validator->validate($normalized, $outputSchema);
            Json::encode($normalized);
            return ToolResult::success($data, $outputSchema);
        } catch (\Throwable $exception) {
            $details = ['phase' => 'output_validation'];
            if ($exception instanceof ValidationException) { $details['errors'] = $exception->errors(); }
            return ToolResult::failure($readOnly ? 'invalid_output' : 'outcome_unknown', $readOnly ? 'Tool output failed schema validation.' : 'Tool ran but its output is invalid; the business operation may already have committed.', $details);
        }
    }
}
