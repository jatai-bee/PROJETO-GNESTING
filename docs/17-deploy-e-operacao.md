# 17 — Deploy e operação (etapas 12 e 13)

Como publicar a loja numa hospedagem compartilhada (cPanel) e mantê-la no ar: pacote, instalação, HTTPS, backups,
restauração, manutenção, cron e monitoramento.

**Sem Terminal (etapa 13).** Planos de entrada de hospedagem compartilhada não dão SSH. Por isso, tudo o que antes era
comando tem um caminho pelo navegador:
- instalar: assistente `instalar.php`;
- atualizar o banco e restaurar backups: tela **Sistema** do painel;
- tarefas automáticas: Cron Jobs do cPanel (é um formulário) ou cron por URL;
- configuração e tabela de frete: arquivo YAML importado pelo painel.

Os comandos de terminal continuam funcionando para quem tiver SSH (§15).

Configuração: [`config/operations.php`](../config/operations.php) e o bloco "Produção e operação" do `.env.example`.

## 1. O que roda onde

| Peça | Onde | Quando |
|---|---|---|
| Loja e painel | `public/index.php` (Apache + PHP 8.2+) | cada requisição |
| Instalador | `public/instalar.php` | uma vez; depois se tranca (`storage/installed.lock`) |
| Tarefas automáticas | Cron Jobs → `bin/cron.php`, ou `cron.php?token=…` por um serviço de ping | a cada 15 min |
| Backup | tarefa do cron ou botão no painel | 1× por dia, depois de `BACKUP_HOUR` |
| Monitor externo | `GET /saude` | a cada 5 min (UptimeRobot ou similar) |
| Alertas | e-mail para `ALERT_EMAIL` | erro 500, falha do cron ou do backup |
| Tela **Sistema** | `/admin/sistema` (só **proprietário**) | saúde, lista de verificação, atualizar banco, backups (fazer, baixar, restaurar), manutenção, cron |
| Configuração em YAML | `/admin/configuracoes` (só **proprietário**) | exportar/importar loja, frete, categorias e materiais (docs/15 §7) |

## 2. Pacote de produção

O pacote é um `.zip` com tudo o que vai para a hospedagem, **inclusive a pasta `vendor/`**: não há Composer no servidor.
Ele é extraído direto no `public_html` do cPanel (§3.2) e traz um `LEIA-ME-INSTALACAO.txt` com o
resumo da instalação.

**No computador, com dois cliques (recomendado):** `gerar-pacote.cmd`, na pasta do projeto. O script:
- acha o PHP e o Composer (PATH, Laragon ou `%USERPROFILE%\tools`);
- roda o `bin/build-release.php`;
- copia o `.zip` e um `LEIA-ME.txt` para a pasta **`PACOTE-CPANEL`**, ao lado da pasta do projeto, e abre essa pasta.

`gerar-pacote.cmd /silencioso` faz o mesmo sem abrir o Explorer nem pausar (para automação).

Pelo terminal, o mesmo:

```bash
composer check                 # o mesmo que o CI roda
php bin/build-release.php      # → build/gnesting-AAAAMMDD-HHMM-<commit>.zip
```

**Alternativa pelo GitHub:** a cada push na `main` com o CI verde, o GitHub também monta o pacote (aba **Actions** →
execução → **Artifacts**). O download vem dentro de outro .zip.

- O pacote sai do **último commit** (`git archive`). Alterações não commitadas ficam de fora, e o script avisa.
- `vendor/` vem só com as dependências de produção (`composer install --no-dev`, autoload *classmap-authoritative*).
- Ficam de fora (`.gitattributes` → `export-ignore`): `tests/`, `docs/`, `.github/`, configs de PHPUnit/PHPStan.
- Nunca vão no pacote: `.env`, logs, sessões, uploads, arquivos de produção, backups.
- O script **recusa** montar o pacote se encontrar `.env`, testes, docs, PHPUnit/PHPStan ou arquivo estranho em `public/`.
  Tudo em `public/` fica público; só podem estar lá `index.php`, `instalar.php`, `cron.php`, `.htaccess`, `assets/` e `uploads/`.
