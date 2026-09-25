<?php

declare(strict_types=1);

namespace GNesting\Helpers;

/** Confere se um IP (v4 ou v6) pertence a um IP exato ou a uma faixa CIDR ("173.245.48.0/20"). */
final class IpRange
{
    /** @param list<string> $ranges */
    public static function matchesAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::matches($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $range): bool
    {
        $range = trim($range);
        if ($range === '') {
            return false;
        }
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
