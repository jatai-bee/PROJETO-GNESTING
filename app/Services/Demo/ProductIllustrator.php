<?php

declare(strict_types=1);

namespace GNesting\Services\Demo;

use GdImage;
use RuntimeException;

/**
 * Ilustrações dos produtos de demonstração, desenhadas com GD (sem fotos de terceiros).
 *
 * Cada produto tem um "tipo" (relógio, nicho, porta-temperos…) e um acabamento (MDF cru,
 * amadeirado, pintado). A textura de madeira é um ladrilho (imagesettile) aplicado dentro de cada
 * forma, com sombra suave e fundo claro: parece foto de estúdio de um objeto real, e o arquivo é
 * trocado pela foto verdadeira quando a loja começar a vender.
 */
final class ProductIllustrator
{
    public const SIZE = 1600;

    /** Acabamentos: [cor base, cor dos veios, cor da borda de corte] */
    private const FINISHES = [
        'mdf' => [[212, 178, 132], [190, 152, 104], [160, 118, 74]],
        'amadeirado' => [[176, 124, 78], [150, 100, 58], [120, 78, 42]],
        'carvalho' => [[198, 158, 108], [172, 130, 82], [140, 100, 60]],
        'nogueira' => [[120, 82, 52], [98, 64, 38], [76, 48, 28]],
        'preto' => [[46, 44, 42], [58, 56, 54], [150, 118, 80]],
        'branco' => [[240, 236, 228], [228, 222, 212], [196, 170, 130]],
        'verde' => [[88, 116, 96], [78, 104, 86], [180, 144, 100]],
        'terracota' => [[196, 96, 62], [180, 84, 52], [150, 110, 72]],
        'rosa' => [[226, 176, 168], [214, 162, 154], [190, 150, 110]],
        'azul' => [[120, 150, 176], [108, 138, 164], [180, 144, 100]],
    ];

    /** Fundos de estúdio (claros, quentes) */
    private const BACKDROPS = [
        [[246, 242, 235], [232, 224, 212]],
        [[240, 236, 230], [222, 214, 202]],
        [[244, 238, 230], [226, 212, 196]],
        [[238, 240, 236], [218, 222, 214]],
    ];

    private GdImage $img;
    /** @var array<string, GdImage> */
    private array $tiles = [];

