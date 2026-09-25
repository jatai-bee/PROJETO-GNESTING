<?php

declare(strict_types=1);

namespace GNesting\Helpers;

/**
 * CEP: normalização e UF/região pela faixa dos Correios.
 */
final class ZipCode
{
    /** Faixas de CEP (5 primeiros dígitos, inclusive) por UF. */
    private const RANGES = [
        ['SP', 1000, 19999], ['RJ', 20000, 28999], ['ES', 29000, 29999], ['MG', 30000, 39999],
        ['BA', 40000, 48999], ['SE', 49000, 49999], ['PE', 50000, 56999], ['AL', 57000, 57999],
        ['PB', 58000, 58999], ['RN', 59000, 59999], ['CE', 60000, 63999], ['PI', 64000, 64999],
        ['MA', 65000, 65999], ['PA', 66000, 68899], ['AP', 68900, 68999], ['AM', 69000, 69299],
        ['RR', 69300, 69399], ['AM', 69400, 69899], ['AC', 69900, 69999], ['DF', 70000, 72799],
        ['GO', 72800, 72999], ['DF', 73000, 73699], ['GO', 73700, 76799], ['RO', 76800, 76999],
        ['TO', 77000, 77999], ['MT', 78000, 78899], ['MS', 79000, 79999], ['PR', 80000, 87999],
        ['SC', 88000, 89999], ['RS', 90000, 99999],
    ];

    public const REGIONS = [
        'N' => ['AC', 'AP', 'AM', 'PA', 'RO', 'RR', 'TO'],
        'NE' => ['AL', 'BA', 'CE', 'MA', 'PB', 'PE', 'PI', 'RN', 'SE'],
        'CO' => ['DF', 'GO', 'MT', 'MS'],
        'SE' => ['ES', 'MG', 'RJ', 'SP'],
        'S' => ['PR', 'RS', 'SC'],
    ];

    /** "40.140-110" → "40140110"; null se não tiver 8 dígitos. */
    public static function normalize(string $zip): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $zip);

        return strlen($digits) === 8 && $digits !== '00000000' ? $digits : null;
    }

    public static function format(string $zip): string
    {
        return strlen($zip) === 8 ? substr($zip, 0, 5) . '-' . substr($zip, 5) : $zip;
    }

    public static function state(string $zip): ?string
    {
        $normalized = self::normalize($zip);
        if ($normalized === null) {
            return null;
        }
        $prefix = (int) substr($normalized, 0, 5);
        foreach (self::RANGES as [$uf, $from, $to]) {
            if ($prefix >= $from && $prefix <= $to) {
                return $uf;
            }
        }

        return null;
    }

    public static function region(string $state): ?string
    {
        foreach (self::REGIONS as $region => $states) {
            if (in_array($state, $states, true)) {
                return $region;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function states(): array
    {
        $all = array_merge(...array_values(self::REGIONS));
        sort($all);

        return $all;
    }
}
