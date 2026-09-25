<?php

declare(strict_types=1);

/*
 * Funções globais de apoio. Mantidas pequenas e sem regra de negócio.
 * Carregadas pelo Composer (autoload.files).
 */

use GNesting\Core\App;
use GNesting\Core\Config;
use GNesting\Core\Csrf;

if (!function_exists('env')) {
    /** Lê uma variável de ambiente carregada do .env, convertendo true/false/null. */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => $value,
        };
    }
}

if (!function_exists('app')) {
    /**
     * @template T of object
     * @param class-string<T>|null $id
     * @return ($id is null ? \GNesting\Core\Container : T)
     */
    function app(?string $id = null): object
    {
        $container = App::container();

        return $id === null ? $container : $container->get($id);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app(Config::class)->get($key, $default);
    }
}

if (!function_exists('e')) {
    /** Escapa qualquer valor para saída HTML. Use em TODA impressão em views. */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('money')) {
    /** Formata centavos em reais sem usar ponto flutuante: 12990 → "R$ 129,90". */
    function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $reais = number_format(intdiv($cents, 100), 0, ',', '.');

        return sprintf('%sR$ %s,%02d', $sign, $reais, $cents % 100);
    }
}

if (!function_exists('parse_money')) {
    /**
     * Converte valor digitado em reais para centavos, sem ponto flutuante.
     * Aceita "129,90", "1.234,56", "R$ 15", "129.90". Retorna null se inválido ou negativo.
     */
    function parse_money(string $value): ?int
    {
        $value = str_replace(['R$', ' ', "\u{00A0}"], '', trim($value));
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            // formato brasileiro: ponto = milhar, vírgula = decimal
            if (!preg_match('/^\d{1,3}(\.\d{3})*,\d{1,2}$|^\d+,\d{1,2}$/', $value)) {
                return null;
            }
            [$reais, $centavos] = explode(',', str_replace('.', '', $value));
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $value)) {
            [$reais, $centavos] = explode('.', $value);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$|^\d+$/', $value)) {
            [$reais, $centavos] = [str_replace('.', '', $value), '0'];
        } else {
            return null;
        }

        if (strlen($reais) > 9) {
            return null; // acima de R$ 999.999.999 não faz sentido para a loja
        }

        return (int) $reais * 100 + (int) str_pad($centavos, 2, '0');
    }
}

if (!function_exists('parse_decimal')) {
    /**
     * Número decimal digitado ("6", "6,5", "2.75", "1.234,5") para o formato do banco
     * com 2 casas ("6.50"), sem ponto flutuante. Null se inválido ou negativo.
     */
    function parse_decimal(string $value): ?string
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value); // formato brasileiro
        }
        if (!preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $value, $m)) {
            return null;
        }

        $integer = ltrim($m[1], '0') ?: '0';

        return $integer . '.' . str_pad($m[2] ?? '', 2, '0');
    }
}

if (!function_exists('format_decimal')) {
    /** "6.50" → "6,5"; "6.00" → "6". Para exibir e repreencher formulários. */
    function format_decimal(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        [$int, $dec] = array_pad(explode('.', (string) $value, 2), 2, '');
        $dec = rtrim($dec, '0');

        return $int . ($dec === '' ? '' : ',' . $dec);
    }
}

if (!function_exists('format_minutes')) {
    /** 95 → "1 h 35 min"; 40 → "40 min". */
    function format_minutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $rest = $minutes % 60;

        return intdiv($minutes, 60) . ' h' . ($rest > 0 ? ' ' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT) . ' min' : '');
    }
}

if (!function_exists('business_days_after')) {
    /**
     * Data (fuso da loja, Y-m-d) N dias úteis depois de um DATETIME UTC.
     * Pula sábados e domingos; feriados não são considerados.
     */
    function business_days_after(string $utc, int $days): string
    {
        $date = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo')));
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6) {
                $days--;
            }
        }

        return $date->format('Y-m-d');
    }
}

if (!function_exists('today_local')) {
    /** Data de hoje no fuso da loja (Y-m-d). */
    function today_local(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo'))))->format('Y-m-d');
    }
}

if (!function_exists('whatsapp_url')) {
    /** Link wa.me com mensagem pronta. $phone em E.164 sem "+" (5571999998888). */
    function whatsapp_url(?string $phone, string $text = ''): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) < 12) {
            return null;
        }

        return 'https://wa.me/' . $digits . ($text === '' ? '' : '?text=' . rawurlencode($text));
    }
}

if (!function_exists('money_input')) {
    /** Centavos para o campo de formulário: 12990 → "129,90". */
    function money_input(?int $cents): string
    {
        return $cents === null ? '' : sprintf('%d,%02d', intdiv($cents, 100), $cents % 100);
    }
}

if (!function_exists('upload_url')) {
    /**
     * URL de uma imagem enviada. $path aponta para a versão de 1600 px;
     * $width escolhe outra versão gerada (400, 800 ou 1600).
     */
    function upload_url(string $path, int $width = 1600): string
    {
        $path = (string) preg_replace('/-1600\.(webp|jpg)$/', '-' . $width . '.$1', $path);

        return url('/uploads/' . ltrim($path, '/'));
    }
}

if (!function_exists('absolute_upload_url')) {
    /** URL absoluta de uma imagem enviada (Open Graph, dados estruturados, sitemap). */
    function absolute_upload_url(string $path, int $width = 1600): string
    {
        $path = (string) preg_replace('/-1600\.(webp|jpg)$/', '-' . $width . '.$1', $path);

        return absolute_url('/uploads/' . ltrim($path, '/'));
    }
}

if (!function_exists('query_url')) {
    /**
     * URL com query string, sem parâmetros vazios. Útil para filtros e paginação.
     *
     * @param array<string, scalar|null> $params
     */
    function query_url(string $path, array $params): string
    {
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== 0);
        $query = http_build_query($params);

        return url($path) . ($query === '' ? '' : '?' . $query);
    }
}

if (!function_exists('url')) {
    /** URL relativa ao caminho base da aplicação (suporta instalação em subpasta). */
    function url(string $path = '/'): string
    {
        $base = (string) config('app.base_path', '');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }
}

if (!function_exists('absolute_url')) {
    /** URL absoluta a partir de APP_URL (canônicas, e-mails, sitemap). */
    function absolute_url(string $path = '/'): string
    {
        $origin = rtrim((string) config('app.url', ''), '/');
        $base = (string) config('app.base_path', '');
        if ($base !== '' && str_ends_with($origin, $base)) {
            $origin = substr($origin, 0, -strlen($base));
        }

        return $origin . url($path);
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        return url('/assets/' . ltrim($path, '/'));
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(Csrf::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('slugify')) {
    /** "Relógio Geométrico G-Nesting" → "relogio-geometrico-g-nesting" */
    function slugify(string $text): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', '×' => 'x',
        ];
        $text = strtr(mb_strtolower($text, 'UTF-8'), $map);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }
}

if (!function_exists('format_datetime')) {
    /** Converte um DATETIME UTC do banco para o fuso da loja. */
    function format_datetime(?string $utc, string $format = 'd/m/Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo')))->format($format);
    }
}

if (!function_exists('now_utc')) {
    /** Data/hora atual em UTC no formato DATETIME do MySQL. */
    function now_utc(string $modifier = ''): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($modifier !== '') {
            $date = $date->modify($modifier);
        }

        return $date->format('Y-m-d H:i:s');
    }
}
