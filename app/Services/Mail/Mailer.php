<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

/**
 * Envio de e-mail transacional (texto simples).
 * Drivers: "log" (desenvolvimento: grava em storage/logs/mail-AAAA-MM-DD.log) e
 * "mail" (função mail() da hospedagem). SMTP autenticado entra na etapa 8.
 */
interface Mailer
{
    /** @return bool false = não enviado (o chamador decide se isso é grave) */
    public function send(string $to, string $subject, string $body): bool;
}
