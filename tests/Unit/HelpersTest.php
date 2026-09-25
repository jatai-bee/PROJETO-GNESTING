<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Bootstrap;
use GNesting\Core\Config;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Bootstrap::createContainer(dirname(__DIR__, 2));
    }

    public function testMoneyFormatsCentsWithoutFloat(): void
    {
        self::assertSame('R$ 129,90', money(12990));
        self::assertSame('R$ 0,05', money(5));
        self::assertSame('R$ 1.234.567,89', money(123456789));
        self::assertSame('-R$ 15,00', money(-1500));
    }

    public function testEscapeNeutralizesHtml(): void
    {
        self::assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', e("<script>alert('x')</script>"));
        self::assertSame('', e(null));
        self::assertSame('&quot;a&quot; &amp; b', e('"a" & b'));
    }

    public function testSlugify(): void
    {
        self::assertSame('relogio-geometrico-g-nesting', slugify('Relógio Geométrico G-Nesting'));
        self::assertSame('quadros-e-paineis', slugify('  Quadros e Painéis!! '));
        self::assertSame('mesa-cadeira', slugify('Mesa & Cadeira'));
        self::assertSame('35x35-cm', slugify('35×35 cm'));
    }

    public function testUrlRespectsBasePath(): void
    {
        self::assertSame('/entrar', url('/entrar'));

        app(Config::class)->set('app.base_path', '/gnesting');
        self::assertSame('/gnesting/entrar', url('entrar'));
        self::assertSame('/gnesting/', url('/'));
        self::assertSame('/gnesting/assets/css/app.css', asset('css/app.css'));
    }

    public function testFormatDatetimeConvertsUtcToStoreTimezone(): void
    {
        self::assertSame('23/09/2026 09:00', format_datetime('2026-09-23 12:00:00'));
        self::assertSame('', format_datetime(null));
    }

    public function testParseAndFormatDecimalWithoutFloats(): void
    {
        self::assertSame('6.00', parse_decimal('6'));
        self::assertSame('6.50', parse_decimal('6,5'));
        self::assertSame('2.75', parse_decimal('2.75'));
        self::assertSame('1234.50', parse_decimal('1.234,5'));
        self::assertSame('0.30', parse_decimal('0,3'));
        self::assertSame('0.00', parse_decimal('000'));
        foreach (['', '-1', 'abc', '1,234', '6.555', '1e3', '123456789'] as $invalid) {
            self::assertNull(parse_decimal($invalid), $invalid);
        }

        self::assertSame('6', format_decimal('6.00'));
        self::assertSame('6,5', format_decimal('6.50'));
        self::assertSame('82,25', format_decimal('82.25'));
        self::assertSame('', format_decimal(null));
    }

    public function testFormatMinutes(): void
    {
        self::assertSame('0 min', format_minutes(0));
        self::assertSame('45 min', format_minutes(45));
        self::assertSame('1 h', format_minutes(60));
        self::assertSame('2 h 06 min', format_minutes(126));
    }
}
