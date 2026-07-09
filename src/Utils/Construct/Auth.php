<?php

namespace App\Utils\Construct;

use App\Security\Session;

class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user']) ? true : false;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        $_SESSION['user'] = $user;
    }

    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    public static function logout(): void
    {
        unset($_SESSION['user']);
        Session::regenerate();
    }

    public static function twoFactor(): bool
    {
        return false;
    }

}
