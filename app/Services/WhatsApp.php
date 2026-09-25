<?php

declare(strict_types=1);

namespace GNesting\Services;

/**
 * Links de WhatsApp da loja (número em /admin/configuracoes). Sem número configurado,
 * nenhum link é gerado — os botões simplesmente não aparecem.
 */
final class WhatsApp
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function general(): ?string
    {
        return whatsapp_url($this->settings->get('whatsapp.number'), $this->settings->get('whatsapp.default_message'));
    }

    public function forProduct(string $productName, string $url): ?string
    {
        return whatsapp_url($this->settings->get('whatsapp.number'), "Olá! Tenho uma dúvida sobre o produto {$productName}: {$url}");
    }

    public function forOrder(string $orderNumber): ?string
    {
        return whatsapp_url($this->settings->get('whatsapp.number'), "Olá! Quero falar sobre o meu pedido {$orderNumber}.");
    }

    public function floatingButton(): ?string
    {
        return $this->settings->get('whatsapp.floating_button') === '1' ? $this->general() : null;
    }
}
