<?php

namespace Tests\Validation;

use App\Validation\ValidationException;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function testValidatesRequiredEmailAndMin(): void
    {
        $data = Validator::make([
            'email' => 'dev@example.com',
            'score' => 10,
        ], [
            'email' => 'required|email',
            'score' => 'required|int|min:1',
        ])->validate();

        $this->assertSame('dev@example.com', $data['email']);
    }

    public function testThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        Validator::make(['email' => 'bad'], ['email' => 'required|email'])->validate();
    }
}
