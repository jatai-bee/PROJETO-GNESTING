# 05 — Segurança

Política obrigatória para todas as etapas. Cada item indica **onde** é implementado.

## 1. Banco de dados: SQL Injection
- PDO com `PDO::ATTR_EMULATE_PREPARES = false`, `PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION`, `charset=utf8mb4`. (`app/Core/Database.php`)
- **Somente prepared statements com parâmetros.** Proibido concatenar entrada em SQL.
- Colunas de ordenação e direção (`ORDER BY`) vêm de **lista branca** no Repository, nunca direto da query string.
- Usuário do banco em produção com privilégios mínimos (`SELECT, INSERT, UPDATE, DELETE`). Migrations e restauração de backup rodam com outro usuário (como fazer: docs/17 §3.3 e §3.5).

## 2. Saída: XSS
- Toda variável impressa em view passa por `e()` = `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Dados em JavaScript: `json_encode` com `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Descrições de produto são texto simples com quebras de linha. Não há HTML livre do admin no MVP.
- Cabeçalho **Content-Security-Policy** restritivo: `default-src 'self'`; scripts só locais; sem `unsafe-inline` em scripts.

## 3. CSRF
- Token por sessão (`random_bytes(32)`), comparado com `hash_equals`. (`app/Core/Csrf.php`, `Middleware/CsrfMiddleware`)
- Obrigatório em **todo POST** de formulário e em chamadas `fetch` (header `X-CSRF-Token`).
- Exceção única: webhooks de pagamento, que são protegidos por assinatura do provedor.
- Cookies com `SameSite=Lax` como camada adicional.

## 4. Sessão e autenticação
- Cookie: `HttpOnly`, `Secure` (produção), `SameSite=Lax`, nome próprio (`SESSION_NAME`), `session.use_strict_mode=1`, `use_only_cookies=1`.
- Sessões salvas em `storage/sessions` (fora do webroot).
- `session_regenerate_id(true)` no login, no logout e na troca de privilégio.
- Expiração por inatividade (`SESSION_LIFETIME`) e timeout absoluto de 12 h para admin.
- **Sessões de cliente e admin são separadas** (chaves distintas; login admin em `/admin/login`).
- Senhas: `password_hash(PASSWORD_ARGON2ID)` com fallback para `PASSWORD_BCRYPT` (cost 12) se a hospedagem não tiver Argon2; `password_needs_rehash` no login.
- Mensagem de erro de login genérica ("e-mail ou senha inválidos"), sem revelar se o e-mail existe.
- Redefinição de senha: token aleatório de 32 bytes, gravado como **SHA-256**, válido por 60 min e de uso único; resposta igual exista ou não a conta. (`PasswordResetService`)
- Trocar a senha encerra as outras sessões da conta (carimbo da senha na sessão, conferido pelo `Auth` a cada requisição).

## 5. Autorização (RBAC)
- Papéis: `owner`, `manager`, `production`, `support` (enum `AdminRole`).
- `AdminRoleMiddleware` verifica o papel **por rota** (ver [04-rotas.md](04-rotas.md)).
- Verificação de posse no Service: um cliente só acessa os **próprios** pedidos e endereços (evita IDOR).
- Arquivos de produção só via controller autenticado com papel `manager` ou `production`.

## 6. Validação de entrada
- Validação **no servidor** sempre (a do front-end é só UX), numa classe `Validator` central.
- Tipos convertidos explicitamente (`(int)`, enums `::tryFrom`).
- Personalização validada pelo `PersonalizationService` com base na regra do banco: tipo, obrigatoriedade, min/max, preset de caracteres, opção existente e ativa. **O valor enviado pelo navegador nunca define preço.**
- **Preço sempre recalculado no servidor** no carrinho e no checkout.
- Normalização: CPF, CEP e telefone só com dígitos; e-mail em minúsculas; `trim` e remoção de caracteres de controle.

## 7. Rate limiting (tabela `rate_limits`)

| Ação | Limite |
|---|---|
| Login cliente/admin | 5 tentativas / 15 min por IP+e-mail; bloqueio de 15 min |
| Recuperar senha | 3 / hora por e-mail e 10 / hora por IP |
| Cadastro | 5 / hora por IP |
| Cupom | 10 / 10 min por IP |
| Busca, cotação de frete | 60 / min por IP |
| Checkout | 10 pedidos / hora por IP (`payment.max_orders_per_hour`) |

Valores em `config/security.php` (`rate_limits`).

