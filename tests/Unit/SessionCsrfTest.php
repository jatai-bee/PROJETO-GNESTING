<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Csrf;
use GNesting\Core\Session;
use PHPUnit\Framework\TestCase;

final class SessionCsrfTest extends TestCase
{
    private function session(): Session
    {
        $session = new Session(
            ['name' => 'test', 'lifetime_minutes' => 120, 'secure_cookie' => false],
            sys_get_temp_dir(),
            '/',
            false,
        );
        $session->start();

        return $session;
    }

    public function testCsrfTokenIsStableAndValidatedInConstantTime(): void
    {
        $csrf = new Csrf($this->session());
        $token = $csrf->token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->validate($token));
        self::assertFalse($csrf->validate(''));
        self::assertFalse($csrf->validate(null));
        self::assertFalse($csrf->validate(str_repeat('0', 64)));
    }

    public function testCsrfRegenerateInvalidatesOldToken(): void
    {
        $csrf = new Csrf($this->session());
        $old = $csrf->token();
        $csrf->regenerate();

        self::assertFalse($csrf->validate($old));
        self::assertNotSame($old, $csrf->token());
    }

    public function testFlashLastsExactlyOneRequest(): void
    {
        $session = $this->session();
        $session->flash('success', 'Salvo!');
        self::assertSame('Salvo!', $session->getFlash('success'));

        $session->start(); // próxima requisição
        self::assertSame('Salvo!', $session->getFlash('success'));

        $session->start(); // requisição seguinte
        self::assertNull($session->getFlash('success'));
    }
}
