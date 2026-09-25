<?php

declare(strict_types=1);

namespace GNesting\Core;

use Throwable;

/**
 * Log em arquivo diário: storage/logs/app-AAAA-MM-DD.log
 * Campos sensíveis do contexto são mascarados antes de gravar.
 */
final class Logger
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'senha', '_token', 'token', 'csrf',
        'card', 'card_number', 'cvv', 'secret', 'authorization', 'cookie',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $line = sprintf(
            "[%s] %s %s %s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : json_encode($this->redact($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        @file_put_contents($this->directory . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string, mixed> $context */
    public function exception(Throwable $e, string $errorId, array $context = []): void
    {
        $this->error("[{$errorId}] " . $e::class . ': ' . $e->getMessage(), $context + [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[removido]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
