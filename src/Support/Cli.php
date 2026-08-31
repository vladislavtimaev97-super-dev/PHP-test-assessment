<?php

declare(strict_types=1);

namespace App\Support;

final class Cli
{
    /**
     * Parse --key=value / --flag arguments.
     *
     * @param  list<string>          $argv
     * @return array<string,string>
     */
    public static function args(array $argv): array
    {
        $out = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $out[$k] = $v;
            } else {
                $out[$arg] = '1';
            }
        }

        return $out;
    }

    public static function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public static function ok(string $text): void
    {
        self::line("\033[32m  PASS\033[0m  " . $text);
    }

    public static function fail(string $text): void
    {
        self::line("\033[31m  FAIL\033[0m  " . $text);
    }

    public static function info(string $text): void
    {
        self::line("\033[90m        " . $text . "\033[0m");
    }

    public static function head(string $text): void
    {
        self::line();
        self::line("\033[1m" . $text . "\033[0m");
    }
}
