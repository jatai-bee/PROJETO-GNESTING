<?php

declare(strict_types=1);

namespace GNesting\Install;

use RuntimeException;

/**
 * Escrita do arquivo .env a partir do .env.example (instalador web).
 *
 * Só troca as linhas das chaves informadas: comentários e demais valores do modelo
 * continuam no arquivo, para quem abrir depois pelo Gerenciador de Arquivos.
 */
final class EnvWriter
{
    public function __construct(
        private readonly string $envFile,
        private readonly string $exampleFile,
    ) {
    }

    public function exists(): bool
    {
        return is_file($this->envFile);
    }

    public function file(): string
    {
        return $this->envFile;
    }

    /** Chave no formato do bin/generate-key.php. */
    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** @param array<string, string> $values */
    public function createFromExample(array $values): void
    {
        if (!is_file($this->exampleFile)) {
            throw new RuntimeException('O arquivo .env.example não foi encontrado na pasta da loja. Envie-o junto com os demais.');
        }
        $this->save($this->applyAll((string) file_get_contents($this->exampleFile), $values));
    }

    /** @param array<string, string> $values */
    public function applyAll(string $content, array $values): string
    {
        foreach ($values as $key => $value) {
            $content = $this->apply($content, $key, $value);
        }

        return $content;
    }

    public function apply(string $content, string $key, string $value): string
    {
        $line = $key . '=' . self::quote($value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $content) === 1) {
            // "\" e "$" são especiais no texto de substituição ("\1", "$1"): sem escapar,
            // uma senha com esses caracteres seria gravada truncada.
            return (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $content, 1);
        }

        return rtrim($content) . PHP_EOL . $line . PHP_EOL;
    }

    /**
     * Aspas só quando necessário. Dentro de aspas duplas o Dotenv interpreta "\" e "${VAR}",
     * então os dois são escapados; aspas simples não servem porque o valor pode conter "'".
     */
    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\/.\-:@+=,]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }

    private function save(string $content): void
    {
        if (@file_put_contents($this->envFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo .env. Confira a permissão de gravação na pasta da loja.');
        }
        // Guarda a senha do banco: só o dono lê. No Windows não tem efeito.
        @chmod($this->envFile, 0600);
    }
}