- O arquivo `RELEASE` (commit e data) aparece na tela Sistema: é assim que se sabe qual versão está no ar.
- O nome do pacote e o LEIA-ME usam o horário de Brasília; o `RELEASE` fica em UTC.

## 3. Primeira instalação (cPanel, sem Terminal)

### 3.1 Banco de dados

cPanel → **Bancos de dados MySQL**:

1. Crie o banco (o cPanel prefixa com o seu usuário: `USUARIO_gnesting`).
2. Crie um usuário com senha forte.
3. **Adicione o usuário ao banco com TODOS OS PRIVILÉGIOS.**

Sem Terminal, é esse usuário que cria e atualiza as tabelas (instalador e "Atualizar banco de dados") e restaura backups.
Quem tiver SSH pode separar um usuário só com `SELECT, INSERT, UPDATE, DELETE` para a loja (§15 e docs/05 §1).

### 3.2 Arquivos

Mesmo jeito do Delivery Premium BR: o pacote vai **direto no `public_html`** e funciona na hora. Depois, o domínio
passa a apontar para `public_html/public`.

1. Gere o pacote (§2) ou use o que está na pasta `PACOTE-CPANEL`.
2. **Gerenciador de Arquivos** → `public_html`:
   - apague os arquivos padrão da hospedagem (`index.html`, `default.php`…), mantendo `cgi-bin` e `.well-known`;
   - envie o `.zip` → botão direito → **Extract** (destino `/home/USUARIO/public_html`) → apague o `.zip`.
3. **Funciona na hora.** O `.htaccess` da raiz do pacote bloqueia `app/`, `bin/`, `config/`, `database/`, `docs/`,
   `routes/`, `storage/`, `tests/`, `vendor/`, arquivos ocultos (`.env`, `.git`) e `composer.*`/`README.md`, e encaminha
   todo o resto para `public/`. Verificado num Apache 2.4 real (docs/16 §3) e coberto por
   `SecurityTest::testWebServerConfigurationBlocksSensitivePaths`.
   - **Antes de instalar, confira** no navegador que `/app/`, `/storage/`, `/vendor/autoload.php` e `/.env.example`
     respondem 403. Se algum mostrar conteúdo, o `mod_rewrite` está desligado: peça ao suporte antes de seguir.
4. **Depois de instalar (recomendado):** *Domínios* → *Gerenciar* → raiz do documento (*Document Root*)
   `public_html/public`. É um reforço: mesmo que o `.htaccess` deixe de funcionar, só `public/` fica na internet. Os
   endereços da loja não mudam. Se a hospedagem não deixa mudar a raiz do domínio principal, peça ao suporte; se não
   for possível, a loja segue protegida pelo `.htaccess`.
5. Permissões (Gerenciador de Arquivos → *Permissions*): `storage/`, `public/uploads/` e a própria `public_html`
   graváveis pelo PHP. **755** costuma bastar no cPanel, onde o PHP roda com o seu usuário.

**Variante mais restrita:** extrair numa pasta fora do `public_html` (ex.: `/home/USUARIO/gnesting`) e apontar a raiz do
documento para `gnesting/public` antes de instalar. Nada fica sob o `public_html` em momento algum; exige poder mudar a
raiz do domínio (ou pedir ao suporte) logo no início.

### 3.3 PHP

*Selecionar versão do PHP* (*Select PHP Version* / *MultiPHP Manager*): **PHP 8.2 ou mais novo** (testado com 8.3), com
as extensões `pdo_mysql`, `mbstring`, `fileinfo`, `gd` (com WebP e JPEG), `openssl`, `curl`, `zlib`, `phar` e `json`.
Nas opções, desligue `expose_php`. O instalador confere tudo isso na primeira tela.

### 3.4 HTTPS antes do instalador

cPanel → **SSL/TLS Status** → *Run AutoSSL*. Espere o cadeado aparecer em `https://SEU-DOMINIO`: o instalador exige
`https://` para endereços de produção.

### 3.5 Assistente de instalação

