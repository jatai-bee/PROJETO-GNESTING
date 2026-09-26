# 17 — Deploy e operação (etapa 12)

Como publicar a loja numa hospedagem compartilhada (cPanel) e mantê-la no ar: pacote, instalação, HTTPS, backups,
restauração, manutenção, cron e monitoramento.

Configuração: [`config/operations.php`](../config/operations.php) e o bloco "Produção e operação" do `.env.example`.

## 1. O que roda onde

| Peça | Onde | Quando |
|---|---|---|
| Loja e painel | `public/index.php` (Apache + PHP 8.2+) | cada requisição |
| Cron | `bin/cron.php` | a cada 15 min (Cron Jobs do cPanel) |
| Backup | tarefa do cron, `bin/backup.php` ou botão no painel | 1× por dia, depois de `BACKUP_HOUR` |
| Monitor externo | `GET /saude` | a cada 5 min (UptimeRobot ou similar) |
| Alertas | e-mail para `ALERT_EMAIL` | erro 500, falha do cron ou do backup |
| Tela **Sistema** | `/admin/sistema` (só **proprietário**) | saúde, lista de verificação, backups, manutenção, último cron |

## 2. Pacote de produção

No computador de desenvolvimento, com tudo commitado e o CI verde:

```bash
composer check                 # o mesmo que o CI roda
php bin/build-release.php      # → build/gnesting-AAAAMMDD-HHMM-<commit>.zip
```

- O pacote sai do **último commit** (`git archive`). Alterações não commitadas ficam de fora, e o script avisa.
- `vendor/` vem só com as dependências de produção (`composer install --no-dev`, autoload *classmap-authoritative*).
- Ficam de fora (`.gitattributes` → `export-ignore`): `tests/`, `docs/`, `.github/`, configs de PHPUnit/PHPStan.
- Nunca vão no pacote: `.env`, logs, sessões, uploads, arquivos de produção, backups.
- O script **recusa** montar o pacote se encontrar `.env`, testes, docs, PHPUnit/PHPStan ou arquivo estranho em `public/`
  (tudo que está em `public/` fica público; só `index.php`, `.htaccess`, `assets/` e `uploads/` podem estar lá).
- O arquivo `RELEASE` (commit e data) aparece na tela Sistema: é assim que se sabe qual versão está no ar.
- Precisa da extensão `zip` do PHP e do Composer (no PATH ou em `COMPOSER_PHAR=/caminho/composer.phar`).

## 3. Primeira instalação (cPanel)

### 3.1 Domínio e pasta

Suba o projeto **fora** de `public_html`, por exemplo em `/home/USUARIO/gnesting`, e faça o domínio servir
`/home/USUARIO/gnesting/public`:

1. **Melhor opção:** em *Domínios*, defina a raiz do documento do domínio como `gnesting/public`.
2. Se a hospedagem não deixa mudar a raiz do domínio principal: renomeie `public_html` e crie um link simbólico
   (Terminal do cPanel): `ln -s /home/USUARIO/gnesting/public /home/USUARIO/public_html`.
3. Último caso: projeto inteiro dentro de `public_html`. O `.htaccess` da raiz encaminha tudo para `public/` e bloqueia
   o resto. Funciona e foi testado (§12), mas qualquer erro de configuração do Apache expõe o código.

### 3.2 PHP

*Select PHP Version* (ou *MultiPHP Manager*): **PHP 8.2 ou mais novo** (testado com 8.3), com as extensões
`pdo_mysql`, `mbstring`, `fileinfo`, `gd` (com WebP e JPEG), `openssl`, `curl`, `zlib`, `phar` e `json`.
Nas opções, desligue `expose_php` (esconde a versão do PHP).

### 3.3 Banco de dados

Em *MySQL Databases*, crie o banco (o cPanel prefixa com o usuário: `USUARIO_gnesting`) e **dois usuários**:

| Usuário | Privilégios | Usado por |
|---|---|---|
| `USUARIO_gnapp` | `SELECT, INSERT, UPDATE, DELETE` | a loja, o cron e os backups (fica no `.env`) |
| `USUARIO_gnadmin` | todos (`ALL PRIVILEGES`) | só migrations e restauração, informado na hora (§3.5) |

Assim, uma falha na aplicação não consegue apagar ou alterar tabelas (docs/05 §1). O backup só precisa de `SELECT`.

### 3.4 `.env`

