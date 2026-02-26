<?php

namespace App\Utils\Construct;

use App\Utils\Construct\Auth;
use App\Utils\Construct\JModule;
use App\Utils\Functions\Layout;

class Template
{
    private     const   BASE_PATH_TEMPLATE = __DIR__ . '/../../../template/';
    private     const   BASE_PATH_MODULES  = __DIR__ . '/../../../public/views/';
    protected   static  $currentModule;
    protected   static  $currentDomain;
    protected   static  $currentPage;
    private     static  $variables = [];
    private     static  $joker = false;
    private static  $force = false;


    public static function load(string $route = "/", array $args = [], string $page = "index", bool $joker = false, bool $force = false): void
    {

        self::$joker = $joker;
        self::$force = $force;
        self::$currentDomain = Layout::getSubdomainHost();
        self::$currentModule = $route;
        self::$currentPage = self::getPage($page);
        self::$variables = $args;

        $templatePath = self::getTemplatePath(self::determineRouteTemplate(), self::$currentDomain);
        
        self::renderTemplate($templatePath);
    }

    public static function loadContent(): void
    {
        extract(self::$variables);
        $module = self::findModulePath(self::$currentModule);
        require $module;
    }

    public static function get()
    {
        $module = self::findModulePath(self::$currentModule, true);
        $json   = new JModule($module);
        return $json->loadAndValidateJson();
    }

    private static function getPage(string $page): string
    {
        return $page ? "{$page}.php" : "{$_ENV['MOD_INDEX_FILE']}.php";
    }


    private static function determineRouteTemplate(): string
    {

        if (self::$joker == true) return  $_ENV['DEFAULT_WEBSITE_CONTENT'];

        return self::twoFactorAuth() ? $_ENV['DEFAULT_DASHBOARD_CONTENT'] : (self::checkAuth() ? $_ENV['DEFAULT_DASHBOARD_CONTENT'] : $_ENV['DEFAULT_WEBSITE_CONTENT']);
    }

    private static function getTemplatePath(string $template, string $domain): string
    {
        $baseTemplate       = self::twoFactorAuth() ? $_ENV['DEFAULT_DASHBOARD_CONTENT'] : $template;
        $pathTemplate       = self::BASE_PATH_TEMPLATE . "$domain{$baseTemplate}/{$_ENV['MOD_INDEX_FILE']}";
        $defaultTemplate    = self::BASE_PATH_TEMPLATE . "{$_ENV['DEFAULT_SYSTEM_CONTENT']}/{$baseTemplate}/{$_ENV['MOD_INDEX_FILE']}";

        return is_file($pathTemplate) ? $pathTemplate : $defaultTemplate;
    }

    private static function renderTemplate(string $path): void
    {
        extract(self::$variables);
        require $path;
    }

    private static function findModulePath(string $module, bool $isJson = false): string
    {
        $routeTemplate = self::determineRouteTemplate();
        $subdomain = self::$currentDomain;
        $assetDir = $isJson ? '/assets/' . $_ENV['MOD_JSON_FILE'] : '/' . self::$currentPage;
        $path = self::BASE_PATH_MODULES . "{$subdomain}/{$routeTemplate}/{$module}{$assetDir}";

        if (!file_exists($path)) {
            $module = $isJson ? '404' : $_ENV['MOD_ERROR_404'];
            $path = self::BASE_PATH_MODULES . "{$_ENV['DEFAULT_SYSTEM_CONTENT']}/{$routeTemplate}/{$module}/{$_ENV['MOD_INDEX_FILE']}";
        }

        return $path;
    }

    public static function checkAuth(): bool
    {
        return Auth::check();
    }

    public static function twoFactorAuth(): bool
    {
        return Auth::twoFactor();
    }

    public static function getScript(string $module = ""): string
    {
        return self::getAssetPath($module, 'main.js', 'script');
    }

    public static function getStyle(string $module = ""): string
    {
        return self::getAssetPath($module, 'style.css', 'css');
    }

    private static function getAssetPath(string $module, string $fileName, string $folder): string
    {
        $route          =  self::$currentModule;
        $routeTemplate  = self::determineRouteTemplate();
        $path           = "views/". self::$currentDomain ."{$routeTemplate}/{$route}/assets/{$folder}/{$fileName}";
        $defaultPath    = "views/". $_ENV['DEFAULT_SYSTEM_CONTENT'] ."/{$routeTemplate}/{$route}/assets/{$folder}/{$fileName}";

        return is_file($path) ? $path : $defaultPath;
    }

    public static function getLibs(string $type = "css", array $libs = []): void
    {

        foreach ($libs as $value) {

            $module = isset($value->module) ? ($value->module == true ? 'module' : '') : '';

            $tag = $type === 'css'
                ? "<link href='assets/libs/%s' rel='stylesheet' type='text/css' />"
                : "<script type='{$module}' type='text/javascript' src='assets/libs/%s'></script>";
            printf($tag . "\n", $value->lib);
        }
    }

    public static function getBaseDirectory(): string
    {
        $route = $_GET['url'] ?? "";
        $routeParts = explode("/", $route);

        try {
            return str_repeat('../', count($routeParts) - 1);
        } catch (\Exception $e) {
            throw new \Exception("Erro ao setar o caminho da base dos diretórios: " . $e->getMessage());
        }
    }
}