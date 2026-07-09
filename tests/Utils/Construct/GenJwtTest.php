<?php

namespace Tests\Utils\Construct;

use App\Utils\Config;
use App\Utils\Construct\GenJwt;
use PHPUnit\Framework\TestCase;

class GenJwtTest extends TestCase
{
    protected function setUp(): void
    {
        Config::load(__DIR__ . '/../../../');

        $_COOKIE = [];
        $_SESSION = [];
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['DEFAULT_DOMINIO'] = 'localhost';
        $_ENV['DEFAULT_DOMINIO'] = 'localhost';
        $_ENV['JWT_EXP'] = '7';
        $_ENV['JWT_REFRESH_EXP'] = '30';
        $_ENV['JWT_KEY'] = str_repeat('a', 32);
    }

    public function testCanGenerateAndValidateToken(): void
    {
        $jwt = new GenJwt();
        $jwt->setUserData([
            'id' => 1,
            'name' => 'Andre Santos',
            'password' => 'secret',
            'status' => 1,
        ]);

        $token = $jwt->genJwt();

        $this->assertTrue($jwt->validateJwt($token));
        $this->assertSame(1, $_SESSION['user']['id']);
        $this->assertArrayNotHasKey('password', $_SESSION['user']);
        $this->assertArrayNotHasKey('status', $_SESSION['user']);
    }

    public function testRejectsTokenWithInvalidAlgorithm(): void
    {
        $jwt = new GenJwt();
        $jwt->setUserData(['id' => 1, 'name' => 'Andre Santos']);
        $token = $jwt->genJwt();
        $parts = explode('.', $token);
        $parts[0] = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'none'], JSON_THROW_ON_ERROR));

        $this->assertFalse($jwt->validateJwt(implode('.', $parts)));
    }

    public function testRejectsTokenWhenKeyIsMissing(): void
    {
        $_ENV['JWT_KEY'] = '';

        $this->assertFalse((new GenJwt())->validateJwt('header.payload.signature'));
    }

    public function testCanIssueAndRotateRefreshToken(): void
    {
        $jwt = new GenJwt();
        $jwt->setUserData(['id' => 10, 'name' => 'Andre Santos']);

        $pair = $jwt->issueTokenPair();

        $this->assertArrayHasKey('access_token', $pair);
        $this->assertArrayHasKey('refresh_token', $pair);
        $this->assertNotNull($jwt->validateRefreshToken($pair['refresh_token']));

        $rotated = $jwt->refreshAccessToken($pair['refresh_token']);

        $this->assertIsArray($rotated);
        $this->assertNotSame($pair['refresh_token'], $rotated['refresh_token']);
        $this->assertNull($jwt->validateRefreshToken($pair['refresh_token']));
        $this->assertNotNull($jwt->validateRefreshToken($rotated['refresh_token']));
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
