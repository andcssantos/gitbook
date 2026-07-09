<?php

namespace Tests\Utils\Security;

use App\Http\Request;
use App\Utils\Functions\CacheManager;
use App\Utils\Security\NonceStore;
use App\Utils\Security\RequestSigner;
use PHPUnit\Framework\TestCase;

class RequestSignerTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['SIGNED_REQUEST_KEY'] = str_repeat('s', 32);
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/action',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
    }

    public function testValidSignatureIsAcceptedOnlyOnce(): void
    {
        $signer = new RequestSigner();
        $timestamp = time();
        $nonce = 'nonce-' . uniqid('', true);

        $_SERVER['HTTP_X_TIMESTAMP'] = (string) $timestamp;
        $_SERVER['HTTP_X_NONCE'] = $nonce;
        $_SERVER['HTTP_X_SIGNATURE'] = $signer->sign(new Request(), $timestamp, $nonce);

        $nonces = new NonceStore(new CacheManager('tests/nonces', 60, 0.0));

        $this->assertTrue($signer->validate(new Request(), 120, 300, $nonces));
        $this->assertFalse($signer->validate(new Request(), 120, 300, $nonces));
    }

    public function testExpiredSignatureIsRejected(): void
    {
        $signer = new RequestSigner();
        $timestamp = time() - 500;
        $nonce = 'nonce-' . uniqid('', true);

        $_SERVER['HTTP_X_TIMESTAMP'] = (string) $timestamp;
        $_SERVER['HTTP_X_NONCE'] = $nonce;
        $_SERVER['HTTP_X_SIGNATURE'] = $signer->sign(new Request(), $timestamp, $nonce);

        $nonces = new NonceStore(new CacheManager('tests/nonces-expired', 60, 0.0));

        $this->assertFalse($signer->validate(new Request(), 120, 300, $nonces));
    }
}
