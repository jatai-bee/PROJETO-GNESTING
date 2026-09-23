<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Arquivo recebido por upload. O conteúdo é sempre validado pelo serviço
 * que o consome (nunca confiar no nome ou no tipo enviados pelo navegador).
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $originalName,
        private readonly string $tmpPath,
        private readonly int $error,
        private readonly int $size,
        private readonly bool $mustBeHttpUpload = true,
    ) {
    }

    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && is_file($this->tmpPath)
            && (!$this->mustBeHttpUpload || is_uploaded_file($this->tmpPath));
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o tamanho máximo permitido pelo servidor.',
            UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
            default => 'Não foi possível receber o arquivo.',
        };
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function wasSent(): bool
    {
        return $this->error !== UPLOAD_ERR_NO_FILE;
    }
}