Abra `https://SEU-DOMINIO/instalar.php` (a home também leva até ele enquanto não houver `.env`).

1. **Conferência da hospedagem:** versão do PHP, extensões e permissões de gravação. Item com ✕ bloqueia: corrija no
   cPanel e recarregue.
2. **Dados da loja** (uma tela):
   - **Endereço da loja:** já vem preenchido com o endereço da página.
   - **UF de onde os pedidos saem:** usada no frete.
   - **Banco:** servidor (`localhost`), porta, nome, usuário e senha. É testado antes de qualquer gravação.
   - **Seu acesso:** nome, e-mail e senha (12+ caracteres). Vira o **proprietário** e recebe os alertas.
   - **Opcionais agora:** SMTP do e-mail da loja e credenciais do Mercado Pago.
3. **Instalar a loja.** O assistente:
   - grava o `.env` com `APP_KEY`, `HEALTH_TOKEN` e `CRON_TOKEN` novos;
   - detecta produção pelo endereço: `APP_ENV=production`, `APP_DEBUG=false`, cookie só em HTTPS e pagamento Mercado Pago
     (o simulado nunca vai para produção);
   - aplica as migrations e confere as tabelas;
   - cria o proprietário;
   - grava `storage/installed.lock`.
4. A tela final mostra o que foi feito, o **comando do Cron Jobs** e o **endereço do cron por URL** (§9).

Depois de instalada, `instalar.php` responde "A loja já está instalada" (403) para qualquer pessoa. Duas travas:
- o arquivo `storage/installed.lock`;
- um proprietário no banco do `.env`, que continua valendo mesmo se alguém apagar o arquivo.

Reinstalar de propósito: apague a trava **e** as tabelas pelo phpMyAdmin.

> **Faça a instalação logo depois de enviar os arquivos.** Até ela terminar, quem abrir `instalar.php` antes de você
> poderia instalar a loja com um banco próprio. O risco vale só para essa janela de minutos, e a trava fecha a porta
> em seguida.

Se algo falhar no meio (ex.: queda de conexão), corrija e envie de novo. As migrations já aplicadas são puladas, e o
`.env` é regravado.

### 3.6 Completar o `.env` (sem Terminal)

O que ficou em branco no assistente (SMTP, Mercado Pago) e os ajustes finos se editam no próprio arquivo:
**Gerenciador de Arquivos** → `public_html/.env` → botão direito → **Edit** (marque "mostrar arquivos ocultos" nas
configurações do Gerenciador).

| Variável | Para quê |
|---|---|
| `MAIL_DRIVER=smtp`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | e-mails da loja (docs/13 §4) |
| `MERCADOPAGO_ACCESS_TOKEN`, `MERCADOPAGO_WEBHOOK_SECRET` | pagamentos (docs/12 §4) |
| `SHIPPING_PICKUP=true` | oferecer "Retirada no ateliê" (também dá pelo YAML, docs/15 §7) |
| `PRODUCTION_DAILY_MINUTES` | capacidade da oficina por dia (docs/14 §3) |
| `PAYMENT_EXPIRY_HOURS` | horas para pagar antes do cancelamento automático |
| `TRUSTED_PROXIES` | faixas da Cloudflare, se usar (§5) |

O `.env` guarda senhas: nunca o envie a ninguém. Na internet ele é bloqueado pelo `.htaccess` (arquivo oculto) e, depois
do passo 4 do §3.2, fica fora da raiz do documento.

## 4. Lista de verificação de produção

Painel → **Sistema** → *Lista de verificação de produção* (com Terminal: `php bin/check-production.php`). **Erro** deve
ser resolvido antes de vender; **aviso**, logo depois. Quase tudo se resolve no `.env` (§3.6).

