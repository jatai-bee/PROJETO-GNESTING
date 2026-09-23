<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\ProductImageRepository;
use GNesting\Repositories\ProductRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\ProductImageService;

final class ProductImageController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductImageRepository $images,
        private readonly ProductImageService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $product = $this->findProduct($request);

        return $this->render('admin/products/images', [
            'title' => 'Imagens | ' . $product['name'] . ' | Painel',
            'product' => $product,
            'images' => $this->images->listByProduct((int) $product['id']),
            'maxImages' => ProductImageService::MAX_IMAGES,
            'maxMb' => intdiv((int) config('uploads.max_image_bytes'), 1024 * 1024),
        ], 'admin');
    }

    public function upload(Request $request): Response
    {
        $product = $this->findProduct($request);

        try {
            $result = $this->service->upload((int) $product['id'], $request->files('images'), $request->string('alt_text'));
            if ($result['saved'] > 0) {
                $this->flash('success', $result['saved'] === 1 ? '1 imagem enviada.' : "{$result['saved']} imagens enviadas.");
            }
            if ($result['errors'] !== []) {
                $this->flash('error', 'Não enviadas: ' . implode(' · ', $result['errors']));
            }
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($product);
    }

    public function alt(Request $request): Response
    {
        return $this->action($request, fn (int $productId, int $imageId) =>
            $this->service->updateAlt($productId, $imageId, $request->string('alt_text')), 'Descrição da imagem atualizada.');
    }

    public function cover(Request $request): Response
    {
        return $this->action($request, fn (int $productId, int $imageId) =>
            $this->service->setCover($productId, $imageId), 'Imagem de capa definida.');
    }

    public function move(Request $request): Response
    {
        $direction = $request->string('direction') === 'up' ? 'up' : 'down';

        return $this->action($request, fn (int $productId, int $imageId) =>
            $this->service->move($productId, $imageId, $direction), null);
    }

    public function destroy(Request $request): Response
    {
        return $this->action($request, fn (int $productId, int $imageId) =>
            $this->service->delete($productId, $imageId), 'Imagem excluída.');
    }

    /** @param callable(int, int): void $callback */
    private function action(Request $request, callable $callback, ?string $success): Response
    {
        $product = $this->findProduct($request);
        try {
            $callback((int) $product['id'], (int) $request->param('imageId'));
            if ($success !== null) {
                $this->flash('success', $success);
            }
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->back($product);
    }

    /** @param array<string, mixed> $product */
    private function back(array $product): Response
    {
        return $this->redirect("/admin/produtos/{$product['id']}/imagens");
    }

    /** @return array<string, mixed> */
    private function findProduct(Request $request): array
    {
        return $this->products->findForAdmin((int) $request->param('id')) ?? throw HttpException::notFound();
    }
}
