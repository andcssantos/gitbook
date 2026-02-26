<?php

namespace App\Core;

class ParamValidator
{
    public function isValidType(string $type, string $value): bool
    {
        return match ($type) {
            'string'    => is_string($value),
            'alpha'     => ctype_alpha($value),
            'int'       => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float'     => filter_var($value, FILTER_VALIDATE_FLOAT) !== false,
            'decimal'   => is_numeric($value),
            'boolean'   => in_array($value, ['true', 'false', '1', '0'], true),
            'date'      => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value),
            'time'      => (bool) preg_match('/^\d{2}:\d{2}:\d{2}$/', $value),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'ipv4'      => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6'      => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'uuid'      => (bool) preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value),
            'phone'     => (bool) preg_match('/^\+?[1-9]\d{1,14}$/', $value),
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'mac'       => (bool) preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $value),
            'hex'       => (bool) preg_match('/^[0-9A-Fa-f]+$/', $value),
            'base64'    => (bool) preg_match('/^[a-zA-Z0-9+\/=]+$/', $value),
            'json'      => json_decode($value) !== null,
            'any'       => true,
            default     => false,
        };
    }

    public function validateParams(string $pattern, array $params): bool
    {
        preg_match_all('/\{(\w+)(?::(\w+))?(?::(\d+))?\}/', $pattern, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $param  = $match[1];
            $type   = $match[2] ?? 'any';
            $length = $match[3] ?? null;

            if (isset($params[$param])) {
                if ($length && (!is_numeric($length) || strlen($params[$param]) > (int)$length)) {
                    return false;
                }

                if (!$this->isValidType($type, $params[$param])) {
                    return false;
                }
            }
        }

        return true;
    }
}
