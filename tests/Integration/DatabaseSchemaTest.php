<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use PDOException;

final class DatabaseSchemaTest extends IntegrationTestCase
{
    public function testAllTablesExist(): void
    {
        $count = $this->fetchValue(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        );

        self::assertSame(43, (int) $count); // 38 (001) + order_notes (003) + production_jobs, production_job_events, material_movements (004) + demo_records (006)
    }

    public function testConnectionUsesUtcAndStrictMode(): void
    {
        self::assertSame('+00:00', $this->fetchValue('SELECT @@session.time_zone'));
        self::assertStringContainsString('STRICT_ALL_TABLES', (string) $this->fetchValue('SELECT @@session.sql_mode'));
    }

    public function testReferenceProductFromSeed(): void
    {
        $row = $this->db->pdo()->query(
            "SELECT v.price_cents, r.max_length, r.price_delta_cents,
                    (SELECT SUM(estimated_minutes) FROM production_spec_steps s WHERE s.spec_id = ps.id) AS total,
                    (SELECT SUM(estimated_minutes) FROM production_spec_steps s WHERE s.spec_id = ps.id AND s.is_passive = 0) AS operador,
                    p.name
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
               JOIN personalization_rules r ON r.product_id = p.id
               JOIN production_specs ps ON ps.variant_id = v.id
              WHERE v.sku = 'REL-GEO-001'"
        )->fetch();

        self::assertSame(12990, $row['price_cents']);
        self::assertSame(20, $row['max_length']);
        self::assertSame(1500, $row['price_delta_cents']);
        self::assertSame(126, (int) $row['total']);
        self::assertSame(66, (int) $row['operador']);
        self::assertSame('Relógio Geométrico G-Nesting', $row['name']);
    }

    public function testCheckConstraintRejectsInconsistentOrderTotal(): void
    {
        // MySQL 5.7 aceita as restrições CHECK mas não as aplica (o CI roda também em 5.7)
        $version = (string) $this->db->pdo()->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') === false && version_compare((string) preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $version), '8.0.16', '<')) {
            self::markTestSkipped("MySQL {$version} não aplica restrições CHECK.");
        }
        $this->expectException(PDOException::class);
        $this->db->pdo()->exec(
            "INSERT INTO customers (name, email) VALUES ('Teste', 't@t.com')"
        );
        $this->db->pdo()->exec(
            "INSERT INTO orders (number, customer_id, subtotal_cents, discount_cents, shipping_cents, total_cents,
                                 customer_name, customer_email, ship_recipient, ship_zip_code, ship_street, ship_number,
                                 ship_district, ship_city, ship_state)
             VALUES ('GN-TESTE', LAST_INSERT_ID(), 10000, 0, 1500, 9999,
                     'Teste', 't@t.com', 'Teste', '01001000', 'Rua', '1', 'Centro', 'São Paulo', 'SP')"
        );
    }
}
