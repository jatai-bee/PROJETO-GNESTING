<?php

declare(strict_types=1);

namespace GNesting\Core;

use ErrorException;
use Throwable;

/**
 * Tratamento seguro de erros:
 * - erros do PHP viram exceções
 * - erros inesperados são registrados em storage/logs com um código curto
 * - o usuário vê uma página da marca, sem stack trace, SQL ou caminhos
 *   (detalhes técnicos só com APP_DEBUG=true fora de produção)
 */
final class ErrorHandler
{
    private const TEMPLATES = [403, 404, 405, 419, 429, 500];

    public function __construct(
        private readonly Logger $logger,
        private readonly View $view,
        private readonly Config $config,
        private readonly Container $container,
    ) {
    }

    public function register(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $id = $this->newErrorId();
            $this->logger->exception($e, $id);
            $this->emitFallback($id);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $id = $this->newErrorId();
                $this->logger->error("[{$id}] Erro fatal: {$error['message']}", ['file' => $error['file'] . ':' . $error['line']]);
                $this->emitFallback($id);
            }
        });
    }

    public function toResponse(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->validationResponse($e, $request);
        }

        if ($e instanceof HttpException) {
            $response = $this->render($e->status(), $e->getMessage(), $request);
            foreach ($e->headers() as $name => $value) {
                $response->withHeader($name, $value);
            }

            return $response;
        }

        $id = $this->newErrorId();
        $this->logger->exception($e, $id, ['method' => $request->method(), 'path' => $request->path(), 'ip' => $request->ip()]);

        return $this->render(500, 'Algo deu errado do nosso lado. Já registramos o problema.', $request, $id, $this->showDetails() ? $e : null);
    }

    private function validationResponse(ValidationException $e, Request $request): Response
    {
        if ($request->expectsJson()) {
            return Response::json(['message' => 'Dados inválidos.', 'errors' => $e->errors()], 422);
        }

        // Devolve ao formulário tudo o que foi digitado, exceto senhas e o token.
        $old = $e->old();
        foreach ($request->all() as $key => $value) {
            if (is_string($key) && is_scalar($value) && !str_contains($key, 'password') && $key !== '_token') {
                $old[$key] ??= (string) $value;
            }
        }

        $session = $this->container->get(Session::class);
        $session->flash('errors', $e->errors());
        $session->flash('old', $old);

        return Response::redirect(url($request->path()));
    }

    private function render(int $status, string $message, Request $request, ?string $errorId = null, ?Throwable $exception = null): Response
    {
        if ($request->expectsJson()) {
            return Response::json(array_filter(['message' => $message, 'error_id' => $errorId]), $status);
        }

        $template = in_array($status, self::TEMPLATES, true) ? "errors/{$status}" : 'errors/500';

        try {
            $html = $this->view->render($template, [
                'status' => $status,
                'message' => $message,
                'errorId' => $errorId,
                'exception' => $exception,
            ], 'error');
        } catch (Throwable $renderError) {
            $this->logger->exception($renderError, $errorId ?? $this->newErrorId());
            $html = $this->plainPage($status, $message, $errorId);
        }

        return Response::html($html, $status);
    }

    private function showDetails(): bool
    {
        return (bool) $this->config->get('app.debug') && $this->config->get('app.env') !== 'production';
    }

    private function emitFallback(string $id): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Erro inesperado [{$id}]. Veja storage/logs.\n");

            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo $this->plainPage(500, 'Algo deu errado do nosso lado. Já registramos o problema.', $id);
    }

    private function plainPage(int $status, string $message, ?string $errorId): string
    {
        $code = $errorId !== null ? '<p>Código: ' . e($errorId) . '</p>' : '';

        return '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>G-Nesting</title>'
            . '<h1>' . $status . '</h1><p>' . e($message) . '</p>' . $code . '</html>';
    }

    private function newErrorId(): string
    {
        return strtoupper(bin2hex(random_bytes(3)));
    }
}
