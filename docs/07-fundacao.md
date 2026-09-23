# 07 — Fundação (Etapa 2)

Guia de uso do núcleo da aplicação. Leia antes de implementar qualquer etapa seguinte.

## 1. O que existe

| Peça | Arquivo | Função |
|---|---|---|
| Front controller | `public/index.php` | único ponto de entrada web |
| Bootstrap | `app/Core/Bootstrap.php` | carrega `.env` e `config/`, prepara o container (web, CLI e testes) |
| Container | `app/Core/Container.php` | injeção de dependências por construtor (autowiring) |
| Roteador | `app/Core/Router.php` | FastRoute com grupos (prefixo + middleware) |
| Kernel | `app/Core/Kernel.php` | rota → middleware → controller; erros viram respostas seguras |
| Request / Response | `app/Core/Request.php`, `Response.php` | entrada e saída HTTP |
| Views | `app/Core/View.php`, `app/Views/` | templates PHP com layout e partials |
| Banco | `app/Core/Database.php`, `Repository.php` | PDO com prepared statements nativos, UTC e modo estrito |
| Sessão / CSRF | `app/Core/Session.php`, `Csrf.php` | sessão segura, mensagens flash, token CSRF |
| Autenticação | `app/Core/Auth.php` (sessão) + `app/Services/Auth/AuthService.php` (credenciais) | login de cliente e de administrador, separados |
| Autorização | `app/Middleware/RequireAdminRole.php` + `app/Enums/AdminRole.php` | RBAC por rota |
| Limite de tentativas | `app/Services/RateLimiter.php` | tabela `rate_limits` |
| Auditoria | `app/Services/AuditService.php` | tabela `audit_logs` |
| Erros e logs | `app/Core/ErrorHandler.php`, `Logger.php` | página da marca + código do erro; log diário |
| Migrations | `database/migrate.php`, `app/Core/Migrations/` | aplica `.sql` pendentes |
| CLI | `bin/create-admin.php`, `bin/generate-key.php`, `bin/cron.php` | administração e tarefas periódicas |

## 2. Comandos

```bash
composer install                  # dependências
php bin/generate-key.php          # gera APP_KEY no .env
composer migrate -- --create-db --seed   # cria o banco, aplica migrations e seeds
php database/migrate.php --status # o que já foi aplicado
php database/migrate.php --fresh  # APAGA e recria tudo (bloqueado em produção)
php bin/create-admin.php --email=voce@dominio.com --name="Seu Nome" --role=owner
composer serve                    # http://localhost:8000 (servidor embutido do PHP)
composer test                     # PHPUnit (unitários + integração no banco gnesting_test)
```

## 3. Como adicionar uma página

1. **Rota** em `routes/web.php` (loja) ou `routes/admin.php` (painel):
   ```php
   $r->get('/produtos/{id:\d+}/editar', [ProductController::class, 'edit'], ['role:manager']);
   ```
2. **Controller** em `app/Controllers/Store|Admin`, estendendo `GNesting\Core\Controller`. As dependências vêm pelo construtor (o container resolve sozinho):
   ```php
   public function __construct(private readonly ProductService $products) {}

   public function edit(Request $request): Response
   {
       $product = $this->products->find((int) $request->param('id')) ?? throw HttpException::notFound();
       return $this->render('admin/products/edit', ['product' => $product], 'admin');
   }
   ```
3. **Service** em `app/Services`, com a regra de negócio e as transações (`$db->transaction(fn () => ...)`) e chamando o `AuditService` quando for ação administrativa.
4. **Repository** em `app/Repositories`, estendendo `GNesting\Core\Repository`. É o único lugar com SQL. Cada parâmetro nomeado aparece **uma vez** por consulta (prepares nativos).
5. **View** em `app/Views/...php`. Toda saída passa por `e()`. Formulários levam `<?= csrf_field() ?>`. Nada de `style=""` nem `<script>` inline (a CSP bloqueia).

## 4. Formulários e validação

```php
$this->validate($request, [
    'name' => 'required|max:150',
    'price' => 'required|integer',
], ['name' => 'Nome', 'price' => 'Preço'], keepOld: ['name', 'price']);
```

- Em caso de erro, o usuário volta ao formulário (mesma URL, via GET) com `$errors` e `$old` preenchidos. O partial `partials/field` já exibe as duas coisas.
- Regras disponíveis: `required`, `email`, `min:n`, `max:n`, `same:campo`, `in:a,b`, `integer`, `digits:n`, `gte:n`, `lte:n`, `money`, `slug`, `sku`.
- Dinheiro: `parse_money("1.234,56")` → `123456` (centavos, sem float); `money_input(12990)` → `"129,90"` para campos.
- Ações que não são formulário (excluir, ativar) usam `BusinessRuleException` → `flash('error', ...)` + redirecionamento.
- Leia a entrada com `$request->string('campo')` (texto limpo), `$request->secret('password')` (sem alteração) ou `$request->param('id')` (rota). **Nunca** use `$_POST` ou `$_GET`.
- Após um POST bem-sucedido: `$this->flash('success', '...')` e `return $this->redirect('/destino');` (303).

## 5. Middleware disponíveis

| Apelido | Efeito |
|---|---|
| `session`, `csrf` | globais, em toda rota |
| `auth` / `guest` | exige / impede cliente logado |
| `admin` / `admin.guest` | exige / impede administrador logado |
| `role:manager,production` | papéis permitidos (depois de `admin`); `owner` sempre passa |

Novos middleware implementam `GNesting\Middleware\Middleware` e são registrados em `config/middleware.php`.

## 6. Autenticação

- Cliente e administrador têm **sessões independentes** (`auth.customer` / `auth.admin`). Sair do painel não desconecta a conta de cliente.
- O perfil é **relido do banco a cada requisição**: bloquear um usuário ou mudar o papel vale na hora.
- A sessão do painel expira em 12 h mesmo com uso (`SESSION_ADMIN_ABSOLUTE_HOURS`). Qualquer sessão expira após `SESSION_LIFETIME` minutos sem uso.
- No login e no logout o ID da sessão e o token CSRF são trocados.
- Senhas: Argon2id (ou bcrypt), rehash automático no login, mínimo de 8 caracteres para clientes e 12 para administradores.
- Limite: 5 falhas por e-mail + IP e 20 por IP em 15 min; cadastro limitado a 5 por hora por IP.
- Quem já comprou como visitante e depois cria conta com o mesmo e-mail fica com o histórico unificado.

## 7. Testes

- `tests/Unit`: sem banco (helpers, validação, rotas, sessão/CSRF, status do pedido, divisor de SQL, dados sensíveis).
- `tests/Integration`: banco `DB_TEST_DATABASE` (padrão `gnesting_test`), **recriado a cada execução**. Cada teste roda numa transação desfeita no final. `HttpKernelTest` simula um navegador passando pelo pipeline HTTP completo.
- Os logs gerados pelos testes vão para a pasta temporária do sistema, não para `storage/logs`.

## 8. Verificações realizadas nesta etapa

- 58 testes automatizados, todos passando.
- Servidor embutido e **Apache 2.4 com os `.htaccess` do projeto**: 20 caminhos sensíveis (`.env`, `vendor/`, `app/`, `storage/`, `database/`, `composer.json`, variações com `../` e `%2e`) retornam 403, e um `.php` colocado em `uploads/` não executa.
- Fluxos de ponta a ponta: cadastro, login e logout de cliente e de administrador; bloqueio após 5 tentativas; CSRF (419); XSS escapado; página 500 sem detalhes técnicos.
