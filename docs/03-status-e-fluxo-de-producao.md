# 03 — Status do pedido e fluxo de produção

## 1. Dois eixos independentes

| Eixo | Coluna | Enum PHP | Quem altera |
|---|---|---|---|
| **Pedido** (comercial + operação) | `orders.status` | `GNesting\Enums\OrderStatus` | admin, sistema, webhook |
| **Pagamento** | `orders.payment_status` / `payments.status` | `GNesting\Enums\PaymentStatus` | somente webhook/gateway (e admin em estorno manual) |

O pagamento **dispara** transições do pedido (pagamento aprovado leva o pedido a `paid`), mas os dois têm ciclos de vida próprios: um estorno não "apaga" o histórico de produção.

## 2. Status do pedido

| Código (banco) | Rótulo (loja/admin) | Etapa do fluxo |
|---|---|---|
| `awaiting_payment` | Aguardando pagamento | Pedido recebido |
| `paid` | Pagamento aprovado | Pagamento confirmado |
| `production_pending` | Produção pendente | Produção liberada / fila |
| `in_production` | Em produção | CNC |
| `finishing` | Acabamento | Lixamento, pintura, secagem, montagem |
| `quality_control` | Controle de qualidade | CQ |
| `packaging` | Embalagem | Embalagem |
| `ready_to_ship` | Pronto para envio | Expedição |
| `shipped` | Enviado | Enviado |
| `delivered` | Entregue | Entregue |
| `cancelled` | Cancelado | — |

## 3. Transições permitidas

```text
awaiting_payment ──► paid ──► production_pending ──► in_production ──► finishing
                                                                           │
          delivered ◄── shipped ◄── ready_to_ship ◄── packaging ◄── quality_control
                                                                           │
                                                    (reprovado) ──► in_production

Cancelamento: de qualquer status até ready_to_ship ──► cancelled
```

| De | Para (permitido) | Origem típica |
|---|---|---|
| `awaiting_payment` | `paid`, `cancelled` | webhook / cron (expiração) / admin |
| `paid` | `production_pending`, `cancelled` | sistema (automático) / admin |
| `production_pending` | `in_production`, `cancelled` | produção |
| `in_production` | `finishing`, `cancelled` | produção |
| `finishing` | `quality_control`, `cancelled` | produção |
| `quality_control` | `packaging`, `in_production` (retrabalho), `cancelled` | produção |
| `packaging` | `ready_to_ship`, `cancelled` | produção |
| `ready_to_ship` | `shipped`, `cancelled` | expedição |
| `shipped` | `delivered` | expedição / rastreio |
| `delivered` | — (final) | |
| `cancelled` | — (final) | |

Regras:
- A matriz fica **no enum** (`OrderStatus::canTransitionTo()`) e é aplicada **somente** pelo `OrderStatusService`. Nenhum outro código faz `UPDATE orders SET status`.
- Toda transição, **na mesma transação**:
  1. atualiza `orders.status`;
  2. insere em `order_status_history` (de, para, origem, usuário, nota);
  3. insere em `audit_logs` (`action = status_change`);
  4. aplica efeitos colaterais: `paid` grava `paid_at`, incrementa `products.sales_count` e confirma reserva de estoque; `cancelled` grava `cancelled_at`, exige motivo e libera a reserva.
- Cancelar um pedido já pago exige também um estorno em `payments`, feito pelo gateway.
- Na primeira versão, `paid → production_pending` é automático.

## 4. Status do pagamento

`pending` → `authorized` → `paid` → (`refunded` | `partially_refunded`)
`pending` → `failed` | `cancelled`

Pix e boleto vão de `pending` para `paid`. Cartão pode passar por `authorized`.

## 5. Permissões por papel

| Papel | Pode mover o pedido |
|---|---|
| `owner`, `manager` | qualquer transição permitida, incluindo cancelamento |
| `production` | `production_pending` → … → `ready_to_ship` (inclui retrabalho) |
| `support` | apenas visualizar e adicionar nota |

## 6. Evolução para o módulo de produção (Etapa 9)

Hoje o status é **por pedido**, suficiente para o MVP. Na Etapa 9:

- `production_jobs`: uma ordem de produção por `order_item` (ou por unidade), com a etapa atual, operador e tempos reais;
- a fila de produção é ordenada por `orders.paid_at` e pelo prazo prometido (`production_days`);
- o status do pedido passa a ser **derivado** do job mais atrasado;
- o tempo estimado vem de `order_items.production_minutes_estimate` (snapshot da ficha), e a capacidade diária é calculada somando os minutos **não passivos**.

Nada disso exige alterar `orders`, `order_items` ou o enum atual; só são adicionadas tabelas.
