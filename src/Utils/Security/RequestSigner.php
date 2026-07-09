<?php

namespace App\Utils\Security;

use App\Http\Request;
use RuntimeException;

class RequestSigner
{
    public function validate(Request $request, int $ttlSeconds, int $nonceTtlSeconds, NonceStore $nonces): bool
    {
        $timestamp = (int) ($request::header('X-Timestamp') ?? 0);
        $nonce = trim((string) ($request::header('X-Nonce') ?? ''));
        $signature = trim((string) ($request::header('X-Signature') ?? ''));

        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > max(1, $ttlSeconds)) {
            return false;
        }

        if (!$nonces->consume($nonce, max($nonceTtlSeconds, $ttlSeconds))) {
            return false;
        }

        return hash_equals($this->sign($request, $timestamp, $nonce), $signature);
    }

    public function sign(Request $request, int $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', $this->canonicalPayload($request, $timestamp, $nonce), $this->secret());
    }

    private function canonicalPayload(Request $request, int $timestamp, string $nonce): string
    {
        return implode("\n", [
            $request::method(),
            $request::path(),
            (string) $timestamp,
            $nonce,
            hash('sha256', $request::rawBody()),
        ]);
    }

    private function secret(): string
    {
        foreach (['SIGNED_REQUEST_KEY', 'CACHE_KEY', 'MASTER_KEY', 'JWT_KEY'] as $key) {
            $value = trim((string) ($_ENV[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        throw new RuntimeException('Nenhuma chave configurada para requests assinadas.');
    }
}