| Verificação | Nível |
|---|---|
| PHP 8.2+, extensões, GD com WebP e JPEG | erro |
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` gerada, `APP_URL` com `https://` | erro |
| `SESSION_SECURE_COOKIE=true` | erro |
| Mercado Pago como provedor, com access token e segredo do webhook | erro |
| E-mail de verdade (`smtp` com host, ou `mail`) | erro |
| Proprietário ativo cadastrado | erro |
| Banco conectado, pastas graváveis, migrations aplicadas | erro |
| Redirecionamento para https ligado | aviso |
| `ALERT_EMAIL` preenchido | aviso |
| `HEALTH_TOKEN` com 20+ caracteres | aviso |
| `expose_php` desligado | aviso |

## 5. HTTPS, domínio e proxies

- **Certificado:** o *AutoSSL* do cPanel (Let's Encrypt/Sectigo) emite e renova sozinho. `/.well-known/` continua acessível
  para a validação (`.htaccess`).
- **Redirecionamento:** com `APP_ENV=production` e `APP_URL` em https, todo acesso vai para `https://` no host de
  `APP_URL`: `http://…` e `https://www.…` → `https://gnesting.com.br/…` com **301** (GET/HEAD) ou **308** (formulários,
  preserva o método). `APP_FORCE_HTTPS=false` desliga; `true` força em outro ambiente.
- **HSTS:** em produção sob https a aplicação envia `Strict-Transport-Security: max-age=31536000; includeSubDomains`.
  Atenção: vale para **todos os subdomínios** por 1 ano. Antes de publicar, confirme que `www`, `mail` etc. também têm
  certificado. HSTS *preload* não é usado.
- **Cloudflare ou outro proxy na frente:** preencha `TRUSTED_PROXIES` com as faixas do proxy
  (Cloudflare: https://www.cloudflare.com/ips/). Só de IPs confiáveis os cabeçalhos `X-Forwarded-For/-Proto/-Host` são
  aceitos: sem isso, o IP de todo cliente seria o do proxy (e os limites de tentativas valeriam para todos juntos).
  Na Cloudflare use SSL **Full (strict)**; o modo *Flexible* chega em http no servidor e entra em loop de redirecionamento.

## 6. Backups

**Automático:** o cron faz um backup por dia, no primeiro ciclo depois de `BACKUP_HOUR` (horário da loja; padrão 3 h).
**Manual:** Sistema → **Fazer backup agora**.

Cada backup é uma pasta `storage/backups/AAAA-MM-DD_HHMMSS/` (horário UTC no nome):

| Arquivo | Conteúdo |
|---|---|
| `database.sql.gz` | banco completo, gerado só com PDO (não depende de `mysqldump` nem de Terminal) |
| `files.tar.gz` | `public/uploads` + `storage/private/production_files` (desligue com `BACKUP_INCLUDE_FILES=false`) |
| `manifest.json` | data, tamanhos, **SHA-256** de cada arquivo, linhas por tabela |

- O backup é montado em `….parcial` e só vira pasta final quando termina: um backup pela metade nunca é listado.
- Dois backups no mesmo segundo ganham nomes diferentes (o segundo seguinte), em vez de falhar.
- **Retenção:** ficam os últimos `BACKUP_KEEP_DAILY` (7) e o primeiro de cada um dos últimos `BACKUP_KEEP_MONTHLY` (3)
  meses. Os demais, e sobras `.parcial` com mais de um dia, são apagados.
- **Contém dados pessoais.** Fica fora da web (`storage/`) e cada download pelo painel vai para a auditoria.

### Cópia fora do servidor (tarefa humana)

Um backup que só existe no mesmo servidor não protege contra perda da hospedagem, invasão ou erro do provedor.
**Toda semana**, em Sistema → Backups, baixe o `banco` (e o `arquivos` quando houver fotos ou arquivos CNC novos) e guarde
num lugar seu, de preferência criptografado. Baixe também a **configuração em YAML** (Configurações → Exportar): é pequena
e se lê num editor de texto. O backup do próprio cPanel é bem-vindo, mas não substitui essa cópia.

**Todo mês**, teste uma restauração numa instalação no computador (§7). Backup que nunca foi restaurado é só uma esperança.

## 7. Restauração (pelo painel)

Sistema → Backups → **Restaurar…** no backup escolhido → marque "Restaurar também fotos e arquivos de produção" se
quiser → digite **RESTAURAR** → *Restaurar este backup*.

Sem a palavra de confirmação nada acontece. Com ela, o sistema:

1. confere os SHA-256 do manifesto (arquivo corrompido interrompe tudo sem mexer no banco);
2. **liga a manutenção** (clientes veem "Voltamos já"; você continua entrando neste navegador);
3. faz um **backup do estado atual** (o ponto de volta, caso o backup escolhido seja o errado);
4. substitui **todo** o banco pelo do backup. Com a opção de arquivos, espelha uploads e arquivos de produção: o que não
   estava no backup é removido;
5. deixa a loja **em manutenção**: confira pedidos, produtos e painel, e só então **Desligar e voltar ao ar**.

Cuidados:
- Os **usuários do painel** voltam a ser os do backup (e as senhas também).
- Pedidos pagos **depois** do backup restaurado somem do banco, mas não do Mercado Pago. Confira o painel do Mercado Pago
  pelo período perdido.
- Se a versão instalada for mais nova que o backup, a tela Sistema pede **Atualizar banco de dados** depois (§11).

**Backup vindo de fora** (loja perdida, hospedagem nova): instale a loja (§3), envie a pasta do backup
(`AAAA-MM-DD_HHMMSS/`, com os 3 arquivos) para `storage/backups/` pelo Gerenciador de Arquivos e restaure pelo painel.
**Sem o PHP da loja:** o `.sql.gz` é SQL comum (phpMyAdmin → Importar), e o `files.tar.gz` abre no Gerenciador de Arquivos
(*Extract*).

Uma restauração de banco grande pode passar do tempo máximo de uma página na hospedagem compartilhada. Se isso
acontecer, a tela Sistema mostra a loja ainda em manutenção: repita a restauração, ou use o phpMyAdmin (Importar).

## 8. Modo manutenção

Enquanto ligado, loja, painel e webhooks respondem **503 "Voltamos já"** com `Retry-After: 600`. O Mercado Pago reenvia as
notificações depois, então nenhum pagamento se perde. `/saude` continua respondendo, com status `atencao`: deploy
planejado não é queda para o monitor.

Painel → Sistema → Manutenção → **Ligar manutenção** / **Desligar e voltar ao ar**. Quem ligou fica com uma passagem
(cookie) no próprio navegador e continua usando loja e painel. Ligar e desligar vão para a auditoria. A restauração
(§7) liga sozinha. Com Terminal: `php bin/maintenance.php on|off` (mostra um link de passagem `?manutencao=…`).

## 9. Tarefas automáticas (cron)

Tarefas (`app/Services/Operations/CronRunner.php`), cada uma isolada: a falha de uma não impede as outras.

| Tarefa | O quê |
|---|---|
| `limites_de_tentativas` | limpa contadores de rate limit vencidos |
| `carrinhos_expirados` | remove carrinhos abandonados vencidos |
| `tokens_de_senha` | remove links de redefinição vencidos |
| `pedidos_nao_pagos` | cancela pedidos sem pagamento após `PAYMENT_EXPIRY_HOURS` e devolve o estoque (docs/12) |
| `logs_antigos` | apaga logs com mais de `LOG_RETENTION_DAYS` dias; `php-errors.log` acima de 10 MB é arquivado |
| `sessoes_expiradas` | apaga sessões vencidas (muitas hospedagens desligam o coletor do PHP em pasta própria) |
| `backup` | backup diário e retenção (§6) |

**Opção 1: Cron Jobs do cPanel (preferível).** É um formulário, não exige Terminal. *Cron Jobs* → configuração comum
**a cada 15 minutos** (`*/15 * * * *`) → comando (a tela final do instalador e a tela Sistema mostram o caminho certo):

```
php /home/USUARIO/public_html/bin/cron.php
```

Se `php` for uma versão antiga, use o caminho do PHP escolhido (ex.: `/opt/cpanel/ea-php83/root/usr/bin/php`); o suporte
da hospedagem informa.

**Opção 2: cron por URL (hospedagem sem Cron Jobs).** Cadastre num serviço de ping gratuito (cron-job.org e afins),
a cada 15 minutos, o endereço mostrado pelo instalador e pela tela Sistema:

```
https://SEU-DOMINIO/cron.php?token=CRON_TOKEN
```

- Sem `CRON_TOKEN` no `.env`, ou com o token errado, a resposta é **404**: quem sonda a URL não descobre que ela existe.
- Resposta `ok` (200) ou `falhou: tarefa…` (500), para o serviço de ping avisar.
- Roda exatamente as mesmas tarefas do `bin/cron.php`.
- Trate o endereço como senha. Trocar o token = editar `CRON_TOKEN` no `.env` e atualizar o serviço de ping.

**Uma execução por vez:** se o Cron Jobs e o cron por URL (ou um ping apressado) coincidirem, a segunda execução sai sem
rodar nada (`storage/cache/cron.lock`). Nunca há dois backups simultâneos.

O resultado fica em `storage/cache/cron.json` (tela Sistema → Último cron). Sem cron há mais de 45 min, `/saude` acusa.

## 10. Monitoramento e alertas

### `/saude`

| Pedido | Resposta |
|---|---|
| `GET /saude` | `{"status":"ok"}`, `"atencao"` ou `"falha"`, sem detalhes |
| `GET /saude?token=HEALTH_TOKEN` | o status e o detalhe de cada verificação |

HTTP **503** só em `falha` (banco fora do ar ou pastas sem gravação); `atencao` responde 200. Não abre sessão nem grava
cookie, não é indexado (`X-Robots-Tag: noindex`, `robots.txt`).

| Verificação | Crítica? | Acusa quando |
|---|---|---|
| `banco` | sim | sem conexão com o MySQL |
| `gravacao` | sim | logs, sessões, cache, uploads ou arquivos de produção sem permissão de gravação |
| `migrations` | não | há migration pendente (Sistema → Atualizar banco de dados) |
| `cron` | não | nunca rodou, está parado há 45+ min, ou alguma tarefa falhou |
| `backup` | não | nenhum backup, ou o último tem mais de 30 h |
| `disco` | não | menos de 500 MB livres (quando a hospedagem informa) |
| `manutencao` | não | modo manutenção ligado |

**Monitor externo** (UptimeRobot, Better Stack…): monitor do tipo *palavra-chave* em `https://gnesting.com.br/saude`,
a cada 5 min, alertando quando **não** contém `"status":"ok"`. Assim você fica sabendo também de cron parado e backup
atrasado. Um monitor simples de HTTP só pegaria as falhas críticas (503). Não use o token na URL do monitor: ele não
precisa dos detalhes.

### Alertas por e-mail

Com `ALERT_EMAIL` preenchido (o instalador usa o e-mail do proprietário), chegam alertas de **erro 500** (com o código do
erro para procurar no log) e de **falha no cron**, inclusive do backup. O mesmo alerta sai no máximo 1× por hora, e no
máximo 20 por dia: uma pane não vira uma enxurrada de e-mails. Os alertas usam o mesmo `MAIL_DRIVER` da loja; se o SMTP
cair, só o monitor externo avisa.

### Logs

`storage/logs/app-AAAA-MM-DD.log` (aplicação, um arquivo por dia, UTC), `php-errors.log` (erros do PHP) e
`mail-*.log` (só com `MAIL_DRIVER=log`). Retenção: `LOG_RETENTION_DAYS` (30). Leia pelo Gerenciador de Arquivos.

## 11. Atualizar a loja (nova versão, sem Terminal)

1. Gere o pacote novo (§2, `gerar-pacote.cmd`). **Guarde o pacote anterior**: é para ele que se volta.
2. Painel → Sistema → **Ligar manutenção**.
3. Sistema → **Fazer backup agora** (o ponto de retorno).
4. Gerenciador de Arquivos → `public_html` → envie o `.zip` → **Extract** (destino `/home/USUARIO/public_html`),
   confirmando a substituição dos arquivos → apague o `.zip`.
   - `.env`, `storage/` e `public/uploads/` não estão no pacote e não são tocados.
   - Arquivos que deixaram de existir na versão nova podem ficar para trás sem efeito: o autoload só carrega as classes
     do pacote novo e `public/.htaccess` só executa `index.php`, `instalar.php` e `cron.php`.
5. Recarregue a tela **Sistema**. Se a versão trouxer mudanças no banco, aparece **Atualização do banco de dados pendente**:
   clique em **Atualizar banco de dados**. Antes de aplicar, o sistema faz outro backup do banco sozinho.
6. Confira a **Lista de verificação** e a versão no topo da tela (commit e data do pacote).
7. Navegue pela loja (home, um produto, carrinho) e pelo painel. Tudo certo: **Desligar e voltar ao ar**.

**Se der errado:** com a manutenção ainda ligada, extraia o pacote anterior. Se o banco foi atualizado, restaure o backup
do passo 3 (§7).

## 12. Verificação do Apache depois do deploy

Repete, no servidor real, o teste feito na etapa 11 (docs/16 §3). No seu computador (PowerShell ou Git Bash):

```bash
for p in / /produtos /assets/css/app.css /saude; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' https://gnesting.com.br$p)  $p"; done          # esperado: 200

for p in /.env /.git/config /app/Core/Kernel.php /config/app.php /storage/logs/ /storage/installed.lock /vendor/autoload.php \
         /database/migrate.php /composer.json /composer.lock /uploads/x.php /x.php /RELEASE; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' https://gnesting.com.br$p)  $p"; done          # esperado: 403 ou 404

curl -s -o /dev/null -w '%{http_code}\n' https://gnesting.com.br/instalar.php   # esperado: 403 (já instalada)
curl -s -o /dev/null -w '%{http_code}\n' https://gnesting.com.br/cron.php       # esperado: 404 (sem token)
curl -sI http://gnesting.com.br/produtos | grep -i -E '^(HTTP|location)'   # 301 → https://gnesting.com.br/produtos
curl -sI https://www.gnesting.com.br/ | grep -i -E '^(HTTP|location)'      # 301 → https://gnesting.com.br/
curl -sI https://gnesting.com.br/ | grep -i -E 'strict-transport|content-security|x-powered-by|^server'
```

Nenhum dos bloqueados pode responder 200. No último comando devem aparecer HSTS e CSP. `X-Powered-By` não deve aparecer
(`expose_php` desligado). Se `Server:` mostrar versões, peça ao suporte `ServerTokens Prod` (docs/16 §3).

## 13. Testes

`tests/Integration/OperationsTest.php`, `InstallerTest.php`, `StoreConfigYamlTest.php`, `tests/Unit/IpRangeTest.php` e
`InstallerUnitTest.php` (255 testes no total, todos no CI).

| Tema | Teste |
|---|---|
| http → https e www → host canônico (301/308); desligado fora de produção | `testProductionRedirectsToHttpsOnTheCanonicalHost`, `testNoRedirectOutsideProductionOrWhenDisabled` |
| `X-Forwarded-*` só de proxies confiáveis; CIDR IPv4/IPv6 | `testForwardedHeadersOnlyCountFromTrustedProxies`, `testForwardedProtoIsHonouredOnlyFromTrustedProxies`, `IpRangeTest` |
| Manutenção bloqueia tudo, menos a passagem e `/saude`; pelo painel, quem liga mantém o acesso | `testMaintenanceModeBlocksEverythingExceptTheBypassAndHealth`, `testOwnerTogglesMaintenanceFromThePanelAndKeepsAccess` |
| `/saude` sem token não mostra detalhes e não abre sessão | `testHealthEndpointHidesDetailsWithoutTokenAndOpensNoSession` |
| Backup → restauração num banco vazio com dados idênticos (aspas, `;`, emoji, quebras de linha) e arquivos espelhados | `testBackupRestoresIntoAnEmptyDatabaseWithIdenticalData` |
| Horário do backup diário e retenção diária/mensal | `testBackupScheduleAndRetention` |
| Download do backup pelo painel, registrado na auditoria (outros papéis: 403 pela varredura de papéis do docs/16) | `testOwnerDownloadsBackupFromThePanel` |
| Cron isola a tarefa que falha e alerta uma vez só; erro 500 gera alerta com limite de frequência | `testCronIsolatesFailingTasksAndAlertsOnce`, `testUnexpectedErrorsSendAThrottledAlert` |
| Limpeza de logs e sessões; lista de verificação de produção | `testHousekeepingRemovesOldLogsAndSessions`, `testProductionChecklist` |
| **Instalação completa** num banco vazio: `.env` de produção, migrations, proprietário, trava; recusa reinstalar (também sem a trava) | `InstallerTest::testProductionInstallWritesEnvCreatesSchemaAndOwnerAndLocksItself` |
| Instalação local com exemplos; credenciais erradas explicadas sem expor a senha | `testLocalInstallCanBringSampleDataAndUsesSimulatedPayment`, `testWrongCredentialsOrMissingDatabaseAreExplainedWithoutTouchingAnything` |
| Senhas com `$`, `\`, aspas e `#` gravadas no `.env` sem truncar; validação do formulário | `InstallerUnitTest` |
| Cron por URL só com o token; uma execução por vez | `testCronByUrlNeedsTheTokenAndRunsTheSameTasks`, `testOnlyOneCronRunsAtATime` |
| Atualizar banco pelo painel, com backup antes | `testOwnerAppliesPendingMigrationsFromThePanelAfterABackup` |
| Restaurar pelo painel só com "RESTAURAR"; manutenção ligada e cópia do estado anterior | `testOwnerRestoresABackupFromThePanelOnlyWithConfirmation` |
| Configuração em YAML (exportar, importar, nunca apagar, erros com o caminho) | `StoreConfigYamlTest` (docs/15 §7) |

## 14. Fora do escopo destas etapas

- **Deploy automático** (CI publicando no servidor). O deploy é manual (§11): o pacote é gerado no computador e enviado pelo Gerenciador de Arquivos.
- **Cópia automática para fora do servidor** (S3, Google Drive). Hoje é a tarefa semanal do §6.
- **Ambiente de homologação.** Recomendável antes de mudanças grandes: um subdomínio com outro banco e `PAYMENT_PROVIDER=simulado`.
- **Editar o `.env` pelo painel.** Segredos continuam no arquivo, editado pelo Gerenciador de Arquivos (§3.6).

## 15. Com Terminal/SSH (opcional)

Tudo acima tem equivalente em comando, para quem tiver Terminal:

| Tarefa | Comando |
|---|---|
| Instalar | `cp .env.example .env` + editar, `php bin/generate-key.php`, `php database/migrate.php`, `php bin/create-admin.php --email=… --name="…" --role=owner` |
| Lista de verificação | `php bin/check-production.php` (código de saída 1 se houver erro) |
| Atualizar banco | `php database/migrate.php` |
| Backup / listar | `php bin/backup.php` (`--sem-arquivos`, `--listar`) |
| Restaurar | `php bin/restore.php AAAA-MM-DD_HHMMSS --confirmar [--arquivos]` (ligue a manutenção antes) |
| Manutenção | `php bin/maintenance.php on ["mensagem"]` / `off` / `status` |
| Cron manual | `php bin/cron.php -v` |

**Banco com privilégio mínimo (recomendado quando há Terminal):**
- a loja usa um usuário só com `SELECT, INSERT, UPDATE, DELETE` (no `.env`);
- migrations e restauração rodam com um usuário administrador, informado só no comando, sem ficar gravado:

```bash
export DB_USERNAME=USUARIO_gnadmin
read -s -p "Senha do gnadmin: " DB_PASSWORD; export DB_PASSWORD; echo
php database/migrate.php            # ou: php bin/restore.php …
unset DB_USERNAME DB_PASSWORD
```

Com essa separação, os botões **Atualizar banco de dados** e **Restaurar** do painel falham por falta de privilégio (a
mensagem diz isso), e o Terminal passa a ser o caminho para essas duas tarefas.
