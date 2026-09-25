<?php

declare(strict_types=1);

namespace GNesting\Core;

/**
 * Validação de entrada no servidor, com mensagens em português.
 *
 *   Validator::validate($data, [
 *       'email' => 'required|email|max:190',
 *       'password' => 'required|min:8|max:128',
 *       'password_confirmation' => 'required|same:password',
 *   ], ['email' => 'E-mail']);
 *
 * Regras: required, email, min:n, max:n (caracteres), same:campo, in:a,b,c, integer, digits:n,
 *         gte:n, lte:n (inteiros), money (reais: "129,90"), slug, sku
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @return array<string, string> primeiro erro de cada campo (vazio = válido)
     */
    public static function errors(array $data, array $rules, array $labels = []): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $isEmpty = $value === null || (is_string($value) && trim($value) === '');

            foreach (explode('|', $ruleString) as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name !== 'required' && $isEmpty) {
                    continue; // campos opcionais vazios não passam pelas demais regras
                }

                $message = self::check($name, $param, $value, $data, $label, $labels);
                if ($message !== null) {
                    $errors[$field] = $message;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @param list<string>          $keepOld campos devolvidos ao formulário em caso de erro
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $labels = [], array $keepOld = []): void
    {
        $errors = self::errors($data, $rules, $labels);
        if ($errors !== []) {
            throw new ValidationException($errors, array_intersect_key($data, array_flip($keepOld)));
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $labels
     */
    private static function check(string $name, ?string $param, mixed $value, array $data, string $label, array $labels): ?string
    {
        $string = is_scalar($value) ? (string) $value : '';

        return match ($name) {
            'required' => ($value === null || (is_string($value) && trim($value) === '') || is_array($value))
                ? "O campo {$label} é obrigatório." : null,
            'email' => (filter_var($string, FILTER_VALIDATE_EMAIL) === false)
                ? "{$label} não é um e-mail válido." : null,
            'min' => (mb_strlen($string) < (int) $param)
                ? "{$label} deve ter pelo menos {$param} caracteres." : null,
            'max' => (mb_strlen($string) > (int) $param)
                ? "{$label} deve ter no máximo {$param} caracteres." : null,
            'same' => ($string !== (string) ($data[(string) $param] ?? ''))
                ? "{$label} não confere com " . ($labels[(string) $param] ?? (string) $param) . '.' : null,
            'in' => (!in_array($string, explode(',', (string) $param), true))
                ? "{$label} contém uma opção inválida." : null,
            'integer' => (filter_var($string, FILTER_VALIDATE_INT) === false)
                ? "{$label} deve ser um número inteiro." : null,
            'digits' => (!preg_match('/^\d{' . (int) $param . '}$/', $string))
                ? "{$label} deve ter {$param} dígitos." : null,
            'gte' => (filter_var($string, FILTER_VALIDATE_INT) === false || (int) $string < (int) $param)
                ? "{$label} deve ser um número inteiro maior ou igual a {$param}." : null,
            'lte' => (filter_var($string, FILTER_VALIDATE_INT) === false || (int) $string > (int) $param)
                ? "{$label} deve ser no máximo {$param}." : null,
            'money' => (parse_money($string) === null)
                ? "{$label} deve ser um valor em reais, por exemplo 129,90." : null,
            'decimal' => (parse_decimal($string) === null)
                ? "{$label} deve ser um número com até 2 casas decimais, por exemplo 6 ou 2,75." : null,
            'slug' => (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $string))
                ? "{$label} deve conter apenas letras minúsculas sem acento, números e hífens." : null,
            'sku' => (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $string))
                ? "{$label} deve conter apenas letras, números, hífen, ponto ou sublinhado." : null,
            default => throw new \InvalidArgumentException("Regra de validação desconhecida: {$name}"),
        };
    }
}
