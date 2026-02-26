<?php

namespace App\Core;

class PatternConverter
{

    private function getPatternForType(string $param, string $type, ?string $length): string
    {
        $patterns = [
            'string'    => '[^/]' . ($length ? '{1,' . $length . '}' : '+'),
            'alpha'     => '[a-zA-Z]+',
            'int'       => '\d' . ($length ? '{1,' . $length . '}' : '+'),
            'float'     => '\d+(\.\d+)?',
            'decimal'   => '\d+(\.\d+)?',
            'boolean'   => 'true|false|1|0',
            'date'      => '\d{4}-\d{2}-\d{2}',
            'time'      => '\d{2}:\d{2}:\d{2}',
            'email'     => '[^@]+@[^@]+\.[a-zA-Z]{2,}',
            'ipv4'      => '\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b',
            'ipv6'      => '([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}',
            'any'       => '[^/]+',
        ];

        $pattern = $patterns[$type] ?? '[^/]+';
        return '(?P<' . $param . '>' . $pattern . ')';
    }

    public function convertPatternToRegex(string $pattern): string
    {
        $regexPattern = preg_replace_callback('/\{(\w+)(?::(\w+))?(?::(\d+))?\}/', function ($matches) {
            $param  = $matches[1];
            $type   = $matches[2] ?? 'any';
            $length = $matches[3] ?? null;
            return $this->getPatternForType($param, $type, $length);
        }, $pattern);

        return '/^' . str_replace('/', '\/', $regexPattern) . '$/';
    }

}