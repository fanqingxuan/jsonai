<?php
namespace JsonAI\Transport;

final class HttpResponse
{
    public $status;
    public $headers;
    public $body;
    public function __construct(int $status, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}
