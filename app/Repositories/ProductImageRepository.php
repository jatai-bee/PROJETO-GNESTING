<?php

declare(strict_types=1);

namespace GNesting\Repositories;

use GNesting\Core\Repository;

final class ProductImageRepository extends Repository
{
    /** @return list<array{id:int,path:string,alt_text:string,sort_order:int,is_cover:int}> */
    public function listByProduct(int $productId): array
    {
        return $this->fetchAll(
            'SELECT id, path, alt_text, sort_order, is_cover FROM product_images
              WHERE product_id = :id ORDER BY is_cover DESC, sort_order, id',
            ['id' => $productId]
        );
    }

    /** @return array{id:int,product_id:int,path:string,alt_text:string,sort_order:int,is_cover:int}|null */
    public function find(int $productId, int $imageId): ?array
    {
        return $this->fetchOne(
            'SELECT id, product_id, path, alt_text, sort_order, is_cover FROM product_images WHERE id = :id AND product_id = :product_id',
            ['id' => $imageId, 'product_id' => $productId]
        );
    }

    public function countByProduct(int $productId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM product_images WHERE product_id = :id', ['id' => $productId]);
    }

    public function create(int $productId, string $path, string $altText, bool $isCover): int
    {
        $next = (int) $this->fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM product_images WHERE product_id = :id',
            ['id' => $productId]
        );

        return $this->insert(
            'INSERT INTO product_images (product_id, path, alt_text, sort_order, is_cover)
             VALUES (:product_id, :path, :alt, :sort, :cover)',
            ['product_id' => $productId, 'path' => $path, 'alt' => $altText, 'sort' => $next, 'cover' => (int) $isCover]
        );
    }

    public function updateAlt(int $imageId, string $altText): void
    {
        $this->execute('UPDATE product_images SET alt_text = :alt WHERE id = :id', ['alt' => $altText, 'id' => $imageId]);
    }

    public function setCover(int $productId, int $imageId): void
    {
        $this->execute(
            'UPDATE product_images SET is_cover = (id = :image_id) WHERE product_id = :product_id',
            ['image_id' => $imageId, 'product_id' => $productId]
        );
    }

    public function updateSort(int $imageId, int $sortOrder): void
    {
        $this->execute('UPDATE product_images SET sort_order = :sort WHERE id = :id', ['sort' => $sortOrder, 'id' => $imageId]);
    }

    public function delete(int $imageId): void
    {
        $this->execute('DELETE FROM product_images WHERE id = :id', ['id' => $imageId]);
    }

    /** @return list<string> */
    public function pathsByProduct(int $productId): array
    {
        return array_column($this->listByProduct($productId), 'path');
    }
}
