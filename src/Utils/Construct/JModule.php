<?php

namespace App\Utils\Construct;

class JModule
{
    private string $jsonPath;

    public function __construct(string $jsonPath)
    {
        $this->jsonPath = $jsonPath;
    }

    private function getJson(): object
    {

        if (!file_exists($this->jsonPath)) {
            throw new \Exception("The JSON file was not found in: " . $this->jsonPath);
        }

        $jsonData = json_decode(file_get_contents($this->jsonPath));

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error decoding the JSON: " . json_last_error_msg());
        }

        return $jsonData;
    }

    private function validateJsonData(object $data): object
    {
     
        $defaultSeo = (object)[
            'title'     => $data->title ?? $_ENV['DEFAULT_TITLE'],
            'favicon'   => 'favicon.ico',
            'meta'      => []
        ];
       
        return (object)[
            'name'      => $data->name ?? $_ENV['DEFAULT_TITLE'],
            'version'   => $data->version ?? $_ENV['DEFAULT_VERSION'],
            'descript'  => $data->description ?? "Sem Descrição",
            'lang'      => $data->lang ?? $_ENV['DEFAULT_LANG'],
            'charset'   => $data->charset ?? $_ENV['DEFAULT_CHARSET'],
            'route'     => $data->route ?? ['Início'],
            'seo'       => $this->validateSeoData($data->seo ?? $defaultSeo),
            'plugins'   => $data->plugins ?? [],
            'styles'    => $data->styles ?? []
        ];
    }

    private function validateSeoData(object $seo): object
    {
        $defaultMeta = (object)[
            'name'    => 'viewport',
            'content' => 'width=device-width, initial-scale=1.0'
        ];

        $hasViewport = false;
        if (isset($seo->meta) && is_array($seo->meta)) {
            foreach ($seo->meta as $meta) {
                if ($meta->name === 'viewport') {
                    $hasViewport = true;
                    break;
                }
            }
        }

        if (!$hasViewport) {
            $seo->meta = array_merge([$defaultMeta], $seo->meta ?? []);
        }

        return (object)[
            'title'   => $seo->title ?? $_ENV['DEFAULT_TITLE'],
            'favicon' => $seo->favicon ?? 'favicon.ico',
            'meta'    => $seo->meta
        ];
    }

    public function loadAndValidateJson(): object
    {
        $jsonData = $this->getJson();
        return $this->validateJsonData($jsonData);
    }
}