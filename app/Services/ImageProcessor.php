<?php

declare(strict_types=1);

namespace GNesting\Services;

use finfo;
use GdImage;
use GNesting\Core\UploadedFile;

/**
 * Validação e otimização de imagens enviadas.
 *
 * - tipo verificado pelo CONTEÚDO (finfo + getimagesize), não pelo nome
 * - limites de tamanho do arquivo, de resolução mínima e de megapixels
 * - a imagem é decodificada e REGRAVADA: remove EXIF (GPS, câmera) e qualquer
 *   conteúdo escondido no arquivo original
 * - gera 3 larguras (400, 800, 1600 px) em WebP (ou JPEG se o servidor não tiver WebP)
 */
final class ImageProcessor
{
    public const WIDTHS = [1600, 800, 400];

    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    private const MIN_SIDE = 500;
    private const MAX_MEGAPIXELS = 30;

    public function __construct(
        private readonly string $uploadsPath,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param string $directory subpasta relativa a public/uploads, ex.: "products/12"
     * @return string caminho relativo da versão de 1600 px (as demais seguem o mesmo nome)
     * @throws BusinessRuleException com mensagem para o administrador
     */
    public function store(UploadedFile $file, string $directory): string
    {
        if (!$file->isOk()) {
            throw new BusinessRuleException($file->errorMessage());
        }
        if ($file->size() > $this->maxBytes) {
            throw new BusinessRuleException('Arquivo maior que ' . intdiv($this->maxBytes, 1024 * 1024) . ' MB.');
        }

        // Arquivo ilegível (ex.: bloqueado pelo antivírus do servidor) também é recusado.
        $mime = @(new finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath());
        $info = @getimagesize($file->tmpPath());
        if (!is_string($mime) || !isset(self::ALLOWED[$mime]) || $info === false || $info['mime'] !== $mime) {
            throw new BusinessRuleException('Formato não aceito. Envie JPG, PNG ou WebP.');
        }
        if (!in_array($file->extension(), self::ALLOWED[$mime], true)) {
            throw new BusinessRuleException('A extensão do arquivo não corresponde ao conteúdo.');
        }

        [$width, $height] = $info;
        if ($width * $height > self::MAX_MEGAPIXELS * 1_000_000) {
            throw new BusinessRuleException('Imagem com resolução muito alta (máximo ' . self::MAX_MEGAPIXELS . ' megapixels).');
        }
        if (min($width, $height) < self::MIN_SIDE) {
            throw new BusinessRuleException('Imagem pequena demais: o menor lado deve ter pelo menos ' . self::MIN_SIDE . ' px.');
        }

        $this->ensureMemory();
        $source = $this->load($file->tmpPath(), $mime);
        $source = $this->fixOrientation($source, $file->tmpPath(), $mime);

        $directory = trim($directory, '/');
        $absoluteDir = $this->uploadsPath . '/' . $directory;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new BusinessRuleException('Não foi possível gravar a imagem no servidor.');
        }

        $name = bin2hex(random_bytes(12));
        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';

        try {
            foreach (self::WIDTHS as $targetWidth) {
                $resized = imagesx($source) > $targetWidth ? imagescale($source, $targetWidth, -1, IMG_BICUBIC) : $source;
                if ($resized === false) {
                    throw new BusinessRuleException('Falha ao redimensionar a imagem.');
                }
                $this->save($resized, "{$absoluteDir}/{$name}-{$targetWidth}.{$extension}", $extension);
                if ($resized !== $source) {
                    imagedestroy($resized);
                }
            }
        } catch (\Throwable $e) {
            $this->delete("{$directory}/{$name}-1600.{$extension}");
            throw $e;
        } finally {
            imagedestroy($source);
        }

        return "{$directory}/{$name}-1600.{$extension}";
    }

    /** Remove todas as larguras geradas para a imagem. */
    public function delete(string $path): void
    {
        foreach (self::WIDTHS as $width) {
            $file = $this->uploadsPath . '/' . preg_replace('/-1600\.(webp|jpg)$/', "-{$width}.$1", ltrim($path, '/'));
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function load(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };
        if (!$image instanceof GdImage) {
            throw new BusinessRuleException('Arquivo de imagem corrompido ou inválido.');
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /** Fotos de celular guardam a rotação no EXIF; aplica antes de descartar os metadados. */
    private function fixOrientation(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }

    private function save(GdImage $image, string $path, string $extension): void
    {
        if ($extension === 'webp') {
            $ok = imagewebp($image, $path, 82);
        } else {
            // JPEG não tem transparência: aplica fundo branco
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $ok = imagejpeg($flat, $path, 85);
            imagedestroy($flat);
        }
        if (!$ok) {
            throw new BusinessRuleException('Não foi possível gravar a imagem no servidor.');
        }
    }

    /** Imagens grandes precisam de memória para decodificar (≈ 5 bytes por pixel). */
    private function ensureMemory(): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit !== '-1' && (int) $limit < 256 && str_ends_with(strtoupper($limit), 'M')) {
            @ini_set('memory_limit', '256M');
        }
    }
}
