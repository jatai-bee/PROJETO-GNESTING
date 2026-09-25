<?php

declare(strict_types=1);

namespace GNesting\Core;

final class Response
{
    /** @var array<string, array{value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    /** Arquivo enviado em send() com readfile (download), no lugar do corpo em texto. */
    private ?string $filePath = null;

    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = [],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Download de arquivo privado: sempre como anexo e octet-stream (nunca exibido
     * pelo navegador — um SVG ou PDF não roda no contexto do painel).
     */
    public static function download(string $path, string $downloadName): self
    {
        $safeName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName);
        $response = new self('', 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $safeName, rawurlencode($downloadName)),
            'Content-Length' => (string) filesize($path),
        ]);
        $response->filePath = $path;

        return $response;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    /** 303 após POST garante que o navegador faça GET no destino. */
    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Cookie enviado com setcookie() (não sobrescreve o cookie da sessão).
     *
     * @param array{expires?: int, path?: string, secure?: bool, httponly?: bool, samesite?: string} $options
     */
    public function withCookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[$name] = ['value' => $value, 'options' => $options + [
            'expires' => 0, 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax',
        ]];

        return $this;
    }

    /** @return array<string, array{value: string, options: array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', $value), true);
            }
            foreach ($this->cookies as $name => $cookie) {
                setcookie($name, $cookie['value'], $cookie['options']);
            }
        }
        if ($this->filePath !== null) {
            readfile($this->filePath);

            return;
        }
        echo $this->body;
    }
}
