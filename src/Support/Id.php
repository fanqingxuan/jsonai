<?php
namespace JsonAI\Support;

final class Id
{
    public static function generate(string $prefix = ''): string { return $prefix . bin2hex(random_bytes(16)); }
}
