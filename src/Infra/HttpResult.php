<?php

declare(strict_types=1);

namespace App\Infra;

/**
 * The classification of an outgoing call. The distinction between TIMEOUT and
 * UNAVAILABLE is the whole point of stage 3:
 *
 *  - UNAVAILABLE — the request never reached the supplier (connection refused,
 *    DNS failure, connect timeout). Nothing could have been issued. Safe to
 *    fail over.
 *  - TIMEOUT — the request WAS delivered, we simply never saw the answer. The
 *    supplier may or may not have issued a code. State is UNKNOWN, and the
 *    only safe move is to retry the SAME request_id until the supplier tells
 *    us what happened. Failing over here can burn a second key.
 */
final class HttpResult
{
    public const OK          = 'ok';
    public const HTTP_ERROR  = 'http_error';
    public const TIMEOUT     = 'timeout';
    public const UNAVAILABLE = 'unavailable';

    /** @param array<string,mixed>|null $json */
    private function __construct(
        public readonly string $kind,
        public readonly ?int $status,
        public readonly ?array $json,
        public readonly ?string $raw,
        public readonly int $durationMs,
        public readonly ?string $error = null,
    ) {
    }

    /** @param array<string,mixed>|null $json */
    public static function response(int $status, ?array $json, ?string $raw, int $ms): self
    {
        return new self(
            $status >= 200 && $status < 300 ? self::OK : self::HTTP_ERROR,
            $status,
            $json,
            $raw,
            $ms
        );
    }

    public static function timeout(int $ms, string $error): self
    {
        return new self(self::TIMEOUT, null, null, null, $ms, $error);
    }

    public static function unavailable(int $ms, string $error): self
    {
        return new self(self::UNAVAILABLE, null, null, null, $ms, $error);
    }

    public function isOk(): bool
    {
        return $this->kind === self::OK;
    }

    public function reason(): ?string
    {
        return is_string($this->json['reason'] ?? null) ? $this->json['reason'] : $this->error;
    }
}
