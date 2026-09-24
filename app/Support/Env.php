<?php

namespace App\Support;

/**
 * 極簡 .env 讀取器 (不依賴 Composer 套件)
 */
class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }
        $path = $path ?? dirname(__DIR__, 2) . '/.env';
        if (!is_file($path)) {
            $path = dirname(__DIR__, 2) . '/.env.example';
        }
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                self::$vars[trim($key)] = trim($value);
            }
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$vars[$key] ?? $default;
    }
}
