# 15 — Marketing: cupons, SEO, WhatsApp e relacionados (etapa 10)

Sem migration nova: as tabelas `coupons`, `coupon_redemptions`, `settings` e as colunas `orders.discount_cents`,
`coupon_id` e `coupon_code` já vinham do esquema da etapa 1 (docs/02).

## 1. Cupons

Painel: **Cupons** (`/admin/cupons`, papel **gestor**). A lista mostra benefício, regras, usos e o total de descontos já dados.

### Tipos

| Tipo | Valor | Cálculo |
|---|---|---|
| Percentual | `value` em pontos-base (1250 = 12,5%) | `subtotal × % ` arredondado **para baixo**, limitado pelo **desconto máximo** (opcional) |
| Valor fixo | `value` em centavos | nunca maior que o subtotal dos produtos |
| Frete grátis | — | abate o valor da opção de entrega **mais econômica**; se o cliente escolher uma mais cara, paga só a diferença |

O desconto de produto incide só sobre os **itens** (com acréscimos de personalização), nunca sobre o frete. Um pedido que
ficaria com total zero é recusado com uma mensagem (o Mercado Pago não aceita pedido zerado).

### Regras (todas opcionais)

- **Compra mínima**: subtotal dos produtos, sem frete.
- **Início / Fim**: digitados no horário da loja e gravados em UTC. Fim é exclusivo.
- **Limite de usos** (total) e **Usos por cliente**. O limite por cliente é conferido **no checkout**, quando o cliente já é
  conhecido pelo e-mail/CPF. No carrinho o comprador ainda é anônimo.
- **Ativo**: desligar é a forma de encerrar um cupom. Um cupom já usado **não pode ser excluído**, porque os pedidos guardam
  o vínculo. Enquanto não foi usado, pode.

Código: 3 a 40 caracteres entre letras, números, `-` e `_`. É gravado em maiúsculas, então o cliente pode digitar como quiser.

### Fluxo

1. **Carrinho**: campo "Cupom de desconto". Um cupom por carrinho. As tentativas são limitadas a **10 a cada 10 minutos
   por IP** (`security.rate_limits.coupon`) para impedir que alguém descubra códigos por tentativa.
2. O cupom é **revalidado a cada leitura** do carrinho. Se deixar de valer (expirou, foi desativado, o subtotal caiu abaixo
   do mínimo), continua aparecendo com o motivo e o checkout é bloqueado até o cliente removê-lo.
3. **Checkout**: o resumo mostra "Cupom X − R$ …". No frete grátis, as opções de entrega aparecem já com o abatimento.
4. **Finalizar**, dentro da transação do pedido:
   1. A linha do cupom é **travada** (`SELECT … FOR UPDATE`) para que dois pedidos simultâneos não passem do limite.
   2. As regras são conferidas de novo.
   3. `orders.discount_cents`, `coupon_id` e `coupon_code` são gravados.
   4. É criado um `coupon_redemptions` e `times_used` sobe em 1.
5. **Cancelamento** do pedido (qualquer motivo, inclusive expiração por falta de pagamento) **devolve o uso**: apaga o
   resgate e decrementa `times_used`.

O desconto aparece na confirmação, no e-mail do pedido e no painel.

**Mercado Pago:** o Checkout Pro não aceita item com preço negativo. Quando há desconto, a preferência vai com **uma linha
só**, "Pedido GN-… (cupom X)", com o total exato. Sem desconto, continua uma linha por item + frete.

## 2. WhatsApp e configurações da loja

Painel: **Configurações** (`/admin/configuracoes`, somente **proprietário**). Os valores ficam na tabela `settings` e cada
alteração vai para a auditoria.

