<?php

namespace App\Security;

use App\Utils\Config;

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', self::enabled('security.session.strict_mode', true) ? '1' : '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', self::enabled('security.session.http_only', true) ? '1' : '0');
        ini_set('session.cookie_samesite', (string) Config::get('security.session.same_site', 'Lax'));

        session_name((string) Config::get('security.session.name', 'GBSESSID'));
        session_set_cookie_params([
            'lifetime' => (int) Config::get('security.session.lifetime', 0),
            'path' => '/',
            'domain' => '',
            'secure' => self::secureCookie(),
            'httponly' => self::enabled('security.session.http_only', true),
            'samesite' => (string) Config::get('security.session.same_site', 'Lax'),
        ]);

        session_start();
    }

    public static function regenerate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        session_regenerate_id(true);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function secureCookie(): bool
    {
        if (self::enabled('security.session.secure', false)) {
            return true;
        }

        return (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
    }

    private static function enabled(string $key, bool $default): bool
    {
        return filter_var(Config::get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}
