# 12 — Checkout, frete e pagamento (etapa 7)

Decisões (2026-09-25): pagamento pelo **Mercado Pago — Checkout Pro**; **compra sem conta** permitida
(quem tem conta entra e usa endereços salvos).

## 1. Fluxo do cliente

1. **Carrinho → Finalizar compra** (`/checkout`). Carrinho vazio ou com itens pendentes volta para o carrinho.
2. **Seus dados**: nome, e-mail (com conta: o da conta), CPF (dígitos verificadores conferidos) e celular com DDD.
3. **Entrega**: endereço salvo (com conta) ou novo; a UF precisa ser a do CEP. Com conta, "Salvar este endereço" (sem duplicar).
4. **Frete**: calculado pelo CEP — ao sair do campo CEP (JavaScript) ou pelo botão **Calcular frete** (funciona sem JavaScript).
   Se o CEP de entrega mudar depois de cotar, o servidor pede para recalcular: o cliente nunca paga um frete que não viu.
5. **Ir para o pagamento** → página do Mercado Pago (Pix, cartão, boleto). Os dados de cartão ficam lá (PCI-DSS SAQ-A).
6. **Retorno** para `/pedido/{número}/confirmacao`: status, itens, entrega, pagamento e andamento. Pedido aguardando pagamento tem **Pagar agora**.
7. **E-mail de confirmação** com o **link privado** do pedido (`?chave=…`).

Quem pode abrir um pedido: o cliente logado dono dele, quem tem o link privado, ou o mesmo navegador que acabou de comprar.
Para qualquer outra pessoa o pedido **não existe** (404). A chave é aleatória (48 hex) e o banco guarda só o SHA-256.

## 2. O que acontece ao finalizar (uma transação)

- Tudo é **recalculado no servidor**: itens, preços, acréscimos de personalização, disponibilidade e frete. Valores enviados pelo navegador são ignorados.
- **Cliente**: com conta, o próprio; sem conta, um cadastro de visitante (reaproveitado pelo e-mail, sem login).
- **Pedido** `GN-AAAA-NNNNNN` com snapshots: dados do cliente, endereço, frete (transportadora, serviço, prazo), prazo de produção,
  itens (SKU, nome, variação, preço base, acréscimo, quantidade) e personalizações (rótulo, tipo, valor/código, acréscimo),
  e o **tempo estimado de produção por unidade** tirado da ficha (etapa 6).
- **Estoque** de itens "pronta entrega" é **reservado** de forma atômica: se outra compra levou a última unidade, nada é gravado.
- Histórico `→ awaiting_payment` (origem: cliente), carrinho encerrado, auditoria.
- O e-mail sai depois da transação; se falhar, o pedido continua válido (fica no log).

## 3. Frete (`config/shipping.php`)

Tabela por faixa: **local** (mesma UF de origem — `SHIPPING_ORIGIN_STATE`) ou **região** de destino (N, NE, CO, SE, S),
com dois serviços (Econômico/PAC e Expresso/SEDEX): **preço = base até 1 kg + adicional por kg**, prazo em dias úteis
(somado ao prazo de produção). Peso = peso da embalagem da variação, ou peso do produto + 200 g, ou 1 kg.
Opcionais: frete grátis a partir de um valor e **Retirada no ateliê** (`SHIPPING_PICKUP=true`).

> **Os valores da tabela são exemplos.** Ajuste preços e prazos à sua realidade antes de vender.
> Trocar por uma API de transportadora = nova implementação de `ShippingCalculator`, sem mexer no checkout.

A UF é obtida pelas faixas de CEP dos Correios (`app/Helpers/ZipCode.php`). Cotação JSON: `POST /api/frete/cotar` (CSRF por cabeçalho, 60/min por IP).

## 4. Pagamento

| Provedor (`PAYMENT_PROVIDER`) | Uso |
|---|---|
| `mercadopago` | produção e homologação (credenciais de teste do MP) |
| `simulado` | **desenvolvimento**: página local com Aprovar / Pendente / Recusar. **Recusado em produção** (a aplicação não sobe o checkout). |

**Regras (docs/05 §10):**

- O status só muda com dado **consultado no provedor**: o webhook traz apenas o id; o pagamento é reconsultado em `GET /v1/payments/{id}`.
  No retorno do cliente (`payment_id` na URL) a mesma reconsulta é feita. Parâmetros de URL nunca confirmam nada sozinhos.
- Webhook `POST /webhooks/pagamento/mercadopago`: assinatura `x-signature` (HMAC-SHA256 com a *assinatura secreta*) obrigatória — inválida = 401 e nada muda.
  Cada aviso é gravado em `payment_events` (único por provedor + id): aviso repetido é ignorado.
