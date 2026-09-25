<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Config;

/**
 * Link privado do pedido (/pedido/{número}/confirmacao?chave=...).
 *
 * A chave é DERIVADA do número do pedido com HMAC-SHA256 e a APP_KEY: não precisa ser
 * guardada e pode ser reenviada em qualquer e-mail (confirmação, envio, mensagens).
 * Sem a APP_KEY ninguém calcula a chave de outro pedido.
 * Atenção: trocar a APP_KEY invalida os links já enviados (docs/13 §6).
 */
final class OrderLink
{
    public function __construct(private readonly Config $config)
    {
    }

    public function token(string $orderNumber): string
    {
        return substr(hash_hmac('sha256', 'order-link:' . $orderNumber, (string) $this->config->get('app.key', '')), 0, 48);
    }

    public function url(string $orderNumber): string
    {
        return absolute_url('/pedido/' . $orderNumber . '/confirmacao') . '?chave=' . $this->token($orderNumber);
    }

    /**
     * @param array<string, mixed> $order precisa de number e access_token_hash
     */
    public function matches(array $order, string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (hash_equals($this->token((string) $order['number']), $key)) {
            return true;
        }

        // Pedidos da etapa 7 (chave aleatória guardada como SHA-256)
        return !empty($order['access_token_hash']) && hash_equals((string) $order['access_token_hash'], hash('sha256', $key));
    }
}
