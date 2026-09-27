<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Core\ValidationException;
use GNesting\Services\AuditService;
use GNesting\Services\SettingsService;
use GNesting\Services\StoreConfig\ConfigExporter;
use GNesting\Services\StoreConfig\ConfigImporter;

/**
 * Configurações da loja (proprietário): WhatsApp, contato, faixa de avisos e a
 * configuração completa em YAML (exportar/importar: loja, frete, categorias, materiais).
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ConfigExporter $exporter,
        private readonly ConfigImporter $importer,
        private readonly AuditService $audit,
    ) {
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

    public function export(Request $request): Response
    {
        $this->audit->record(AuditService::EXPORT, 'store_config');

        return Response::html($this->exporter->toYaml())
            ->withHeader('Content-Type', 'application/yaml; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="gnesting-configuracao-' . gmdate('Y-m-d') . '.yaml"');
    }

    public function import(Request $request): Response
    {
        $file = $request->files('arquivo')[0] ?? null;
        if ($file === null || !$file->isOk()) {
            $this->flash('error', $file === null ? 'Escolha o arquivo .yaml exportado.' : $file->errorMessage());

            return $this->redirect('/admin/configuracoes');
        }
        if (!preg_match('/\.ya?ml$/i', $file->originalName())) {
            $this->flash('error', 'Envie um arquivo .yaml (o mesmo formato do "Exportar configuração").');

            return $this->redirect('/admin/configuracoes');
        }

        try {
            $summary = $this->importer->fromYaml((string) file_get_contents($file->tmpPath()));
        } catch (ValidationException $e) {
            $lines = [];
            foreach (array_slice($e->errors(), 0, 15, true) as $path => $message) {
                $lines[] = "{$path}: {$message}";
            }
            $extra = count($e->errors()) - count($lines);
            $this->flash('error', 'Nada foi importado. Corrija o arquivo: ' . implode(' · ', $lines) . ($extra > 0 ? " · e mais {$extra}." : ''));

            return $this->redirect('/admin/configuracoes');
        }

        $parts = [];
        foreach ($summary as $label => $count) {
            $parts[] = "{$count} {$label}";
        }
        $this->flash('success', 'Configuração importada: ' . ($parts === [] ? 'nada mudou.' : implode(', ', $parts) . '.'));

        return $this->redirect('/admin/configuracoes');
    }
}
