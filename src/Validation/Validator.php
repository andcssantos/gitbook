<?php

namespace App\Validation;

class Validator
{
    private array $errors = [];

    public function __construct(private array $data, private array $rules)
    {
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function validate(): array
    {
        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', (string) $rules);
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                $this->apply((string) $field, (string) $rule, $value);
            }
        }

        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }

        return $this->data;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    private function apply(string $field, string $rule, mixed $value): void
    {
        [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
        $present = array_key_exists($field, $this->data);

        if ($name === 'required' && (!$present || $value === '' || $value === null)) {
            $this->add($field, 'required');
        }

        if (!$present || $value === null || $value === '') {
            return;
        }

        $valid = match ($name) {
            'string' => is_string($value),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($value),
            'bool', 'boolean' => is_bool($value) || in_array($value, ['0', '1', 0, 1, 'true', 'false'], true),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'array' => is_array($value),
            'min' => $this->min($value, (int) $param),
            'max' => $this->max($value, (int) $param),
            'in' => in_array((string) $value, explode(',', (string) $param), true),
            default => true,
        };

        if (!$valid) {
            $this->add($field, $name);
        }
    }

    private function min(mixed $value, int $min): bool
    {
        return is_numeric($value) ? $value >= $min : mb_strlen((string) $value) >= $min;
    }

    private function max(mixed $value, int $max): bool
    {
        return is_numeric($value) ? $value <= $max : mb_strlen((string) $value) <= $max;
    }

    private function add(string $field, string $rule): void
    {
        $this->errors[$field][] = $rule;
    }
}
