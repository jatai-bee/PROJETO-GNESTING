<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

/** Conexão SMTP (interface para testar o protocolo sem servidor). */
interface SmtpTransport
{
    /** @throws \RuntimeException */
    public function open(string $host, int $port, bool $implicitTls, int $timeout): void;

    public function write(string $line): void;

    /** Resposta completa (inclui linhas "250-..." de continuação). */
    public function read(): string;

    /** @throws \RuntimeException */
    public function startTls(): void;

    public function close(): void;
}
