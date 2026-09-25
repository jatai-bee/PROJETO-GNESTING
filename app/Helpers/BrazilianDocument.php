<?php

declare(strict_types=1);

namespace GNesting\Helpers;

/** CPF e telefone brasileiros: normalização (só dígitos) e validação. */
final class BrazilianDocument
{
    /** CPF com dígitos verificadores válidos; retorna só os 11 dígitos ou null. */
    public static function cpf(string $value): ?string
    {
        $cpf = (string) preg_replace('/\D/', '', $value);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return null;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return null;
            }
        }

        return $cpf;
    }

    public static function formatCpf(?string $cpf): string
    {
        return $cpf !== null && strlen($cpf) === 11
            ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9)
            : (string) $cpf;
    }

    /** CPF mascarado para quem não precisa do número completo: ***.982.247-** */
    public static function maskCpf(?string $cpf): string
    {
        return $cpf !== null && strlen($cpf) === 11 ? '***.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-**' : '';
    }

    /**
     * Telefone com DDD (10 ou 11 dígitos, aceita +55 na frente) → E.164 sem "+": 5571999998888.
     */
    public static function phone(string $value): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $value);
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        if (!preg_match('/^[1-9]{2}(9\d{8}|[2-5]\d{7})$/', $digits)) {
            return null;
        }

        return '55' . $digits;
    }

    public static function formatPhone(?string $phone): string
    {
        if ($phone === null || strlen($phone) < 12) {
            return (string) $phone;
        }
        $local = substr($phone, 2);
        $ddd = substr($local, 0, 2);
        $number = substr($local, 2);

        return "({$ddd}) " . (strlen($number) === 9 ? substr($number, 0, 5) . '-' . substr($number, 5) : substr($number, 0, 4) . '-' . substr($number, 4));
    }
}
