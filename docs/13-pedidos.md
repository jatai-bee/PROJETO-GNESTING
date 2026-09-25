# 13 — Gestão de pedidos, clientes e comunicação (etapa 8)

## 1. Quem faz o quê

| Papel | Pedidos | Clientes |
|---|---|---|
| **Proprietário / gestor** | tudo: avançar etapas, despachar com rastreio, marcar entregue, **cancelar com estorno**, notas, mensagens, reenviar link | consulta completa (CPF inteiro) |
| **Produção** | avançar `Produção pendente → Em produção → Acabamento → Controle de qualidade → Embalagem → Pronto para envio` (inclui **retrabalho**: CQ → Em produção) e notas internas | — |
| **Atendimento** | consultar, notas internas, **mensagens ao cliente**, reenviar link | consulta com **CPF mascarado** (`***.982.247-**`) |

"Pagamento aprovado" **nunca** é manual: só o provedor de pagamento confirma (docs/12). Despachar e marcar entregue ficam com a
gestão nesta etapa; a expedição pela equipe de produção vem com o módulo de produção (etapa 9).

## 2. Telas

- **Pedidos** (`/admin/pedidos`): abas por situação com contagem ("Em aberto" = tudo que não foi entregue nem cancelado), busca por
  número, nome, e-mail, CPF ou telefone, filtro de pagamento e período. A coluna **Prazo de produção** mostra a data prometida
  (pagamento + dias úteis de produção; fica vermelha quando atrasada). "Pers." indica pedido com personalização.
- **Pedido** (`/admin/pedidos/{id}`): itens com a personalização em destaque (é o que vai para a gravação), pagamento, envio,
  **notas e mensagens**, histórico completo (quem, quando, origem), **Próximo passo** com os botões que o seu papel permite,
  dados do cliente com **WhatsApp** (mensagem já com o número do pedido) e endereço de entrega.
- **Clientes** (`/admin/clientes`): busca, pedidos, total pago, último pedido, endereços salvos.
- **Painel**: vendas de hoje e do mês (só gestão), pedidos em produção, prontos para envio e aguardando pagamento.

## 3. Regras das mudanças de status

Todas passam pelo `OrderStatusService` — o único código que altera `orders.status` (docs/03). Numa transação:
confere a matriz, grava o histórico (de, para, origem, usuário, observação) e a auditoria, e aplica os efeitos:

| Para | Efeito |
|---|---|
| Pagamento aprovado | `paid_at`; **"mais vendidos"** somado; reserva de estoque vira saída |
| Enviado | registra a remessa: transportadora, serviço, **código de rastreio** (maiúsculas) e link (só `http(s)://`) |
| Entregue | remessa marcada como entregue |
| Cancelado | **motivo obrigatório** (vai para o cliente). Não pago: reserva devolvida. **Pago**: estorno + itens de pronta entrega de volta ao estoque + "mais vendidos" desfeito |

**Cancelar pedido pago** exige escolher a devolução:
- **Estornar pelo provedor** (Mercado Pago, estorno total). Se o provedor recusar, **nada é cancelado** e aparece o aviso.
- **Já estornei por fora**: registra o estorno como manual (nota interna).

Pagamento aprovado **depois** de um cancelamento não reativa o pedido: fica uma nota interna para estornar.

## 4. Comunicação com o cliente

E-mails automáticos (texto simples, com o link do pedido):

| Quando | Assunto |
|---|---|
| Pedido feito | Pedido GN-… recebido (itens, frete, total, "Pagar agora") |
| Pagamento aprovado | Pagamento aprovado — pedido GN-… (prazo de produção) |
| Em produção | Seu pedido GN-… está em produção |
| Enviado | Pedido GN-… enviado (transportadora, rastreio, prazo) |
| Entregue | Pedido GN-… entregue |
| Cancelado | Pedido GN-… cancelado (motivo; aviso de estorno) |

**Mensagem ao cliente** (gestor/atendimento): vai por e-mail **e** aparece na página do pedido ("Mensagens da G-Nesting").
Se o e-mail falhar, a mensagem fica na página e o painel avisa ("e-mail NÃO enviado").
**Nota interna**: só a equipe vê — nunca aparece para o cliente. Notas do sistema (ex.: valor divergente) também ficam aqui.
**Reenviar link**: manda de novo o link privado do pedido.

Falha de e-mail nunca desfaz uma mudança de status (fica registrada no log).

### Link do pedido

A chave do link (`?chave=…`) é **derivada** do número do pedido com HMAC-SHA256 e a `APP_KEY` — não precisa ser guardada,
por isso qualquer e-mail pode trazer o link. **Trocar a `APP_KEY` invalida os links já enviados** (use "Reenviar link").
Pedidos feitos antes desta etapa continuam abrindo com a chave antiga.

### Configurar o e-mail (SMTP)

`.env` (conta de e-mail do cPanel ou serviço transacional):

```
MAIL_DRIVER=smtp
MAIL_HOST=mail.seudominio.com.br
MAIL_PORT=587
MAIL_ENCRYPTION=tls        # 587 = tls (STARTTLS) · 465 = ssl
MAIL_USERNAME=loja@seudominio.com.br
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=loja@seudominio.com.br
MAIL_FROM_NAME="G-Nesting"
```

Use como remetente um endereço do **próprio domínio** e configure SPF/DKIM no cPanel (evita cair no spam).
Erros de envio vão para `storage/logs` (sem a senha). Em desenvolvimento, `MAIL_DRIVER=log` grava os e-mails em `storage/logs/mail-AAAA-MM-DD.log`.

## 5. Onde está no código

| Responsabilidade | Arquivo |
|---|---|
| Transições (matriz, papéis, efeitos, histórico, auditoria) | `app/Services/OrderStatusService.php` |
| Pagamento aprovado / expiração (usam o serviço acima) | `app/Services/OrderService.php` |
| E-mails ao cliente | `app/Services/OrderNotifier.php`, `app/Views/emails/order.php` |
| Link privado | `app/Services/OrderLink.php` |
| SMTP | `app/Services/Mail/SmtpMailer.php` (+ `StreamSmtpTransport`) |
| Painel | `app/Controllers/Admin/OrderController.php`, `CustomerController.php`, views em `app/Views/admin/orders/` e `customers/` |
| Banco | `database/migrations/003_order_notes.sql` |

## 6. Testes

- `tests/Integration/OrderManagementTest.php`: matriz de papéis; fluxo completo (pago → produção com retrabalho → envio com rastreio →
  entregue) com e-mails, link e bloqueios por papel; cancelamento de pedido pago (estorno, estoque, "mais vendidos"); estorno recusado
  (pedido intacto) e estorno manual; notas × mensagens (o cliente não vê notas internas); CPF mascarado; filtros; clientes; indicadores.
- `tests/Unit/SmtpMailerTest.php`: STARTTLS e AUTH, corpo base64, falha sem vazar senha, bloqueio de header injection.
- `tests/Unit/HelpersTest.php`: dias úteis (fins de semana; feriados não são considerados) e link do WhatsApp.

## 7. Limites conhecidos

- Feriados não entram no cálculo de prazo (só fins de semana).
- Estorno **parcial** não é feito pelo painel (faça no provedor e registre como manual).
- Um pedido tem uma remessa ativa (envio parcial em volumes separados: etapa 9).
