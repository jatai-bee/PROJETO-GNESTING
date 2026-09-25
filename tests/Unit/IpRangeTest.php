<?php

declare(strict_types=1);

namespace GNesting\Tests\Unit;

use GNesting\Core\Request;
use GNesting\Helpers\IpRange;
use PHPUnit\Framework\TestCase;

final class IpRangeTest extends TestCase
{
    public function testExactAndCidrMatchingForIpv4AndIpv6(): void
    {
        self::assertTrue(IpRange::matches('10.0.0.5', '10.0.0.5'));
        self::assertTrue(IpRange::matches('173.245.49.10', '173.245.48.0/20'));
        self::assertFalse(IpRange::matches('173.245.64.1', '173.245.48.0/20'));
        self::assertTrue(IpRange::matches('192.168.1.200', '192.168.1.128/25'));
        self::assertFalse(IpRange::matches('192.168.1.100', '192.168.1.128/25'));
        self::assertTrue(IpRange::matches('2400:cb00::1', '2400:cb00::/32'));
        self::assertFalse(IpRange::matches('2401:cb00::1', '2400:cb00::/32'));
        self::assertTrue(IpRange::matches('8.8.8.8', '0.0.0.0/0'));

        self::assertFalse(IpRange::matches('10.0.0.5', '::/0'), 'IPv4 não casa com faixa IPv6');
        self::assertFalse(IpRange::matches('lixo', '10.0.0.0/8'));
        self::assertFalse(IpRange::matches('10.0.0.5', '10.0.0.0/33'));
        self::assertFalse(IpRange::matches('10.0.0.5', ''));
    }

    public function testForwardedHeadersOnlyCountFromTrustedProxies(): void
    {
        $server = ['REMOTE_ADDR' => '173.245.48.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 200.10.20.30', 'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_HOST' => 'origem.interna', 'HTTP_X_FORWARDED_HOST' => 'loja.test'];

        $direct = new Request('GET', '/', [], [], [], $server);
        self::assertSame('173.245.48.7', $direct->ip(), 'Sem proxy confiável, cabeçalhos são ignorados');
        self::assertFalse($direct->isSecure());
        self::assertSame('origem.interna', $direct->host());

        $proxied = (new Request('GET', '/', [], [], [], $server))->trustProxies(['173.245.48.0/20']);
        self::assertSame('200.10.20.30', $proxied->ip(), 'Último endereço antes do proxy (o 6.6.6.6 pode ser forjado)');
        self::assertTrue($proxied->isSecure());
        self::assertSame('loja.test', $proxied->host());

        $chain = (new Request('GET', '/', [], [], [], ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '200.1.1.1, 10.0.0.9']))
            ->trustProxies(['10.0.0.0/8']);
        self::assertSame('200.1.1.1', $chain->ip(), 'Pula proxies internos em cadeia');
    }
}
