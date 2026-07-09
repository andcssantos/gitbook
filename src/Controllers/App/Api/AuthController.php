<?php

namespace App\Controllers\App\Api;

use App\Core\Controller;
use App\Game\Identity\Services\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(array $params = []): void
    {
        try {
            $payload = $this->validate(Request::body(), [
                'email' => 'required|email|max:160',
                'password' => 'required|string|min:8|max:255',
            ]);

            $identity = (new AuthService())->login((string) $payload['email'], (string) $payload['password']);

            $this->audit('auth.login.success', ['email' => $payload['email']]);
            $this->success($identity, 'Authenticated.');
        } catch (ValidationException $e) {
            $this->fail('Validation failed', 422, $e->errors());
        } catch (HttpException $e) {
            $this->audit('auth.login.failed', ['email' => Request::input('email', '')]);
            $this->fail($e->getMessage(), $e->status(), $e->errors());
        }
    }

    public function logout(array $params = []): void
    {
        (new AuthService())->logout();

        $this->success([], 'Logged out.');
    }

    public function me(array $params = []): void
    {
        $identity = (new AuthService())->currentIdentity();
        if ($identity === null) {
            $this->fail('Unauthenticated.', 401);
            return;
        }

        $this->success($identity, 'Authenticated identity.');
    }
}
