<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Services\Mail\SmtpMailer;
use GNesting\Services\Mail\SmtpTransport;
use PHPUnit\Framework\TestCase;

/** Protocolo SMTP com um servidor falso roteirizado (sem rede). */
final class SmtpMailerTest extends TestCase
{
    /** @param list<string> $responses */
    private function transport(array $responses): SmtpTransport
    {
        return new class ($responses) implements SmtpTransport {
            /** @var list<string> */
            public array $written = [];
            public bool $tls = false;
            public bool $closed = false;
            public ?array $opened = null;

            public function __construct(private array $responses)
            {
            }

            public function open(string $host, int $port, bool $implicitTls, int $timeout): void
            {
                $this->opened = [$host, $port, $implicitTls];
            }

            public function write(string $line): void
            {
                $this->written[] = $line;
            }

            public function read(): string
            {
                return array_shift($this->responses) ?? '';
            }

            public function startTls(): void
            {
                $this->tls = true;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
    }

    public function testSendsAuthenticatedMessageOverStartTls(): void
    {
        $transport = $this->transport([
            "220 smtp.test ESMTP\r\n", "250-smtp.test\r\n250 STARTTLS\r\n", "220 go ahead\r\n", "250-smtp.test\r\n250 AUTH LOGIN\r\n",
            "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n", "235 ok\r\n", "250 sender ok\r\n", "250 rcpt ok\r\n", "354 data\r\n", "250 queued\r\n",
        ]);
        $mailer = new SmtpMailer($transport, 'smtp.test', 587, 'tls', 'loja@gnesting.com.br', 'segredo', 'loja@gnesting.com.br', 'G-Nesting');

        self::assertTrue($mailer->send('ana@cliente.test', 'Pedido GN-2026-000001 enviado', "Olá!\n.linha com ponto\nAção"));
        self::assertSame(['smtp.test', 587, false], $transport->opened);
        self::assertTrue($transport->tls, 'STARTTLS antes de autenticar');
        self::assertSame('AUTH LOGIN', $transport->written[3]);
        self::assertSame(base64_encode('loja@gnesting.com.br'), $transport->written[4]);
        self::assertSame(base64_encode('segredo'), $transport->written[5]);
        self::assertContains('RCPT TO:<ana@cliente.test>', $transport->written);
        self::assertContains('Subject: =?UTF-8?B?' . base64_encode('Pedido GN-2026-000001 enviado') . '?=', $transport->written);
        self::assertContains('Content-Transfer-Encoding: base64', $transport->written);
        self::assertSame('QUIT', end($transport->written));
        self::assertTrue($transport->closed);

        // Corpo em base64: nenhuma linha começa com "." (fim de DATA acidental)
        $dataEnd = array_search('.', $transport->written, true);
        $bodyLines = array_slice($transport->written, array_search('', $transport->written, true) + 1, $dataEnd - array_search('', $transport->written, true) - 1);
        self::assertSame("Olá!\r\n.linha com ponto\r\nAção", base64_decode(implode('', $bodyLines)));
    }

    public function testFailureIsReportedWithoutPassword(): void
    {
        $errors = [];
        $transport = $this->transport(["220 ok\r\n", "250 ok\r\n", "334 u\r\n", "334 p\r\n", "535 5.7.8 authentication failed\r\n"]);
        $mailer = new SmtpMailer($transport, 'smtp.test', 465, 'ssl', 'user', 'senha-secreta', 'loja@x.test', 'Loja',
            function (string $e) use (&$errors): void { $errors[] = $e; });

        self::assertFalse($mailer->send('ana@cliente.test', 'Teste', 'corpo'));
        self::assertTrue($transport->opened[2], 'SSL implícito na porta 465');
        self::assertStringContainsString('535', $errors[0]);
        self::assertStringNotContainsString('senha-secreta', implode(' ', $errors));
        self::assertTrue($transport->closed);
    }

    public function testRejectsHeaderInjection(): void
    {
        $transport = $this->transport([]);
        $mailer = new SmtpMailer($transport, 'h', 25, 'none', '', '', 'a@b.test', 'Loja');

        self::assertFalse($mailer->send("ana@x.test\r\nBcc: todos@x.test", 'Oi', 'x'));
        self::assertFalse($mailer->send('ana@x.test', "Oi\r\nBcc: todos@x.test", 'x'));
        self::assertNull($transport->opened, 'Nem conecta');
    }
}
