<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

use RuntimeException;
use Throwable;

/**
 * SMTP autenticado (e-mail do cPanel ou serviço transacional), sem dependências.
 * encryption: "tls" (STARTTLS, porta 587), "ssl" (TLS implícito, porta 465) ou "none" (só local).
 * Corpo em base64: sem problemas de linhas longas, acentos ou linhas começando com ".".
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly SmtpTransport $transport,
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly ?\Closure $onError = null,
        private readonly string $heloName = 'localhost',
        private readonly int $timeout = 15,
    ) {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $to . $subject)) {
            return false;
        }

        try {
            $this->transport->open($this->host, $this->port, $this->encryption === 'ssl', $this->timeout);
            $this->expect('220');
            $this->command('EHLO ' . $this->heloName, '250');
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', '220');
                $this->transport->startTls();
                $this->command('EHLO ' . $this->heloName, '250');
            }
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', '334');
                $this->command(base64_encode($this->username), '334');
                $this->command(base64_encode($this->password), '235');
            }
            $this->command('MAIL FROM:<' . $this->fromAddress . '>', '250');
            $this->command('RCPT TO:<' . $to . '>', '25');
            $this->command('DATA', '354');
            foreach (explode("\r\n", $this->message($to, $subject, $body)) as $line) {
                $this->transport->write($line);
            }
            $this->command('.', '250');
            $this->transport->write('QUIT');

            return true;
        } catch (Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e->getMessage()); // nunca inclui a senha
            }

            return false;
        } finally {
            $this->transport->close();
        }
    }

    private function message(string $to, string $subject, string $body): string
    {
        $domain = substr((string) strrchr($this->fromAddress, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: =?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))), 76, "\r\n"));
    }

    private function command(string $line, string $expected): void
    {
        $this->transport->write($line);
        $this->expect($expected);
    }

    private function expect(string $code): void
    {
        $response = $this->transport->read();
        if (!str_starts_with($response, $code)) {
            throw new RuntimeException('SMTP respondeu: ' . trim(substr($response, 0, 200)));
        }
    }
}
