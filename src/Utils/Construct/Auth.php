<?php

namespace App\Utils\Construct;

class Auth
{
    public static function check(): bool
    {
        return isset($_SESSION['user']) ? true : false;
    }

    public static function twoFactor(): bool
    {
        return false;
    }

}