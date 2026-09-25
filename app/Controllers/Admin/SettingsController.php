<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Services\SettingsService;

/** Configurações da loja (proprietário): WhatsApp, contato, faixa de avisos. */
final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function edit(Request $request): Response
    {
        return $this->render('admin/settings/form', [
            'title' => 'Configurações | Painel',
            'settings' => $this->settings->editable(),
        ], 'admin');
    }

    public function update(Request $request): Response
    {
        // Nomes de campo sem ponto (o PHP trocaria "." por "_" no POST)
        $this->settings->save([
            'whatsapp.number' => $request->string('whatsapp_number'),
            'whatsapp.default_message' => $request->string('whatsapp_default_message'),
            'whatsapp.floating_button' => $request->boolean('whatsapp_floating_button') ? '1' : '',
            'store.contact_email' => $request->string('store_contact_email'),
            'store.announcement' => $request->string('store_announcement'),
        ]);
        $this->flash('success', 'Configurações salvas.');

        return $this->redirect('/admin/configuracoes');
    }
}
