<?php

declare(strict_types=1);

namespace GNesting\Services;

use DateTimeImmutable;
use GNesting\Core\ValidationException;
use GNesting\Enums\PersonalizationType;
use GNesting\Repositories\PersonalizationRepository;

/**
 * Valida a personalização enviada pelo cliente contra as regras do produto.
 *
 * - Só existem os campos que o administrador cadastrou; qualquer outro é ignorado.
 * - O valor é normalizado (espaços, maiúsculas na inicial, data ISO) antes de ir para o carrinho.
 * - O acréscimo vem SEMPRE da regra/opção no banco — nunca do navegador.
 * - Conjuntos de caracteres são presets fixos (sem regex digitada pelo admin: evita ReDoS).
 */
final class PersonalizationService
{
    /** Presets de caracteres: rótulo para o admin e padrão aplicado (Unicode, alfabeto latino). */
    public const CHARSETS = [
        'letters' => ['Somente letras e espaços', '/^[\p{Latin} ]+$/u'],
        'letters_numbers' => ['Letras, números e espaços', '/^[\p{Latin}0-9 ]+$/u'],
        'text_basic' => ['Texto com pontuação simples (. , - & \' ! ? ( ) / :)', '/^[\p{Latin}0-9 .,\-&\'!?()\/:]+$/u'],
    ];

    public const FIELD_PREFIX = 'pers_';

    public function __construct(private readonly PersonalizationRepository $rules)
    {
    }

    /** @return list<array<string, mixed>> regras ativas (com opções ativas) para a loja */
    public function rulesForProduct(int $productId): array
    {
        // Sem memoização: preço e regras precisam refletir o banco a cada leitura
        return $this->rules->rulesForProduct($productId, true);
    }

    /**
     * Valida e normaliza. Entrada: rule_id => valor bruto (texto; id da opção em "select").
     *
     * @param array<int, string> $input
     * @return array{items: list<array{rule_id: int, value_id: ?int, value_text: ?string, label: string, display: string, price_delta_cents: int}>,
     *               hash: string, price_delta_cents: int}
     * @throws ValidationException chaves "pers_{rule_id}"
     */
    public function validate(int $productId, array $input): array
    {
        $items = [];
        $errors = [];

        foreach ($this->rulesForProduct($productId) as $rule) {
            $ruleId = (int) $rule['id'];
            $raw = trim((string) preg_replace('/\s+/u', ' ', (string) ($input[$ruleId] ?? '')));
            if (class_exists(\Normalizer::class)) {
                $raw = (string) \Normalizer::normalize($raw, \Normalizer::FORM_C);
            }

            if ($raw === '') {
                if ((bool) $rule['is_required']) {
                    $errors[self::FIELD_PREFIX . $ruleId] = "Preencha \"{$rule['label']}\".";
                }
                continue;
            }

            try {
                $items[] = $this->validateValue($rule, $raw);
            } catch (BusinessRuleException $e) {
                $errors[self::FIELD_PREFIX . $ruleId] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'items' => $items,
            'hash' => $this->hash($items),
            'price_delta_cents' => array_sum(array_column($items, 'price_delta_cents')),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array{rule_id: int, value_id: ?int, value_text: ?string, label: string, display: string, price_delta_cents: int}
     * @throws BusinessRuleException mensagem para o cliente
     */
    private function validateValue(array $rule, string $raw): array
    {
        $label = (string) $rule['label'];
        $ruleDelta = (int) $rule['price_delta_cents'];
        $item = ['rule_id' => (int) $rule['id'], 'value_id' => null, 'value_text' => null, 'label' => $label];

        switch (PersonalizationType::tryFrom((string) $rule['type'])) {
            case PersonalizationType::Select:
                $valueId = filter_var($raw, FILTER_VALIDATE_INT);
                foreach ($rule['values'] as $value) {
                    if ($valueId !== false && (int) $value['id'] === $valueId) {
                        return [
                            'value_id' => $valueId,
                            'display' => (string) $value['label'],
                            'price_delta_cents' => $ruleDelta + (int) $value['price_delta_cents'],
                        ] + $item;
                    }
                }
                throw new BusinessRuleException("Escolha uma das opções de \"{$label}\".");

            case PersonalizationType::Date:
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ?: DateTimeImmutable::createFromFormat('!d/m/Y', $raw);
                $errors = DateTimeImmutable::getLastErrors();
                if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                    || (int) $date->format('Y') < 1900 || (int) $date->format('Y') > 2100) {
                    throw new BusinessRuleException("Informe uma data válida em \"{$label}\" (dd/mm/aaaa).");
                }

                return ['value_text' => $date->format('Y-m-d'), 'display' => $date->format('d/m/Y'), 'price_delta_cents' => $ruleDelta] + $item;

            case PersonalizationType::Initial:
                $text = mb_strtoupper(str_replace(' ', '', $raw), 'UTF-8');
                $max = max(1, (int) ($rule['max_length'] ?? 1));
                if (!preg_match(self::CHARSETS['letters'][1], $text) || mb_strlen($text) > $max) {
                    throw new BusinessRuleException($max === 1
                        ? "Em \"{$label}\", use uma única letra."
                        : "Em \"{$label}\", use até {$max} letras, sem números ou símbolos.");
                }

                return ['value_text' => $text, 'display' => $text, 'price_delta_cents' => $ruleDelta] + $item;

            case PersonalizationType::Text:
                $length = mb_strlen($raw);
                $min = (int) ($rule['min_length'] ?? 1);
                $max = (int) ($rule['max_length'] ?? 255);
                if ($length < $min || $length > $max) {
                    throw new BusinessRuleException($min > 1
                        ? "\"{$label}\" precisa ter entre {$min} e {$max} caracteres."
                        : "\"{$label}\" pode ter até {$max} caracteres.");
                }
                [$charsetLabel, $pattern] = self::CHARSETS[(string) $rule['charset']] ?? self::CHARSETS['letters_numbers'];
                if (!preg_match($pattern, $raw)) {
                    throw new BusinessRuleException("\"{$label}\" aceita: " . mb_strtolower($charsetLabel) . '.');
                }

                return ['value_text' => $raw, 'display' => $raw, 'price_delta_cents' => $ruleDelta] + $item;
        }

        throw new BusinessRuleException("Campo \"{$label}\" indisponível.");
    }

    /**
     * Identifica a combinação (linha do carrinho): mesma variante com personalizações
     * diferentes = linhas diferentes. Sem personalização = ''.
     *
     * @param list<array{rule_id: int, value_id: ?int, value_text: ?string}> $items
     */
    private function hash(array $items): string
    {
        if ($items === []) {
            return '';
        }
        $canonical = array_map(static fn (array $i): array => [$i['rule_id'], $i['value_id'], $i['value_text']], $items);
        usort($canonical, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