Copie `.env.example` para `.env` **no servidor** (nunca no pacote) e ajuste:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gnesting.com.br
APP_KEY=                        # php bin/generate-key.php
DB_DATABASE=USUARIO_gnesting
DB_USERNAME=USUARIO_gnapp
DB_PASSWORD=...
SESSION_SECURE_COOKIE=true
MAIL_DRIVER=smtp                # + MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD (docs/13)
PAYMENT_PROVIDER=mercadopago    # + MERCADOPAGO_ACCESS_TOKEN e MERCADOPAGO_WEBHOOK_SECRET (docs/12)
HEALTH_TOKEN=...                # 20+ caracteres aleatórios
ALERT_EMAIL=voce@gnesting.com.br
```

Permissões: `.env` com `600`; `storage/` e `public/uploads/` graváveis pelo PHP (`755` nas pastas costuma bastar no cPanel,
onde o PHP roda com o seu usuário).

### 3.5 Tabelas e primeiro acesso

No Terminal do cPanel, dentro da pasta do projeto. As migrations usam o usuário administrador, informado só neste
comando (variáveis do ambiente têm prioridade sobre o `.env`):

```bash
export DB_USERNAME=USUARIO_gnadmin
read -s -p "Senha do gnadmin: " DB_PASSWORD; export DB_PASSWORD; echo
php database/migrate.php            # produção: sem --seed (o seed é de desenvolvimento)
unset DB_USERNAME DB_PASSWORD

php bin/create-admin.php --email=voce@gnesting.com.br --name="Seu Nome" --role=owner
php bin/check-production.php        # §4: precisa terminar em "Pronto para produção"
```

Depois: Cron (§9), monitor (§10), webhook do Mercado Pago apontando para
`https://gnesting.com.br/webhooks/pagamento/mercadopago` (docs/12) e a verificação do Apache (§12).

## 4. Lista de verificação de produção

`php bin/check-production.php` (e o quadro na tela Sistema). **Erro** impede publicar; **aviso** deve ser resolvido logo.
Código de saída 1 quando há erro.

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

Cada backup é uma pasta `storage/backups/AAAA-MM-DD_HHMMSS/` (horário UTC no nome):

| Arquivo | Conteúdo |
|---|---|
| `database.sql.gz` | banco completo, gerado só com PDO (não depende de `mysqldump` nem de `exec`) |
| `files.tar.gz` | `public/uploads` + `storage/private/production_files` (desligue com `BACKUP_INCLUDE_FILES=false`) |
| `manifest.json` | data, tamanhos, **SHA-256** de cada arquivo, linhas por tabela |

- O backup é montado em `….parcial` e só vira pasta final quando termina: um backup pela metade nunca é listado.
- **Retenção:** ficam os últimos `BACKUP_KEEP_DAILY` (7) e o primeiro de cada um dos últimos `BACKUP_KEEP_MONTHLY` (3)
  meses. Os demais, e sobras `.parcial` com mais de um dia, são apagados.
- **Manual:** `php bin/backup.php` (`--sem-arquivos`, `--listar`) ou **Sistema → Fazer backup agora**.
- **Contém dados pessoais.** Fica fora da web (`storage/`) e cada download pelo painel vai para a auditoria.

### Cópia fora do servidor (tarefa humana)

Um backup que só existe no mesmo servidor não protege contra perda da hospedagem, invasão ou erro do provedor.
**Toda semana**, em Sistema → Backups, baixe o `banco` (e o `arquivos` quando houver fotos ou arquivos CNC novos) e guarde
num lugar seu, de preferência criptografado. O backup do próprio cPanel é bem-vindo, mas não substitui essa cópia.

**Todo mês**, teste a restauração no Laragon (§7). Backup que nunca foi restaurado é só uma esperança.

## 7. Restauração

Substitui **todo** o banco pelo backup (e, com `--arquivos`, espelha uploads e arquivos de produção: o que não estava
no backup é removido). Antes de alterar qualquer coisa, os SHA-256 do manifesto são conferidos: arquivo corrompido
interrompe tudo sem mexer no banco.

```bash
php bin/maintenance.php on "Voltamos em alguns minutos."
php bin/backup.php                                # cópia do estado atual, por segurança
php bin/backup.php --listar

export DB_USERNAME=USUARIO_gnadmin                # DROP/CREATE: precisa do usuário administrador (§3.3)
read -s -p "Senha do gnadmin: " DB_PASSWORD; export DB_PASSWORD; echo
php bin/restore.php 2026-09-25_060000 --confirmar --arquivos
unset DB_USERNAME DB_PASSWORD

# confira a loja pelo link de passagem que o "maintenance on" mostrou
php bin/maintenance.php off
```

