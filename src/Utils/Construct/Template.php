<?php

namespace App\Utils\Construct;

use App\Modules\ModuleDefinition;
use App\Modules\ModuleManager;
use App\Modules\ComponentManager;
use App\Http\Middleware;
use App\Http\Response;
use App\Utils\Config;
use App\Utils\Functions\Layout;

class Template
{
    private const BASE_PATH_TEMPLATE = __DIR__ . '/../../../template/';

    protected static string $currentModule = 'home';
    protected static string $currentDomain = 'app/';
    protected static string $currentPage = 'index.php';
    private static array $variables = [];
    private static bool $joker = false;
    private static ?ModuleDefinition $definition = null;
    private static ?ModuleManager $manager = null;

    public static function load(string $route = '/', array $args = [], string $page = 'index', bool $joker = false, bool $force = false): void
    {
        self::$joker = $joker;
        self::$currentDomain = Layout::getSubdomainHost();
        self::$currentModule = trim($route, '/') ?: 'home';
        self::$currentPage = self::getPage($page);
        self::$variables = $args;
        self::$definition = self::manager()->resolve(
            self::$currentDomain,
            self::determineRouteTemplate(),
            self::$currentModule,
            self::$currentPage
        );
        self::enforceManifest(self::$definition);

        $htmlTtl = (int) (self::$definition->manifest->cache['html'] ?? 0);
        $templatePath = self::getTemplatePath(self::determineRouteTemplate(), self::$currentDomain);
        if (
            $htmlTtl > 0
            && !(bool) (self::$definition->manifest->security['auth'] ?? false)
            && !self::checkAuth()
            && !self::templateUsesCsrf($templatePath)
        ) {
            $cache = new \App\Utils\Functions\CacheManager('modules/html', $htmlTtl);
            $manifestMtime = is_file(self::$definition->manifestPath) ? (string) filemtime(self::$definition->manifestPath) : 'missing';
            echo $cache->remember(
                'html:' . self::$definition->id() . ':' . md5((string) filemtime(self::$definition->contentPath) . $manifestMtime),
                fn (): string => self::renderTemplateToString($templatePath),
                $htmlTtl,
                ['tags' => ['modules', 'module_html', 'module:' . self::$definition->id()]]
            );
            return;
        }

        self::renderTemplate($templatePath);
    }

    public static function loadContent(): void
    {
        extract(self::$variables);
        echo '<main data-module="' . htmlspecialchars(self::moduleId(), ENT_QUOTES, 'UTF-8') . '">';
        require self::definition()->contentPath;
        echo '</main>';
    }

    public static function get(): object
    {
        return self::definition()->manifest->toObject();
    }

    public static function moduleId(): string
    {
        return self::definition()->id();
    }

