<?php
declare(strict_types=1);

namespace JsonAI\Tool;

use JsonAI\Schema\Schema;
use JsonAI\Support\Json;

final class ToolResult
{
    private $success;
    private $data;
    private $error;

    private function __construct(bool $success, $data, ?array $error)
    {
        $this->success = $success;
        $this->data = Json::decode(Json::encode($data));
        $this->error = $error === null ? null : Json::decodeArray(Json::encode($error));
    }

    public static function success(array $data, ?Schema $schema = null): self
    {
        $normalized = $schema ? $schema->normalize($data) : (object) $data;
        return new self(true, $normalized, null);
    }

    public static function failure(string $code, string $message, array $details = []): self
    {
        return new self(false, new \stdClass(), ['code' => $code, 'message' => $message, 'details' => $details]);
    }

    public function isSuccessful(): bool { return $this->success; }

    /** Return a detached business view; nested JSON objects retain their type. */
    public function data(): array
    {
        $data = $this->copyData();
        return is_object($data) ? get_object_vars($data) : $data;
    }

    public function error(): ?array
    {
        return $this->error;
    }

    public function toArray(): array
    {
        return ['success' => $this->success, 'data' => $this->copyData(), 'error' => $this->error];
    }

    private function copyData()
    {
        return Json::decode(Json::encode($this->data));
    }
}
