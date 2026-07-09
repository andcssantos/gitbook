<?php

namespace App\Http;

use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 400,
        private array $errors = []
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
