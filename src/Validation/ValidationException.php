<?php

namespace App\Validation;

use RuntimeException;

class ValidationException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct('Dados invalidos.');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
