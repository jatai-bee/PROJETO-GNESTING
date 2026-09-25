<?php
/** @var list<array<string, mixed>> $coupons */
use GNesting\Services\CouponService;

$now = now_utc();
?>
<div class="page-header">
    <h1 class="page-title">Cupons</h1>
    <a class="btn btn--primary" href="<?= e(url('/admin/cupons/novo')) ?>">Novo cupom</a>
</div>

<section class="panel">
    <?php if ($coupons === []): ?>
        <p class="muted">Nenhum cupom cadastrado.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Código</th><th>Benefício</th><th>Regras</th><th class="table__num">Usos</th><th class="table__num">Descontos dados</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead>
                <tbody>
                <?php foreach ($coupons as $coupon): ?>
                    <?php
                    $expired = $coupon['ends_at'] !== null && $coupon['ends_at'] <= $now;
                    $scheduled = $coupon['starts_at'] !== null && $coupon['starts_at'] > $now;
                    $exhausted = $coupon['usage_limit'] !== null && (int) $coupon['times_used'] >= (int) $coupon['usage_limit'];
                    ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/cupons/' . $coupon['id'] . '/editar')) ?>"><code><?= e($coupon['code']) ?></code></a>
                            <?php if ($coupon['description']): ?><br><small class="muted"><?= e($coupon['description']) ?></small><?php endif ?></td>
                        <td><?= e(CouponService::describe($coupon)) ?></td>
                        <td><small>
                            <?= $coupon['min_subtotal_cents'] !== null ? 'mín. ' . e(money((int) $coupon['min_subtotal_cents'])) . '<br>' : '' ?>
                            <?= $coupon['usage_limit_per_customer'] !== null ? e($coupon['usage_limit_per_customer']) . '× por cliente<br>' : '' ?>
                            <?= $coupon['ends_at'] !== null ? 'até ' . e(format_datetime($coupon['ends_at'])) : 'sem prazo' ?>
                        </small></td>
                        <td class="table__num"><?= e($coupon['times_used']) ?><?= $coupon['usage_limit'] !== null ? ' / ' . e($coupon['usage_limit']) : '' ?></td>
                        <td class="table__num nowrap"><?= e(money((int) $coupon['discount_total'])) ?></td>
                        <td>
                            <?php if (!$coupon['is_active']): ?><span class="status status--off">Inativo</span>
                            <?php elseif ($expired): ?><span class="status status--off">Vencido</span>
                            <?php elseif ($exhausted): ?><span class="status status--warn">Esgotado</span>
                            <?php elseif ($scheduled): ?><span class="status status--progress">Agendado</span>
                            <?php else: ?><span class="status status--on">Ativo</span><?php endif ?>
                        </td>
                        <td class="table__actions"><a class="btn btn--secondary btn--sm" href="<?= e(url('/admin/cupons/' . $coupon['id'] . '/editar')) ?>">Editar</a></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php endif ?>
</section>
