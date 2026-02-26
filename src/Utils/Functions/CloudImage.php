<?php

namespace App\Utils\Functions;

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Transformation\Resize;
use Cloudinary\Transformation\Gravity;
use Cloudinary\Transformation\Format;
use Cloudinary\Transformation\Quality;
use Cloudinary\Transformation\Effect;
use Exception;

class CloudImage
{
    private static ?CloudImage $instance = null;
    private Cloudinary $cloudinary;
    private UploadApi $uploader;
    private AdminApi $admin;

    private function __construct()
    {
        $config = new Configuration($_ENV['CLOUDINARY_URL']);
        $this->cloudinary = new Cloudinary($config);
        $this->uploader = new UploadApi($config);
        $this->admin = new AdminApi($config);
    }

    public static function getInstance(): CloudImage
    {
        if (self::$instance === null) {
            self::$instance = new CloudImage();
        }
        return self::$instance;
    }

    /**
     * Upload de imagem a partir do caminho do arquivo.
     *
     * @throws Exception
     */
    public function upload(string $filePath, array $options = []): mixed
    {
        $this->validateLocalFile($filePath);
        try {
            return $this->uploader->upload($filePath, $options);
        } catch (Exception $e) {
            throw new Exception("Erro ao fazer upload: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Upload de imagem em base64.
     *
     * @throws Exception
     */
    public function uploadBase64(string $base64Data, array $options = []): mixed
    {
        $dataUri = "data:image/jpeg;base64," . $base64Data;
        try {
            return $this->uploader->upload($dataUri, $options);
        } catch (Exception $e) {
            throw new Exception("Erro no upload Base64: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Upload de imagens via $_FILES.
     *
     * @param array $files $_FILES['field']
     * @param array $options Opções para o upload Cloudinary
     * @return array Resultados do upload
     */
    public function uploadFromFiles(array $files, array $options = []): array
    {
        $results = [];

        $isMultiple = is_array($files['name']);
        $fileCount = $isMultiple ? count($files['name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $fileData = [
                'name' => $isMultiple ? $files['name'][$i] : $files['name'],
                'tmp_name' => $isMultiple ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error' => $isMultiple ? $files['error'][$i] : $files['error'],
                'type' => $isMultiple ? $files['type'][$i] : $files['type'],
                'size' => $isMultiple ? $files['size'][$i] : $files['size'],
            ];

            try {
                $this->validateImage($fileData);

                if ($fileData['error'] === UPLOAD_ERR_OK && $this->isImage($fileData['tmp_name'])) {
                    $publicId = pathinfo($fileData['name'], PATHINFO_FILENAME);
                    $uploadOptions = array_merge($options, ['public_id' => $publicId]);
                    $results[] = $this->upload($fileData['tmp_name'], $uploadOptions);
                } else {
                    $results[] = ['error' => 'Arquivo inválido ou com erro no upload.'];
                }
            } catch (Exception $e) {
                $results[] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Deleta imagem pelo public_id.
     *
     * @throws Exception
     */
    public function delete(string $publicId): mixed
    {
        try {
            return $this->uploader->destroy($publicId);
        } catch (Exception $e) {
            throw new Exception("Erro ao deletar imagem: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gera a tag <img> com transformação.
     */
    public function generateImageTag(string $publicId, array $options = []): string
    {
        $imageTag = $this->cloudinary->imageTag($publicId)
            ->resize($this->buildResize($options));

        return (string) $imageTag;
    }

    /**
     * Retorna URL transformada da imagem.
     */
    public function getUrl(string $publicId, array $options = []): string
    {
        $image = $this->cloudinary->image($publicId)
            ->resize($this->buildResize($options));

        if (!empty($options['format'])) {
            $format = $this->resolveFormat($options['format']);
            if ($format !== null) {
                $image->format($format);
            }
        }

        if (!empty($options['blur'])) {
            $image->effect(Effect::blur((int) $options['blur']));
        }

        if (!empty($options['quality'])) {
            $image->quality(Quality::auto());
        }

        if (!empty($options['version'])) {
            $image->version($options['version']);
        }

        return $image->toUrl();
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
        $width = $options['width'] ?? 400;
        $height = $options['height'] ?? null;
        $resize = Resize::auto()->width($width);

        if ($height !== null) {
            $resize->height($height);
        }

        $gravity = strtolower($options['gravity'] ?? 'auto');
        $gravityMap = [
            'face' => Gravity::face(),
            'center' => Gravity::center(),
            'auto' => Gravity::auto(),
        ];

        $resize->gravity($gravityMap[$gravity] ?? Gravity::auto());

        return $resize;
    }

    private function validateImage(array $file, int $maxSizeMB = 5, array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp']): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Erro no upload: código " . $file['error']);
        }

        if (!in_array($file['type'], $allowedTypes, true)) {
            throw new Exception("Tipo de arquivo não permitido: " . $file['type']);
        }

        if ($file['size'] > ($maxSizeMB * 1024 * 1024)) {
            throw new Exception("Arquivo excede o tamanho máximo de {$maxSizeMB}MB.");
        }
    }

    private function validateLocalFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new Exception("Arquivo local não encontrado ou inacessível: {$filePath}");
        }
        if (!$this->isImage($filePath)) {
            throw new Exception("Arquivo local não é uma imagem válida.");
        }
    }

    private function isImage(string $filePath): bool
    {
        return getimagesize($filePath) !== false;
    }

    // Métodos para acesso direto aos objetos Cloudinary, caso precise
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
