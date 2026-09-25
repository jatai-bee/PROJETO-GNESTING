<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\AuditContext;
use GNesting\Core\Database;
use GNesting\Core\UploadedFile;
use GNesting\Repositories\ProductionSpecRepository;

/**
 * Arquivos de produção (programas CNC, projetos, desenhos) — docs/05 §9.
 *
 * - extensão em lista branca; nome original nunca vira caminho: grava-se com nome aleatório
 *   em storage/private/production_files/{spec_id}/ (fora do webroot);
 * - SHA-256 registrado (conferência de integridade na máquina);
 * - reenviar um arquivo com o mesmo nome cria nova VERSÃO; as anteriores continuam disponíveis;
 * - download só por controller autenticado (manager/production), sempre como anexo.
 */
final class ProductionFileService
{
    public const TYPES = ['cnc' => 'Programa CNC', 'design' => 'Projeto', 'drawing' => 'Desenho técnico', 'other' => 'Outro'];
    public const EXTENSIONS = ['nc', 'tap', 'gcode', 'dxf', 'svg', 'pdf', 'crv', 'crv3d', 'zip'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductionSpecRepository $specs,
        private readonly AuditService $audit,
        private readonly AuditContext $auditContext,
        private readonly string $storagePath,
        private readonly int $maxBytes,
    ) {
    }

    /** @throws BusinessRuleException */
    public function upload(int $variantId, UploadedFile $file, string $type): int
    {
        $spec = $this->specs->findByVariant($variantId)
            ?? throw new BusinessRuleException('Salve a ficha desta variação antes de enviar arquivos.');
        if (!array_key_exists($type, self::TYPES)) {
            throw new BusinessRuleException('Escolha o tipo do arquivo.');
        }
        if (!$file->isOk()) {
            throw new BusinessRuleException($file->errorMessage());
        }
        if (!in_array($file->extension(), self::EXTENSIONS, true)) {
            throw new BusinessRuleException('Tipo de arquivo não aceito. Use: ' . implode(', ', self::EXTENSIONS) . '.');
        }
        $size = (int) filesize($file->tmpPath());
        if ($size === 0 || $size > $this->maxBytes) {
            throw new BusinessRuleException('O arquivo precisa ter até ' . intdiv($this->maxBytes, 1024 * 1024) . ' MB (e não pode estar vazio).');
        }

        $specId = (int) $spec['id'];
        $originalName = $this->cleanName($file->originalName());
        $relative = $specId . '/' . bin2hex(random_bytes(16)) . '.' . $file->extension();
        $target = $this->storagePath . '/' . $relative;

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0770, true) && !is_dir(dirname($target))) {
            throw new \RuntimeException('Não foi possível criar a pasta de arquivos de produção.');
        }
        if (!$file->moveTo($target)) {
            throw new \RuntimeException('Não foi possível gravar o arquivo de produção.');
        }

        try {
            return $this->db->transaction(function () use ($specId, $type, $originalName, $relative, $target, $size): int {
                $data = [
                    'file_type' => $type,
                    'original_name' => $originalName,
                    'stored_path' => $relative,
                    'mime_type' => (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($target) ?: 'application/octet-stream'),
                    'size_bytes' => $size,
                    'checksum_sha256' => (string) hash_file('sha256', $target),
                    'version' => $this->specs->nextVersion($specId, $originalName),
                    'uploaded_by_user_id' => $this->auditContext->userId(),
                ];
                $fileId = $this->specs->createFile($specId, $data);
                $this->audit->record(AuditService::CREATE, 'production_file', $fileId, null, array_diff_key($data, ['stored_path' => true]) + ['spec_id' => $specId]);

                return $fileId;
            });
        } catch (\Throwable $e) {
            @unlink($target); // não deixa arquivo órfão
            throw $e;
        }
    }

    /**
     * Caminho absoluto de um arquivo, conferindo que pertence ao produto informado
     * e que continua dentro da pasta privada.
     *
     * @return array{path: string, name: string}|null
     */
    public function locate(int $fileId, ?int $productId = null): ?array
    {
        $file = $this->specs->findFile($fileId);
        if ($file === null || ($productId !== null && (int) $file['product_id'] !== $productId)) {
            return null;
        }
        $base = realpath($this->storagePath);
        $path = realpath($this->storagePath . '/' . $file['stored_path']);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $name = pathinfo((string) $file['original_name'], PATHINFO_FILENAME);
        $extension = pathinfo((string) $file['original_name'], PATHINFO_EXTENSION);

        return ['path' => $path, 'name' => "{$name}-v{$file['version']}.{$extension}"];
    }

    /** @throws BusinessRuleException */
    public function delete(int $productId, int $fileId): void
    {
        $file = $this->specs->findFile($fileId);
        if ($file === null || (int) $file['product_id'] !== $productId) {
            throw new BusinessRuleException('Arquivo não encontrado.');
        }
        $located = $this->locate($fileId, $productId);

        $this->db->transaction(function () use ($file): void {
            $this->specs->deleteFile((int) $file['id']);
            $this->audit->record(AuditService::DELETE, 'production_file', (int) $file['id'], [
                'original_name' => $file['original_name'], 'version' => $file['version'],
                'checksum_sha256' => $file['checksum_sha256'], 'sku' => $file['sku'],
            ]);
        });
        if ($located !== null) {
            @unlink($located['path']);
        }
    }

    /** Nome exibido: sem caminho nem caracteres de controle, até 190 caracteres. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return mb_substr($name === '' ? 'arquivo' : $name, -190);
    }
}
