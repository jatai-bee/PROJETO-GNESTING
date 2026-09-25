<?php

declare(strict_types=1);

namespace GNesting\Services\Payment;

/**
 * Cliente HTTP mínimo para APIs externas (hospedagem compartilhada: cURL).
 * Interface para que os testes usem um cliente falso, sem rede.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws \RuntimeException falha de rede/timeout
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array;
}
