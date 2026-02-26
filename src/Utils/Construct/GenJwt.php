<?php

namespace App\Utils\Construct;

use App\Utils\Functions\{Layout, FormatString};

class GenJwt
{
    private array $user = [];
    private string $domain;
    private Object $fmtStr;

    public function __construct()
    {
        $this->domain = Layout::getSubdomainHost();
        $this->fmtStr = new FormatString;
    }

    /**
     * Limpa e formata os dados do usuário para o payload.
     */
    public function setUserData(array $user): void
    {
        $forbidden = ["password", "block_attempts", "status"];
        foreach ($forbidden as $key) unset($user[$key]);
        $user['name'] = $this->fmtStr->formatName($user['name'] ?? '');
        $this->user = $user;
    }

    /**
     * Helper para codificação Base64Url (Padrão JWT).
     */
    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Helper para decodificação Base64Url.
     */
    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public function genJwt(): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        
        $payload = $this->base64UrlEncode(json_encode([
            'iss'  => $this->domain . $_ENV['DEFAULT_DOMINIO'],
            'aud'  => $this->domain . $_ENV['DEFAULT_DOMINIO'],
            'iat'  => time(),
            'exp'  => time() + ($_ENV['JWT_EXP'] * 86400), // 86400 = 24 * 60 * 60
            'data' => $this->user
        ]));

        $signature = hash_hmac('sha256', "$header.$payload", $_ENV['JWT_KEY'], true);
        $signature = $this->base64UrlEncode($signature);

        return "$header.$payload.$signature";
    }

    public function validateJwt(?string $token = null): bool
    {
        // Se não passar token, tenta pegar do Cookie (Web) ou do Header (API)
        $token = $token ?? $_COOKIE['token'] ?? $this->getBearerToken();

        if (!$token) {
            $this->destroyJwt();
            return false;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) return false;

        [$header, $payload, $signature] = $parts;

        // Valida Assinatura
        $validSig = hash_hmac('sha256', "$header.$payload", $_ENV['JWT_KEY'], true);
        if (!hash_equals($this->base64UrlEncode($validSig), $signature)) {
            $this->destroyJwt();
            return false;
        }

        $data = json_decode($this->base64UrlDecode($payload), true);

        // Valida Expiração
        if (($data['exp'] ?? 0) < time()) {
            $this->destroyJwt();
            return false;
        }

        $_SESSION['user'] = $data['data'] ?? $data;
        return true;
    }

    private function getBearerToken(): ?string
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s(\S+)/', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function destroyJwt(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['user']);
        }
        setcookie('token', '', time() - 3600, '/');
    }
}