- **Valor pago ≠ total do pedido** → o pagamento é registrado mas o pedido **não** é confirmado; fica uma nota interna no pedido.
- **Aprovado** → pedido `paid` → `production_pending` (automático nesta versão), `paid_at`, reserva de estoque vira **saída**.
- **Recusado** → pedido continua aguardando; o cliente pode tentar de novo (**Pagar agora** cria nova tentativa).
- **Pagamento depois do cancelamento** → não reativa sozinho: nota interna para estornar ou reativar (etapa 8).
- Só guardamos: id do provedor, meio (Pix/cartão/boleto), parcelas, bandeira e 4 últimos dígitos.

### Configurar o Mercado Pago

1. Painel do Mercado Pago → **Suas integrações** → criar aplicação (Checkout Pro).
2. **Credenciais**: copie o *Access Token* (teste para homologar; produção para vender) → `MERCADOPAGO_ACCESS_TOKEN`.
3. **Webhooks**: URL `https://SEU-DOMINIO/webhooks/pagamento/mercadopago`, evento **Pagamentos**; copie a *assinatura secreta* → `MERCADOPAGO_WEBHOOK_SECRET`.
4. `.env`: `PAYMENT_PROVIDER=mercadopago`. `APP_URL` precisa ser o endereço público com HTTPS (é dele que saem as URLs de retorno e de aviso).
5. Faça uma compra de teste com os cartões de teste do Mercado Pago e confira em `/pedido/.../confirmacao`.

## 5. Expiração (cron)

`bin/cron.php` cancela pedidos **sem pagamento** após `PAYMENT_EXPIRY_HOURS` (padrão 48 h): status `cancelled`, motivo registrado,
**reserva de estoque devolvida**. A página do pedido avisa o prazo.

## 6. Conta do cliente

- `/conta` mostra os últimos pedidos; `/conta/pedidos`, todos. Cada um abre a página do pedido.
- Ao entrar na conta, o carrinho do navegador passa a ser da conta; sem carrinho no navegador, o carrinho ativo da conta é retomado.
- `/entrar?voltar=/checkout` volta ao checkout depois do login.
- **Segurança**: criar conta com um e-mail **não** vincula compras feitas antes sem conta com esse e-mail (sem confirmar o e-mail,
  alguém poderia se cadastrar com o e-mail de outra pessoa e ver endereço e CPF dela). Essas compras seguem pelo link privado.
  O vínculo pode vir junto com a confirmação de e-mail.

## 7. E-mail

`MAIL_DRIVER=log` (padrão; grava em `storage/logs/mail-AAAA-MM-DD.log`) ou `mail` (função `mail()` da hospedagem, remetente `MAIL_FROM_*`).
Nesta etapa: confirmação do pedido. SMTP autenticado e demais avisos (pagamento aprovado, envio) entram na etapa 8.

## 8. Onde está no código

| Responsabilidade | Arquivo |
|---|---|
| Checkout (validação, pedido, reserva, e-mail) | `app/Services/CheckoutService.php` |
| Transições automáticas do pedido, expiração | `app/Services/OrderService.php` |
| Pagamentos (checkout, webhook, aplicação do status) | `app/Services/PaymentService.php` |
| Provedores | `app/Services/Payment/MercadoPagoGateway.php`, `SimulatedGateway.php` (interface `PaymentGateway`) |
| Frete | `app/Services/Shipping/TableShippingCalculator.php` (interface `ShippingCalculator`), `config/shipping.php` |
| CEP, CPF, telefone | `app/Helpers/ZipCode.php`, `BrazilianDocument.php` |
| Rotas | `routes/web.php` (checkout, pedido, simulado, conta), `routes/api.php` (frete, webhook) |
| Banco | `database/migrations/002_checkout.sql` (chave do pedido, referência do checkout) |

## 9. Testes

- `tests/Unit/CheckoutUnitTest.php`: CEP→UF/região, CPF/telefone, tabela de frete (faixas, peso, frete grátis, retirada),
  Mercado Pago com cliente HTTP falso (preferência com itens + frete = total, mapeamento de status/meio, assinatura do webhook).
- `tests/Integration/CheckoutHttpTest.php`: compra sem conta de ponta a ponta (cotação sem JS, preço do servidor, snapshots,
  tempo de produção, reserva → saída, e-mail com link, acesso ao pedido só com chave), validações, carrinho com pendência,
  última unidade não vendida duas vezes, expiração e pagamento tardio, recusa e nova tentativa, webhook do Mercado Pago
  (assinatura inválida, valor divergente, confirmação, aviso repetido), URL de retorno que não confirma nada,
  cliente com conta (endereço salvo, pedidos isolados), carrinho retomado ao entrar, API de frete.
