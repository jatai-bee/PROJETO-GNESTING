<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Sessão segura.
 * - Cookie HttpOnly, SameSite=Lax, Secure (produção), modo estrito
 * - Arquivos em storage/sessions (fora do webroot)
 * - Expiração por inatividade
 * - Mensagens "flash" (valem para a próxima requisição)
 *
 * Em CLI (testes) funciona em memória, sem enviar cookies.
 */
final class Session
{
    private const FLASH_NEW = '_flash.new';
    private const FLASH_OLD = '_flash.old';
    private const LAST_ACTIVITY = '_last_activity';

    /** @var array<string, mixed> */
    private array $memory = [];

    private bool $started = false;

    /**
     * @param array{name: string, lifetime_minutes: int, secure_cookie: bool} $config
     */
    public function __construct(
        private readonly array $config,
        private readonly string $savePath,
        private readonly string $cookiePath = '/',
        private readonly bool $native = true,
    ) {
    }

    public function start(): void
    {
        // Em memória (testes), cada requisição simulada chama start() de novo
        // e os dados persistem entre elas, como um navegador com cookie.
        if ($this->started && $this->native) {
            return;
        }

        if ($this->native && session_status() !== PHP_SESSION_ACTIVE) {
            $this->startNative();
        }
        $this->started = true;

        $data = &$this->data();
        $now = time();
        $last = $data[self::LAST_ACTIVITY] ?? null;
        if (is_int($last) && ($now - $last) > $this->config['lifetime_minutes'] * 60) {
            $data = [];
            $this->regenerate();
        }
        $data[self::LAST_ACTIVITY] = $now;

        // Envelhece o flash: o que foi gravado na requisição anterior fica legível agora.
        $data[self::FLASH_OLD] = $data[self::FLASH_NEW] ?? [];
        $data[self::FLASH_NEW] = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $data = &$this->data();
        $data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data());
    }

    public function remove(string $key): void
    {
        $data = &$this->data();
        unset($data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /** Grava um valor disponível apenas na próxima requisição. */
    public function flash(string $key, mixed $value): void
    {
        $data = &$this->data();
        $data[self::FLASH_NEW][$key] = $value;
    }

    public function getFlash(string $key, mixed $default = null): mixed
    {
        $data = $this->data();

        return $data[self::FLASH_OLD][$key] ?? $data[self::FLASH_NEW][$key] ?? $default;
    }

    /** Troca o ID da sessão (login, logout, mudança de privilégio) — evita fixação de sessão. */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** @return array<string, mixed> */
    private function &data(): array
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            return $_SESSION;
        }

        return $this->memory;
    }

    private function startNative(): void
    {
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0770, true);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ($this->config['lifetime_minutes'] * 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_save_path($this->savePath);
        session_name($this->config['name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->config['secure_cookie'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