    public static function meta(): string
    {
        $manifest = self::definition()->manifest;
        $seo = $manifest->seo;
        $html = [];

        foreach ((array) ($seo['meta'] ?? []) as $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $name = htmlspecialchars((string) ($meta['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $content = htmlspecialchars((string) ($meta['content'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($name !== '' && $content !== '') {
                $html[] = "<meta name=\"{$name}\" content=\"{$content}\">";
            }
        }

        if (!empty($seo['description'])) {
            $html[] = '<meta name="description" content="' . htmlspecialchars((string) $seo['description'], ENT_QUOTES, 'UTF-8') . '">';
        }

        if (!empty($seo['canonical'])) {
            $html[] = '<link rel="canonical" href="' . htmlspecialchars((string) $seo['canonical'], ENT_QUOTES, 'UTF-8') . '">';
        }

        return implode("\n", $html);
    }

    public static function title(): string
    {
        return htmlspecialchars((string) (self::definition()->manifest->seo['title'] ?? ($_ENV['DEFAULT_TITLE'] ?? 'GitBook')), ENT_QUOTES, 'UTF-8');
    }

    public static function styles(): string
    {
        $tags = [];
        foreach (self::cssAssets() as $href) {
            $tags[] = '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
        }

        return implode("\n", $tags);
    }

    public static function scripts(): string
    {
        $tags = [];
        foreach (self::jsAssets() as $asset) {
            $src = htmlspecialchars($asset['src'], ENT_QUOTES, 'UTF-8');
            $type = !empty($asset['type']) ? ' type="' . htmlspecialchars($asset['type'], ENT_QUOTES, 'UTF-8') . '"' : '';
            $defer = !empty($asset['defer']) ? ' defer' : '';
            $async = !empty($asset['async']) ? ' async' : '';
            $tags[] = "<script{$type}{$defer}{$async} src=\"{$src}\"></script>";
        }

        return implode("\n", $tags);
    }

    public static function preloads(): string
    {
        $tags = [];
        foreach ((array) self::definition()->manifest->assets['preload'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $href = htmlspecialchars((string) ($asset['href'] ?? ''), ENT_QUOTES, 'UTF-8');
            $as = htmlspecialchars((string) ($asset['as'] ?? 'fetch'), ENT_QUOTES, 'UTF-8');
            if ($href !== '') {
                $tags[] = "<link rel=\"preload\" href=\"{$href}\" as=\"{$as}\">";
            }
        }

        foreach ((array) self::definition()->manifest->assets['prefetch'] as $asset) {
            $href = htmlspecialchars(is_array($asset) ? (string) ($asset['href'] ?? '') : (string) $asset, ENT_QUOTES, 'UTF-8');
            if ($href !== '') {
                $tags[] = "<link rel=\"prefetch\" href=\"{$href}\">";
            }
        }

        return implode("\n", $tags);
    }

    public static function moduleData(array $extra = []): string
    {
        $data = array_merge(self::definition()->manifest->data, $extra);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        return '<script type="application/json" id="module-data">' . ($json ?: '{}') . '</script>';
    }

    public static function component(string $name, array $data = []): string
    {
        return (new ComponentManager())->render($name, $data);
    }

    public static function checkAuth(): bool
    {
        return Auth::check();
    }

    public static function twoFactorAuth(): bool
    {
        return Auth::twoFactor();
    }

    public static function getScript(string $module = ''): string
    {
        return self::manager()->versionedAsset(self::definition(), 'script', 'main.js') ?? '';
    }

    public static function getStyle(string $module = ''): string
    {
        return self::manager()->versionedAsset(self::definition(), 'css', 'style.css') ?? '';
    }

    public static function getLibs(string $type = 'css', array $libs = []): void
    {
        foreach ($libs as $value) {
            $lib = is_object($value) ? ($value->lib ?? '') : (is_array($value) ? ($value['lib'] ?? '') : (string) $value);
            if ($lib === '') {
                continue;
            }

            $module = is_object($value) && isset($value->module) && $value->module ? 'module' : '';
            $safeLib = htmlspecialchars($lib, ENT_QUOTES, 'UTF-8');
            echo $type === 'css'
                ? "<link href=\"assets/libs/{$safeLib}\" rel=\"stylesheet\" type=\"text/css\">\n"
                : "<script type=\"{$module}\" src=\"assets/libs/{$safeLib}\"></script>\n";
        }
    }

    public static function getBaseDirectory(): string
    {
        $route = $_GET['url'] ?? '';
        $routeParts = explode('/', (string) $route);

        return str_repeat('../', max(0, count($routeParts) - 1));
    }

    private static function cssAssets(): array
    {
        $definition = self::definition();
        $assets = [];

        foreach ((array) ($definition->manifest->dependencies['libs']['css'] ?? []) as $asset) {
            $href = self::dependencyUrl((string) $asset);
            if ($href !== null) {
                $assets[] = $href;
            }
        }

        $default = self::manager()->versionedAsset($definition, 'css', 'style.css');
        if ($default !== null) {
            $assets[] = $default;
        }

        foreach ((array) $definition->manifest->assets['css'] as $asset) {
            $file = is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset;
            $href = self::manager()->versionedAsset($definition, 'css', $file);
            if ($href !== null && !in_array($href, $assets, true)) {
                $assets[] = $href;
            }
        }

        return $assets;
    }

    private static function jsAssets(): array
    {
        $definition = self::definition();
        $assets = [];

        foreach ((array) ($definition->manifest->dependencies['libs']['js'] ?? []) as $asset) {
            $src = self::dependencyUrl(is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset);
            if ($src !== null) {
                $assets[] = [
                    'src' => $src,
                    'type' => is_array($asset) ? (string) ($asset['type'] ?? 'text/javascript') : 'text/javascript',
                    'defer' => is_array($asset) && (bool) ($asset['defer'] ?? true),
                    'async' => is_array($asset) && (bool) ($asset['async'] ?? false),
                ];
            }
        }

        $default = self::manager()->versionedAsset($definition, 'script', 'main.js');
        if ($default !== null) {
            $assets[] = ['src' => $default, 'type' => 'module', 'defer' => true, 'async' => false];
        }

        foreach ((array) $definition->manifest->assets['js'] as $asset) {
            $file = is_array($asset) ? (string) ($asset['src'] ?? '') : (string) $asset;
            $src = self::manager()->versionedAsset($definition, 'script', $file);
            if ($src === null) {
                continue;
            }

            $assets[] = [
                'src' => $src,
                'type' => is_array($asset) ? (string) ($asset['type'] ?? 'module') : 'module',
                'defer' => !is_array($asset) || (bool) ($asset['defer'] ?? true),
                'async' => is_array($asset) && (bool) ($asset['async'] ?? false),
            ];
        }

        return $assets;
    }

    private static function dependencyUrl(string $asset): ?string
    {
        $asset = trim($asset);
        if ($asset === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $asset)) {
            $host = parse_url($asset, PHP_URL_HOST);
            $allowed = (array) Config::get('modules.allowed_external_hosts', []);

            return is_string($host) && in_array($host, $allowed, true) ? $asset : null;
        }

        $asset = ltrim(str_replace('\\', '/', $asset), '/');
        if (str_contains($asset, '../')) {
            return null;
        }

        if (str_starts_with($asset, 'assets/')) {
            return $asset;
        }

        return trim((string) Config::get('modules.libs_base_url', 'assets/libs'), '/') . '/' . $asset;
    }

    private static function templateUsesCsrf(string $templatePath): bool
    {
        $contents = is_file($templatePath) ? file_get_contents($templatePath) : false;

        return is_string($contents) && str_contains($contents, 'csrf_token()');
    }

    private static function getPage(string $page): string
    {
        return $page ? "{$page}.php" : ($_ENV['MOD_INDEX_FILE'] ?? 'index.php');
    }

    private static function determineRouteTemplate(): string
    {
        if (self::$joker) {
            return $_ENV['DEFAULT_WEBSITE_CONTENT'] ?? 'website';
        }

        return self::twoFactorAuth()
            ? ($_ENV['DEFAULT_DASHBOARD_CONTENT'] ?? 'dashboard')
            : (self::checkAuth() ? ($_ENV['DEFAULT_DASHBOARD_CONTENT'] ?? 'dashboard') : ($_ENV['DEFAULT_WEBSITE_CONTENT'] ?? 'website'));
    }

    private static function getTemplatePath(string $template, string $domain): string
    {
        $baseTemplate = self::twoFactorAuth() ? ($_ENV['DEFAULT_DASHBOARD_CONTENT'] ?? 'dashboard') : $template;
        $pathTemplate = self::BASE_PATH_TEMPLATE . "{$domain}{$baseTemplate}/" . ($_ENV['MOD_INDEX_FILE'] ?? 'index.php');
        $defaultTemplate = self::BASE_PATH_TEMPLATE . ($_ENV['DEFAULT_SYSTEM_CONTENT'] ?? 'app') . "/{$baseTemplate}/" . ($_ENV['MOD_INDEX_FILE'] ?? 'index.php');

        return is_file($pathTemplate) ? $pathTemplate : $defaultTemplate;
    }

    private static function renderTemplate(string $path): void
    {
        extract(self::$variables);
        require $path;
    }

    private static function renderTemplateToString(string $path): string
    {
        ob_start();
        self::renderTemplate($path);

        return (string) ob_get_clean();
    }

    private static function enforceManifest(ModuleDefinition $definition): void
    {
        $security = $definition->manifest->security;
        $auth = (bool) ($security['auth'] ?? $definition->manifest->legacy['auth'] ?? false);

        if ($auth && !self::checkAuth()) {
            Response::json([
                'success' => false,
                'error' => true,
                'message' => 'Acesso restrito.',
            ], 401);
            exit;
        }

        $roles = (array) ($security['roles'] ?? []);
        if ($roles !== []) {
            $role = $_SESSION['user']['role'] ?? $_SESSION['user']['perfil'] ?? null;
            if (!is_string($role) || !in_array($role, $roles, true)) {
                Response::json([
                    'success' => false,
                    'error' => true,
                    'message' => 'Permissao insuficiente.',
                ], 403);
                exit;
            }
        }

        $middlewares = (array) ($security['middlewares'] ?? []);
        if ($middlewares !== []) {
            Middleware::handle($middlewares);
        }
    }

    private static function definition(): ModuleDefinition
    {
        if (self::$definition === null) {
            self::$definition = self::manager()->resolve(
                self::$currentDomain,
                self::determineRouteTemplate(),
                self::$currentModule,
                self::$currentPage
            );
        }

        return self::$definition;
    }

    private static function manager(): ModuleManager
    {
        if (self::$manager === null) {
            self::$manager = new ModuleManager();
        }

        return self::$manager;
    }
}