- **No Laragon (teste mensal ou cópia local):** copie a pasta do backup para `storage/backups/` e rode o mesmo
  `bin/restore.php` (lá o `root` já tem os privilégios).
- **Sem o PHP do projeto:** o `.sql.gz` é SQL comum:
  `gunzip < database.sql.gz | mysql -u USUARIO_gnadmin -p USUARIO_gnesting`, e o `files.tar.gz` abre com `tar -xzf`.
- Pedidos pagos **depois** do backup restaurado somem do banco, mas não do Mercado Pago. Confira o painel do Mercado Pago
  pelo período perdido.

## 8. Modo manutenção

Enquanto ligado, loja, painel e webhooks respondem **503 "Voltamos já"** com `Retry-After: 600`. O Mercado Pago reenvia as
notificações depois, então nenhum pagamento se perde. `/saude` continua respondendo, com status `atencao`: deploy
planejado não é queda para o monitor.

| Como | Ligar | Desligar |
|---|---|---|
| Terminal | `php bin/maintenance.php on ["mensagem"]` | `php bin/maintenance.php off` |
| Painel | Sistema → Manutenção → **Ligar manutenção** | **Desligar e voltar ao ar** |

- **Passagem:** o terminal mostra um link `https://…/?manutencao=SEGREDO`. Quem abre esse link recebe um cookie e navega
  normalmente. Pelo painel, quem ligou já fica com a passagem no próprio navegador.
- O segredo é gerado a cada `on`; no arquivo `storage/maintenance.json` e no cookie fica só o hash.
- Ligar e desligar pelo painel vai para a auditoria.

## 9. Cron

cPanel → *Cron Jobs*, a cada 15 minutos (confira o caminho do PHP com `which php` no Terminal; em servidores com
EasyApache costuma ser `/opt/cpanel/ea-php83/root/usr/bin/php`):

```
*/15 * * * * /usr/local/bin/php /home/USUARIO/gnesting/bin/cron.php
```

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

- **Silencioso quando tudo dá certo** (o cPanel manda por e-mail qualquer saída do cron). Falhas saem com código 1 e geram
  alerta. `php bin/cron.php -v` mostra todas as tarefas.
- O resultado fica em `storage/cache/cron.json` (tela Sistema → Último cron). Sem cron há mais de 45 min, `/saude` acusa.

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
| `migrations` | não | há migration pendente |
| `cron` | não | nunca rodou, está parado há 45+ min, ou alguma tarefa falhou |
| `backup` | não | nenhum backup, ou o último tem mais de 30 h |
| `disco` | não | menos de 500 MB livres (quando a hospedagem informa) |
| `manutencao` | não | modo manutenção ligado |

**Monitor externo** (UptimeRobot, Better Stack…): monitor do tipo *palavra-chave* em `https://gnesting.com.br/saude`,
a cada 5 min, alertando quando **não** contém `"status":"ok"`. Assim você fica sabendo também de cron parado e backup
atrasado. Um monitor simples de HTTP só pegaria as falhas críticas (503). Não use o token na URL do monitor: ele não
precisa dos detalhes.

### Alertas por e-mail

Com `ALERT_EMAIL` preenchido, chegam alertas de **erro 500** (com o código do erro para procurar no log) e de **falha no
cron**, inclusive do backup. O mesmo alerta sai no máximo 1× por hora, e no máximo 20 por dia: uma pane não vira uma
enxurrada de e-mails. Os alertas usam o mesmo `MAIL_DRIVER` da loja; se o SMTP cair, só o monitor externo avisa.

### Logs

`storage/logs/app-AAAA-MM-DD.log` (aplicação, um arquivo por dia, UTC), `php-errors.log` (erros do PHP) e
`mail-*.log` (só com `MAIL_DRIVER=log`). Retenção: `LOG_RETENTION_DAYS` (30).

## 11. Atualizar a loja (roteiro de deploy)

1. **Local:** `composer check` verde e commit feito; CI verde no GitHub.
2. `php bin/build-release.php` e envie o `.zip` para o servidor (Gerenciador de Arquivos ou SFTP).
3. `php bin/maintenance.php on "Voltamos em 10 minutos."` (guarde o link de passagem).
4. `php bin/backup.php`: é o ponto de retorno se algo der errado.
5. Extraia o `.zip` **por cima** da pasta do projeto. `.env`, `storage/` e `public/uploads/` não estão no pacote e não são
   tocados. Arquivos que deixaram de existir na versão nova podem ficar para trás sem efeito: o autoload só carrega as classes
   do pacote novo e `public/.htaccess` só executa `index.php`.
