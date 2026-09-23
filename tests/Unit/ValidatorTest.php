<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\ValidationException;
use GNesting\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private const RULES = [
        'name' => 'required|min:2|max:10',
        'email' => 'required|email',
        'password' => 'required|min:8',
        'password_confirmation' => 'required|same:password',
        'role' => 'in:owner,manager',
        'zip' => 'digits:8',
    ];

    public function testValidDataHasNoErrors(): void
    {
        $errors = Validator::errors([
            'name' => 'Maria',
            'email' => 'maria@exemplo.com',
            'password' => 'segura123',
            'password_confirmation' => 'segura123',
            'role' => 'manager',
            'zip' => '01001000',
        ], self::RULES);

        self::assertSame([], $errors);
    }

    public function testReportsFirstErrorPerFieldInPortuguese(): void
    {
        $errors = Validator::errors([
            'name' => '',
            'email' => 'maria@',
            'password' => 'curta',
            'password_confirmation' => 'outra',
            'role' => 'hacker',
            'zip' => '123',
        ], self::RULES, ['name' => 'Nome', 'password' => 'Senha']);

        self::assertSame('O campo Nome é obrigatório.', $errors['name']);
        self::assertSame('Email não é um e-mail válido.', $errors['email']);
        self::assertSame('Senha deve ter pelo menos 8 caracteres.', $errors['password']);
        self::assertStringContainsString('não confere com Senha', $errors['password_confirmation']);
        self::assertArrayHasKey('role', $errors);
        self::assertArrayHasKey('zip', $errors);
    }

    public function testOptionalEmptyFieldsSkipOtherRules(): void
    {
        self::assertSame([], Validator::errors(['zip' => ''], ['zip' => 'digits:8']));
    }

    public function testArrayInputIsRejectedAsRequired(): void
    {
        self::assertArrayHasKey('name', Validator::errors(['name' => ['x']], ['name' => 'required']));
    }

    public function testMaxCountsMultibyteCharacters(): void
    {
        self::assertSame([], Validator::errors(['name' => 'Ação Útil'], ['name' => 'max:9']));
    }

    public function testValidateThrowsWithOldInput(): void
    {
        try {
            Validator::validate(['email' => 'x', 'password' => 'y'], ['email' => 'email'], [], ['email']);
            self::fail('Deveria lançar ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['email' => 'x'], $e->old());
            self::assertArrayHasKey('email', $e->errors());
        }
    }
}
