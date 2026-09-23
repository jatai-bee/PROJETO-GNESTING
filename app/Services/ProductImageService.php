<?php

declare(strict_types=1);

namespace GNesting\Services;

use GNesting\Core\Database;
use GNesting\Core\UploadedFile;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductRepository;

final class ProductImageService
{
    public const MAX_IMAGES = 12;

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $products,
        private readonly ProductImageRepository $images,
        private readonly ImageProcessor $processor,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Processa cada arquivo de forma independente: um arquivo inválido não impede os outros.
     *
     * @param list<UploadedFile> $files
     * @return array{saved: int, errors: list<string>}
     */
    public function upload(int $productId, array $files, string $altText): array
    {
        $product = $this->products->findForAdmin($productId) ?? throw new BusinessRuleException('Produto não encontrado.');
        if ($files === []) {
            throw new BusinessRuleException('Selecione pelo menos uma imagem.');
        }

        $altText = $altText !== '' ? mb_substr($altText, 0, 150) : (string) $product['name'];
        $saved = 0;
        $errors = [];

        foreach ($files as $file) {
            if ($this->images->countByProduct($productId) >= self::MAX_IMAGES) {
                $errors[] = "{$file->originalName()}: limite de " . self::MAX_IMAGES . ' imagens por produto.';
                continue;
            }

            try {
                $path = $this->processor->store($file, 'products/' . $productId);
            } catch (BusinessRuleException $e) {
                $errors[] = "{$file->originalName()}: {$e->getMessage()}";
                continue;
            }

            try {
                $this->db->transaction(function () use ($productId, $path, $altText): void {
                    $isCover = $this->images->countByProduct($productId) === 0;
                    $imageId = $this->images->create($productId, $path, $altText, $isCover);
                    $this->audit->record(AuditService::CREATE, 'product_image', $imageId, null, [
                        'product_id' => $productId, 'path' => $path,
                    ]);
                });
            } catch (\Throwable $e) {
                $this->processor->delete($path); // não deixa arquivo órfão
                throw $e;
            }
            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    public function updateAlt(int $productId, int $imageId, string $altText): void
    {
        $image = $this->find($productId, $imageId);
        $altText = mb_substr(trim($altText), 0, 150);
        if ($altText === '') {
            throw new BusinessRuleException('Descreva a imagem (texto alternativo) para acessibilidade e SEO.');
        }
        $this->images->updateAlt($imageId, $altText);
        $this->audit->recordChanges(AuditService::UPDATE, 'product_image', $imageId, $image, ['alt_text' => $altText]);
    }

    public function setCover(int $productId, int $imageId): void
    {
        $this->find($productId, $imageId);
        $this->images->setCover($productId, $imageId);
        $this->audit->record(AuditService::UPDATE, 'product_image', $imageId, null, ['is_cover' => 1]);
    }

    /** Move uma posição para cima ou para baixo, trocando a ordem com a vizinha. */
    public function move(int $productId, int $imageId, string $direction): void
    {
        $this->db->transaction(function () use ($productId, $imageId, $direction): void {
            $list = array_values(array_filter(
                $this->images->listByProduct($productId),
                static fn (array $i) => !(bool) $i['is_cover'] // a capa é sempre a primeira
            ));
            $ids = array_column($list, 'id');
            $index = array_search($imageId, $ids, true);
            if ($index === false) {
                return;
            }
            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if (!isset($list[$target])) {
                return;
            }

            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
            foreach ($list as $position => $image) {
                $this->images->updateSort((int) $image['id'], ($position + 1) * 10);
            }
        });
    }

    public function delete(int $productId, int $imageId): void
    {
        $image = $this->find($productId, $imageId);

        $this->db->transaction(function () use ($productId, $imageId, $image): void {
            $this->images->delete($imageId);
            if ((bool) $image['is_cover']) {
                $next = $this->images->listByProduct($productId)[0] ?? null;
                if ($next !== null) {
                    $this->images->setCover($productId, (int) $next['id']);
                }
            }
            $this->audit->record(AuditService::DELETE, 'product_image', $imageId, [
                'product_id' => $productId, 'path' => $image['path'],
            ]);
        });

        $this->processor->delete($image['path']);
    }

    /** @return array<string, mixed> */
    private function find(int $productId, int $imageId): array
    {
        return $this->images->find($productId, $imageId) ?? throw new BusinessRuleException('Imagem não encontrada.');
    }
}
