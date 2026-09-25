<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Requisição HTTP. Os controllers leem entrada somente por aqui (nunca $_POST/$_GET).
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $server
     * @param array<string, mixed>  $files  formato de $_FILES ou listas de UploadedFile (testes)
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $cookies = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizePath((string) parse_url($uri, PHP_URL_PATH), $basePath),
            $_GET,
            $_POST,
            $_COOKIE,
            $_SERVER,
            $_FILES,
            // Corpo bruto só para JSON (webhooks); formulários usam $_POST. Limite de 1 MB.
            str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')
                ? (string) file_get_contents('php://input', false, null, 0, 1024 * 1024)
                : '',
        );
    }

    /** Corpo bruto da requisição (JSON de webhooks). */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** Remove o caminho base e a barra final: "/gnesting/entrar/" → "/entrar". */
    public static function normalizePath(string $path, string $basePath = ''): string
    {
        $path = '/' . ltrim(rawurldecode($path), '/');
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $path = '/' . trim($path, '/');

        return $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Texto da query string (filtros, busca), limpo e limitado. */
    public function queryString(string $key, int $maxLength = 100): string
    {
        $value = $this->query[$key] ?? null;
        if (!is_scalar($value)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value)), 0, $maxLength);
    }

    public function queryInt(string $key, int $default = 0): int
    {
        $value = filter_var($this->query[$key] ?? null, FILTER_VALIDATE_INT);

        return $value === false ? $default : $value;
    }

    /** Checkbox de formulário: presente e diferente de "0" = marcado. */
    public function boolean(string $key): bool
    {
        $value = $this->body[$key] ?? null;

        return is_scalar($value) && !in_array((string) $value, ['', '0'], true);
    }

    /**
     * Arquivos enviados no campo (aceita <input type="file" multiple>).
     *
     * @return list<UploadedFile>
     */
    public function files(string $key): array
    {
        $entry = $this->files[$key] ?? null;
        if ($entry === null) {
            return [];
        }
        if ($entry instanceof UploadedFile) {
            return [$entry];
        }
        if (is_array($entry) && isset($entry[0]) && $entry[0] instanceof UploadedFile) {
            return array_values($entry);
        }
        if (!is_array($entry) || !isset($entry['tmp_name'], $entry['error'])) {
            return [];
        }

        $files = [];
        foreach ((array) $entry['tmp_name'] as $index => $tmp) {
            if (!is_string($tmp)) {
                continue; // estruturas aninhadas não são aceitas
            }
            $files[] = new UploadedFile(
                (string) ((array) $entry['name'])[$index],
                $tmp,
                (int) ((array) $entry['error'])[$index],
                (int) ((array) $entry['size'])[$index],
            );
        }

        return array_values(array_filter($files, static fn (UploadedFile $f) => $f->wasSent()));
    }

    public function contentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Texto do corpo (POST): ignora arrays (evita injeção de tipos),
     * remove caracteres de controle e espaços nas pontas.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value));
    }

    /**
     * Senhas: sem trim nem remoção de caracteres (o usuário pode usar espaços).
     */
    public function secret(string $key): string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    public function cookie(string $key): ?string
    {
        $value = $this->cookies[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        // Somente REMOTE_ADDR: cabeçalhos X-Forwarded-For podem ser forjados.
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return ($https !== '' && strtolower((string) $https) !== 'off')
            || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    public function expectsJson(): bool
    {
        return str_contains((string) $this->header('Accept'), 'application/json')
            || $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->routeParams[$name] ?? $default;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function attribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }
}
