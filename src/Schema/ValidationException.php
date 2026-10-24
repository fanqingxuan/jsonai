<?php
declare(strict_types=1);

namespace JsonAI\Schema;

final class ValidationException extends \InvalidArgumentException
{
    private $errors;

    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct('Schema validation failed: ' . implode('; ', array_map(function (array $error): string {
            return $error['path'] . ': ' . $error['message'];
        }, $errors)));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
