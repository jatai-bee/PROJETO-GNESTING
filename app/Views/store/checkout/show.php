<?php
/**
 * @var array<string, mixed>      $cart            resumo do CartService (sem pendências)
 * @var array<string, mixed>|null $customer        cliente logado
 * @var array<string, mixed>|null $contact         CPF/telefone já cadastrados
 * @var list<array<string, mixed>> $savedAddresses
 * @var list<\GNesting\Services\Shipping\ShippingOption> $shippingOptions
 * @var string|null $quotedZip
 * @var list<string> $states
 * @var array $errors
 * @var array $old
 */
use GNesting\Helpers\BrazilianDocument;
use GNesting\Helpers\ZipCode;

$f = fn (string $partial, array $vars): string => $this->partial($partial, $vars + ['errors' => $errors, 'old' => $old]);
$chosenAddress = (string) ($old['address_id'] ?? ($savedAddresses[0]['id'] ?? ''));
$chosenShipping = (string) ($old['shipping_code'] ?? ($shippingOptions[0]->code ?? ''));
$stateOptions = array_combine($states, $states);
$chosenOption = null;
foreach ($shippingOptions as $option) {
    if ($option->code === $chosenShipping) {
        $chosenOption = $option;
    }
}
// Cupom de frete grátis: abate o valor da opção mais barata (a mesma regra do servidor)
$coupon = ($cart['coupon']['error'] ?? null) === null ? $cart['coupon'] : null;
$cheapest = $shippingOptions === [] ? 0 : min(array_map(static fn ($o) => $o->priceCents, $shippingOptions));
$shippingPrice = static fn ($o): int => ($coupon['free_shipping'] ?? false) ? $o->priceCents - min($o->priceCents, $cheapest) : $o->priceCents;
$itemsTotal = (int) $cart['subtotal_cents'] - (int) $cart['discount_cents'];
?>
<div class="container">
    <header class="page-head">
        <h1 class="page-head__title">Finalizar compra</h1>
        <?php if ($customer === null): ?>
            <p class="page-head__intro">Compre sem criar conta. Já é cliente? <a href="<?= e(url('/entrar?voltar=/checkout')) ?>">Entre</a> para usar seus endereços.</p>
        <?php endif ?>
    </header>

    <form method="post" action="<?= e(url('/checkout')) ?>" class="checkout" novalidate data-checkout>
        <?= csrf_field() ?>
        <div class="checkout__main">
            <section class="checkout-step">
                <h2 class="checkout-step__title"><span>1</span> Seus dados</h2>
                <div class="form-grid-2">
                    <?= $f('partials/field', ['name' => 'name', 'label' => 'Nome completo', 'value' => $customer['name'] ?? '', 'autocomplete' => 'name', 'maxlength' => 120]) ?>
                    <?php if ($customer === null): ?>
                        <?= $f('partials/field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'autocomplete' => 'email', 'maxlength' => 190,
                            'hint' => 'Enviamos a confirmação e o link do pedido para este e-mail.']) ?>
                    <?php else: ?>
                        <div class="field"><span class="field__label">E-mail</span><p class="checkout__static"><?= e($customer['email']) ?></p></div>
                    <?php endif ?>
                    <?= $f('partials/field', ['name' => 'cpf', 'label' => 'CPF', 'value' => BrazilianDocument::formatCpf($contact['cpf'] ?? null), 'inputmode' => 'numeric',
                        'maxlength' => 14, 'placeholder' => '000.000.000-00', 'hint' => 'Para a nota fiscal e o transporte.']) ?>
                    <?= $f('partials/field', ['name' => 'phone', 'label' => 'Celular com DDD', 'type' => 'tel', 'autocomplete' => 'tel', 'value' => BrazilianDocument::formatPhone($contact['phone'] ?? null),
                        'maxlength' => 20, 'placeholder' => '(71) 99999-9999']) ?>
                </div>
            </section>

            <section class="checkout-step">
                <h2 class="checkout-step__title"><span>2</span> Entrega</h2>

                <?php if ($savedAddresses !== []): ?>
                    <fieldset class="choice-list">
                        <legend class="visually-hidden">Endereço de entrega</legend>
                        <?php foreach ($savedAddresses as $address): ?>
                            <label class="choice">
                                <input type="radio" name="address_id" value="<?= e($address['id']) ?>" <?= $chosenAddress === (string) $address['id'] ? 'checked' : '' ?> data-address-zip="<?= e($address['zip_code']) ?>">
                                <span>
                                    <strong><?= e($address['recipient_name']) ?></strong>
                                    <?= e("{$address['street']}, {$address['number']}" . ($address['complement'] ? " — {$address['complement']}" : '')) ?><br>
                                    <?= e("{$address['district']}, {$address['city']}/{$address['state']} · CEP " . ZipCode::format($address['zip_code'])) ?>
                                </span>
                            </label>
                        <?php endforeach ?>
                        <label class="choice">
                            <input type="radio" name="address_id" value="" <?= $chosenAddress === '' ? 'checked' : '' ?>>
                            <span><strong>Usar outro endereço</strong></span>
                        </label>
                    </fieldset>
                <?php endif ?>

                <div class="address-form" data-address-form>
                    <div class="form-grid-3">
                        <?= $f('partials/field', ['name' => 'zip_code', 'label' => 'CEP', 'inputmode' => 'numeric', 'autocomplete' => 'postal-code', 'maxlength' => 9, 'placeholder' => '00000-000']) ?>
                        <?= $f('partials/field', ['name' => 'street', 'label' => 'Rua', 'autocomplete' => 'address-line1', 'maxlength' => 160]) ?>
                        <?= $f('partials/field', ['name' => 'number', 'label' => 'Número', 'maxlength' => 20]) ?>
                        <?= $f('partials/field', ['name' => 'complement', 'label' => 'Complemento', 'required' => false, 'autocomplete' => 'address-line2', 'maxlength' => 80]) ?>
                        <?= $f('partials/field', ['name' => 'district', 'label' => 'Bairro', 'maxlength' => 80]) ?>
                        <?= $f('partials/field', ['name' => 'city', 'label' => 'Cidade', 'autocomplete' => 'address-level2', 'maxlength' => 80]) ?>
                        <?= $f('partials/select', ['name' => 'state', 'label' => 'UF', 'options' => $stateOptions, 'placeholder' => 'UF']) ?>
                        <?= $f('partials/field', ['name' => 'recipient_name', 'label' => 'Quem recebe', 'required' => false, 'maxlength' => 120, 'hint' => 'Vazio = o seu nome.']) ?>
                    </div>
                    <?php if ($customer !== null): ?>
                        <?= $f('partials/checkbox', ['name' => 'save_address', 'label' => 'Salvar este endereço na minha conta', 'checked' => true]) ?>
                    <?php endif ?>
                </div>
            </section>

            <section class="checkout-step" aria-live="polite">
                <h2 class="checkout-step__title"><span>3</span> Frete</h2>
                <input type="hidden" name="quoted_zip" value="<?= e($quotedZip ?? '') ?>" data-quoted-zip>
                <div data-shipping-options>
                    <?php if ($shippingOptions === []): ?>
                        <p class="muted"><?= $quotedZip === null ? 'Informe o CEP e calcule o frete.' : 'Não entregamos neste CEP pelas opções automáticas. Fale com a gente.' ?></p>
                    <?php else: ?>
                        <p class="muted">Para o CEP <?= e(ZipCode::format($quotedZip)) ?>:</p>
                        <fieldset class="choice-list">
                            <legend class="visually-hidden">Opção de entrega</legend>
                            <?php foreach ($shippingOptions as $option): ?>
                                <label class="choice">
                                    <input type="radio" name="shipping_code" value="<?= e($option->code) ?>" <?= $chosenShipping === $option->code ? 'checked' : '' ?>
                                           data-price-cents="<?= e($shippingPrice($option)) ?>" data-price="<?= e($shippingPrice($option) === 0 ? 'Grátis' : money($shippingPrice($option))) ?>">
                                    <span class="choice__row">
                                        <span><strong><?= e($option->service) ?></strong><br>
                                            <small class="muted"><?= $option->days > 0 ? e("até {$option->days} dias úteis após a produção") : 'combine a retirada após a produção' ?></small></span>
                                        <strong>
                                            <?php if ($shippingPrice($option) !== $option->priceCents): ?><s class="muted"><?= e(money($option->priceCents)) ?></s><?php endif ?>
                                            <?= $shippingPrice($option) === 0 ? 'Grátis' : e(money($shippingPrice($option))) ?>
                                        </strong>
                                    </span>
                                </label>
                            <?php endforeach ?>
                        </fieldset>
                    <?php endif ?>
                </div>
                <?php if (!empty($errors['shipping_code'])): ?><p class="field__error"><?= e($errors['shipping_code']) ?></p><?php endif ?>
                <button type="submit" name="action" value="quote" class="btn btn--secondary btn--sm" data-quote-button>Calcular frete</button>
            </section>
        </div>

        <aside class="checkout__summary cart__summary" aria-labelledby="resumo-pedido">
            <h2 id="resumo-pedido" class="cart__summary-title">Seu pedido</h2>
            <ul class="summary-items">
                <?php foreach ($cart['items'] as $item): ?>
                    <li>
                        <span><?= e($item['quantity']) ?> × <?= e($item['name']) ?><?php if ($item['variant_name']): ?> <small class="muted">(<?= e($item['variant_name']) ?>)</small><?php endif ?>
                            <?php foreach ($item['personalization'] as $choice): ?><br><small class="muted"><?= e($choice['label']) ?>: <?= e($choice['display']) ?></small><?php endforeach ?>
                        </span>
                        <span><?= e(money($item['line_total_cents'])) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
            <dl class="summary-lines">
                <div><dt>Subtotal</dt><dd><?= e(money($cart['subtotal_cents'])) ?></dd></div>
                <?php if ($coupon !== null): ?>
                    <div class="summary-lines__discount"><dt>Cupom <?= e($coupon['code']) ?></dt>
                        <dd><?= (int) $cart['discount_cents'] > 0 ? '− ' . e(money((int) $cart['discount_cents'])) : e($coupon['label']) ?></dd></div>
                <?php endif ?>
                <div><dt>Frete</dt><dd data-summary-shipping><?= $chosenOption === null ? '<span class="muted">calcule acima</span>' : e($shippingPrice($chosenOption) === 0 ? 'Grátis' : money($shippingPrice($chosenOption))) ?></dd></div>
                <div class="summary-lines__total"><dt>Total</dt><dd data-summary-total data-subtotal="<?= e($itemsTotal) ?>"><?= e(money($itemsTotal + ($chosenOption === null ? 0 : $shippingPrice($chosenOption)))) ?></dd></div>
            </dl>
            <p class="field__hint">Produção em até <?= e($cart['lead_days']) ?> dia<?= $cart['lead_days'] === 1 ? '' : 's' ?> úte<?= $cart['lead_days'] === 1 ? 'il' : 'is' ?>, depois o prazo de entrega.</p>
            <button type="submit" class="btn btn--primary btn--block">Ir para o pagamento</button>
            <p class="field__hint">Pix, cartão ou boleto em ambiente seguro do Mercado Pago. Não guardamos dados de cartão.</p>
            <a class="cart__continue" href="<?= e(url('/carrinho')) ?>">Voltar ao carrinho</a>
        </aside>
    </form>
</div>
