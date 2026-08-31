<?php

declare(strict_types=1);

namespace App\Infra;

/**
 * Structured (JSON lines) logging. Every payment / delivery event carries the
 * identifiers you need to reconstruct an incident: trace_id, order_id,
 * event_id, request_id, supplier, attempt, outcome, duration_ms.
 */
final class Log
{
    private static string $traceId = '-';
    private static string $service = 'core';
    /** @var resource|null */
    private static $stream = null;

    public static function init(string $service, ?string $traceId = null): void
    {
        self::$service = $service;
        self::$traceId = $traceId ?? self::newTraceId();
    }

    public static function newTraceId(): string
    {
        return 'trc_' . bin2hex(random_bytes(8));
    }

    public static function setTraceId(string $id): void
    {
        self::$traceId = $id;
    }

    public static function traceId(): string
    {
        return self::$traceId;
    }

    /** @param array<string,mixed> $ctx */
    public static function info(string $event, array $ctx = []): void
    {
        self::write('info', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function warn(string $event, array $ctx = []): void
    {
        self::write('warn', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public static function error(string $event, array $ctx = []): void
    {
        self::write('error', $event, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private static function write(string $level, string $event, array $ctx): void
    {
        if (self::$stream === null) {
            $fh = @fopen('php://stdout', 'w');
            self::$stream = $fh === false ? fopen('php://stderr', 'w') : $fh;
        }

        $line = json_encode(
            array_merge([
                'ts'       => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.vP'),
                'level'    => $level,
                'service'  => self::$service,
                'event'    => $event,
                'trace_id' => self::$traceId,
            ], $ctx),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        fwrite(self::$stream, $line . PHP_EOL);
    }
}
