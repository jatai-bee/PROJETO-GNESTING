<?php

declare(strict_types=1);

namespace GNesting\Tests\Support;

use GNesting\Core\UploadedFile;

/** Gera arquivos temporários para testes de upload. */
final class TestFiles
{
    /** @var list<string> */
    private static array $created = [];

    public static function image(string $name, int $width, int $height, string $format = 'png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 185, 138, 94));
        imagefilledrectangle($image, 10, 10, (int) ($width / 2), (int) ($height / 2), (int) imagecolorallocate($image, 196, 83, 45));

        $path = self::tempPath();
        match ($format) {
            'png' => imagepng($image, $path),
            'jpeg' => imagejpeg($image, $path, 90),
            'webp' => imagewebp($image, $path, 90),
        };
        imagedestroy($image);

        return new UploadedFile($name, $path, UPLOAD_ERR_OK, (int) filesize($path), false);
    }

    public static function raw(string $name, string $content): UploadedFile
    {
        $path = self::tempPath();
        file_put_contents($path, $content);

        return new UploadedFile($name, $path, UPLOAD_ERR_OK, strlen($content), false);
    }

    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        self::$created[] = $dir;

        return $dir;
    }

    public static function cleanup(): void
    {
        foreach (array_reverse(self::$created) as $path) {
            if (is_dir($path)) {
                $items = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        self::$created = [];
    }

    private static function tempPath(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gnimg');
        self::$created[] = $path;

        return $path;
    }
}
