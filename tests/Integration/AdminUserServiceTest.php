<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Enums\AdminRole;
use GNesting\Services\AdminUserService;
use GNesting\Services\Auth\AuthService;
use GNesting\Services\BusinessRuleException;

final class AdminUserServiceTest extends IntegrationTestCase
{
    private AdminUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->container->get(AdminUserService::class);
    }

    public function testCannotChangeOwnRoleOrDeactivateSelf(): void
    {
        $owner = $this->service->create('Dono', 'dono@t.test', AdminRole::Owner, 'senha-muito-segura');
        // Mesmo havendo outro proprietário, ninguém rebaixa a si mesmo
        $this->service->create('Dona 2', 'dona2@t.test', AdminRole::Owner, 'senha-muito-segura');

        $this->expectException(BusinessRuleException::class);
        $this->service->update($owner, 'Dono', AdminRole::Manager, true, $owner);
    }

    public function testLastActiveOwnerCannotBeDemotedOrDeactivated(): void
    {
        $owner = $this->service->create('Único dono', 'unico@t.test', AdminRole::Owner, 'senha-muito-segura');
        $manager = $this->service->create('Gestora', 'gestora@t.test', AdminRole::Manager, 'senha-muito-segura');

        try {
            $this->service->update($owner, 'Único dono', AdminRole::Owner, false, $manager);
            self::fail('Não pode desativar o último proprietário');
        } catch (BusinessRuleException $e) {
            self::assertStringContainsString('proprietário', $e->getMessage());
        }

        // Com um segundo proprietário, a mudança passa
        $second = $this->service->create('Sócio', 'socio@t.test', AdminRole::Owner, 'senha-muito-segura');
        $this->service->update($owner, 'Único dono', AdminRole::Manager, true, $second);
        self::assertSame('manager', $this->fetchValue('SELECT role FROM admins WHERE id = :id', ['id' => $owner]));
    }

    public function testDeactivatedAdminCannotLogIn(): void
    {
        $this->service->create('Dono', 'd@t.test', AdminRole::Owner, 'senha-muito-segura');
        $support = $this->service->create('Atendimento', 'at@t.test', AdminRole::Support, 'senha-muito-segura');
        $owner = (int) $this->fetchValue("SELECT a.id FROM admins a JOIN users u ON u.id = a.user_id WHERE u.email = 'd@t.test'");

        $this->service->update($support, 'Atendimento', AdminRole::Support, false, $owner);

        $this->expectException(\GNesting\Services\Auth\AuthenticationException::class);
        $this->container->get(AuthService::class)->attemptAdmin('at@t.test', 'senha-muito-segura', '10.1.1.1');
    }

    public function testResetPasswordRequiresMinimumAndWorks(): void
    {
        $id = $this->service->create('Produção', 'prod@t.test', AdminRole::Production, 'senha-muito-segura');

        try {
            $this->service->resetPassword($id, 'curta');
            self::fail('Senha curta não pode ser aceita');
        } catch (BusinessRuleException) {
        }

        $this->service->resetPassword($id, 'nova-senha-muito-segura');
        $admin = $this->container->get(AuthService::class)->attemptAdmin('prod@t.test', 'nova-senha-muito-segura', '10.1.1.2');
        self::assertSame('production', $admin['role']);

        $audit = (string) $this->fetchValue("SELECT new_values FROM audit_logs WHERE entity_type = 'admin' AND entity_id = :id AND action = 'update'", ['id' => $id]);
        self::assertStringNotContainsString('nova-senha', $audit);
    }
}
