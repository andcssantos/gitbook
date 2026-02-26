<?php

namespace App\Http;

class Route
{
    private static $routes = [
        'GET'    => [],
        'POST'   => [],
        'PUT'    => [],
        'DELETE' => [],
        'PATCH'  => [],
        'HEAD'   => []
    ];

    /**
     * Registra uma rota do tipo GET.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function get(string $path, $action, array $options = []): void
    {
        self::addRoute('GET', $path, $action, $options);
    }

    /**
     * Registra uma rota do tipo POST.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function post(string $path, $action, array $options = []): void
    {
        self::addRoute('POST', $path, $action, $options);
    }

    /**
     * Registra uma rota do tipo PUT.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function put(string $path, $action, array $options = []): void
    {
        self::addRoute('PUT', $path, $action, $options);
    }

    /**
     * Registra uma rota do tipo DELETE.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function delete(string $path, $action, array $options = []): void
    {
        self::addRoute('DELETE', $path, $action, $options);
    }

    /**
     * Registra uma rota do tipo PATCH.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function patch(string $path, $action, array $options = []): void
    {
        self::addRoute('PATCH', $path, $action, $options);
    }

    /**
     * Registra uma rota para o verbo HEAD.
     *
     * @param string $path
     * @param string $action
     * @param array $options
     */
    public static function head(string $path, string $action, array $options = []): void
    {
        self::addRoute('HEAD', $path, $action, $options);
    }

    /**
     * Registra uma rota para múltiplos métodos HTTP.
     *
     * @param string $path
     * @param string|callable $action
     * @param array $options
     * @return void
     */
    public static function any(string $path, $action, array $options = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            self::addRoute($method, $path, $action, $options);
        }
    }

    /**
     * Registra uma rota para múltiplos métodos.
     * Route::match(['GET', 'POST'], '/search', 'SearchController@results');
     */
    public static function match(array $methods, string $path, $action, array $options = []): void
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $path, $action, $options);
        }
    }

    /**
     * Registra uma rota de redirecionamento direto.
     * Route::redirect('/login-antigo', '/login')
     */
    public static function redirect(string $from, string $to, int $status = 301): void
    {
        self::addRoute('GET', $from, null, ['redirect' => $to, 'status' => $status]);
    }

    /**
     * Registra um conjunto de rotas para CRUD (Resource).
     * Route::resource('capitulo', 'ChapterController');
     */
    public static function resource(string $path, string $controller, array $options = []): void
    {
        $path = trim($path, '/');
        $name = $options['as'] ?? $path;

        self::get("/$path", "$controller@index", array_merge($options, ['as' => "$name.index"]));
        self::get("/$path/create", "$controller@create", array_merge($options, ['as' => "$name.create"]));
        self::post("/$path", "$controller@store", array_merge($options, ['as' => "$name.store"]));
        self::get("/$path/{id:int}", "$controller@show", array_merge($options, ['as' => "$name.show"]));
        self::get("/$path/{id:int}/edit", "$controller@edit", array_merge($options, ['as' => "$name.edit"]));
        self::put("/$path/{id:int}", "$controller@update", array_merge($options, ['as' => "$name.update"]));
        self::delete("/$path/{id:int}", "$controller@destroy", array_merge($options, ['as' => "$name.destroy"]));
    }

    /**
     * Método group :: Permite agrupar rotas que compartilham os mesmos atributos (como prefixos de URL ou middleware).
     * Exemplo:
     *
     *  Route::group(['prefix' => 'admin'], function () {
     *      Route::post('/register', 'AdminController@register');
     *  });
     *
     * No ajax será chamado: '/admin/register'
     */
    public static function group(array $attributes, callable $callback): void
    {
        $prefix = isset($attributes['prefix']) ? '/' . trim($attributes['prefix'], '/') : '';
        $middleware = (array) ($attributes['middleware'] ?? []);

        // Snapshot do estado atual para identificar novas rotas
        $beforeGroup = self::$routes;

        $callback();

        // Itera sobre todos os verbos para aplicar as transformações do grupo
        foreach (self::$routes as $method => &$methodRoutes) {
            $oldKeys = array_keys($beforeGroup[$method]);
            
            foreach ($methodRoutes as $path => &$details) {
                // Se a rota foi adicionada durante a execução do callback
                if (!in_array($path, $oldKeys)) {
                    $newPath = rtrim($prefix, '/') . '/' . ltrim($path, '/');
                    $newPath = ($newPath === '') ? '/' : $newPath;

                    // Atualiza o path e anexa middlewares
                    $details['path'] = $newPath;
                    $details['options']['middleware'] = array_merge(
                        (array) ($details['options']['middleware'] ?? []),
                        $middleware
                    );

                    // Reindexa a rota se o path mudou
                    if ($newPath !== $path) {
                        $methodRoutes[$newPath] = $details;
                        unset($methodRoutes[$path]);
                    }
                }
            }
        }
    }

    /**
     * Método para definir uma rota qualquer (usado internamente)
     */
    public static function url(string $name, array $params = []): string
    {
        foreach (self::$routes as $method) {
            foreach ($method as $route) {
                // Buscamos em 'name', que é onde o addRoute salvou
                if (isset($route['name']) && $route['name'] === $name) {
                    $path = $route['path'];
                    // Futuramente, aqui você pode usar os $params para substituir {id}
                    return $path;
                }
            }
        }
        return '';
    }

    /**
     * Busca uma rota específica.
     */
    private static function addRoute(string $method, string $path, $action, array $options = [])
    {
        $path = '/' . trim($path, '/');
        
        // Se a opção 'as' (nome da rota) existir, guardamos para busca posterior
        self::$routes[strtoupper($method)][$path] = [
            'method'    => strtoupper($method),
            'path'      => $path,
            'action'    => $action,
            'options'   => $options,
            'name'      => $options['as'] ?? null 
        ];
    }

    /**
     * Route::get('/dashboard/perfil/configuracoes', 'UserController@settings', ['as' => 'user.settings']);
     */
    public static function name(string $name): ?string
    {
        foreach (self::$routes as $method => $paths) {
            foreach ($paths as $path => $data) {
                if (isset($data['name']) && $data['name'] === $name) {
                    return $path;
                }
            }
        }
        return null;
    }

    public static function findRoute(string $method, string $path)
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        return self::$routes[$method][$path] ?? null;
    }

    public static function routes(): array
    {
        return self::$routes;
    }

    public static function clearRoutes(): void
    {
        foreach (self::$routes as &$m) $m = [];
    }

    public static function cacheToFile(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $export = var_export(self::$routes, true);
        file_put_contents($filePath, "<?php

return " . $export . ";
");
    }

    public static function loadFromFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $routes = require $filePath;
        if (!is_array($routes)) {
            return false;
        }

        self::$routes = $routes;
        return true;
    }

}
