<?php

namespace App\Utils\Functions;

use App\Utils\Config;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\ApiUtils;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Transformation\Effect;
use Cloudinary\Transformation\Format;
use Cloudinary\Transformation\Gravity;
use Cloudinary\Transformation\Quality;
use Cloudinary\Transformation\Resize;
use Exception;
use RuntimeException;

class CloudImage
{
    private static ?CloudImage $instance = null;
    private Cloudinary $cloudinary;
    private UploadApi $uploader;
    private AdminApi $admin;
    private CacheManager $cache;

    private function __construct()
    {
        $url = (string) Config::get('cloudinary.url', $_ENV['CLOUDINARY_URL'] ?? '');
        if ($url === '') {
            throw new RuntimeException('CLOUDINARY_URL nao configurada.');
        }

        $config = new Configuration($url);
        $this->cloudinary = new Cloudinary($config);
        $this->uploader = new UploadApi($config);
        $this->admin = new AdminApi($config);
        $this->cache = new CacheManager('cloudinary', 'long');
    }

    public static function getInstance(): CloudImage
    {
        if (self::$instance === null) {
            self::$instance = new CloudImage();
        }

        return self::$instance;
    }

    public function upload(string $filePath, array $options = []): mixed
    {
        $this->validateLocalFile($filePath, $options);

        try {
            return $this->uploader->upload($filePath, $this->uploadOptions($options));
        } catch (Exception $e) {
            throw new Exception('Erro ao fazer upload: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadBase64(string $base64Data, array $options = []): mixed
    {
        $mime = $options['mime'] ?? 'image/jpeg';
        if (!$this->isAllowedMime((string) $mime, $options)) {
            throw new Exception("Tipo de arquivo nao permitido: {$mime}");
        }

        $dataUri = "data:{$mime};base64," . preg_replace('/^data:[^;]+;base64,/', '', $base64Data);

        try {
            return $this->uploader->upload($dataUri, $this->uploadOptions($options));
        } catch (Exception $e) {
            throw new Exception('Erro no upload Base64: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadFromFiles(array $files, array $options = []): array
    {
        $results = [];
        $isMultiple = isset($files['name']) && is_array($files['name']);
        $fileCount = $isMultiple ? count($files['name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $fileData = [
                'name' => $isMultiple ? $files['name'][$i] : ($files['name'] ?? ''),
                'tmp_name' => $isMultiple ? $files['tmp_name'][$i] : ($files['tmp_name'] ?? ''),
                'error' => $isMultiple ? $files['error'][$i] : ($files['error'] ?? UPLOAD_ERR_NO_FILE),
                'type' => $isMultiple ? $files['type'][$i] : ($files['type'] ?? ''),
                'size' => $isMultiple ? $files['size'][$i] : ($files['size'] ?? 0),
            ];

            try {
                $this->validateImage($fileData, $options);
                $publicId = $this->safePublicId(pathinfo($fileData['name'], PATHINFO_FILENAME));
                $results[] = $this->upload($fileData['tmp_name'], array_merge($options, ['public_id' => $publicId]));
            } catch (Exception $e) {
                $results[] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function delete(string $publicId, array $options = []): mixed
    {
        try {
            $result = $this->uploader->destroy($publicId, $options);
            $this->cache->flushTag($this->assetTag($publicId));

            return $result;
        } catch (Exception $e) {
            throw new Exception('Erro ao deletar imagem: ' . $e->getMessage(), 0, $e);
        }
    }

    public function rename(string $fromPublicId, string $toPublicId, array $options = []): mixed
    {
        $result = $this->uploader->rename($fromPublicId, $toPublicId, $options);
        $this->cache->flushTag($this->assetTag($fromPublicId));
        $this->cache->flushTag($this->assetTag($toPublicId));

        return $result;
    }

    public function details(string $publicId, array $options = []): mixed
    {
        return $this->cache->remember(
            'details:' . md5($publicId . serialize($options)),
            fn (): mixed => $this->admin->asset($publicId, $options),
            'medium',
            ['tags' => ['cloudinary_details', $this->assetTag($publicId)]]
        );
    }

    public function list(array $options = []): mixed
    {
        return $this->cache->remember(
            'list:' . md5(serialize($options)),
            fn (): mixed => $this->admin->assets($options),
            'short',
            ['tags' => ['cloudinary_list']]
        );
    }

    public function listByTag(string $tag, array $options = []): mixed
    {
        return $this->cache->remember(
            'tag:' . md5($tag . serialize($options)),
            fn (): mixed => $this->admin->assetsByTag($tag, $options),
            'short',
            ['tags' => ['cloudinary_list', "cloudinary_tag:{$tag}"]]
        );
    }

    public function generateImageTag(string $publicId, array $options = []): string
    {
        $imageTag = $this->cloudinary->imageTag($publicId)
            ->resize($this->buildResize($this->presetOptions($options)));

        return (string) $imageTag;
    }

    public function getUrl(string $publicId, array $options = []): string
    {
        $options = $this->presetOptions($options);
        $image = $this->cloudinary->image($publicId)->resize($this->buildResize($options));

        if (!empty($options['format'])) {
            $format = $this->resolveFormat((string) $options['format']);
            if ($format !== null) {
                $image->format($format);
            }
        }

        if (!empty($options['blur'])) {
            $image->effect(Effect::blur((int) $options['blur']));
        }

        if (($options['quality'] ?? null) === 'auto' || !empty($options['quality'])) {
            $image->quality(Quality::auto());
        }

        if (!empty($options['version'])) {
            $image->version($options['version']);
        }

        return $image->toUrl();
    }

    public function responsiveSources(string $publicId, array $options = []): array
    {
        $widths = (array) ($options['widths'] ?? Config::get('cloudinary.responsive_widths', [320, 640, 960]));
        $sources = [];

        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width <= 0) {
                continue;
            }

            $sources[$width . 'w'] = $this->getUrl($publicId, array_merge($options, ['width' => $width]));
        }

        return $sources;
    }

    public function srcset(string $publicId, array $options = []): string
    {
        $parts = [];
        foreach ($this->responsiveSources($publicId, $options) as $descriptor => $url) {
            $parts[] = "{$url} {$descriptor}";
        }

        return implode(', ', $parts);
    }

    public function signedUploadParams(array $params = []): array
    {
        $params = array_merge($this->uploadOptions($params), ['timestamp' => time()]);
        ApiUtils::signRequest($params, $this->uploader->getCloud());

        return $params;
    }

    public function unsignedUpload(string $filePath, string $uploadPreset, array $options = []): mixed
    {
        $this->validateLocalFile($filePath, $options);

        return $this->uploader->unsignedUpload($filePath, $uploadPreset, $this->uploadOptions($options));
    }

    private function uploadOptions(array $options): array
    {
        $defaults = (array) Config::get('cloudinary.upload_defaults', []);
        $folder = $options['folder'] ?? Config::get('cloudinary.default_folder');

        if ($folder) {
            $defaults['folder'] = $folder;
        }

        return array_merge($defaults, $options);
    }

    private function presetOptions(array $options): array
    {
        $preset = $options['preset'] ?? null;
        if (is_string($preset)) {
            $presets = (array) Config::get('cloudinary.transformations', []);
            $options = array_merge((array) ($presets[$preset] ?? []), $options);
        }

        return $options;
    }

    private function resolveFormat(string $format): ?Format
    {
        return match (strtolower($format)) {
            'auto' => Format::auto(),
            'jpg', 'jpeg' => Format::jpg(),
            'png' => Format::png(),
            'webp' => Format::webp(),
            'gif' => Format::gif(),
            'avif' => Format::avif(),
            default => null,
        };
    }

    private function buildResize(array $options): mixed
    {
        $width = max(1, (int) ($options['width'] ?? 400));
        $height = isset($options['height']) ? max(1, (int) $options['height']) : null;
        $crop = strtolower((string) ($options['crop'] ?? 'auto'));

        $resize = match ($crop) {
            'fill' => Resize::fill()->width($width),
            'fit' => Resize::fit()->width($width),
            'crop' => Resize::crop()->width($width),
            'scale' => Resize::scale()->width($width),
            default => Resize::auto()->width($width),
        };

        if ($height !== null) {
            $resize->height($height);
        }

        $gravity = strtolower((string) ($options['gravity'] ?? 'auto'));
        $gravityMap = [
            'face' => Gravity::face(),
            'center' => Gravity::center(),
            'auto' => Gravity::auto(),
        ];

        $resize->gravity($gravityMap[$gravity] ?? Gravity::auto());

        return $resize;
    }

    private function validateImage(array $file, array $options = []): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('Erro no upload: codigo ' . ($file['error'] ?? UPLOAD_ERR_NO_FILE));
        }

        if (($file['size'] ?? 0) > ($this->maxSizeMb($options) * 1024 * 1024)) {
            throw new Exception('Arquivo excede o tamanho maximo de ' . $this->maxSizeMb($options) . 'MB.');
        }

        $this->validateLocalFile((string) $file['tmp_name'], $options);
    }

    private function validateLocalFile(string $filePath, array $options = []): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new Exception("Arquivo local nao encontrado ou inacessivel: {$filePath}");
        }

        if (filesize($filePath) > ($this->maxSizeMb($options) * 1024 * 1024)) {
            throw new Exception('Arquivo excede o tamanho maximo de ' . $this->maxSizeMb($options) . 'MB.');
        }

        $mime = $this->mimeType($filePath);
        if (!$this->isAllowedMime($mime, $options)) {
            throw new Exception("Tipo de arquivo nao permitido: {$mime}");
        }

        if (!$this->isImage($filePath)) {
            throw new Exception('Arquivo local nao e uma imagem valida.');
        }
    }

    private function mimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return $finfo->file($filePath) ?: 'application/octet-stream';
    }

    private function isAllowedMime(string $mime, array $options = []): bool
    {
        $allowed = (array) ($options['allowed_types'] ?? Config::get('cloudinary.allowed_types', []));

        return in_array($mime, $allowed, true);
    }

    private function maxSizeMb(array $options = []): int
    {
        return max(1, (int) ($options['max_size_mb'] ?? Config::get('cloudinary.max_size_mb', 5)));
    }

    private function isImage(string $filePath): bool
    {
        return getimagesize($filePath) !== false;
    }

    private function safePublicId(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-\/]/', '_', $name);
        $name = trim((string) $name, '/_');

        return $name !== '' ? $name : 'asset_' . bin2hex(random_bytes(8));
    }

    private function assetTag(string $publicId): string
    {
        return 'cloudinary_asset:' . hash('sha256', $publicId);
    }

    public function rawCloudinary(): Cloudinary
    {
        return $this->cloudinary;
    }

    public function rawUploader(): UploadApi
    {
        return $this->uploader;
    }

    public function rawAdmin(): AdminApi
    {
        return $this->admin;
    }
}
