<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }

        return $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);

        return $v === null ? $default : (int) $v;
    }

    public static function float(string $key, float $default): float
    {
        $v = self::get($key);

        return $v === null ? $default : (float) $v;
    }

    public static function bool(string $key, bool $default): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }
}
