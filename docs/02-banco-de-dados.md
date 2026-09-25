# 02 — Banco de dados

Arquivos:
- Esquema: [`database/migrations/001_initial_schema.sql`](../database/migrations/001_initial_schema.sql)
- Seed de desenvolvimento: [`database/seeds/001_seed.sql`](../database/seeds/001_seed.sql)

Compatibilidade: **MySQL 8.0.16+** ou **MariaDB 10.6+**, InnoDB, `utf8mb4_unicode_ci`.

## 1. Mapa das entidades

```text
IDENTIDADE
users ─1:1─ admins                    (papel: owner | manager | production | support)
users ─1:1─ customers ─1:N─ addresses (customers.user_id NULL = compra como visitante)
users ─1:N─ password_resets
rate_limits                           (independente)

CATÁLOGO
categories (auto-relacionamento parent_id)
categories ─1:N─ products ─1:N─ product_variants   (SKU, preço, medidas públicas)
products ─1:N─ product_options ─1:N─ product_option_values
product_variants ─N:N─ product_option_values        (via variant_option_values)
products ─1:N─ product_images (opcionalmente ligada a uma variante)

PERSONALIZAÇÃO CONTROLADA
products ─1:N─ personalization_rules ─1:N─ personalization_values (opções de "select")

FICHA DE PRODUÇÃO (interna)
product_variants ─1:1─ production_specs ─N:1─ materials
production_specs ─1:N─ production_spec_steps       (etapas + tempos)
production_specs ─1:N─ production_files            (arquivos em storage/private)

ESTOQUE
product_variants ─1:1─ inventory
product_variants ─1:N─ inventory_movements         (razão imutável)

CARRINHO
carts ─1:N─ cart_items ─1:N─ cart_item_personalizations

PEDIDO
customers ─1:N─ orders ─1:N─ order_items ─1:N─ order_item_personalizations
orders ─1:N─ order_status_history
orders ─1:N─ payments ─1:N─ payment_events
orders ─1:N─ shipments
coupons ─1:N─ coupon_redemptions ─1:1─ orders

RELACIONAMENTO / SISTEMA
reviews, wishlists, settings, audit_logs, schema_migrations
```

## 2. Decisões de modelagem

### Dinheiro, medidas e datas
- Todo valor monetário é `INT UNSIGNED` em **centavos**. `CHECK` garante `total = subtotal − desconto + frete` em `orders` e `line_total = (preço + personalização) × qtd` em `order_items`.
- Medidas em mm e peso em g, como inteiros, sem arredondamento de ponto flutuante.
- `DATETIME` em UTC. A aplicação define `time_zone = '+00:00'` em cada conexão.

### Identidade: `users` + `customers` + `admins`
- `users` guarda **só credenciais** (e-mail, hash, tipo, status).
- `customers` guarda o perfil comercial. `user_id` é opcional para permitir **checkout sem cadastro**: o cliente visitante vira um `customer` sem login e pode criar a conta depois.
- `admins` guarda nome e **papel**, base do controle de acesso (ver [05-seguranca.md](05-seguranca.md)).
- `customers.anonymized_at` (migration 005): cadastro anonimizado a pedido do titular (LGPD). Os pedidos continuam ligados a ele por obrigação fiscal. Ver [16 — Segurança, LGPD e testes](16-seguranca-e-testes.md) §2.
- `password_resets` (desde a etapa 1, usada a partir da etapa 11): só o SHA-256 do token, 60 min, uso único.

### Produto × variante (SKU)
- **Todo produto tem ao menos uma variante** (`is_default = 1`). O SKU, o preço, o material/acabamento *exibidos* e as medidas ficam na variante.
- Produto sem variações = uma variante única (`name` NULL). Isso evita dois caminhos de código.
- Variações só existem como combinações **pré-cadastradas** (`product_options` → `product_option_values` → `variant_option_values`). O cliente não compõe combinações arbitrárias.
- Medidas de embalagem (`package_*`) alimentam o cálculo de frete.

### Personalização controlada
- `personalization_rules`: cada campo tem tipo (`text`, `initial`, `date`, `select`), obrigatoriedade, limites de caracteres, **conjunto de caracteres por preset** (`letters`, `letters_numbers`, `text_basic`) e acréscimo em centavos.
  - O admin escolhe um preset em vez de digitar uma expressão regular. Isso evita regex mal escrita e ataques de ReDoS, e mantém a gravação compatível com a produção.
  - `max_size_mm` informa a área máxima de gravação à produção.
- `personalization_values`: opções pré-definidas para `select` (modelos, fontes, ícones), cada uma com acréscimo próprio.
- **Não existe** coluna ou tabela para "descreva a alteração que deseja".

