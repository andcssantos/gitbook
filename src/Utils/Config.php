<?php

namespace App\Utils;

class Config
{
    private static array $items = [];

    public static function load(string $basePath): void
    {
        $configPath = rtrim($basePath, '/').'/config';
        if (!is_dir($configPath)) {
            return;
        }

        foreach (glob($configPath.'/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $data = require $file;

            if (is_array($data)) {
                self::$items[$key] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
