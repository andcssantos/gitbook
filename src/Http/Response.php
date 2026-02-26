<?php

namespace App\Http;

use InvalidArgumentException;

class Response
{
    private array $headers = [];
    private mixed $content = null;
    private int $statusCode = 200;

    public const CONTENT_TYPE_JSON = 'application/json';
    public const CONTENT_TYPE_HTML = 'text/html; charset=utf-8';
    public const CONTENT_TYPE_TEXT = 'text/plain';
    public const CONTENT_TYPE_OCTET_STREAM = 'application/octet-stream';


    /**
     * Chainable method to set the content type header.
     *
     * Retorna um objeto $this para permitir a chamada encadeada de métodos.
     *
     * $response = (new Response())
     * ->setStatusCode(200)
     * ->addHeader('Content-Type', 'application/json')
     * ->setContent(['message' => 'Hello World'])
     * ->send();
     */

    /**
     * Define o conteúdo da resposta.
     */
    public function setContent(mixed $content): self
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Define o código de status HTTP.
     */
    public function setStatusCode(int $statusCode): self
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException("Código de status HTTP inválido: $statusCode");
        }
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * Adiciona um cabeçalho HTTP à resposta.
     */
    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Envia a resposta HTTP.
     */
    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        if ($this->content !== null) {
            if ($this->headers['Content-Type'] === self::CONTENT_TYPE_JSON) {
                echo json_encode($this->content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                echo $this->content;
            }
        }
    }

    /**
     * Retorna uma resposta JSON.
     *
     * Response::json(['message' => 'Operação realizada com sucesso'], 200);
     *
     */
    public static function json(mixed $data = [], int $status = 200, array $headers = []): void
    {
        (new self())
            ->setStatusCode($status)
            ->addHeader('Content-Type', self::CONTENT_TYPE_JSON)
            ->addMultipleHeaders($headers)
            ->setContent($data)
            ->send();
    }

    /**
     * Retorna uma resposta HTML.
     *
     * Response::html('<h1>Página não encontrada</h1>', 404);
     *
     */
    public static function html(string $content, int $status = 200, array $headers = []): void
    {
        (new self())
            ->setStatusCode($status)
            ->addHeader('Content-Type', self::CONTENT_TYPE_HTML)
            ->addMultipleHeaders($headers)
            ->setContent($content)
            ->send();
    }

    /**
     * Retorna uma resposta de texto simples.
     *
     * Response::text('Conteúdo simples em texto', 200);
     *
     */
    public static function text(string $content, int $status = 200, array $headers = []): void
    {
        (new self())
            ->setStatusCode($status)
            ->addHeader('Content-Type', self::CONTENT_TYPE_TEXT)
            ->addMultipleHeaders($headers)
            ->setContent($content)
            ->send();
    }

    /**
     * Redireciona para uma URL.
     *
     * Response::redirect('/login', 302);
     *
     */
    public static function redirect(string $url, int $status = 302): void
    {
        (new self())
            ->setStatusCode($status)
            ->addHeader('Location', $url)
            ->send();
    }

    /**
     * Faz download de um arquivo.
     *
     * Response::file('/caminho/para/arquivo.pdf', 'meu-arquivo.pdf');
     *
     */
    public static function file(string $filePath, string $fileName = 'null'): void
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Arquivo não encontrado: $filePath");
        }

        $fileName = $fileName ?? basename($filePath);

        (new self())
            ->setStatusCode(200)
            ->addHeader('Content-Type', self::CONTENT_TYPE_OCTET_STREAM)
            ->addHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->addHeader('Content-Length', (string)filesize($filePath))
            ->setContent(file_get_contents($filePath))
            ->send();
    }

    /**
     * Adiciona múltiplos cabeçalhos à resposta.
     */
    private function addMultipleHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->addHeader($name, $value);
        }
        return $this;
    }
}
