<?php

namespace App\Game\Exploration;

use App\Http\HttpException;

class ExplorationException extends HttpException
{
    public function __construct(private string $errorCode, string $message, int $status = 422, array $context = [])
    {
        parent::__construct($message, $status, array_merge(['code' => $errorCode], $context));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
