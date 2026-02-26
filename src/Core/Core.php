<?php

namespace App\Core;

use App\Http\Request;
use App\Http\Response;
use App\Utils\Config;
use App\Utils\Construct\Auth;

class Core
{
    private static string $prefixController = 'App\\Controllers\\';
    private static string $templateController = 'Website';

    private static function resolveTemplateController(): string
    {
        if (self::twoFactorAuth() || self::checkAuth()) {
            return Config::get('routing.default_dashboard_content', self::$templateController);
        }

        return Config::get('routing.default_website_content', self::$templateController);
    }

    public static function twoFactorAuth(): bool
    {
        return Auth::twoFactor();
    }

    public static function checkAuth(): bool
    {
        return Auth::check();
    }

    private static function getRequestUrl(): string
    {
        $url = '/';
        if (isset($_GET['url'])) {
            $url .= rtrim(filter_var($_GET['url'], FILTER_SANITIZE_URL), '/');
            $url = urldecode($url);
        }

        return $url;
    }

    private static function matchRoute(array $route, string $url)
    {
        $queryParams = $route['options']['queryParams'] ?? true;
        $auth = $route['options']['auth'] ?? false;

        if ($auth && !Auth::check()) {
            return null;
        }

        return RouteMatcher::getParams($route['path'], $url, $queryParams ? $_GET : []);
    }

    public static function dispatch(array $routes): void
    {
        self::$templateController = self::resolveTemplateController();

        $url = self::getRequestUrl();
        $method = Request::method();
        $routeFound = false;

        $subdomain = self::getSubdomainHost();
        $controllerBaseNamespace = self::$prefixController . $subdomain;

        $relevantRoutes = $routes[$method] ?? [];

        foreach ($relevantRoutes as $route) {
            $params = self::matchRoute($route, $url);

            if ($params !== null) {
                $routeFound = true;

                if (!empty($route['options']['middleware'])) {
                    \App\Http\Middleware::handle((array) $route['options']['middleware']);
                }

                if (isset($route['options']['redirect'])) {
                    header('Location: ' . $route['options']['redirect'], true, $route['options']['status'] ?? 301);
                    exit;
                }

                if (is_string($route['action']) && strpos($route['action'], '@') !== false) {
                    [$controller, $action] = explode('@', $route['action']);
                } else {
                    continue;
                }

                $class = str_replace('/', '\\', 'App\\Controllers\\' . $controller);
                if (class_exists($class)) {
                    $controllerInstance = new $class();

                    if (method_exists($controllerInstance, $action)) {
                        $controllerInstance->$action($params);
                        return;
                    }

                    self::handleActionMethodNotFound();
                    return;
                }

                $controllerFallback = self::$prefixController . Config::get('routing.default_system_content', 'App') . '\\' . str_replace('/', '\\', $controller);

                if (class_exists($controllerFallback)) {
                    $controllerInstance = new $controllerFallback();
                    if (method_exists($controllerInstance, $action)) {
                        $controllerInstance->$action($params);
                        return;
                    }
                }

                self::handleControllerNotFound();
                return;
            }
        }

        if (!$routeFound && self::pathExistsForDifferentMethod($routes, $url, $method)) {
            self::handleMethodNotAllowed($routes, $url);
            return;
        }

        if (!$routeFound) {
            self::handleNotFound($controllerBaseNamespace);
        }
    }

    private static function pathExistsForDifferentMethod(array $routes, string $url, string $currentMethod): bool
    {
        foreach ($routes as $method => $methodRoutes) {
            if ($method === $currentMethod) {
                continue;
            }

            foreach ($methodRoutes as $route) {
                if (self::matchRoute($route, $url) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function handleNotFound(string $controllerBaseNamespace): void
    {
        $controller = $controllerBaseNamespace . self::$templateController . '\\NotFoundController';

        if (!class_exists($controller)) {
            $controller = self::$prefixController . ucfirst(Config::get('routing.default_system_content', 'App')) . '\\' . self::$templateController . '\\NotFoundController';
        }

        $controllerInstance = new $controller();
        $controllerInstance->index(new Request(), new Response(), []);
    }

    public static function getSubdomainHost(): string
    {
        $host = filter_var($_SERVER['HTTP_HOST'] ?? '', FILTER_SANITIZE_URL);
        $domain = filter_var($_SERVER['DEFAULT_DOMINIO'] ?? Config::get('app.domain', ''), FILTER_SANITIZE_URL);
        $host = preg_replace('/^www./', '', $host);
        $domain = preg_replace('/^www./', '', $domain);

        $host = str_replace('.' . $domain, '', $host);

        return ($host == $domain) ? Config::get('routing.default_system_content', 'App') . '/' : "{$host}/";
    }

    private static function handleMethodNotAllowed(array $routes, string $url): void
    {
        $allowedMethods = [];

        foreach ($routes as $method => $methodRoutes) {
            foreach ($methodRoutes as $route) {
                if (self::matchRoute($route, $url) !== null) {
                    $allowedMethods[] = $method;
                    break;
                }
            }
        }

        $allowedMethods = array_values(array_unique($allowedMethods));
        if (!empty($allowedMethods)) {
            header('Allow: ' . implode(', ', $allowedMethods));
        }

        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Method not allowed',
            'allowed_methods' => $allowedMethods,
        ], 405);
    }

    private static function handleControllerNotFound(): void
    {
        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Controller not found',
        ], 404);
    }

    private static function handleActionMethodNotFound(): void
    {
        Response::json([
            'success' => false,
            'error' => true,
            'message' => 'Action not found',
        ], 404);
    }
}
