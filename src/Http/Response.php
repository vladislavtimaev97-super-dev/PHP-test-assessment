<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string,mixed>|list<mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        /** @var array<string,string> */
        public readonly array $headers = [],
    ) {
    }

    /** @param array<string,mixed>|list<mixed> $body */
    public static function json(array $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function error(int $status, string $code, string $message): self
    {
        return new self($status, ['error' => ['code' => $code, 'message' => $message]]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo json_encode(
            $this->body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
