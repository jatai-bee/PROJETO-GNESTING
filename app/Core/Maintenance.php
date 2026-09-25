<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Modo manutenção (bin/maintenance.php on|off). Enquanto ligado, a loja e o painel respondem 503
 * com Retry-After, inclusive os webhooks: o Mercado Pago reenvia depois, nada se perde.
 *
 * Quem tem o segredo entra mesmo assim: abrir qualquer página com ?manutencao=SEGREDO grava um
 * cookie de passagem (só o hash do segredo fica no arquivo e no cookie).
 */
final class Maintenance
{
    public const BYPASS_COOKIE = 'gn_manutencao';
    public const BYPASS_QUERY = 'manutencao';

    public function __construct(private readonly string $file)
    {
    }

    /** Liga e devolve o segredo de passagem (gerado se não for informado). */
    public function enable(string $message = '', ?string $secret = null): string
    {
        $secret ??= bin2hex(random_bytes(12));
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($this->file, (string) json_encode([
            'since' => gmdate('Y-m-d H:i:s'),
            'message' => $message,
            'secret_hash' => hash('sha256', $secret),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $secret;
    }

    public function disable(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    /** @return array{since: string, message: string, secret_hash: string}|null */
    public function status(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->file), true);

        return is_array($data) ? $data + ['since' => '', 'message' => '', 'secret_hash' => ''] : null;
    }

    /**
     * null = segue normalmente. Resposta = passagem liberada (redireciona gravando o cookie).
     *
     * @throws HttpException 503 enquanto em manutenção
     */
    public function check(Request $request): ?Response
    {
        $status = $this->status();
        // /saude segue respondendo (status "atencao"): deploy planejado não é queda para o monitor
        if ($status === null || $request->path() === '/saude') {
            return null;
        }
        $hash = $status['secret_hash'];

        $offered = $request->queryString(self::BYPASS_QUERY, 100);
        if ($offered !== '' && $hash !== '' && hash_equals($hash, hash('sha256', $offered))) {
            return Response::redirect(url($request->path()))
                ->withCookie(self::BYPASS_COOKIE, $hash, ['httponly' => true, 'samesite' => 'Lax', 'secure' => $request->isSecure()]);
        }
        $cookie = (string) $request->cookie(self::BYPASS_COOKIE);
        if ($cookie !== '' && $hash !== '' && hash_equals($hash, $cookie)) {
            return null;
        }

        throw HttpException::maintenance($status['message']);
    }
}
