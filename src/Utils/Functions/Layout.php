<?php

namespace App\Utils\Functions;

use App\Utils\Config;

class Layout
{
    public static function getSubdomainHost(): string
    {
        $host = filter_var($_SERVER['HTTP_HOST'] ?? '', FILTER_SANITIZE_URL);
        $domain = filter_var($_SERVER['DEFAULT_DOMINIO'] ?? Config::get('app.domain', ''), FILTER_SANITIZE_URL);
        $host = preg_replace('/:\d+$/', '', $host);
        $domain = preg_replace('/:\d+$/', '', $domain);
        $host = preg_replace('/^www\./', '', $host);
        $domain = preg_replace('/^www\./', '', $domain);
        $host = in_array($host, ['127.0.0.1', '::1'], true) ? 'localhost' : $host;
        $domain = in_array($domain, ['127.0.0.1', '::1'], true) ? 'localhost' : $domain;
        $subdomain = str_replace('.' . $domain, '', $host);

        return ($subdomain === $domain || empty($subdomain))
            ? Config::get('routing.default_system_content', 'App') . '/'
            : $subdomain . '/';
    }

    public function loadTemplate($templatePath, $data = [])
    {
        extract($data);
        ob_start();
        include_once $templatePath;
        return ob_get_clean();
    }
}
