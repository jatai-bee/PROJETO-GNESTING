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
}
