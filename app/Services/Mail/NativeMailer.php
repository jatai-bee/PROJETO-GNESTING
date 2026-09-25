<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

/**
 * Envio pela função mail() da hospedagem (cPanel). Cabeçalhos montados aqui,
 * sem nada vindo do usuário além do destinatário validado (evita header injection).
 */
final class NativeMailer implements Mailer
{
    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }
        $headers = [
            'From' => sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromAddress),
            'Reply-To' => $this->fromAddress,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];

        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $this->fromAddress);
    }
}