    /**
     * Desenha e grava um PNG. $view: "principal" ou "detalhe" (mais próximo, outro fundo).
     */
    public function render(string $kind, string $finish, string $path, string $view = 'principal', int $seed = 0): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('A extensão GD é necessária para gerar as imagens de demonstração.');
        }
        mt_srand(crc32($kind . $finish . $view) + $seed);
        $this->img = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($this->img, true);
        imageantialias($this->img, false); // com antialias, o GD ignora imagesetthickness
        $this->backdrop(self::BACKDROPS[($seed + ($view === 'detalhe' ? 1 : 0)) % count(self::BACKDROPS)]);

        $scale = $view === 'detalhe' ? 1.25 : 1.0;
        $method = 'draw' . str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)));
        if (!method_exists($this, $method)) {
            $method = 'drawBox';
        }
        $this->{$method}($finish, $scale);

        imagepng($this->img, $path, 6);
        imagedestroy($this->img);
        foreach ($this->tiles as $tile) {
            imagedestroy($tile);
        }
        $this->tiles = [];
    }

    // ---- fundo, sombra e textura ---------------------------------------------------------

    /** @param array{0: array{int,int,int}, 1: array{int,int,int}} $colors */
    private function backdrop(array $colors): void
    {
        [$top, $bottom] = $colors;
        $horizon = (int) (self::SIZE * 0.70);
        for ($y = 0; $y < self::SIZE; $y++) {
            $t = $y < $horizon ? $y / $horizon * 0.35 : 0.35 + ($y - $horizon) / (self::SIZE - $horizon) * 0.65;
            $c = $this->mix($top, $bottom, $t);
            imageline($this->img, 0, $y, self::SIZE, $y, $this->color($c));
        }
    }

    /** Sombra suave sob o objeto: elipses concêntricas quase transparentes (degradê, sem blur). */
    private function shadow(int $cx, int $cy, int $w, int $h, int $alpha = 70): void
    {
        $steps = 14;
        $strength = max(1, (int) round($alpha / 14));
        for ($i = 0; $i < $steps; $i++) {
            $f = 1.35 - $i * (0.75 / $steps);
            imagefilledellipse($this->img, $cx, $cy, (int) ($w * $f), (int) ($h * $f), imagecolorallocatealpha($this->img, 60, 44, 28, 127 - $strength));
        }
    }

    /** Ladrilho de madeira/MDF: base + veios ondulados + poros. */
    private function tile(string $finish, bool $vertical = false): GdImage
    {
        $key = $finish . ($vertical ? 'v' : 'h');
        if (isset($this->tiles[$key])) {
            return $this->tiles[$key];
        }
        [$base, $grain] = self::FINISHES[$finish] ?? self::FINISHES['mdf'];
        $s = 400;
        $t = imagecreatetruecolor($s, $s);
        imagefill($t, 0, 0, $this->colorOn($t, $base));
        $painted = in_array($finish, ['preto', 'branco', 'verde', 'terracota', 'rosa', 'azul'], true);
        $lines = $painted ? 6 : 26;
        for ($i = 0; $i < $lines; $i++) {
            $offset = mt_rand(0, $s);
            $amp = mt_rand(3, $painted ? 4 : 14);
            $freq = mt_rand(8, 20) / 1000;
            $shade = $this->mix($base, $grain, mt_rand(35, $painted ? 45 : 100) / 100);
            $col = $this->colorOn($t, $shade);
            for ($x = 0; $x < $s; $x += 2) {
                $y = (int) ($offset + sin($x * $freq + $i) * $amp) % $s;
                $vertical ? imagesetpixel($t, $y, $x, $col) : imagesetpixel($t, $x, $y, $col);
                $vertical ? imagesetpixel($t, $y, $x + 1, $col) : imagesetpixel($t, $x + 1, $y, $col);
            }
        }
        if (!$painted) {
            for ($i = 0; $i < 900; $i++) {
                imagesetpixel($t, mt_rand(0, $s - 1), mt_rand(0, $s - 1), $this->colorOn($t, $this->mix($base, $grain, 0.8)));
            }
        }

        return $this->tiles[$key] = $t;
    }

    /** Aplica a textura como "tinta" das formas seguintes. */
    private function wood(string $finish, bool $vertical = false): int
    {
        imagesettile($this->img, $this->tile($finish, $vertical));

        return IMG_COLOR_TILED;
    }

    /** Borda de corte (o "lado" da chapa, mais escuro): dá espessura às peças. */
    private function edge(string $finish): int
    {
        return $this->color((self::FINISHES[$finish] ?? self::FINISHES['mdf'])[2]);
    }

    private function ink(int $r, int $g, int $b, int $alpha = 0): int
    {
        return imagecolorallocatealpha($this->img, $r, $g, $b, $alpha);
    }

    // ---- primitivas com espessura (peça recortada em chapa) ------------------------------

    /** Retângulo de chapa: face texturizada + lateral inferior/direita (espessura). */
    private function slab(int $x, int $y, int $w, int $h, string $finish, int $depth = 18, bool $vertical = false): void
    {
        imagefilledpolygon($this->img, [$x + $w, $y, $x + $w + $depth, $y + $depth, $x + $w + $depth, $y + $h + $depth, $x + $depth, $y + $h + $depth, $x, $y + $h, $x + $w, $y + $h], $this->edge($finish));
        imagefilledrectangle($this->img, $x, $y, $x + $w, $y + $h, $this->wood($finish, $vertical));
    }

    /** Disco de chapa. */
    private function disc(int $cx, int $cy, int $d, string $finish, int $depth = 20): void
    {
        imagefilledellipse($this->img, $cx + (int) ($depth * 0.7), $cy + $depth, $d, $d, $this->edge($finish));
        imagefilledellipse($this->img, $cx, $cy, $d, $d, $this->wood($finish));
    }

    /** Polígono regular de chapa (hexágono etc.). */
    private function poly(int $cx, int $cy, int $r, int $sides, string $finish, float $rotation = 0.0, int $depth = 20, bool $hollow = false, int $wall = 0): void
    {
        $points = static function (int $cx, int $cy, int $r) use ($sides, $rotation): array {
            $p = [];
            for ($i = 0; $i < $sides; $i++) {
                $a = $rotation + 2 * M_PI * $i / $sides;
                $p[] = (int) ($cx + $r * cos($a));
                $p[] = (int) ($cy + $r * sin($a));
            }

            return $p;
        };
        imagefilledpolygon($this->img, $points($cx + (int) ($depth * 0.7), $cy + $depth, $r), $this->edge($finish));
        imagefilledpolygon($this->img, $points($cx, $cy, $r), $this->wood($finish));
        if ($hollow) {
            // interior do nicho: fundo mais escuro (profundidade)
            imagefilledpolygon($this->img, $points($cx, $cy, $r - $wall), $this->edge($finish));
            imagefilledpolygon($this->img, $points($cx + 10, $cy + 14, $r - $wall - 16), $this->wood($finish === 'branco' ? 'carvalho' : $finish));
            imagefilledpolygon($this->img, $points($cx + 10, $cy + 14, $r - $wall - 16), $this->ink(0, 0, 0, 100));
        }
    }

    // ---- tipos de produto ---------------------------------------------------------------

    private function drawClockRound(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $d = (int) (980 * $k);
        $this->shadow($c + 30, $c + (int) ($d * 0.52), (int) ($d * 0.9), 120);
        $this->disc($c, $c - 40, $d, $finish, 26);
        // marcações geométricas recortadas
        for ($i = 0; $i < 12; $i++) {
            $a = -M_PI / 2 + $i * M_PI / 6;
            $r1 = $d * 0.40;
            $len = $i % 3 === 0 ? 90 : 46;
            $x = (int) ($c + $r1 * cos($a));
            $y = (int) ($c - 40 + $r1 * sin($a));
            imagesetthickness($this->img, $i % 3 === 0 ? 22 : 12);
            imageline($this->img, $x, $y, (int) ($x - $len * cos($a)), (int) ($y - $len * sin($a)), $this->edge($finish));
        }
        $this->hands($c, $c - 40, $d, $finish === 'preto' ? [214, 180, 134] : [40, 36, 32]);
    }

    private function drawClockHex(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $r = (int) (520 * $k);
        $this->shadow($c + 30, $c + $r, (int) ($r * 1.6), 110);
        $this->poly($c, $c - 40, $r, 6, $finish, M_PI / 6, 26);
        // triângulos internos em outro tom (composição geométrica)
        for ($i = 0; $i < 6; $i += 2) {
            $a1 = M_PI / 6 + 2 * M_PI * $i / 6;
            $a2 = M_PI / 6 + 2 * M_PI * ($i + 1) / 6;
            $rr = $r * 0.86;
            imagefilledpolygon($this->img, [$c, $c - 40, (int) ($c + $rr * cos($a1)), (int) ($c - 40 + $rr * sin($a1)), (int) ($c + $rr * cos($a2)), (int) ($c - 40 + $rr * sin($a2))], $this->wood($finish === 'preto' ? 'carvalho' : 'nogueira'));
        }
        $this->hands($c, $c - 40, $r * 2, [245, 240, 232]);
    }

    private function drawClockMinimal(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $d = (int) (900 * $k);
        $this->shadow($c + 30, $c + (int) ($d * 0.52), (int) ($d * 0.85), 110);
        $this->disc($c, $c - 40, $d, $finish, 24);
        foreach ([0, 3, 6, 9] as $h) {
            $a = -M_PI / 2 + $h * M_PI / 6;
            imagefilledellipse($this->img, (int) ($c + $d * 0.40 * cos($a)), (int) ($c - 40 + $d * 0.40 * sin($a)), 34, 34, $this->ink(40, 36, 32));
        }
        $this->hands($c, $c - 40, $d, [40, 36, 32]);
    }

    private function drawClockTable(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (900 * $k);
        $this->shadow($c + 20, $c + 140, $w + 80, 120, 80);
        imagefilledarc($this->img, $c + 18, $c + 18, $w, $w, 180, 360, $this->edge($finish), IMG_ARC_PIE);
        imagefilledarc($this->img, $c, $c, $w, $w, 180, 360, $this->wood($finish), IMG_ARC_PIE);
        $this->slab($c - (int) ($w * 0.55), $c, (int) ($w * 1.1), 110, $finish, 18);
        imagefilledellipse($this->img, $c, $c - 120, (int) ($w * 0.46), (int) ($w * 0.46), $this->ink(250, 246, 238));
        $this->hands($c, $c - 120, (int) ($w * 0.46), [40, 36, 32]);
    }

    /** Refaz o fundo numa faixa (para "cortar" formas). */
    private function backdropBand(int $from, int $to): void
    {
        [$top, $bottom] = self::BACKDROPS[0];
        $horizon = (int) (self::SIZE * 0.70);
        for ($y = max(0, $from); $y < min(self::SIZE, $to); $y++) {
            $t = $y < $horizon ? $y / $horizon * 0.35 : 0.35 + ($y - $horizon) / (self::SIZE - $horizon) * 0.65;
            imageline($this->img, 0, $y, self::SIZE, $y, $this->color($this->mix($top, $bottom, $t)));
        }
    }

    /** @param array{int,int,int} $rgb */
    private function hands(int $cx, int $cy, int $d, array $rgb): void
    {
        $col = $this->color($rgb);
        imagesetthickness($this->img, 20);
        imageline($this->img, $cx, $cy, (int) ($cx + $d * 0.20 * cos(-2.3)), (int) ($cy + $d * 0.20 * sin(-2.3)), $col);
        imagesetthickness($this->img, 12);
        imageline($this->img, $cx, $cy, (int) ($cx + $d * 0.32 * cos(-0.55)), (int) ($cy + $d * 0.32 * sin(-0.55)), $col);
        imagesetthickness($this->img, 1);
        imagefilledellipse($this->img, $cx, $cy, 48, 48, $col);
        imagefilledellipse($this->img, $cx, $cy, 14, 14, $this->ink(196, 83, 45));
    }

    private function drawFrameFloral(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (820 * $k);
        $h = (int) (1040 * $k);
        $this->shadow($c + 30, $c + (int) ($h / 2) + 40, $w, 90);
        $this->slab($c - (int) ($w / 2), $c - (int) ($h / 2) - 30, $w, $h, $finish, 22);
        imagefilledrectangle($this->img, $c - (int) ($w / 2) + 70, $c - (int) ($h / 2) + 40, $c + (int) ($w / 2) - 70, $c + (int) ($h / 2) - 100, $this->ink(250, 247, 241));
        // flores vazadas (pétalas em elipse)
        foreach ([[0, -120, 1.0], [-150, 90, 0.7], [160, 120, 0.8]] as [$dx, $dy, $s]) {
            for ($p = 0; $p < 6; $p++) {
                $a = $p * M_PI / 3;
                imagefilledellipse($this->img, (int) ($c + $dx + cos($a) * 70 * $s), (int) ($c + $dy + sin($a) * 70 * $s), (int) (110 * $s), (int) (110 * $s), $this->wood('terracota'));
            }
            imagefilledellipse($this->img, $c + $dx, $c + $dy, (int) (70 * $s), (int) (70 * $s), $this->wood('amadeirado'));
        }
        imagesetthickness($this->img, 10);
        imagearc($this->img, $c - 60, $c + 260, 400, 300, 200, 320, $this->color([88, 116, 96]));
        imagesetthickness($this->img, 1);
    }

    private function drawFrameMap(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (1180 * $k);
        $h = (int) (720 * $k);
        $this->shadow($c + 30, $c + (int) ($h / 2) + 40, $w, 80);
        $this->slab($c - (int) ($w / 2), $c - (int) ($h / 2) - 30, $w, $h, $finish, 20);
        // continentes estilizados vazados (manchas em tom escuro)
        $blobs = [[-360, -80, 260, 200], [-280, 110, 150, 230], [-20, -120, 170, 150], [40, 60, 200, 240], [270, -60, 330, 220], [390, 190, 150, 110]];
        foreach ($blobs as [$dx, $dy, $bw, $bh]) {
            imagefilledellipse($this->img, $c + $dx, $c + $dy - 30, $bw, $bh, $this->edge($finish));
            imagefilledellipse($this->img, $c + $dx + 30, $c + $dy - 50, (int) ($bw * 0.7), (int) ($bh * 0.6), $this->edge($finish));
        }
    }

    private function drawPanelSlats(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $h = (int) (1080 * $k);
        $n = 9;
        $slat = (int) (80 * $k);
        $gap = (int) (36 * $k);
        $total = $n * $slat + ($n - 1) * $gap;
        $this->shadow($c + 30, $c + (int) ($h / 2) + 30, $total + 80, 80);
        for ($i = 0; $i < $n; $i++) {
            $x = $c - (int) ($total / 2) + $i * ($slat + $gap);
            $this->slab($x, $c - (int) ($h / 2) - 40, $slat, $h, $finish, 16, true);
        }
    }

    private function drawMandala(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $d = (int) (1040 * $k);
        $this->shadow($c + 30, $c + (int) ($d * 0.5), (int) ($d * 0.85), 100);
        $this->disc($c, $c - 40, $d, $finish, 22);
        $contrast = $finish === 'preto' ? [214, 178, 132] : [255, 250, 243];
        foreach ([[0.42, 16, 90], [0.30, 12, 70], [0.19, 8, 56]] as [$rr, $petals, $size]) {
            for ($p = 0; $p < $petals; $p++) {
                $a = 2 * M_PI * $p / $petals;
                imagefilledellipse($this->img, (int) ($c + $d * $rr * cos($a)), (int) ($c - 40 + $d * $rr * sin($a)), (int) ($size * $k), (int) ($size * $k), $this->color($contrast));
            }
        }
        imagefilledellipse($this->img, $c, $c - 40, (int) (120 * $k), (int) (120 * $k), $this->color($contrast));
        imagefilledellipse($this->img, $c, $c - 40, (int) (60 * $k), (int) (60 * $k), $this->wood($finish));
    }

    private function drawVase(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 470, 560, 100, 90);
        $pts = [$c - 160, $c - 420, $c + 160, $c - 420, $c + 260, $c - 60, $c + 190, $c + 440, $c - 190, $c + 440, $c - 260, $c - 60];
        $pts = array_map(fn ($v, $i) => $i % 2 === 0 ? (int) ($c + ($v - $c) * $k) : (int) ($c + ($v - $c) * $k), $pts, array_keys($pts));
        $shifted = array_map(fn ($v, $i) => $v + ($i % 2 === 0 ? 20 : 20), $pts, array_keys($pts));
        imagefilledpolygon($this->img, $shifted, $this->edge($finish));
        imagefilledpolygon($this->img, $pts, $this->wood($finish, true));
        imagesetthickness($this->img, 6);
        imageline($this->img, $pts[10], $pts[11], $pts[2], $pts[3], $this->edge($finish));
        imageline($this->img, $pts[0], $pts[1], $pts[6], $pts[7], $this->ink(0, 0, 0, 90));
        imagesetthickness($this->img, 1);
        // galhos secos
        imagesetthickness($this->img, 8);
        foreach ([[-80, -700], [30, -760], [120, -680]] as [$dx, $dy]) {
            imageline($this->img, $c, (int) ($c - 400 * $k), (int) ($c + $dx * $k), (int) ($c + $dy * $k), $this->color([120, 92, 60]));
        }
        imagesetthickness($this->img, 1);
    }

    private function drawLampTree(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 500, 620, 110, 90);
        for ($i = 0; $i < 5; $i++) {
            $w = (int) ((200 + $i * 110) * $k);
            $y = (int) ($c - 520 * $k + $i * 180 * $k);
            imagefilledpolygon($this->img, [$c + 18, $y + 18, $c + (int) ($w / 2) + 18, $y + (int) (220 * $k) + 18, $c - (int) ($w / 2) + 18, $y + (int) (220 * $k) + 18], $this->edge($finish));
            imagefilledpolygon($this->img, [$c, $y, $c + (int) ($w / 2), $y + (int) (220 * $k), $c - (int) ($w / 2), $y + (int) (220 * $k)], $this->wood($finish));
            imagefilledellipse($this->img, $c, $y + (int) (150 * $k), 40, 40, $this->ink(255, 214, 140, 30));
        }
        $this->slab($c - 50, (int) ($c + 380 * $k), 100, 100, $finish, 14);
    }

    private function drawDrawerOrganizer(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 60, (int) (980 * $k), (int) (560 * $k), (int) (200 * $k), $finish, [[0.33, 0], [0.66, 0], [0, 0.5]]);
    }

    private function drawKeyHolder(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (1040 * $k);
        $h = (int) (360 * $k);
        $this->shadow($c + 30, $c + 330, $w, 70, 60);
        $this->slab($c - (int) ($w / 2), $c - (int) ($h / 2) - 60, $w, $h, $finish, 22);
        // casinha recortada + ganchos
        imagefilledpolygon($this->img, [$c - 80, $c - 190, $c, $c - 260, $c + 80, $c - 190, $c + 80, $c - 110, $c - 80, $c - 110], $this->edge($finish));
        for ($i = 0; $i < 5; $i++) {
            $x = $c - (int) ($w / 2) + (int) (($i + 0.5) * $w / 5);
            imagefilledrectangle($this->img, $x - 10, $c + 30, $x + 10, $c + 140, $this->ink(90, 88, 84));
            imagefilledellipse($this->img, $x, $c + 150, 50, 30, $this->ink(90, 88, 84));
        }
        // chaveiro pendurado
        imagesetthickness($this->img, 6);
        imageellipse($this->img, $c - (int) ($w / 5), $c + 220, 70, 70, $this->ink(160, 150, 130));
        imagesetthickness($this->img, 1);
        imagefilledrectangle($this->img, $c - (int) ($w / 5) - 14, $c + 250, $c - (int) ($w / 5) + 14, $c + 380, $this->ink(186, 160, 110));
    }

    private function drawHexNiche(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $r = (int) (520 * $k);
        $this->shadow($c + 30, $c + $r, (int) ($r * 1.5), 100);
        $this->poly($c, $c - 40, $r, 6, $finish, 0, 34, true, 60);
        // objetos dentro do nicho
        imagefilledellipse($this->img, $c - 90, $c + 120, 140, 140, $this->color([88, 116, 96]));
        imagefilledrectangle($this->img, $c - 110, $c + 120, $c - 70, $c + 230, $this->ink(196, 150, 110));
        $this->slab($c + 30, $c + 80, 150, 170, 'branco', 10);
    }

    private function drawHoneycomb(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $r = (int) (300 * $k);
        $this->shadow($c + 30, $c + 540, (int) ($r * 3.4), 100);
        $dx = (int) ($r * 1.5 * 1.02);
        $dy = (int) ($r * sqrt(3) / 2 * 1.02);
        foreach ([[-1, -1], [1, -1], [0, 1]] as [$ix, $iy]) {
            $this->poly($c + $ix * (int) ($dx * 0.66), $c - 60 + $iy * $dy, $r, 6, $finish, 0, 26, true, 40);
        }
    }

    private function drawHeadphoneStand(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 520, 700, 90, 90);
        $this->slab($c - 350, (int) ($c + 420 * $k), 700, 60, $finish, 18);
        $this->slab($c - 45, (int) ($c - 420 * $k), 90, (int) (840 * $k), $finish, 16, true);
        $this->slab($c - 170, (int) ($c - 470 * $k), 340, 70, $finish, 16);
        // fone
        imagesetthickness($this->img, 46);
        imagearc($this->img, $c, (int) ($c - 300 * $k), 520, 520, 190, 350, $this->ink(52, 50, 48));
        imagesetthickness($this->img, 1);
        imagefilledellipse($this->img, $c - 250, (int) ($c - 150 * $k), 150, 210, $this->ink(52, 50, 48));
        imagefilledellipse($this->img, $c + 250, (int) ($c - 150 * $k), 150, 210, $this->ink(52, 50, 48));
    }

    private function drawCableOrganizer(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (960 * $k);
        $this->shadow($c + 20, $c + 260, $w, 80, 70);
        $this->slab($c - (int) ($w / 2), $c - 90, $w, 220, $finish, 26);
        for ($i = 0; $i < 6; $i++) {
            $x = $c - (int) ($w / 2) + (int) (($i + 0.5) * $w / 6);
            imagefilledellipse($this->img, $x, $c - 90, 90, 110, $this->color(self::BACKDROPS[0][0]));
            $cable = [[40, 40, 40], [240, 240, 236], [196, 83, 45], [60, 90, 140], [40, 40, 40], [120, 120, 118]][$i];
            imagesetthickness($this->img, 26);
            imageline($this->img, $x, $c - 400, $x, $c - 100, $this->color($cable));
            imagesetthickness($this->img, 1);
        }
    }

    private function drawSpiceRack(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (1080 * $k);
        $this->shadow($c + 30, $c + 560, $w, 80, 70);
        foreach ([-260, 140] as $row) {
            $this->slab($c - (int) ($w / 2), $c + $row + 140, $w, 44, $finish, 18);
            for ($i = 0; $i < 5; $i++) {
                $x = $c - (int) ($w / 2) + 80 + $i * (int) (($w - 160) / 4);
                imagefilledrectangle($this->img, $x - 64, $c + $row - 110, $x + 64, $c + $row + 140, $this->ink(236, 238, 236, 20));
                imagefilledrectangle($this->img, $x - 64, $c + $row - 140, $x + 64, $c + $row - 100, $this->ink(60, 58, 56));
                $spice = [[176, 60, 40], [214, 160, 40], [88, 116, 60], [150, 90, 50], [196, 120, 60]][($i + (int) ($row > 0)) % 5];
                imagefilledrectangle($this->img, $x - 58, $c + $row - 10, $x + 58, $c + $row + 136, $this->color($spice));
            }
        }
        $this->slab($c - (int) ($w / 2) - 40, $c - 440, 40, 1020, $finish, 12, true);
        $this->slab($c + (int) ($w / 2), $c - 440, 40, 1020, $finish, 12, true);
    }

    private function drawSpiceCarousel(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 440, 880, 130, 90);
        imagefilledellipse($this->img, $c + 14, $c + 360, 900, 220, $this->edge($finish));
        imagefilledellipse($this->img, $c, $c + 340, 900, 220, $this->wood($finish));
        for ($i = 0; $i < 7; $i++) {
            $a = M_PI + $i * M_PI / 6;
            $x = (int) ($c + 360 * cos($a));
            $y = (int) ($c + 300 + 70 * sin($a) * -1);
            imagefilledrectangle($this->img, $x - 60, $y - 260, $x + 60, $y, $this->ink(236, 238, 236, 20));
            imagefilledrectangle($this->img, $x - 60, $y - 290, $x + 60, $y - 250, $this->ink(60, 58, 56));
            imagefilledrectangle($this->img, $x - 54, $y - 150, $x + 54, $y - 4, $this->color([[176, 60, 40], [214, 160, 40], [88, 116, 60], [150, 90, 50]][$i % 4]));
        }
        $this->slab($c - 30, $c - 380, 60, 700, $finish, 12, true);
    }

    private function drawKitchenKit(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2 - 220, self::SIZE / 2 + 200, 560, 380, 300, $finish, [[0.5, 0]]);
        $this->isoBox(self::SIZE / 2 + 260, self::SIZE / 2 + 240, 420, 300, 220, $finish, []);
        // colheres e espátulas saindo da caixa
        imagesetthickness($this->img, 26);
        foreach ([[-330, -260], [-240, -300], [-150, -250]] as [$dx, $dy]) {
            imageline($this->img, self::SIZE / 2 + $dx, self::SIZE / 2 + 120, self::SIZE / 2 + $dx - 20, self::SIZE / 2 + $dy - 150, $this->wood('carvalho'));
        }
        imagesetthickness($this->img, 1);
    }

    private function drawCuttingBoard(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (1120 * $k);
        $h = (int) (620 * $k);
        $this->shadow($c + 30, $c + (int) ($h / 2) + 60, $w, 90, 80);
        imagefilledellipse($this->img, $c + 20, $c + 50, $w, $h, $this->edge($finish));
        imagefilledellipse($this->img, $c, $c + 30, $w, $h, $this->wood($finish));
        // alça e frios
        imagefilledellipse($this->img, $c + (int) ($w / 2) - 60, $c + 30, 70, 70, $this->color(self::BACKDROPS[0][0]));
        foreach ([[-260, 0, [226, 170, 110]], [-80, -60, [240, 214, 150]], [120, 40, [196, 96, 80]], [280, -30, [236, 200, 120]]] as [$dx, $dy, $rgb]) {
            imagefilledellipse($this->img, $c + $dx, $c + 30 + $dy, 170, 120, $this->color($rgb));
        }
        imagefilledellipse($this->img, $c - 160, $c + 180, 90, 90, $this->color([120, 70, 110]));
    }

    private function drawGlassHolder(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $w = (int) (1000 * $k);
        $this->shadow($c + 20, $c - 200, $w, 60, 50);
        $this->slab($c - (int) ($w / 2), $c - 420, $w, 90, $finish, 20);
        for ($i = 0; $i < 3; $i++) {
            $x = $c - (int) ($w / 3) + $i * (int) ($w / 3);
            imagefilledpolygon($this->img, [$x - 20, $c - 330, $x + 20, $c - 330, $x + 12, $c - 40, $x - 12, $c - 40], $this->ink(220, 226, 228, 40));
            imagefilledellipse($this->img, $x, $c + 60, 230, 260, $this->ink(220, 226, 228, 40));
            imagefilledellipse($this->img, $x, $c + 100, 200, 170, $this->ink(150, 30, 50, 40));
            imagefilledrectangle($this->img, $x - 8, $c + 180, $x + 8, $c + 380, $this->ink(220, 226, 228, 40));
            imagefilledellipse($this->img, $x, $c + 390, 180, 40, $this->ink(220, 226, 228, 40));
        }
    }

    private function drawNapkinHolder(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 440, 820, 90, 80);
        foreach ([-170, 170] as $dx) {
            $pts = [$c + $dx - 60, $c + 400, $c + $dx - 60, $c - 160, $c + $dx, $c - 300, $c + $dx + 60, $c - 160, $c + $dx + 60, $c + 400];
            imagefilledpolygon($this->img, array_map(fn ($v, $i) => $v + 16, $pts, array_keys($pts)), $this->edge($finish));
            imagefilledpolygon($this->img, $pts, $this->wood($finish, true));
        }
        imagefilledrectangle($this->img, $c - 150, $c - 60, $c + 150, $c + 330, $this->ink(250, 248, 244));
        imageline($this->img, $c - 150, $c - 60, $c + 150, $c - 60, $this->ink(200, 196, 188));
        $this->slab($c - 400, $c + 400, 800, 60, $finish, 16);
    }

    private function drawDeskOrganizer(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 120, (int) (1000 * $k), (int) (400 * $k), (int) (300 * $k), $finish, [[0.3, 0], [0.62, 0]]);
        // lápis e papéis
        imagesetthickness($this->img, 22);
        foreach ([[-380, [196, 83, 45]], [-330, [214, 170, 50]], [-280, [60, 90, 140]]] as [$dx, $rgb]) {
            imageline($this->img, self::SIZE / 2 + $dx, self::SIZE / 2 - 60, self::SIZE / 2 + $dx + 30, self::SIZE / 2 - 420, $this->color($rgb));
        }
        imagesetthickness($this->img, 1);
        imagefilledrectangle($this->img, self::SIZE / 2 + 120, self::SIZE / 2 - 330, self::SIZE / 2 + 380, self::SIZE / 2 - 40, $this->ink(250, 248, 244));
    }

    private function drawPenHolder(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 420, 520, 100, 90);
        $this->slab($c - 210, $c - 160, 420, 560, $finish, 30, true);
        imagesetthickness($this->img, 20);
        foreach ([[-120, -560, [30, 30, 30]], [-40, -620, [196, 83, 45]], [50, -580, [60, 90, 140]], [130, -540, [40, 40, 40]]] as [$dx, $top, $rgb]) {
            imageline($this->img, $c + $dx, $c - 160, $c + $dx + 20, $c + $top, $this->color($rgb));
        }
        imagesetthickness($this->img, 1);
        imagefilledrectangle($this->img, $c - 150, $c + 120, $c + 150, $c + 190, $this->edge($finish));
    }

    private function drawLaptopStand(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 440, 1100, 110, 90);
        foreach ([-420, 360] as $dx) {
            $pts = [$c + $dx, $c + 400, $c + $dx + 60, $c + 400, $c + $dx + 60, $c - 60, $c + $dx, $c + 120];
            imagefilledpolygon($this->img, array_map(fn ($v, $i) => $v + 14, $pts, array_keys($pts)), $this->edge($finish));
            imagefilledpolygon($this->img, $pts, $this->wood($finish, true));
        }
        imagefilledpolygon($this->img, [$c - 520, $c + 150, $c + 500, $c - 90, $c + 520, $c - 40, $c - 500, $c + 200], $this->ink(180, 182, 186));
        imagefilledpolygon($this->img, [$c - 500, $c + 150, $c + 480, $c - 90, $c + 360, $c - 560, $c - 560, $c - 330], $this->ink(60, 62, 66));
        imagefilledpolygon($this->img, [$c - 470, $c + 110, $c + 440, $c - 110, $c + 330, $c - 520, $c - 520, $c - 310], $this->ink(120, 150, 176));
    }

    private function drawDocumentTray(string $finish, float $k): void
    {
        foreach ([0, 1, 2] as $i) {
            $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 300 - $i * 230, 900, 600, 110, $finish, [], $i === 0);
            imagefilledpolygon($this->img, [self::SIZE / 2 - 300, self::SIZE / 2 + 170 - $i * 230, self::SIZE / 2 + 260, self::SIZE / 2 + 20 - $i * 230, self::SIZE / 2 + 380, self::SIZE / 2 + 80 - $i * 230, self::SIZE / 2 - 180, self::SIZE / 2 + 230 - $i * 230], $this->ink(250, 248, 244));
        }
    }

    private function drawGiftBox(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 160, 820, 620, 360, $finish, []);
        // fita
        $c = self::SIZE / 2;
        imagefilledpolygon($this->img, [$c - 40, $c - 250, $c + 40, $c - 270, $c + 40, $c + 350, $c - 40, $c + 370], $this->ink(196, 83, 45));
        imagefilledellipse($this->img, $c - 80, $c - 300, 180, 90, $this->ink(196, 83, 45));
        imagefilledellipse($this->img, $c + 80, $c - 300, 180, 90, $this->ink(196, 83, 45));
        imagefilledellipse($this->img, $c, $c - 290, 70, 70, $this->ink(160, 60, 30));
    }

    private function drawMemoryBox(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 160, 900, 560, 320, $finish, []);
        $c = self::SIZE / 2;
        // coração gravado na tampa
        imagefilledellipse($this->img, $c - 50, $c - 170, 110, 100, $this->edge($finish));
        imagefilledellipse($this->img, $c + 50, $c - 170, 110, 100, $this->edge($finish));
        imagefilledpolygon($this->img, [$c - 104, $c - 160, $c + 104, $c - 160, $c, $c - 60], $this->edge($finish));
    }

    private function drawPhotoFrame(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 480, 760, 90, 80);
        $this->slab($c - 360, $c - 460, 720, 900, $finish, 26);
        imagefilledrectangle($this->img, $c - 260, $c - 360, $c + 260, $c + 240, $this->ink(212, 220, 226));
        imagefilledpolygon($this->img, [$c - 260, $c + 240, $c - 60, $c - 40, $c + 60, $c + 90, $c + 150, $c, $c + 260, $c + 240], $this->ink(120, 150, 110));
        imagefilledellipse($this->img, $c + 150, $c - 220, 110, 110, $this->ink(250, 220, 150));
        // corações da plaquinha gravada
        foreach ([-40, 40] as $dx) {
            imagefilledellipse($this->img, $c + $dx, $c + 330, 50, 46, $this->edge($finish));
        }
    }

    private function drawNameLetters(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 360, 1200, 80, 70);
        // letras "A N A" estilizadas em blocos
        $letters = [[-420, 'A'], [-60, 'N'], [300, 'A']];
        $colors = ['rosa', 'azul', 'verde'];
        foreach ($letters as $i => [$dx, $l]) {
            $f = $finish === 'mdf' ? $colors[$i] : $finish;
            $x = $c + $dx;
            if ($l === 'A') {
                $pts = [$x, $c + 280, $x + 120, $c - 280, $x + 220, $c - 280, $x + 340, $c + 280, $x + 240, $c + 280, $x + 210, $c + 140, $x + 130, $c + 140, $x + 100, $c + 280];
            } else {
                $pts = [$x, $c + 280, $x, $c - 280, $x + 90, $c - 280, $x + 230, $c + 40, $x + 230, $c - 280, $x + 320, $c - 280, $x + 320, $c + 280, $x + 230, $c + 280, $x + 90, $c - 40, $x + 90, $c + 280];
            }
            imagefilledpolygon($this->img, array_map(fn ($v, $n) => $v + ($n % 2 === 0 ? 18 : 22), $pts, array_keys($pts)), $this->edge($f));
            imagefilledpolygon($this->img, $pts, $this->wood($f));
            if ($l === 'A') {
                imagefilledpolygon($this->img, [$x + 150, $c + 40, $x + 170, $c - 60, $x + 190, $c + 40], $this->color(self::BACKDROPS[0][0]));
            }
        }
    }

    private function drawHouseShelf(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        $this->shadow($c + 20, $c + 560, 820, 100, 90);
        imagefilledpolygon($this->img, [$c + 20, $c - 620, $c + 460, $c - 200, $c + 440, $c + 560, $c - 400, $c + 560, $c - 420, $c - 200], $this->edge($finish));
        imagefilledpolygon($this->img, [$c, $c - 640, $c + 440, $c - 220, $c + 420, $c + 540, $c - 420, $c + 540, $c - 440, $c - 220], $this->wood($finish));
        imagefilledrectangle($this->img, $c - 360, $c - 170, $c + 360, $c + 480, $this->edge($finish));
        foreach ([-10, 250] as $y) {
            $this->slab($c - 360, $c + $y, 720, 34, $finish, 10);
        }
        // livros coloridos
        $x = $c - 330;
        foreach ([[70, 210, [196, 83, 45]], [60, 180, [60, 90, 140]], [80, 230, [214, 170, 50]], [50, 170, [88, 116, 96]], [70, 200, [226, 176, 168]]] as [$w, $h, $rgb]) {
            imagefilledrectangle($this->img, $x, $c - 10 - $h, $x + $w, $c - 12, $this->color($rgb));
            $x += $w + 12;
        }
        imagefilledellipse($this->img, $c + 180, $c + 170, 170, 150, $this->color([240, 214, 180]));
    }

    private function drawCloudMobile(string $finish, float $k): void
    {
        $c = self::SIZE / 2;
        imagesetthickness($this->img, 4);
        $this->slab($c - 450, $c - 640, 900, 40, $finish, 12);
        foreach ([[-330, 120, 'azul'], [-110, 320, 'branco'], [110, 180, 'rosa'], [330, 360, 'verde']] as [$dx, $len, $f]) {
            imageline($this->img, $c + $dx, $c - 600, $c + $dx, $c - 600 + $len, $this->ink(120, 110, 100));
            $y = $c - 600 + $len + 70;
            foreach ([[-60, 0, 150], [40, -30, 170], [110, 10, 120]] as [$ox, $oy, $d]) {
                imagefilledellipse($this->img, $c + $dx + $ox + 12, $y + $oy + 14, $d, $d, $this->edge($f));
            }
            foreach ([[-60, 0, 150], [40, -30, 170], [110, 10, 120]] as [$ox, $oy, $d]) {
                imagefilledellipse($this->img, $c + $dx + $ox, $y + $oy, $d, $d, $this->wood($f));
            }
        }
        imagesetthickness($this->img, 1);
    }

    private function drawBox(string $finish, float $k): void
    {
        $this->isoBox(self::SIZE / 2, self::SIZE / 2 + 140, 900, 600, 320, $finish, []);
    }

    /**
     * Caixa/bandeja em perspectiva isométrica com divisórias.
     *
     * @param list<array{0: float, 1: float}> $dividers frações [ao longo da largura, ao longo da profundidade]
     */
    private function isoBox(int $cx, int $cy, int $w, int $d, int $h, string $finish, array $dividers, bool $shadow = true): void
    {
        $shadow && $this->shadow($cx + 30, $cy + (int) ($d * 0.28) + 40, (int) ($w * 1.05), (int) ($d * 0.45), 90);
        $a = [$cx - (int) ($w / 2), $cy];                         // frente-esquerda (base)
        $b = [$cx + (int) ($w / 2) - (int) ($d * 0.35), $cy + (int) ($d * 0.2)]; // frente-direita
        $dx = (int) ($d * 0.35);
        $dy = (int) ($d * -0.45);
        $pt = static fn (array $p, int $ox, int $oy): array => [$p[0] + $ox, $p[1] + $oy];
        // fundo (interior) em tom mais escuro
        $top = [$pt($a, 0, -$h), $pt($b, 0, -$h), $pt($b, $dx, $dy - $h), $pt($a, $dx, $dy - $h)];
        imagefilledpolygon($this->img, array_merge(...$top), $this->edge($finish));
        $floor = [$pt($a, 16, -$h + 30), $pt($b, -16, -$h + 30), $pt($b, $dx - 16, $dy - $h + 30), $pt($a, $dx + 16, $dy - $h + 30)];
        imagefilledpolygon($this->img, array_merge(...$floor), $this->wood($finish));
        imagefilledpolygon($this->img, array_merge(...$floor), $this->ink(0, 0, 0, 96));
        foreach ($dividers as [$fw, $fd]) {
            if ($fw > 0) {
                $p1 = [(int) ($a[0] + ($b[0] - $a[0]) * $fw), (int) ($a[1] + ($b[1] - $a[1]) * $fw)];
                imagefilledpolygon($this->img, array_merge($pt($p1, 0, -$h), $pt($p1, $dx, $dy - $h), $pt($p1, $dx, $dy - $h + 26), $pt($p1, 0, -$h + 26)), $this->edge($finish));
                imagefilledpolygon($this->img, array_merge($pt($p1, 0, -$h), $pt($p1, $dx, $dy - $h), $pt($p1, $dx + 14, $dy - $h + 6), $pt($p1, 14, -$h + 6)), $this->wood($finish));
            }
            if ($fd > 0) {
                $o = [(int) ($dx * $fd), (int) ($dy * $fd)];
                imagefilledpolygon($this->img, array_merge($pt($a, $o[0], $o[1] - $h), $pt($b, $o[0], $o[1] - $h), $pt($b, $o[0], $o[1] - $h + 22), $pt($a, $o[0], $o[1] - $h + 22)), $this->wood($finish));
            }
        }
        // faces externas: frente (textura) e lateral direita (mais escura)
        imagefilledpolygon($this->img, array_merge($pt($b, 0, 0), $pt($b, $dx, $dy), $pt($b, $dx, $dy - $h), $pt($b, 0, -$h)), $this->edge($finish));
        imagefilledpolygon($this->img, array_merge($pt($b, 0, 0), $pt($b, $dx, $dy), $pt($b, $dx, $dy - $h), $pt($b, 0, -$h)), $this->ink(0, 0, 0, 105));
        imagefilledpolygon($this->img, array_merge($a, $b, $pt($b, 0, -$h), $pt($a, 0, -$h)), $this->wood($finish));
        // borda superior da frente (espessura da chapa)
        imagesetthickness($this->img, 14);
        imageline($this->img, $a[0], $a[1] - $h, $b[0], $b[1] - $h, $this->edge($finish));
        imagesetthickness($this->img, 1);
    }

    // ---- cor ----------------------------------------------------------------------------

    /** @param array{int,int,int} $rgb */
    private function color(array $rgb): int
    {
        return imagecolorallocate($this->img, $rgb[0], $rgb[1], $rgb[2]);
    }

    /** @param array{int,int,int} $rgb */
    private function colorOn(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     * @return array{int,int,int}
     */
    private function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));

        return [(int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t)];
    }
}
