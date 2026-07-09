<?php

namespace App\Http;

class Route
{
    private static array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
        'PATCH' => [],
        'HEAD' => [],
    ];

    private static array $groupStack = [];

    public static function get(string $path, $action, array $options = []): void
    {
        self::addRoute('GET', $path, $action, $options);
    }

    public static function post(string $path, $action, array $options = []): void
    {
        self::addRoute('POST', $path, $action, $options);
    }

    public static function put(string $path, $action, array $options = []): void
    {
        self::addRoute('PUT', $path, $action, $options);
    }

    public static function delete(string $path, $action, array $options = []): void
    {
        self::addRoute('DELETE', $path, $action, $options);
    }

    public static function patch(string $path, $action, array $options = []): void
    {
        self::addRoute('PATCH', $path, $action, $options);
    }

    public static function head(string $path, string $action, array $options = []): void
    {
        self::addRoute('HEAD', $path, $action, $options);
    }

    public static function any(string $path, $action, array $options = []): void
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            self::addRoute($method, $path, $action, $options);
        }
    }

    public static function match(array $methods, string $path, $action, array $options = []): void
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper((string) $method), $path, $action, $options);
        }
    }

    public static function redirect(string $from, string $to, int $status = 301): void
    {
        self::addRoute('GET', $from, null, ['redirect' => $to, 'status' => $status]);
    }

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

    public static function group(array $attributes, callable $callback): void
    {
        self::$groupStack[] = [
            'prefix' => isset($attributes['prefix']) ? '/' . trim((string) $attributes['prefix'], '/') : '',
            'middleware' => (array) ($attributes['middleware'] ?? []),
        ];

        try {
            $callback();
        } finally {
            array_pop(self::$groupStack);
        }
    }

    public static function url(string $name, array $params = []): string
    {
        foreach (self::$routes as $method) {
            foreach ($method as $route) {
                if (isset($route['name']) && $route['name'] === $name) {
                    return self::replaceParams($route['path'], $params);
                }
            }
        }

        return '';
    }

    public static function name(string $name): ?string
    {
        foreach (self::$routes as $paths) {
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
        foreach (self::$routes as &$methodRoutes) {
            $methodRoutes = [];
        }

        self::$groupStack = [];
    }

    public static function cacheToFile(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $export = var_export(self::$routes, true);
        file_put_contents($filePath, "<?php\n\nreturn " . $export . ";\n");
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
        self::$groupStack = [];

        return true;
    }

    private static function addRoute(string $method, string $path, $action, array $options = []): void
    {
        [$path, $options] = self::applyGroupAttributes($path, $options);
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        self::$routes[$method][$path] = [
            'method' => $method,
            'path' => $path,
            'action' => $action,
            'options' => $options,
            'name' => $options['as'] ?? null,
        ];
    }

    private static function applyGroupAttributes(string $path, array $options): array
    {
        $prefix = '';
        $groupMiddleware = [];

        foreach (self::$groupStack as $group) {
            $prefix .= '/' . trim((string) $group['prefix'], '/');
            $groupMiddleware = array_merge($groupMiddleware, (array) $group['middleware']);
        }

        if ($prefix !== '') {
            $path = rtrim($prefix, '/') . '/' . ltrim($path, '/');
        }

        if ($groupMiddleware !== []) {
            $options['middleware'] = array_merge(
                $groupMiddleware,
                (array) ($options['middleware'] ?? [])
            );
        }

        return [$path, $options];
    }

    private static function replaceParams(string $path, array $params): string
    {
        foreach ($params as $key => $value) {
            $path = preg_replace('/\{' . preg_quote((string) $key, '/') . '(?::[^}]+)?\}/', rawurlencode((string) $value), $path);
        }

        return $path;
    }
}
