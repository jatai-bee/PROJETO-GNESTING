# 16 — Segurança, LGPD e testes (etapa 11)

Esta etapa fechou as lacunas da política de segurança ([05](05-seguranca.md)) e transformou o checklist do §15 em testes
automáticos. Também criou a verificação que deve rodar antes de todo deploy: `composer check`.

## 1. Recuperação de senha

Loja: **Entrar → Esqueci minha senha** (`/recuperar-senha`). Painel: **login → Esqueci minha senha**
(`/admin/recuperar-senha`).

1. A pessoa informa o e-mail. A resposta é **sempre a mesma**, exista ou não a conta: ninguém descobre quais e-mails
   estão cadastrados.
2. Se houver conta **ativa daquele tipo**, ela recebe um link: `/redefinir-senha/<token>` na loja e
   `/admin/redefinir-senha/<token>` no painel.
   - Um cliente nunca recebe link do painel, e um admin nunca recebe link da loja.
   - O token é de 32 bytes aleatórios. O banco (`password_resets`) guarda só o **SHA-256** dele.
   - Vale **60 minutos** e **uma vez**. Pedir de novo invalida o link anterior.
3. A página da nova senha é enviada com `Referrer-Policy: no-referrer`, para o token não vazar para outro site.
4. Salvar a nova senha:
   - troca o hash;
   - consome o token;
   - marca o e-mail como confirmado (`users.email_verified_at`), porque só quem recebeu o link chega ali;
   - registra `password_reset` na auditoria;
   - leva de volta ao login.
5. **Limites:** 3 pedidos por hora por e-mail e 10 por hora por IP (`security.rate_limits.password_reset*`).
6. O cron apaga os tokens vencidos há mais de um dia.

Os requisitos da nova senha são os mesmos do cadastro: mínimo de 8 caracteres na loja e 12 no painel.

### Sessões caem quando a senha muda

A sessão guarda um **carimbo** da senha: os 16 primeiros caracteres do SHA-256 do hash. A cada requisição, `Auth` compara
esse carimbo com o do banco. Se a senha foi trocada (pelo link, ou pelo proprietário em **Usuários**), as outras sessões
daquela conta são encerradas na próxima página.

Quando o proprietário troca **a própria** senha, a sessão atual continua (`Auth::refreshAdminStamp`).

## 2. LGPD: direitos do titular

A política de privacidade orienta o cliente a pedir, **do e-mail cadastrado**, acesso, cópia, correção ou exclusão.
O **proprietário** executa o pedido na página do cliente (**Clientes → cliente → Dados pessoais (LGPD)**).
Antes, deve confirmar a identidade de quem pediu, por exemplo respondendo ao e-mail cadastrado.

| Ação | O que faz |
|---|---|
| **Exportar dados (JSON)** | Baixa `dados-cliente-<id>.json` com cadastro, conta (datas de criação, último login, e-mail confirmado), endereços e pedidos com itens e personalização |
| **Anonimizar cadastro** | Exige digitar `ANONIMIZAR`. Recusada se houver pedido em andamento (não finalizado) |

A anonimização:

- troca o nome por "Cliente anonimizado" e o e-mail por `anonimizado-<id>@anonimizado.invalid`;
- apaga CPF, telefone e consentimentos;
- **apaga o login**;
- apaga endereços, carrinhos e lista de desejos;
- grava `customers.anonymized_at` (migration 005).

Os **pedidos** continuam ligados ao cadastro, porque a legislação fiscal e o CDC exigem a guarda (LGPD, art. 16, I).
Os pedidos **mais antigos que `ORDER_RETENTION_YEARS`** (padrão 5 anos) também têm nome, e-mail, CPF, telefone, endereço
e textos de personalização apagados na mesma operação. Os mais recentes são mantidos como estão.

As duas ações vão para a auditoria **sem dado pessoal**: só a ação, o id e as contagens.

**Cookies:** a loja usa só cookies necessários (sessão e carrinho), então não precisa de banner de consentimento. Se um
dia entrar pixel de anúncio ou analytics de terceiros, o banner passa a ser obrigatório *antes* de carregá-los.

**Consentimento de marketing:** as colunas `customers.marketing_opt_in` e `whatsapp_opt_in` existem desde a etapa 1, mas
ainda não há tela para marcá-las, porque a loja não envia marketing. Quando houver newsletter, o consentimento deve ser uma
caixa **desmarcada** no cadastro e no checkout, e o descadastro precisa estar em todo e-mail.

## 3. Endurecimentos desta etapa

- **`public/.htaccess` só executa `index.php`.** Qualquer outro `.php` em `public/` responde 403.
  - Antes, um script esquecido ali (backup, teste, `info.php`) era executado. Verificado num Apache 2.4 real: `/qa-evil.php`
    dava **200** e passou a dar **403**.
  - Arquivos ocultos em `public/` também dão 403.
- **Raiz:** `phpstan.neon.dist` entrou na lista de arquivos bloqueados. Na verdade tudo na raiz já cai no front controller;
  a lista é defesa em profundidade.
- **Auditoria:** passou a ter rótulos para todos os tipos de item criados nas etapas 5–11.

### Verificação no Apache (manual, antes de publicar)

