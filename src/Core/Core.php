<?php

namespace App\Core;

use App\Core\RouteMatcher;
use App\Http\Request;
use App\Http\Response;
use App\Utils\Construct\Auth;

class Core
{
    private static string $prefixController = 'App\\Controllers\\';
    private static string $templateController = "Website";
    
    public function __construct()
    {
        $this->templateController = self::twoFactorAuth() ? $_ENV['DEFAULT_DASHBOARD_CONTENT'] : (self::checkAuth() ? $_ENV['DEFAULT_DASHBOARD_CONTENT'] : $_ENV['DEFAULT_WEBSITE_CONTENT']);
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
            return null; // Retorna null explicitamente se não estiver autenticado
        }

        // O erro ocorria aqui porque $route['path'] não existia no loop antigo
        return RouteMatcher::getParams($route['path'], $url, $queryParams ? $_GET : []);
    }

    public static function dispatch(array $routes): void
    {
        $url = self::getRequestUrl();
        $method = Request::method(); // Obtém o método atual (GET, POST, etc)
        $routeFound = false;

        $subdomain = self::getSubdomainHost();
        $controllerPrefix = self::$prefixController . $subdomain . self::$templateController . '\\';

        // AJUSTE AQUI: Acessamos apenas as rotas do método atual
        $relevantRoutes = $routes[$method] ?? [];

        foreach ($relevantRoutes as $route) {
            $params = self::matchRoute($route, $url);

            if ($params !== null) {
                $routeFound = true;

                // Verificamos se existem middlewares definidos nas opções da rota
                if (!empty($route['options']['middleware'])) {
                    \App\Http\Middleware::handle((array) $route['options']['middleware']);
                }

                // Verifica se é um redirecionamento direto
                if (isset($route['options']['redirect'])) {
                    header("Location: " . $route['options']['redirect'], true, $route['options']['status'] ?? 301);
                    exit;
                }
                
                // Extração segura do controller e action
                if (is_string($route['action']) && strpos($route['action'], '@') !== false) {
                    [$controller, $action] = explode('@', $route['action']);
                } else {
                    // Caso você use callables/closures futuramente
                    continue; 
                }

                $class = str_replace('/', '\\', "App\Controllers\\" . $controller);
                $controllerFullNamespace = $controllerPrefix . str_replace('/', '\\', $controller);

                // Verificação de existência da classe e método
                if (class_exists($class)) {
                    $controllerInstance = new $class();

                    if (method_exists($controllerInstance, $action)) {
                        $controllerInstance->$action($params);
                        return; // Rota executada com sucesso
                    } else {
                        self::handleActionMethodNotFound();
                        return;
                    }
                } else {
                    // Fallback para controllers do sistema
                    $controllerFallback = self::$prefixController . $_ENV['DEFAULT_SYSTEM_CONTENT'] . '\\' . str_replace('/', '\\', $controller);
                    
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
        }

        // Se não encontrou nenhuma rota compatível para o método informado
        if (!$routeFound) {
            self::handleNotFound($controllerPrefix);
        }
    }

    private static function handleNotFound(string $controllerPrefix): void
    {
        $controller = $controllerPrefix . self::$templateController . 'NotFoundController';

        if (!class_exists($controller)) {
            $controller = self::$prefixController . ucfirst($_ENV['DEFAULT_SYSTEM_CONTENT']) . '\\' . self::$templateController .'\\NotFoundController';
        }

        $controllerInstance = new $controller();
        $controllerInstance->index(new Request(), new Response(), []);
    }

    public static function getSubdomainHost(): string
    {
        $host       = filter_var($_SERVER['HTTP_HOST'], FILTER_SANITIZE_URL);
        $domain     = filter_var($_SERVER['DEFAULT_DOMINIO'], FILTER_SANITIZE_URL);
        $host       = preg_replace('/^www./', '', $host);
        $domain     = preg_replace('/^www./', '', $domain);

        $host = str_replace('.' . $domain, '', $host);

        return ($host == $domain) ? "{$_ENV['DEFAULT_SYSTEM_CONTENT']}/" : "{$host}/";
    }

    private static function handleMethodNotAllowed(): void
    {
        Response::json([
            "success" => false,
            "error" => true,
            "message" => "Method not allowed"
        ], 405);
    }

    private static function handleControllerNotFound(): void
    {
        Response::json([
            "success" => false,
            "error" => true,
            "message" => "Controller not found"
        ], 404);
    }

    private static function handleActionMethodNotFound(): void
    {
        Response::json([
            "success" => false,
            "error" => true,
            "message" => "Action not found"
        ], 404);
    }
}