### Ficha de produção separada da vitrine
- `production_specs` é **1:1 com a variante**, porque material, espessura e tempo podem mudar entre variantes.
- `production_spec_steps` guarda as etapas com tempo estimado. O tempo total é `SUM(estimated_minutes)`. `is_passive = 1` marca etapas que não ocupam operador (secagem), o que é essencial para calcular capacidade produtiva no futuro.
- `production_files` referencia arquivos em `storage/private/production_files` com checksum e versão. Só são servidos por um controller autenticado.
- `materials` prepara o controle de estoque de chapas e o custo.
- Nenhuma consulta da loja pública lê essas tabelas.

### Estoque preparado para produção
- `inventory.stock_mode`:
  - `stock`: pronta entrega; a venda consome `quantity_on_hand` e reserva em `quantity_reserved`.
  - `made_to_order`: produzido após o pedido; a disponibilidade depende do prazo e da capacidade, não da quantidade.
- `inventory_movements` é um **razão só de inserção**: saldo auditável, com referência ao pedido.

### Carrinho
- O carrinho é identificado por um token aleatório em cookie; o banco guarda só o **hash** (`token_hash`).
- `cart_items.personalization_hash` diferencia linhas da mesma variante com personalizações diferentes.
- **Preço não é gravado no carrinho**: é sempre recalculado no servidor, e o valor final é congelado no pedido.

### Pedido com snapshots
- `orders` copia os dados do cliente, o **endereço de entrega**, o frete e os prazos no momento da compra.
- `order_items` copia SKU, nome, preço base, acréscimo de personalização e tempo estimado de produção.
- `order_item_personalizations` copia rótulo, tipo, valor digitado/opção e acréscimo. **É exatamente o que vai para a produção**, mesmo que a regra mude ou seja apagada depois (`rule_id` vira NULL, o snapshot permanece).
- `order_status_history` registra toda transição (de → para, origem, autor, nota).
- Número público no formato `GN-AAAA-NNNNNN`, gerado na aplicação dentro da transação do checkout.

### Pagamento e frete
- `payments` separado de `orders`: um pedido pode ter mais de uma tentativa de pagamento. Guarda só referências do gateway e dados **não sensíveis** (bandeira e 4 últimos dígitos). Número de cartão, CVV e validade **nunca** tocam o banco.
- `payment_events` guarda os webhooks brutos com `UNIQUE (provider, event_id)`, o que garante **idempotência** no reprocessamento.
- `shipments` suporta várias remessas por pedido, rastreio, custo real e retirada (carrier = "Retirada").

### Auditoria
- `audit_logs` é **somente INSERT**: usuário, ação, entidade, JSON antes/depois, IP e user agent.
- Ações previstas: `login`, `logout`, `login_failed`, `create`, `update`, `delete`, `price_change`, `stock_change`, `status_change`.

## 3. Integridade referencial

| Situação | Regra |
|---|---|
| Excluir produto/variante com pedidos | `RESTRICT`. Usa-se exclusão lógica (`deleted_at`) |
| Excluir categoria com produtos | `RESTRICT` |
| Excluir usuário | perfis `CASCADE`/`SET NULL`; logs e histórico mantêm o registro com `user_id` NULL |
| Excluir regra de personalização | carrinhos são limpos (`CASCADE`); pedidos mantêm o snapshot (`SET NULL`) |
| Excluir pedido | não permitido na aplicação; pagamentos e remessas usam `RESTRICT` |

## 4. Evolução prevista (sem reconstrução)

| Etapa | Adição | Impacto no esquema atual |
|---|---|---|
| 9 — Produção | `production_jobs` (1 por unidade/item) e `production_job_events` | Apenas novas tabelas ligadas a `order_items` |
| 9 — Capacidade | `workstations`, `capacity_calendar` | Usa `production_spec_steps.estimated_minutes`/`is_passive` |
| 9 — Matéria-prima | `material_movements` (consumo de chapas por job) | Usa `materials` |
| 10 — Marketing | `product_relations`, `campaigns` | Novas tabelas |

## 5. Validação após aplicar

```sql
-- Produto de referência com variante, personalização e tempo total de produção
SELECT p.name, v.sku, v.price_cents, v.material_label,
       CONCAT(v.width_mm DIV 10, ' × ', v.height_mm DIV 10, ' cm') AS dimensoes,
       r.label AS personalizacao, r.max_length, r.price_delta_cents,
       (SELECT SUM(s.estimated_minutes) FROM production_spec_steps s WHERE s.spec_id = ps.id) AS minutos_totais,
       (SELECT SUM(s.estimated_minutes) FROM production_spec_steps s WHERE s.spec_id = ps.id AND s.is_passive = 0) AS minutos_operador
FROM products p
JOIN product_variants v       ON v.product_id = p.id
LEFT JOIN personalization_rules r ON r.product_id = p.id
LEFT JOIN production_specs ps ON ps.variant_id = v.id
WHERE v.sku = 'REL-GEO-001';
```

Resultado esperado: `REL-GEO-001 | 12990 | MDF amadeirado 6 mm | 35 × 35 cm | Nome gravado | 20 | 1500 | 126 | 66`.