6. Se a versão trouxer migration: `php database/migrate.php` com o usuário administrador (§3.5).
7. `php bin/check-production.php` → "Pronto para produção".
8. Abra a loja pelo link de passagem: home, um produto, carrinho, painel. Confira a versão em Sistema.
9. `php bin/maintenance.php off`.

**Se der errado:** com a manutenção ainda ligada, extraia o pacote anterior (guarde sempre o último `.zip` que funcionou) e,
se houve migration, restaure o backup do passo 4 (§7).

## 12. Verificação do Apache depois do deploy

Repete, no servidor real, o teste feito na etapa 11 (docs/16 §3). No seu computador:

```bash
for p in / /produtos /assets/css/app.css /saude; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' https://gnesting.com.br$p)  $p"; done          # esperado: 200

for p in /.env /.git/config /app/Core/Kernel.php /config/app.php /storage/logs/ /vendor/autoload.php \
         /database/migrate.php /composer.json /composer.lock /uploads/x.php /x.php /RELEASE; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' https://gnesting.com.br$p)  $p"; done          # esperado: 403 ou 404

curl -sI http://gnesting.com.br/produtos | grep -i -E '^(HTTP|location)'   # 301 → https://gnesting.com.br/produtos
curl -sI https://www.gnesting.com.br/ | grep -i -E '^(HTTP|location)'      # 301 → https://gnesting.com.br/
curl -sI https://gnesting.com.br/ | grep -i -E 'strict-transport|content-security|x-powered-by|^server'
```

Nenhum dos bloqueados pode responder 200. No último comando devem aparecer HSTS e CSP. `X-Powered-By` não deve aparecer
(`expose_php` desligado). Se `Server:` mostrar versões, peça ao suporte `ServerTokens Prod` (docs/16 §3).

## 13. Testes

`tests/Integration/OperationsTest.php` e `tests/Unit/IpRangeTest.php` (230 testes no total, todos no CI):

| Tema | Teste |
|---|---|
| http → https e www → host canônico (301/308); desligado fora de produção | `testProductionRedirectsToHttpsOnTheCanonicalHost`, `testNoRedirectOutsideProductionOrWhenDisabled` |
| `X-Forwarded-*` só de proxies confiáveis; CIDR IPv4/IPv6 | `testForwardedHeadersOnlyCountFromTrustedProxies`, `testForwardedProtoIsHonouredOnlyFromTrustedProxies`, `IpRangeTest` |
| Manutenção bloqueia tudo, menos a passagem e `/saude`; pelo painel, quem liga mantém o acesso | `testMaintenanceModeBlocksEverythingExceptTheBypassAndHealth`, `testOwnerTogglesMaintenanceFromThePanelAndKeepsAccess` |
| `/saude` sem token não mostra detalhes e não abre sessão | `testHealthEndpointHidesDetailsWithoutTokenAndOpensNoSession` |
| Backup → restauração num banco vazio com dados idênticos (aspas, `;`, emoji, quebras de linha) e arquivos espelhados | `testBackupRestoresIntoAnEmptyDatabaseWithIdenticalData` |
| Horário do backup diário e retenção diária/mensal | `testBackupScheduleAndRetention` |
| Download do backup pelo painel, registrado na auditoria (outros papéis: 403 pela varredura de papéis do docs/16) | `testOwnerDownloadsBackupFromThePanel` |
| Cron isola a tarefa que falha e alerta uma vez só | `testCronIsolatesFailingTasksAndAlertsOnce` |
| Erro 500 gera alerta com limite de frequência | `testUnexpectedErrorsSendAThrottledAlert` |
| Limpeza de logs e sessões | `testHousekeepingRemovesOldLogsAndSessions` |
| Lista de verificação de produção | `testProductionChecklist` |

## 14. Fora do escopo desta etapa

- **Deploy automático** (CI publicando no servidor). O deploy é manual (§11); com SSH e Git na hospedagem, dá para automatizar depois.
- **Cópia automática para fora do servidor** (S3, Google Drive). Hoje é a tarefa semanal do §6.
- **Ambiente de homologação.** Recomendável antes de mudanças grandes: um subdomínio com outro banco e `PAYMENT_PROVIDER=simulado`.
- **Monitoramento de desempenho** (APM, tempo de resposta por rota).
