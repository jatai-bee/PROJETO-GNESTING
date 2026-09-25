<?php

declare(strict_types=1);

namespace GNesting\Controllers\Admin;

use DateTimeImmutable;
use DateTimeZone;
use GNesting\Core\Controller;
use GNesting\Core\HttpException;
use GNesting\Core\Request;
use GNesting\Core\Response;
use GNesting\Repositories\CouponRepository;
use GNesting\Services\BusinessRuleException;
use GNesting\Services\CouponService;

/** Cupons de desconto (gestor). */
final class CouponController extends Controller
{
    private const LABELS = [
        'code' => 'Código', 'description' => 'Descrição', 'type' => 'Tipo', 'percent' => 'Percentual', 'fixed' => 'Valor',
        'min_subtotal' => 'Compra mínima', 'max_discount' => 'Desconto máximo', 'starts_at' => 'Início', 'ends_at' => 'Fim',
        'usage_limit' => 'Limite de usos', 'usage_limit_per_customer' => 'Usos por cliente',
    ];

    public function __construct(
        private readonly CouponRepository $coupons,
        private readonly CouponService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render('admin/coupons/index', [
            'title' => 'Cupons | Painel',
            'coupons' => $this->coupons->all(),
        ], 'admin');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->create($this->input($request));
        $this->flash('success', 'Cupom criado.');

        return $this->redirect('/admin/cupons');
    }

    public function edit(Request $request): Response
    {
        return $this->form($this->coupon($request));
    }

    public function update(Request $request): Response
    {
        $coupon = $this->coupon($request);
        $this->validate($request, $this->rules(), self::LABELS);
        $this->service->update((int) $coupon['id'], $this->input($request));
        $this->flash('success', 'Cupom atualizado.');

        return $this->redirect('/admin/cupons');
    }

    public function destroy(Request $request): Response
    {
        $coupon = $this->coupon($request);
        try {
            $this->service->delete((int) $coupon['id']);
            $this->flash('success', "Cupom {$coupon['code']} excluído.");
        } catch (BusinessRuleException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/cupons');
    }

    /** @param array<string, mixed>|null $coupon */
    private function form(?array $coupon): Response
    {
        return $this->render('admin/coupons/form', [
            'title' => ($coupon ? 'Editar cupom' : 'Novo cupom') . ' | Painel',
            'coupon' => $coupon,
            'types' => CouponService::TYPES,
        ], 'admin');
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        return [
            'code' => 'required|max:40',
            'description' => 'max:200',
            'type' => 'required|in:' . implode(',', array_keys(CouponService::TYPES)),
            'percent' => 'decimal',
            'fixed' => 'money',
            'min_subtotal' => 'money',
            'max_discount' => 'money',
            'usage_limit' => 'gte:1|lte:1000000',
            'usage_limit_per_customer' => 'gte:1|lte:1000',
        ];
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $money = static fn (string $v): ?int => $v === '' ? null : parse_money($v);
        $int = static fn (string $v): ?int => $v === '' ? null : (int) $v;
        $percent = parse_decimal($request->string('percent'));

        return [
            'code' => $request->string('code'),
            'description' => $request->string('description'),
            'type' => $request->string('type'),
            // "12,5" → "12.50" → 1250 pontos-base
            'percent_basis_points' => $percent === null ? null : (int) str_replace('.', '', $percent),
            'fixed_cents' => $money($request->string('fixed')),
            'min_subtotal_cents' => $money($request->string('min_subtotal')),
            'max_discount_cents' => $money($request->string('max_discount')),
            'starts_at' => $this->localToUtc($request->string('starts_at')),
            'ends_at' => $this->localToUtc($request->string('ends_at')),
            'usage_limit' => $int($request->string('usage_limit')),
            'usage_limit_per_customer' => $int($request->string('usage_limit_per_customer')),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** "2026-10-01T09:00" (horário da loja, campo datetime-local) → DATETIME UTC. */
    private function localToUtc(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone((string) config('app.timezone', 'America/Sao_Paulo')));

        return $date === false ? null : $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function coupon(Request $request): array
    {
        return $this->coupons->find((int) $request->param('id')) ?? throw HttpException::notFound();
    }
}