| Campo | Efeito |
|---|---|
| Número com DDD | Vazio = nenhum botão de WhatsApp na loja. Validado como telefone brasileiro e gravado como `55DDDNÚMERO` |
| Mensagem inicial | Texto que abre a conversa pelo botão flutuante |
| Botão flutuante | Botão verde fixo no canto de todas as páginas da loja |
| E-mail de contato | Aparece no rodapé |
| Faixa de avisos | Uma linha no topo da loja ("Frete grátis acima de…"). Vazio = sem faixa |

Os links são sempre `https://wa.me/<número>?text=…`, sem nenhum script de terceiros:

- **Produto**: "Dúvidas sobre este produto? Fale no WhatsApp", com o nome e o link do produto na mensagem.
- **Confirmação do pedido**: "Falar sobre o pedido no WhatsApp", com o número do pedido.

O WhatsApp complementa o atendimento. Pedido, pagamento e status continuam na loja.

## 3. SEO

- **`/sitemap.xml`**: home, categorias ativas e produtos ativos (estes com `lastmod` e a imagem de capa). Cache público de 1 hora.
- **`/robots.txt`**:
  - **Fora de produção**: `Disallow: /`, para que homologação e desenvolvimento nunca sejam indexados.
  - **Em produção**: bloqueia `/admin`, carrinho, checkout, conta, pedido, busca e as rotas técnicas, e aponta o sitemap.
- **Open Graph / Twitter** em todas as páginas da loja. No produto: `og:type=product` e a foto de capa (800 px). Nas demais:
  `public/assets/img/og-default.png` (1200×630).
- **Dados estruturados (JSON-LD)**:
  - **Produto**: `Product` com `Offer` (preço, BRL, disponibilidade, SKU, marca) e `BreadcrumbList`.
  - **Home**: `Organization`.

  O JSON é gerado por `SeoData::encode`, que escapa `<`, `>` e `&`: um nome de produto não consegue fechar a tag
  `<script>`. Blocos `application/ld+json` não são executados, então a CSP (sem script inline) continua valendo.
- `canonical` e `meta description` continuam como na etapa 4.

`APP_URL` precisa ser a URL pública real em produção: sitemap, canonical e og:image usam endereços absolutos.

## 4. Produtos relacionados

`RelatedProducts` preenche até 4 vagas, nesta ordem, sem repetir e só com produtos que podem ser vendidos agora:

1. **Comprados juntos**: produtos que aparecem nos mesmos pedidos **pagos e não cancelados**, ordenados pela frequência.
2. **Mesma categoria** (só na página de produto).
3. **Mais vendidos** da loja (`sales_count`).

A página de produto mostra a seção "Você também pode gostar". O carrinho mostra "Combina com o seu pedido", sem os itens que
já estão nele.

## 5. Código

| Onde | O quê |
|---|---|
| `Services/CouponService` · `Repositories/CouponRepository` | regras, cálculo, CRUD, resgate/devolução |
| `CartService::applyCoupon/removeCoupon/summary` | cupom no carrinho (`carts.coupon_id`) |
| `CheckoutService::place` · `OrderStatusService::cancel` | resgate travado · devolução |
| `Services/SettingsService` · `Repositories/SettingsRepository` | configurações com valores padrão |
| `Services/WhatsApp` | links do WhatsApp |
| `Services/SeoData` · `Controllers/Store/SeoController` | JSON-LD · sitemap e robots |
| `Services/RelatedProducts` · `CatalogRepository::boughtTogether/bestsellers` | relacionados |

Testes: `tests/Integration/MarketingTest.php`, e o caso do Mercado Pago com desconto em `tests/Unit/CheckoutUnitTest.php`.

## 6. Fora do escopo desta etapa

- Cupom restrito a categoria/produto, cupom por primeira compra automática e acúmulo de cupons.
- Integração com a API oficial do WhatsApp Business (mensagens automáticas). Hoje é só link.
- Feed de produtos (Google Merchant / Meta Catálogo) e pixels de anúncio. Esses dependem de consentimento de cookies
  (LGPD), que será tratado na etapa 11.