Os `.htaccess` foram testados num Apache 2.4.66 com mod_php apontando para a **raiz** do projeto (o pior caso na
hospedagem compartilhada). Com arquivos-isca criados só para o teste:

- **Liberados (200):** `/`, `/produtos` e `/assets/css/app.css`.
- **Bloqueados (403), sem vazar conteúdo:**
  - `/.env`, `/.git/config`, `/app/…`, `/config/…`, `/storage/…`, `/vendor/…`, `/database/…`, `/docs/…`, `/tests/…`, `/bin/…`;
  - `composer.json/lock`, `phpunit.xml`, `README.md`;
  - `/uploads/x.php`, `/x.php` e `/public/.env.x`.

Repita no servidor real depois do deploy: roteiro em [17 — Deploy e operação](17-deploy-e-operacao.md) §12.

O cabeçalho `Server:` mostra a versão do Apache e do PHP. Numa hospedagem compartilhada isso é configuração do servidor
(`ServerTokens Prod`, `expose_php = Off`) e não dá para mudar pelo `.htaccess`. Peça ao suporte, ou desligue `expose_php`
no "Select PHP Version" do cPanel.

## 4. Testes

`composer check` roda, em ordem (e é o que o CI roda a cada push):

| Passo | Comando | O quê |
|---|---|---|
| Sintaxe | `composer lint` (`bin/lint.php`) | `php -l` em todos os PHP, **inclusive as views** |
| Análise estática | `composer analyse` | PHPStan nível 6 em `app/`, `bin/`, `config/`, `database/`, `routes/` (views ficam de fora: são templates) |
| Dependências | `composer audit` | vulnerabilidades conhecidas nos pacotes do Composer |
| Testes | `composer test` | PHPUnit: **215 testes** (unitários + integração com MySQL) |

Na primeira passada, o PHPStan achou 23 pontos. Nenhum era falha de segurança; eram tipos mal declarados, checagens
redundantes e um `match` sem `default`. Todos foram corrigidos.

**CI:** `.github/workflows/ci.yml` roda `composer check` num Ubuntu com PHP 8.3, em MySQL 8.4 **e** 5.7 (etapa 13), a cada push na `main` e em pull
requests.

### Checklist de segurança (docs/05 §15) → testes

| Item | Onde |
|---|---|
| SQLi em busca, filtros e login | `SecurityTest::testSqlInjectionAttemptsAreHarmless` |
| XSS armazenado em nome de produto, cliente, endereço e personalização | `testStoredXssIsEscapedInStoreAdminAndProduction`, `testPersonalizationRejectsMarkup` |
| POST sem CSRF → 419 | `testEveryPostRouteRejectsRequestsWithoutCsrfToken` (varre **todas** as 68 rotas POST) |
| Cliente A não vê pedido do cliente B | `testOrderPagesAreOnlyVisibleToTheirOwner`, `CheckoutHttpTest::testLoggedCustomerSavesAddressAndSeesOnlyOwnOrders` |
| Papéis (ex.: atendimento não altera preço) | `testEveryRoleRestrictedRouteForbidsOtherRoles` (cada rota × cada papel não autorizado → 403) |
| Painel exige login | `testEveryAdminRouteRequiresLogin` (todas as rotas `/admin`) |
| `/.env`, `/storage`, `/app` → 403 | `testWebServerConfigurationBlocksSensitivePaths` (regras dos `.htaccess`) + verificação no Apache (§3) |
| Upload de `.php` renomeado para `.jpg` | `ProductImagesTest::testPhpDisguisedAsImageIsRejected` |
| Erro sem stack trace com `APP_DEBUG=false` | `HttpKernelTest::testUnexpectedErrorShowsGenericPageWithoutDetails` |
| Preço alterado no HTML não muda o total | `testPricesComeFromTheServerNotFromTheForm` |
| Redirecionamento aberto no login | `testLoginNeverRedirectsToAnotherSite` |
| Cabeçalhos em todo tipo de resposta | `testSecurityHeadersOnEveryKindOfResponse` |

Os testes que varrem rotas leem o `Router`, então **uma rota nova já nasce coberta**. Se ela esquecer o papel ou o CSRF, o
teste falha. O teste de XSS foi conferido por mutação: tirar o `e()` do nome do cliente na página do pedido faz o teste falhar.

Recuperação de senha, sessões e LGPD: `tests/Integration/AccountSecurityTest.php`.
Base para testes HTTP com vários navegadores e caixa de e-mail compartilhada: `tests/Integration/HttpTestCase.php`.

## 5. Fora do escopo desta etapa

- **Confirmação de e-mail no cadastro.** Hoje o e-mail só é confirmado quando a pessoa redefine a senha. Com isso, pedidos
  feitos sem conta continuam **não** sendo ligados automaticamente a uma conta nova com o mesmo e-mail (etapa 7).
- **Autenticação em dois fatores** para o painel.
- **Troca de senha e de dados pelo próprio cliente** em "Minha conta".
- **Cabeçalhos do servidor** (`Server`, HSTS preload) e **backups:** tratados na etapa 12 ([17 — Deploy e operação](17-deploy-e-operacao.md)).