## 8. Uploads
- Imagens de produto: MIME verificado pelo conteúdo (`finfo`) **e** extensão em lista branca (`jpg`, `jpeg`, `png`, `webp`), tamanho máximo pelo `.env`, reprocessadas com GD (remove metadados/EXIF e código embutido), nome aleatório. Ficam em `public/uploads`, onde o `.htaccess` impede executar PHP.
- Arquivos de produção: lista branca (`nc`, `tap`, `gcode`, `dxf`, `svg`, `pdf`, `crv`, `crv3d`, `zip`), nome aleatório, gravados em `storage/private/production_files`, com SHA-256 registrado. Download via `readfile` com `Content-Disposition: attachment`.

## 9. Cabeçalhos HTTP (`SecurityHeadersMiddleware`)
`Content-Security-Policy`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (+ `frame-ancestors 'self'` na CSP), `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `Strict-Transport-Security` (somente produção com HTTPS). Remover `X-Powered-By` (`expose_php=Off` ou `header_remove`).

## 10. Erros e logs
- `APP_DEBUG=false` em produção: `display_errors=0`. Exceções vão para `storage/logs/app-AAAA-MM-DD.log` e o usuário vê página genérica (500) **sem stack trace, SQL ou caminhos**.
- Em dev, detalhes só com `APP_DEBUG=true`.
- Logs nunca registram senha, token, dados de cartão ou payload completo de login.
  - O `Logger` mascara chaves sensíveis (`password`, `_token`, `card`...).
  - Stack traces são gerados **sem argumentos** (`zend.exception_ignore_args=1`, definido no bootstrap independentemente do `php.ini` da hospedagem).
  - Todo parâmetro que recebe senha usa `#[\SensitiveParameter]` (PHP 8.2). Um teste (`tests/Unit/SensitiveDataTest.php`) garante as duas proteções.
- Cada erro recebe um ID curto exibido ao usuário ("Código: 7F3A2C") para correlacionar com o log.

## 11. Segredos e arquivos
- Credenciais só no `.env` (fora do webroot, bloqueado pelo `.htaccess`, fora do Git). `.env.example` versionado sem valores.
- `settings` no banco guarda só configurações **não sensíveis**.
- Diretórios `app/`, `config/`, `storage/`, `vendor/` etc. inacessíveis pela web (ver [01-arquitetura.md](01-arquitetura.md)).

## 12. Pagamentos
- Nenhum dado de cartão passa pelo servidor: tokenização/checkout do **próprio gateway** (PCI-DSS SAQ-A).
- Webhook: assinatura validada (HMAC com `PAYMENT_WEBHOOK_SECRET`), status **reconsultado na API do gateway** antes de confirmar e idempotência por `payment_events(provider, event_id)`.
- Valor pago conferido contra `orders.total_cents`.

## 13. Auditoria
Registrar em `audit_logs`: login, logout, falha de login, criação, alteração e exclusão de entidades administrativas, **alteração de preço**, **alteração de estoque**, alteração de ficha de produção, alteração de pedido e **mudanças de status** (com JSON antes/depois, IP e user agent).

## 14. LGPD
- Coletar só o necessário (nome, e-mail, telefone, CPF para nota fiscal/transportadora, endereço).
- Opt-in explícito e separado para WhatsApp e marketing (`customers.whatsapp_opt_in`, `marketing_opt_in`).
- Página de privacidade; exportação (JSON) e anonimização do cliente sob solicitação, pelo proprietário, com guarda fiscal dos pedidos recentes (`CustomerPrivacyService`, [16](16-seguranca-e-testes.md) §2).
- Só cookies necessários (sessão e carrinho): sem banner de consentimento enquanto não houver cookie de terceiros.

## 15. Checklist de verificação (Etapa 11)

Todos cobertos por testes automáticos — mapa item → teste em [16 — Segurança, LGPD e testes](16-seguranca-e-testes.md) §4.

- [x] Tentativa de SQLi em busca, filtros e login
- [x] XSS armazenado em nome de produto, personalização e dados do cliente (avaliações ainda não existem: testar quando existirem)
- [x] POST sem token CSRF é rejeitado (419)
- [x] Cliente A não acessa pedido do cliente B
- [x] Papel `support` não altera preço nem status de produção
- [x] Acesso direto a `/.env`, `/storage/...`, `/app/...` retorna 403 (regras testadas + verificação manual no Apache — repetir no servidor real)
- [x] Upload de `.php` renomeado para `.jpg` é rejeitado
- [x] Erro forçado não exibe stack trace com `APP_DEBUG=false`
- [x] Preço alterado no HTML não altera o total do pedido
