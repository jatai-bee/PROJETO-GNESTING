<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Paginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyAndPaginatorTest extends TestCase
{
    /** @return iterable<string, array{string, ?int}> */
    public static function moneyCases(): iterable
    {
        yield 'vírgula decimal' => ['129,90', 12990];
        yield 'milhar e decimal' => ['1.234,56', 123456];
        yield 'com R$' => ['R$ 15,00', 1500];
        yield 'uma casa decimal' => ['15,5', 1550];
        yield 'inteiro' => ['15', 1500];
        yield 'ponto decimal' => ['129.90', 12990];
        yield 'ponto de milhar' => ['1.234', 123400];
        yield 'zero' => ['0,00', 0];
        yield 'negativo' => ['-10,00', null];
        yield 'texto' => ['abc', null];
        yield 'três decimais' => ['1,999', null];
        yield 'milhar mal formado' => ['12.34,00', null];
        yield 'vazio' => ['', null];
    }

    #[DataProvider('moneyCases')]
    public function testParseMoney(string $input, ?int $expected): void
    {
        self::assertSame($expected, parse_money($input));
    }

    public function testMoneyInputRoundTrip(): void
    {
        self::assertSame('129,90', money_input(12990));
        self::assertSame('0,05', money_input(5));
        self::assertSame('', money_input(null));
        self::assertSame(12990, parse_money(money_input(12990)));
    }

    public function testPaginatorClampsPageAndComputesOffsets(): void
    {
        $p = new Paginator(45, 9, 20);
        self::assertSame(3, $p->lastPage);
        self::assertSame(3, $p->page);
        self::assertSame(40, $p->offset());
        self::assertSame(41, $p->from());
        self::assertSame(45, $p->to());

        $empty = new Paginator(0, -5, 20);
        self::assertSame(1, $empty->page);
        self::assertSame(0, $empty->from());
        self::assertFalse($empty->hasPages());
    }
}
