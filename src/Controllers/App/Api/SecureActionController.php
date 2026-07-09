<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Http\Request;
use App\Validation\ValidationException;
use PDO;

class SecureActionController extends Controller
{
    public function store(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'action' => 'required|string|max:80',
                'client_tick' => 'required|int|min:0',
                'nonce' => 'required|string|max:120',
            ]);
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
            return;
        }

        $result = $this->idempotent('api.example.action', function () use ($payload): array {
            return $this->transaction(function (PDO $pdo) use ($payload): array {
                $this->audit('api.example.action.accepted', [
                    'action' => $payload['action'],
                    'client_tick' => (int) $payload['client_tick'],
                ]);

                return [
                    'accepted' => true,
                    'action' => $payload['action'],
                    'server_time' => time(),
                ];
            });
        });

        $this->success($result, 'Action accepted', 202);
    }
}
