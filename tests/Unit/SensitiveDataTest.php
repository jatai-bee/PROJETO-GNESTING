<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Bootstrap;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\Auth\PasswordHasher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

/**
 * Senhas nunca podem aparecer em stack traces (que vão para os logs).
 */
final class SensitiveDataTest extends TestCase
{
    public function testBootstrapRemovesArgumentsFromStackTraces(): void
    {
        Bootstrap::createContainer(dirname(__DIR__, 2));

        self::assertSame('1', ini_get('zend.exception_ignore_args'));
    }

    /** @return iterable<string, array{class-string, string}> */
    public static function passwordParameters(): iterable
    {
        yield 'attemptAdmin' => [AuthService::class, 'attemptAdmin'];
        yield 'attemptCustomer' => [AuthService::class, 'attemptCustomer'];
        yield 'registerCustomer' => [AuthService::class, 'registerCustomer'];
        yield 'createAdmin' => [AuthService::class, 'createAdmin'];
        yield 'attempt' => [AuthService::class, 'attempt'];
        yield 'AdminUserService::create' => [\GNesting\Services\AdminUserService::class, 'create'];
        yield 'AdminUserService::resetPassword' => [\GNesting\Services\AdminUserService::class, 'resetPassword'];
        yield 'hash' => [PasswordHasher::class, 'hash'];
        yield 'verify' => [PasswordHasher::class, 'verify'];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('passwordParameters')]
    public function testPasswordParametersAreMarkedSensitive(string $class, string $method): void
    {
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->getName() === 'password') {
                self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class), "{$class}::{$method}");

                return;
            }
        }
        self::fail("{$class}::{$method} não tem parâmetro \$password");
    }

    public function testSensitiveValueIsHiddenEvenWhenArgumentsAreCollected(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $fail = static function (#[SensitiveParameter] string $password): never {
                throw new RuntimeException('falha');
            };
            $fail('segredo-que-nao-pode-vazar');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('segredo-que-nao-pode-vazar', $e->getTraceAsString());
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }
}
