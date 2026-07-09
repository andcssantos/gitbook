<?php

namespace App\Utils\Construct;

use App\Utils\Functions\{Layout, FormatString};
use App\Utils\Functions\CacheManager;
use JsonException;
use RuntimeException;

class GenJwt
{
    private array $user = [];
    private string $domain;
    private object $fmtStr;
    private CacheManager $refreshStore;

    public function __construct()
    {
        $this->domain = Layout::getSubdomainHost();
        $this->fmtStr = new FormatString();
        $this->refreshStore = new CacheManager('auth/refresh_tokens', $this->refreshExpirationDays() * 86400);
    }

    public function setUserData(array $user): void
    {
        foreach (['password', 'block_attempts', 'status'] as $key) {
            unset($user[$key]);
        }

        $user['name'] = $this->fmtStr->formatName($user['name'] ?? '');
        $this->user = $user;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);
        if ($decoded === false) {
            throw new RuntimeException('Token JWT contem base64 invalido.');
        }

        return $decoded;
    }

    public function genJwt(): string
    {
        return $this->genAccessToken();
    }

    public function genAccessToken(): string
    {
        $header = $this->base64UrlEncode($this->jsonEncode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode($this->jsonEncode([
            'iss' => $this->expectedIssuer(),
            'aud' => $this->expectedIssuer(),
            'iat' => time(),
            'exp' => time() + ($this->jwtExpirationDays() * 86400),
            'type' => 'access',
            'data' => $this->user,
        ]));

        $signature = hash_hmac('sha256', "$header.$payload", $this->jwtKey(), true);

        return "$header.$payload." . $this->base64UrlEncode($signature);
    }

    public function issueTokenPair(): array
    {
        $accessToken = $this->genAccessToken();
        $refreshToken = $this->genRefreshToken();

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtExpirationDays() * 86400,
            'refresh_expires_in' => $this->refreshExpirationDays() * 86400,
        ];
    }

    public function genRefreshToken(): string
    {
        if ($this->user === []) {
            throw new RuntimeException('Dados do usuario precisam ser definidos antes de gerar refresh token.');
        }

        $token = $this->base64UrlEncode(random_bytes(64));
        $tokenHash = $this->tokenHash($token);
        $ttl = $this->refreshExpirationDays() * 86400;

        $this->refreshStore->set($tokenHash, [
            'user' => $this->user,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'rotated_at' => null,
        ], $ttl, [
            'tags' => ['refresh_tokens', $this->userTag($this->user)],
        ]);

        return $token;
    }

    public function validateJwt(?string $token = null): bool
    {
        $token = $token ?? $_COOKIE['token'] ?? $this->getBearerToken();

        if (!$token) {
            $this->destroyJwt();
            return false;
        }

        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            [$header, $payload, $signature] = $parts;
            $headerData = $this->jsonDecode($this->base64UrlDecode($header));

            if (($headerData['alg'] ?? null) !== 'HS256' || ($headerData['typ'] ?? null) !== 'JWT') {
                $this->destroyJwt();
                return false;
            }

            $validSig = hash_hmac('sha256', "$header.$payload", $this->jwtKey(), true);
            if (!hash_equals($this->base64UrlEncode($validSig), $signature)) {
                $this->destroyJwt();
                return false;
            }

            $data = $this->jsonDecode($this->base64UrlDecode($payload));
            if (!$this->isValidPayload($data, 'access')) {
                $this->destroyJwt();
                return false;
            }

            $_SESSION['user'] = $data['data'];
            return true;
        } catch (RuntimeException | JsonException) {
            $this->destroyJwt();
            return false;
        }
    }

    public function persistJwtCookie(string $token): void
    {
        $this->setTokenCookie($token, time() + ($this->jwtExpirationDays() * 86400));
    }

    public function persistRefreshCookie(string $token): void
    {
        $this->setCookie('refresh_token', $token, time() + ($this->refreshExpirationDays() * 86400));
    }

    public function refreshAccessToken(string $refreshToken, bool $rotate = true): ?array
    {
        $record = $this->validateRefreshToken($refreshToken);
        if ($record === null) {
            return null;
        }

        $this->user = $record['user'];

        if ($rotate) {
            $this->revokeRefreshToken($refreshToken);
            return $this->issueTokenPair();
        }

        return [
            'access_token' => $this->genAccessToken(),
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtExpirationDays() * 86400,
            'refresh_expires_in' => max(0, (int) $record['expires_at'] - time()),
        ];
    }

    public function validateRefreshToken(string $refreshToken): ?array
    {
        $record = $this->refreshStore->get($this->tokenHash($refreshToken));

        if (!is_array($record) || !isset($record['user'], $record['expires_at'])) {
            return null;
        }

        if ((int) $record['expires_at'] < time()) {
            $this->revokeRefreshToken($refreshToken);
            return null;
        }

        return $record;
    }

    public function revokeRefreshToken(string $refreshToken): void
    {
        $this->refreshStore->delete($this->tokenHash($refreshToken));
    }

    public function revokeUserRefreshTokens(array $user): void
    {
        $this->refreshStore->flushTag($this->userTag($user));
    }

    public function destroyJwt(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['user']);
        }

        $this->setTokenCookie('', time() - 3600);
        $this->setCookie('refresh_token', '', time() - 3600);
    }

    private function getBearerToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function setTokenCookie(string $token, int $expires): void
    {
        $this->setCookie('token', $token, $expires);
    }

    private function setCookie(string $name, string $token, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($name, $token, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function jwtKey(): string
    {
        $key = trim((string) ($_ENV['JWT_KEY'] ?? ''));
        if ($key === '' || strlen($key) < 32) {
            throw new RuntimeException('JWT_KEY deve estar configurada com pelo menos 32 caracteres.');
        }

        return $key;
    }

    private function jwtExpirationDays(): int
    {
        return max(1, (int) ($_ENV['JWT_EXP'] ?? 7));
    }

    private function refreshExpirationDays(): int
    {
        return max($this->jwtExpirationDays() + 1, (int) ($_ENV['JWT_REFRESH_EXP'] ?? 30));
    }

    private function expectedIssuer(): string
    {
        return $this->domain . ($_ENV['DEFAULT_DOMINIO'] ?? '');
    }

    /**
     * @throws JsonException
     */
    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private function jsonDecode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : [];
    }

    private function isValidPayload(array $data, string $expectedType): bool
    {
        if (($data['iss'] ?? null) !== $this->expectedIssuer()) {
            return false;
        }

        if (($data['aud'] ?? null) !== $this->expectedIssuer()) {
            return false;
        }

        if (!isset($data['exp']) || (int) $data['exp'] < time()) {
            return false;
        }

        if (!isset($data['iat']) || (int) $data['iat'] > time() + 60) {
            return false;
        }

        return ($data['type'] ?? null) === $expectedType && array_key_exists('data', $data);
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->jwtKey());
    }

    private function userTag(array $user): string
    {
        $id = $user['id'] ?? $user['sf'] ?? $user['email'] ?? $user['user_email'] ?? hash('sha256', $this->jsonEncode($user));

        return 'refresh_user:' . hash('sha256', (string) $id);
    }

    private function isHttps(): bool
    {
        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
    }
}
