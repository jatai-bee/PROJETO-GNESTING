<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

/** Desenvolvimento/testes: o e-mail é gravado em arquivo em vez de enviado. */
final class LogMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string}> enviados nesta execução (testes) */
    private array $sent = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }
        $entry = sprintf("==== %s UTC\nPara: %s\nAssunto: %s\n\n%s\n\n", gmdate('Y-m-d H:i:s'), $to, $subject, $body);

        return file_put_contents($this->directory . '/mail-' . gmdate('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    /** @return list<array{to: string, subject: string, body: string}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
