<?php

declare(strict_types=1);

namespace GNesting\Tests\Integration;

use GNesting\Services\BusinessRuleException;
use GNesting\Services\ImageProcessor;
use GNesting\Services\ProductImageService;
use GNesting\Tests\Support\TestFiles;

final class ProductImagesTest extends IntegrationTestCase
{
    private string $uploads;
    private ImageProcessor $processor;
    private ProductImageService $service;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = TestFiles::tempDir('gn-uploads');
        $this->container->set(ImageProcessor::class, fn () => new ImageProcessor($this->uploads, 5 * 1024 * 1024));
        $this->processor = $this->container->get(ImageProcessor::class);
        $this->service = $this->container->get(ProductImageService::class);
        $this->productId = (int) $this->fetchValue("SELECT product_id FROM product_variants WHERE sku = 'REL-GEO-001'");
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestFiles::cleanup();
    }

    public function testImageIsReencodedIntoThreeWidthsWithoutUpscaling(): void
    {
        $path = $this->processor->store(TestFiles::image('foto.png', 2000, 1500), 'products/99');

        self::assertMatchesRegularExpression('#^products/99/[a-f0-9]{24}-1600\.webp$#', $path);
        foreach ([1600 => 1600, 800 => 800, 400 => 400] as $suffix => $expectedWidth) {
            $file = $this->uploads . '/' . str_replace('-1600.', "-{$suffix}.", $path);
            self::assertFileExists($file);
            [$width] = getimagesize($file);
            self::assertSame($expectedWidth, $width);
            self::assertSame('image/webp', mime_content_type($file));
        }

        $small = $this->processor->store(TestFiles::image('foto.jpg', 700, 700, 'jpeg'), 'products/99');
        [$width] = getimagesize($this->uploads . '/' . $small);
        self::assertSame(700, $width, 'Imagem menor que 1600 px não é ampliada');
    }

    public function testPhpDisguisedAsImageIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Formato não aceito');
        $this->processor->store(TestFiles::raw('foto.jpg', "<?php system(\$_GET['c']); ?>"), 'products/99');
    }

    public function testExtensionMustMatchContent(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('extensão');
        $this->processor->store(TestFiles::image('foto.php', 800, 800), 'products/99');
    }

    public function testTooSmallImageIsRejected(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('pequena demais');
        $this->processor->store(TestFiles::image('foto.png', 300, 900), 'products/99');
    }

    public function testFirstImageBecomesCoverAndDeletingCoverPromotesNext(): void
    {
        $result = $this->service->upload($this->productId, [
            TestFiles::image('a.png', 800, 800),
            TestFiles::raw('ruim.jpg', 'não é imagem'),
            TestFiles::image('b.png', 800, 800),
        ], '');

        self::assertSame(2, $result['saved']);
        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('ruim.jpg', $result['errors'][0]);

        $images = $this->db->pdo()->query("SELECT id, is_cover, alt_text, path FROM product_images WHERE product_id = {$this->productId} ORDER BY id")->fetchAll();
        self::assertSame([1, 0], array_column($images, 'is_cover'));
        self::assertSame('Relógio Geométrico G-Nesting', $images[0]['alt_text'], 'Texto alternativo padrão = nome do produto');

        $this->service->delete($this->productId, (int) $images[0]['id']);
        self::assertFileDoesNotExist($this->uploads . '/' . $images[0]['path'], 'Arquivos da imagem excluída são removidos');
        self::assertSame(1, (int) $this->fetchValue('SELECT is_cover FROM product_images WHERE id = :id', ['id' => $images[1]['id']]));
    }

    public function testImageFromAnotherProductCannotBeTouched(): void
    {
        $this->service->upload($this->productId, [TestFiles::image('a.png', 800, 800)], 'x');
        $imageId = (int) $this->fetchValue('SELECT MAX(id) FROM product_images');

        $this->expectException(BusinessRuleException::class);
        $this->service->delete($this->productId + 999, $imageId);
    }

    public function testMoveSwapsOrderOfNonCoverImages(): void
    {
        $this->service->upload($this->productId, [
            TestFiles::image('capa.png', 800, 800),
            TestFiles::image('b.png', 800, 800),
            TestFiles::image('c.png', 800, 800),
        ], 'x');
        $ids = array_map('intval', $this->db->pdo()->query("SELECT id FROM product_images WHERE product_id = {$this->productId} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));

        $this->service->move($this->productId, $ids[2], 'up');

        $order = array_map('intval', $this->db->pdo()->query(
            "SELECT id FROM product_images WHERE product_id = {$this->productId} ORDER BY is_cover DESC, sort_order, id"
        )->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$ids[0], $ids[2], $ids[1]], $order);
    }
}
