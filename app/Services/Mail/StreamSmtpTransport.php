<?php

declare(strict_types=1);

namespace GNesting\Services\Mail;

use RuntimeException;

final class StreamSmtpTransport implements SmtpTransport
{
    /** @var resource|null */
    private $stream = null;

    public function open(string $host, int $port, bool $implicitTls, int $timeout): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @stream_socket_client(($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("Não foi possível conectar ao SMTP {$host}:{$port} ({$error}).");
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
    }

    public function write(string $line): void
    {
        if ($this->stream === null || fwrite($this->stream, $line . "\r\n") === false) {
            throw new RuntimeException('Falha ao escrever na conexão SMTP.');
        }
    }

    public function read(): string
    {
        $response = '';
        while ($this->stream !== null && ($line = fgets($this->stream, 1024)) !== false) {
            $response .= $line;
            // "250-..." continua; "250 ..." encerra
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $response;
    }

    public function startTls(): void
    {
        if ($this->stream === null || !stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('Falha ao iniciar TLS na conexão SMTP.');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
