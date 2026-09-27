# 18 — Guia de instalação e uso

Guia passo a passo para colocar a loja G-Nesting no ar e usá-la no dia a dia. Os detalhes técnicos de cada assunto
estão nos documentos citados entre parênteses; para instalar e usar, este guia basta.

- **Parte 1 — Instalação:** do zero até a primeira venda, **só pelo navegador**: cPanel, Gerenciador de Arquivos e o
  assistente da loja. Não precisa de Terminal, programação nem GitHub.
- **Parte 2 — Uso:** cadastro, pedidos, produção, expedição e rotinas, com o que cada pessoa da equipe faz.

---

# Parte 1 — Instalação

## 1.0 Antes de começar

### O que você vai precisar

| Item | Para quê | Observação |
|---|---|---|
| **O pacote da loja** (`gnesting-….zip`) | é a loja inteira, pronta para usar | vem na pasta `PACOTE-CPANEL`, junto com este guia |
| **Domínio** (ex.: `gnesting.com.br`) | endereço da loja | registro.br ou outro |
| **Hospedagem com cPanel** | onde a loja roda | PHP **8.2 ou mais novo** e MySQL 5.7 ou mais novo (ou MariaDB 10.6+). Não precisa de Terminal/SSH |
| **Conta Mercado Pago** (vendedor) | receber pagamentos | Pix, cartão e boleto |
| **Serviço de monitoramento** (opcional) | ser avisado se a loja cair | UptimeRobot tem plano gratuito |

Reserve **1 a 2 horas**, sem contar a espera do certificado HTTPS (minutos) e da aprovação do Mercado Pago.

### Ficha de anotações

Durante a instalação você vai criar senhas e receber endereços que precisa guardar. Copie esta ficha para o seu
gerenciador de senhas e preencha conforme avança. **Nunca envie estes dados por e-mail ou WhatsApp.**

| O quê | Onde aparece | Anote aqui |
|---|---|---|
| Nome do banco (com prefixo) | passo 1.1 | |
| Usuário do banco (com prefixo) | passo 1.1 | |
| Senha do banco | passo 1.1 | |
| E-mail da loja e senha | passo 1.2 | |
| Servidor SMTP e porta | passo 1.2 | |
| Seu e-mail e senha do painel | passo 1.7 | |
| Comando do Cron Jobs | tela final do passo 1.7 | |
| Endereço do cron (secreto) | tela final do passo 1.7 | |
| Access Token e assinatura do Mercado Pago | passo 1.12 | |

### Como a loja fica organizada na hospedagem

O pacote é extraído **direto na pasta `public_html`**, a pasta que o domínio mostra na internet. A instalação acontece
em dois momentos:

1. **Logo depois de extrair**, a loja já funciona. O arquivo `.htaccess` que vem no pacote encaminha os visitantes para
   a subpasta `public` e **bloqueia** as demais (código, senhas, backups).
2. **Depois de instalada** (passo 1.9), você aponta o domínio direto para `public_html/public`. É uma camada extra de
   segurança: aí nem o `.htaccess` precisa trabalhar. Os endereços da loja não mudam.

```
/home/USUARIO/                  ← sua pasta pessoal no cPanel
└── public_html/                ← o .zip é extraído aqui
    ├── .htaccess                    encaminha para public/ e bloqueia o resto
    ├── app/  config/  vendor/  …    código (bloqueado na internet)
    ├── storage/                     backups, logs (bloqueado)
    ├── .env                         senhas (criado pelo assistente; bloqueado)
    └── public/                 ← no passo 1.9, o domínio passa a apontar para cá
        ├── index.php                a loja
        └── instalar.php             o assistente de instalação
```

### Palavras que aparecem no guia

| Termo | O que é |
|---|---|
| **cPanel** | o painel da hospedagem, onde se criam banco, e-mails e domínios |
| **Gerenciador de Arquivos** | a tela do cPanel para enviar, extrair e editar arquivos (*File Manager*) |
| **Raiz do documento** | a pasta que o domínio mostra na internet (*Document Root*) |
| **`.env`** | arquivo com as senhas e configurações da loja. O assistente cria; você só edita para completar dados |
| **Cron** | tarefa automática que a hospedagem roda sozinha de tempos em tempos |
| **SSL / HTTPS** | o cadeado do navegador: a conexão com a loja é criptografada |
| **Painel** | a área administrativa da loja, em `https://SEU-DOMINIO/admin` |

> Os nomes dos menus variam um pouco entre hospedagens e idiomas do cPanel. Quando o nome em inglês for diferente, ele
> aparece em itálico ao lado (ex.: *File Manager*).

## 1.1 Criar o banco de dados

**Para quê:** a loja guarda produtos, pedidos e clientes num banco de dados MySQL.

**Onde:** cPanel → **Bancos de dados MySQL** (*MySQL Databases*).

**Faça:**
1. Em **Criar novo banco de dados**, digite `gnesting` → *Criar banco de dados*. O cPanel acrescenta um prefixo:
   o nome completo fica algo como `USUARIO_gnesting`. **Anote o nome completo.**
2. Em **Adicionar novo usuário**, digite `gnloja`, clique em **Gerador de senha**, copie a senha → *Criar usuário*.
   **Anote o usuário completo** (`USUARIO_gnloja`) **e a senha.**
3. Em **Adicionar usuário ao banco de dados**, escolha o usuário e o banco → *Adicionar* → marque
   **TODOS OS PRIVILÉGIOS** → *Fazer alterações*.

✅ **Deu certo se** a lista *Bancos de dados atuais* mostra o banco com o usuário embaixo, em "Usuários privilegiados".

⚠️ **Se esquecer o passo 3**, o assistente vai dizer "O usuário não tem permissão neste banco". Volte aqui e adicione.

## 1.2 Criar o e-mail da loja

**Para quê:** é o remetente dos e-mails de pedido ("Pedido recebido", "Pagamento aprovado"…).

**Onde:** cPanel → **Contas de e-mail** (*Email Accounts*).

**Faça:**
1. *Criar* → endereço `loja@gnesting.com.br` → senha forte → *Criar*. **Anote a senha.**
2. Na conta criada, **Conectar dispositivos** (*Connect Devices*): anote o **servidor de saída (SMTP)** e a **porta**
   (normalmente `mail.gnesting.com.br`, porta 465 ou 587).
3. cPanel → **Capacidade de entrega de e-mail** (*Email Deliverability*): se o domínio mostrar problemas em **SPF** ou
   **DKIM**, clique em *Reparar* (*Repair*).

✅ **Deu certo se** SPF e DKIM aparecem como válidos. Sem isso, os e-mails da loja costumam cair no spam.

## 1.3 Escolher a versão do PHP

**Para quê:** a loja é feita em PHP 8.2 ou mais novo, e usa algumas extensões.

**Onde:** cPanel → **Selecionar versão do PHP** (*Select PHP Version*) ou **MultiPHP Manager**.

**Faça:**
1. Escolha **8.3** (ou 8.2 ou mais novo) para o domínio.
2. Na aba *Extensions*, marque: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `openssl`, `curl`, `zlib`, `phar`, `json`.
3. Na aba *Options*, desmarque `expose_php` (esconde a versão do PHP dos curiosos).

✅ Não precisa acertar tudo agora: o assistente (passo 1.7) confere cada item e diz o que falta.

## 1.4 Enviar e extrair o pacote no `public_html`

**Para quê:** colocar os arquivos da loja na hospedagem.

**Onde:** cPanel → **Gerenciador de Arquivos** (*File Manager*).

**Faça:**
1. Abra a pasta **`public_html`** (duplo clique).
2. **Limpe os arquivos padrão da hospedagem**, se houver: selecione `index.html`, `index.php`, `default.php`, `default.htm`
   e parecidos → *Delete*. **Não apague** as pastas `cgi-bin` e `.well-known`, se existirem.
3. Clique em **Carregar** (*Upload*) → escolha o arquivo `gnesting-….zip` da pasta `PACOTE-CPANEL` → espere a barra
   chegar a 100% → volte ao Gerenciador.
4. Clique com o botão direito no `.zip` → **Extract** → confirme o destino `/home/USUARIO/public_html` →
   *Extract Files*. Se perguntar sobre substituir arquivos, confirme.
5. Apague o `.zip` (botão direito → *Delete*): ele não é mais necessário no servidor.
6. **Permissões:** selecione as pastas `storage` e `public`, clique em **Permissions** e confira `755`. A própria pasta
   `public_html` também precisa permitir gravação (o assistente grava o arquivo `.env` nela): volte um nível e confira.

✅ **Deu certo se** dentro de `public_html` aparecem as pastas `app`, `bin`, `config`, `database`, `public`, `routes`,
`storage`, `vendor` e os arquivos `.htaccess`, `.env.example`, `LEIA-ME-INSTALACAO.txt` e `RELEASE`
(os que começam com ponto só aparecem com "Mostrar arquivos ocultos", passo 1.10).

⚠️ **Se as pastas apareceram dentro de outra pasta** (ex.: `public_html/gnesting-2026…/app`), mova o conteúdo um nível
acima: entre nela, selecione tudo → *Move* → `/public_html`.

## 1.5 Ativar o HTTPS (cadeado)

**Para quê:** a loja só instala e funciona em produção com `https://`.

**Onde:** cPanel → **SSL/TLS Status**.

**Faça:** marque o domínio (e o `www`) → **Run AutoSSL**. Espere de 2 a 15 minutos.

✅ **Deu certo se** `https://gnesting.com.br` abre com o cadeado no navegador. A página que aparece é a do assistente de
instalação (a loja leva até ele enquanto não está instalada).

⚠️ **Se o AutoSSL falhar**, o domínio pode ainda não estar apontado para esta hospedagem (DNS). Confira com quem registrou
o domínio ou com o suporte.

## 1.6 Conferir a proteção

**Para quê:** com a loja dentro do `public_html`, quem protege o código e as senhas é o arquivo `.htaccess` do pacote.
Esta conferência leva um minuto e garante que ele está funcionando nesta hospedagem.

**Onde:** no navegador, abra cada endereço abaixo (troque pelo seu domínio).

| Endereço | Deve aparecer |
|---|---|
| `https://gnesting.com.br/.env.example` | "Forbidden", "Acesso negado" (403) ou "Página não encontrada" |
| `https://gnesting.com.br/app/` | "Forbidden" (403) |
| `https://gnesting.com.br/storage/` | "Forbidden" (403) |
| `https://gnesting.com.br/vendor/autoload.php` | "Forbidden" (403) |
| `https://gnesting.com.br/composer.json` | "Forbidden" (403) |

✅ **Deu certo se** nenhum desses endereços mostra conteúdo de arquivo (texto com `APP_KEY=`, código PHP, lista de pastas).

⚠️ **Se algum mostrar conteúdo**, o módulo de reescrita do Apache (`mod_rewrite`) está desligado nesta hospedagem.
**Não siga adiante**: peça ao suporte para ativar o `mod_rewrite` e o uso de `.htaccess`, ou pule para o passo 1.9 e
aponte o domínio para `public_html/public` antes de instalar.

## 1.7 Instalar pelo assistente

**Para quê:** o assistente cria a configuração, as tabelas do banco e o seu acesso ao painel, sozinho.

**Onde:** abra **`https://gnesting.com.br/instalar.php`** no navegador.

A página tem duas partes.

**Parte de cima — 1. Conferência da hospedagem.** Uma lista com ✓ (certo) e ✕ (falta). Qualquer ✕ bloqueia a instalação: a linha
diz o que corrigir (versão do PHP e extensões → passo 1.3; permissão de gravação → passo 1.4). Corrija no cPanel e
recarregue a página (F5).

**Parte de baixo — 2. Dados da loja.** Preencha com a ficha de anotações ao lado:

| Campo | O que colocar |
|---|---|
| Endereço da loja | já vem preenchido: `https://gnesting.com.br`. Confira se começa com `https://` |
| UF de onde os pedidos saem | a UF da oficina (usada no cálculo do frete) |
| Servidor / Porta | `localhost` / `3306` (padrão do cPanel) |
| Nome do banco / Usuário / Senha | os três do passo 1.1, **com o prefixo** (`USUARIO_…`) |
| Seu nome / e-mail | você vira o **proprietário** da loja; o e-mail também recebe os alertas de erro |
| Senha / Repita a senha | 12 caracteres ou mais. É a senha do painel: guarde bem |
| E-mail da loja (opcional) | servidor SMTP, porta, `loja@gnesting.com.br` e a senha do passo 1.2 |
| Mercado Pago (opcional) | pode deixar em branco agora e preencher no passo 1.12 |

Clique em **Instalar a loja** e espere (menos de um minuto; não feche a página).

✅ **Deu certo se** aparece **"Pronto! A loja foi instalada"**, com a lista do que foi feito (arquivo `.env` gravado,
tabelas criadas, proprietário criado, instalação travada). Na mesma tela, **anote na ficha**:
- o **comando do Cron Jobs** (algo como `php /home/USUARIO/public_html/bin/cron.php`);
- o **endereço do cron** (`https://gnesting.com.br/cron.php?token=…`). Ele é secreto, como uma senha.

A partir daqui, o assistente **se desliga**: quem abrir `instalar.php` vê "A loja já está instalada".

⚠️ **Se aparecer erro no banco:**
- "Usuário ou senha do banco incorretos": confira a senha e o prefixo no passo 1.1;
- "O banco informado não existe": confira o nome completo, com prefixo;
- "O usuário não tem permissão": falta o passo 1.1 item 3;
- "O banco é MySQL 5.6…" (ou mais antigo): a loja precisa de MySQL 5.7 ou mais novo. Peça à hospedagem a atualização.

Corrija e clique de novo em *Instalar*: o que já tinha sido feito é aproveitado.

> Faça a instalação logo depois de enviar os arquivos. Enquanto ela não termina, o assistente fica aberto para qualquer
> pessoa que encontre o endereço.

## 1.8 Primeiro acesso ao painel

**Para quê:** conferir que está tudo certo e fazer o primeiro backup.

**Onde:** `https://gnesting.com.br/admin`.

**Faça:**
1. Entre com o e-mail e a senha que você cadastrou no assistente.
2. Menu **Sistema**. Veja os quadros:
   - **Saúde**: tudo deve estar "ok", menos *Cron* (ainda não configurado, passo 1.11) e *Backup* (ainda não existe);
   - **Lista de verificação de produção**: mostra o que falta para vender. É normal aparecerem "E-mail de verdade" e
     "Credenciais do Mercado Pago" se você deixou esses campos em branco.
   - Se a hospedagem usa **MySQL 5.7**, aparece o aviso "MySQL 8 ou MariaDB". A loja funciona normalmente; é só uma
     recomendação para pedir à hospedagem a versão 8 quando possível (a 5.7 não recebe mais atualizações de segurança).
3. Clique em **Fazer backup agora**.

✅ **Deu certo se** o backup aparece na lista *Backups* com data, tamanho e os links *banco* / *arquivos*.

## 1.9 Apontar o domínio para `public_html/public` (recomendado)

**Para quê:** reforço de segurança. Com o domínio apontando direto para a subpasta `public`, o código e as senhas ficam
fora do alcance da internet mesmo que o `.htaccess` deixe de funcionar um dia. Os endereços da loja não mudam.

**Onde:** cPanel → **Domínios** (*Domains*).

**Faça:**
1. Na linha do domínio, clique em **Gerenciar** (*Manage*).
2. Em **Raiz do documento** (*Document Root*), troque `public_html` por `public_html/public` → *Atualizar* (*Update*).
3. Abra a loja e o painel de novo para conferir.

✅ **Deu certo se** a coluna *Raiz do documento* mostra `/home/USUARIO/public_html/public`, e a loja e o painel abrem
normalmente.

⚠️ **Se a loja parar de abrir** logo depois, volte a raiz para `public_html` (a loja volta na hora) e confira se digitou
`public_html/public` sem espaços.

⚠️ **Se o campo estiver bloqueado** (muitas hospedagens não deixam mudar o domínio principal), a loja continua
funcionando e protegida pelo `.htaccess` (passo 1.6). Se quiser o reforço, abra um chamado no suporte com este texto:

> Olá! Preciso que a raiz do documento (Document Root) do domínio gnesting.com.br aponte para a pasta
> /home/USUARIO/public_html/public. Vocês podem fazer essa alteração? Obrigado.

## 1.10 Completar a configuração (arquivo `.env`)

**Para quê:** preencher o que ficou em branco no assistente (e-mail, Mercado Pago) e ajustes finos.

**Onde:** cPanel → **Gerenciador de Arquivos**.

**Faça:**
1. Clique em **Configurações** (*Settings*, canto superior direito) → marque **Mostrar arquivos ocultos**
   (*Show Hidden Files*) → *Salvar*. O `.env` começa com ponto e fica escondido sem isso.
2. Entre na pasta `public_html` → botão direito no arquivo `.env` → **Edit** → *Edit* de novo na janela que abre.
3. Encontre a linha e troque **só o que vem depois do `=`**. Exemplo, para o e-mail da loja:

   ```
   antes:   MAIL_DRIVER=mail
   depois:  MAIL_DRIVER=smtp
   ```

4. Clique em **Save Changes** (canto superior direito) e feche o editor.
5. Volte ao painel → **Sistema** e recarregue: os itens corrigidos ficam verdes.

| Para ativar | Linhas do `.env` |
|---|---|
| E-mail pelo SMTP | `MAIL_DRIVER=smtp` · `MAIL_HOST=mail.gnesting.com.br` · `MAIL_PORT=587` (ou 465) · `MAIL_ENCRYPTION=tls` (ou `ssl` com 465) · `MAIL_USERNAME=loja@gnesting.com.br` · `MAIL_PASSWORD=…` |
| Mercado Pago | `MERCADOPAGO_ACCESS_TOKEN=…` · `MERCADOPAGO_WEBHOOK_SECRET=…` (passo 1.12) |
| Retirada no ateliê | `SHIPPING_PICKUP=true` |
| Capacidade da oficina | `PRODUCTION_DAILY_MINUTES=420` (1 pessoa × 7 h; 2 pessoas = 840) |
| Prazo para pagar | `PAYMENT_EXPIRY_HOURS=48` (depois disso o pedido é cancelado sozinho) |

⚠️ **Se uma senha tiver espaço, `#`, `$` ou aspas**, escreva o valor entre aspas duplas: `MAIL_PASSWORD="minha senha#1"`.
Não apague nenhuma outra linha, e **nunca envie o `.env` a ninguém**: ele guarda as senhas da loja.

## 1.11 Ligar as tarefas automáticas

**Para quê:** a cada 15 minutos a loja cancela pedidos não pagos no prazo, limpa dados vencidos e, uma vez por dia, faz
**o backup**. Sem isso, nada disso acontece.

**Onde:** cPanel → **Cron Jobs** (*Tarefas Cron*).

**Faça:**
1. Em **Configuração comum** (*Common Settings*), escolha **A cada 15 minutos** (*Once Per Fifteen Minutes*).
2. Em **Comando** (*Command*), cole o comando do Cron Jobs da sua ficha (tela final do assistente; também aparece no
   painel, em Sistema → Último cron).
3. *Adicionar novo cron job* (*Add New Cron Job*).

✅ **Deu certo se**, em até 15 minutos, o painel → **Sistema** → **Último cron** mostra a data e as tarefas com "ok".

⚠️ **Se a sua hospedagem não tem Cron Jobs:** crie uma conta gratuita em **cron-job.org** → *Create cronjob* → em *URL*,
cole o **endereço do cron** da sua ficha → *Every 15 minutes* → *Create*. Funciona igual. Pode até usar os dois juntos:
a loja nunca roda a tarefa duas vezes ao mesmo tempo.

⚠️ **Se "Último cron" continuar vazio** com o Cron Jobs configurado, a hospedagem pode usar outro caminho para o PHP.
Pergunte ao suporte "qual o caminho do PHP 8.3 para usar no Cron Jobs" e troque o `php` do comando por ele
(ex.: `/opt/cpanel/ea-php83/root/usr/bin/php`).

## 1.12 Conectar o Mercado Pago

**Para quê:** receber Pix, cartão e boleto.

**Onde:** https://www.mercadopago.com.br/developers → **Suas integrações**.

**Faça:**
1. *Criar aplicação* → produto **Checkout Pro**.
2. **Credenciais de teste** → copie o *Access Token* → cole em `MERCADOPAGO_ACCESS_TOKEN` no `.env` (passo 1.10).
3. **Webhooks** → *Configurar notificações* → URL `https://gnesting.com.br/webhooks/pagamento/mercadopago` → evento
   **Pagamentos** → *Salvar* → copie a **assinatura secreta** → cole em `MERCADOPAGO_WEBHOOK_SECRET`.
4. Faça a compra de teste do passo 1.15 com essas credenciais de **teste**.
5. Depois dela, troque o Access Token pelo das **credenciais de produção** e confira no Mercado Pago se o modo de produção
   também tem a URL do webhook.

✅ **Deu certo se** a Lista de verificação (Sistema) mostra "Credenciais do Mercado Pago" em verde.

Detalhes: [12 — Checkout](12-checkout.md) §4.

## 1.13 Ajustar a tabela de frete

**Para quê:** a tabela de frete vem com **valores de exemplo**. Ajuste aos preços e prazos reais antes de vender.

**Onde:** painel → **Configurações** (fim da página).

**Faça:**
1. **Exportar configuração (.yaml)**. O arquivo baixa para o seu computador.
2. Abra no **Bloco de Notas** (botão direito → Abrir com → Bloco de Notas).
3. Na seção `frete` → `tabela`, cada faixa tem os serviços `economico` e `expresso`. As faixas são **local** (mesma UF da
   oficina) e as regiões de destino **N, NE, CO, SE, S**:

   ```yaml
       SE:
         economico: { ate_1kg: 29.9, por_kg_adicional: 6.0, prazo_dias: 8 }
   ```

   - `ate_1kg`: preço até 1 kg, em reais, com **ponto** (`29.9` = R$ 29,90);
   - `por_kg_adicional`: quanto soma por kg acima de 1 kg;
   - `prazo_dias`: dias úteis de transporte (o prazo de produção é somado à parte);
   - `frete_gratis_acima`: um valor (ex.: `300`) ou `null` para não ter frete grátis.
4. Salve o arquivo (Ctrl+S) sem mudar o nome da extensão (`.yaml`).
5. De volta às Configurações: **Importar configuração** → escolha o arquivo → *Importar*.

✅ **Deu certo se** aparece "Configuração importada" no topo, e o carrinho da loja calcula o frete com os valores novos.

⚠️ **Se aparecer "Nada foi importado"**, a mensagem diz onde está o erro (ex.: `frete.tabela.SE.economico.ate_1kg`).
Corrija essa linha e importe de novo. Nada muda na loja enquanto o arquivo tiver erro.

Detalhes: [15 — Marketing](15-marketing.md) §7.

## 1.14 Monitoramento (recomendado)

**Para quê:** ser avisado por e-mail se a loja cair, se as tarefas automáticas pararem ou se o backup atrasar.

**Onde:** https://uptimerobot.com (conta gratuita).

**Faça:** *Add New Monitor* → tipo **Keyword** → URL `https://gnesting.com.br/saude` → palavra-chave `"status":"ok"` →
alertar quando **não existir** (*Keyword not exists*) → intervalo **5 minutos** → *Create*.

✅ **Deu certo se** o monitor aparece verde (*Up*). Os alertas de erro da própria loja já chegam no e-mail do proprietário.

## 1.15 Compra de teste

**Para quê:** conferir o caminho inteiro (produto → carrinho → pagamento → e-mails → produção) antes de abrir.

**Faça** (com as credenciais **de teste** do Mercado Pago):
1. No painel, cadastre **uma categoria** e **um produto** com foto (passos 2.3 e 2.4) e clique em **Ativar produto**.
2. Na loja, coloque o produto no carrinho e finalize a compra sem criar conta, com o seu e-mail.
3. No Mercado Pago, pague com um
   [cartão de teste](https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/additional-content/your-integrations/test/cards).
4. Cancele o pedido de teste: painel → Pedidos → o pedido → **Cancelar** → "Estornar agora pelo provedor de pagamento"
   (assim você testa também o estorno).
5. Troque o Access Token para o de **produção** (passo 1.12).

✅ **Deu certo se:**
- você voltou para a página do pedido com **"Pagamento aprovado"**;
- chegaram os e-mails "Pedido recebido" e "Pagamento aprovado";
- o pedido apareceu em **Pedidos** e o item em **Produção**;
- o cancelamento mostrou o estorno.

## 1.16 Checklist final: loja pronta para vender

- ☐ `https://gnesting.com.br` abre com cadeado, e `http://` e `www` levam para ele.
- ☐ `https://gnesting.com.br/instalar.php` mostra "A loja já está instalada".
- ☐ `/.env.example`, `/app/`, `/storage/` e `/vendor/autoload.php` respondem bloqueado (passo 1.6).
- ☐ Raiz do documento em `public_html/public`, se a hospedagem permitir (passo 1.9).
- ☐ Painel → **Sistema** → Lista de verificação toda verde.
- ☐ **Último cron** atualizado há menos de 15 minutos, e pelo menos um backup na lista.
- ☐ E-mails da loja chegando (e fora do spam).
- ☐ Mercado Pago com credenciais **de produção** e webhook configurado.
- ☐ Tabela de frete com os valores reais (passo 1.13).
- ☐ Configurações: WhatsApp, e-mail de contato, faixa de avisos (passo 2.1).
- ☐ Ficha de anotações guardada num lugar seguro, e o `.zip` do pacote guardado (para voltar, se precisar).

**A loja está aberta.** Agora cadastre o catálogo (Parte 2).

---

# Parte 2 — Uso do sistema

## 2.0 Quem faz o quê

O painel fica em `https://gnesting.com.br/admin`. Cada pessoa tem o seu login, com um **papel**. O menu só mostra o que
aquele papel pode usar.

| Papel | Faz |
|---|---|
| **Proprietário** | tudo, e ainda: usuários, configurações da loja (inclusive o arquivo YAML), auditoria, sistema (atualizar banco, backups, restaurar, manutenção), LGPD |
| **Gestor** | produtos, categorias, variações, personalização, cupons, pedidos (inclusive cancelar e estornar), clientes, produção, expedição |
| **Produção** | fila de produção, fichas de produção, materiais, expedição (despachar e marcar entregue), notas internas nos pedidos |
| **Atendimento** | consultar pedidos e clientes (CPF mascarado), mensagens aos clientes, notas internas, reenviar link do pedido |

## 2.1 Primeiro dia (proprietário)

1. **Entre** em `/admin` com o e-mail e a senha cadastrados no assistente (passo 1.7).
2. **Sistema**: veja se está tudo verde (Saúde e Lista de verificação). Clique em **Fazer backup agora**.
3. **Configurações**: WhatsApp (número com DDD, mensagem inicial, botão flutuante), e-mail de contato do rodapé, faixa de
   avisos no topo da loja (ex.: "Frete grátis acima de R$ 300"). No fim da página, **Exportar configuração (.yaml)**
   guarda tudo isso, mais frete, categorias e materiais, num arquivo (passo 2.19).
4. **Usuários → Novo usuário**: crie um acesso para cada pessoa da equipe, com o papel certo e uma senha inicial de 12+
   caracteres. Envie a senha por um canal seguro e peça para a pessoa trocá-la em "Esqueci minha senha".
   Nunca compartilhe o seu login de proprietário.

## 2.2 Cadastro: a ordem que funciona

```
Categorias → Materiais → Produto (dados + fotos) → Variações → Personalização → Ficha de produção → Ativar
```

Os materiais vêm antes porque a ficha de produção os usa. Um produto só vai para a loja quando é **ativado**.

## 2.3 Categorias (gestor)

**Categorias → Nova categoria**. Até dois níveis (ex.: *Relógios* → *Relógios de parede*). Uma categoria inativa esconde os
produtos dela. Para excluir uma categoria, mova antes os produtos e subcategorias.

## 2.4 Produto (gestor)

1. **Produtos → Novo produto**: nome, categoria, **SKU** (código único, permanente), preço (ex.: `129,90`), preço "de"
   (opcional, para promoção), prazo de produção em dias úteis, material, medidas, peso e medidas da embalagem (usados no
   frete), e o tipo de estoque:
   - **Produzido sob pedido** (padrão): vende sem limite de quantidade;
   - **Pronta entrega**: controla a quantidade em estoque.
2. **Salvar** → o produto nasce **inativo** e abre a aba **Imagens**.
3. **Fotos**: JPG, PNG ou WebP, até 5 MB, lado menor com pelo menos 500 px (ideal 1600 px+). Até 12 fotos. A primeira é
   a capa. Preencha a **descrição** de cada foto (ajuda na acessibilidade e no Google).
4. **Ativar produto**: exige foto, preço e categoria ativa.

- **Desativar** tira da loja temporariamente. **Excluir** é definitivo para a loja, mas o histórico dos pedidos fica.
- Mudar o **endereço (slug)** quebra links já divulgados.

Detalhes: [08 — Painel administrativo](08-painel-administrativo.md).

## 2.5 Variações (gestor)

Para vender o mesmo produto em acabamentos ou tamanhos diferentes. Aba **Variações** do produto:

1. Crie a **opção** e os valores separados por vírgula: *Acabamento* → `Natural, Preto`.
2. Repita para outra opção, se houver: *Tamanho* → `30 cm, 45 cm` (até 3 opções).
3. **Gerar variações**: o sistema cria todas as combinações (Natural/30 cm, Natural/45 cm, Preto/30 cm…), cada uma com SKU
   próprio e o preço da variação padrão.
4. **Editar** cada variação para ajustar preço, medidas, peso e estoque. Desative as combinações que você não vende.

Na loja, o cliente escolhe numa lista e o preço muda na hora.
Detalhes: [10 — Variações e personalização](10-variacoes-e-personalizacao.md) §1.

## 2.6 Personalização (gestor)

Para gravar um nome, uma data ou iniciais. Aba **Personalização** → **Novo campo**:

| Tipo | Exemplo | Você define |
|---|---|---|
| Texto curto | "Nome para gravação" | mínimo e máximo de caracteres, caracteres aceitos |
| Inicial | "Iniciais" | até 3 letras |
| Data | "Data do casamento" | — |
| Opção pré-definida | "Fonte" | lista de opções, cada uma com acréscimo |

- **Acréscimo**: valor somado ao preço quando o cliente preenche o campo.
- **Obrigatório**: sem preencher, o produto não entra no carrinho.
- O cliente só escolhe o que você cadastrou; não existe campo livre "descreva o que quer".

Detalhes: [10 — Variações e personalização](10-variacoes-e-personalizacao.md) §2.

## 2.7 Materiais e ficha de produção (gestor ou produção)

A ficha diz **como fabricar** cada variação. Nada dela aparece na loja.

1. **Materiais → Novo material**: código, nome, espessura, unidade (chapa, m², unidade), medidas da chapa, custo, saldo e
   estoque mínimo (a lista avisa **Repor** quando o saldo chega ao mínimo).
2. No produto, aba **Ficha de produção** (ou menu **Fichas de produção**), escolha a variação e preencha:
   - material, medidas de corte, **peças por chapa**, referência do programa CNC;
   - **etapas** com minutos por peça: CNC, lixamento, pintura/acabamento, secagem (marque *passiva*: não ocupa ninguém),
     montagem;
   - instruções de acabamento e observações;
   - **arquivos**: programa CNC (`.nc`, `.tap`, `.gcode`), projeto (`.dxf`, `.svg`, `.crv`), desenho (`.pdf`). Enviar um
     arquivo com o mesmo nome cria a versão seguinte (v2, v3…).
3. **Copiar de outra variação** poupa trabalho quando só muda o tamanho ou o acabamento.

A lista **Fichas de produção** mostra o que está *completo*, *incompleto* (e o que falta) ou *sem ficha*. Sem ficha, o
pedido ainda é produzido, mas a fila avisa e a previsão de carga fica incompleta.
Detalhes: [11 — Ficha de produção](11-ficha-de-producao.md).

## 2.8 Cupons (gestor)

**Cupons → Novo cupom**: código (ex.: `BEMVINDO10`), tipo (**percentual**, **valor fixo** ou **frete grátis**) e regras
opcionais: compra mínima, início e fim, limite total de usos, usos por cliente, desconto máximo.

- Para encerrar um cupom, **desative-o**. Cupom já usado não pode ser excluído.
- Cancelar um pedido devolve o uso do cupom.

Detalhes: [15 — Marketing](15-marketing.md) §1.

## 2.9 O caminho de um pedido

```
Aguardando pagamento → Pagamento aprovado → Produção pendente → Em produção → Acabamento
  → Controle de qualidade → Embalagem → Pronto para envio → Enviado → Entregue
```

- **Pagamento aprovado** é sempre automático (o Mercado Pago confirma). Ninguém marca pagamento à mão.
- Pedido não pago em 48 h (ou `PAYMENT_EXPIRY_HOURS`) é **cancelado sozinho** e o estoque volta.
- O cliente recebe e-mail em cada marco: pedido recebido, pagamento aprovado, em produção, enviado, entregue, cancelado.
- As etapas de produção andam pela **fila de Produção** (2.10), não pela página do pedido.

Detalhes: [03 — Status](03-status-e-fluxo-de-producao.md) e [13 — Pedidos](13-pedidos.md).

## 2.10 Produção: a fila da oficina (produção ou gestor)

Menu **Produção**. Funciona no celular, com botões grandes para usar na oficina.

1. Cada **item pago** vira um cartão, na aba da etapa em que está.
2. Cores: borda **vermelha** = atrasado; **amarela** = em risco de atrasar.
3. Abra o cartão para ver a **personalização em letras grandes** ("confira antes de gravar"), a ficha, as instruções e os
   arquivos do CNC.
4. **Assumir** marca que você está cuidando daquele item ("Só os meus" filtra).
5. Um botão por etapa: **Iniciar CNC** → **Concluir → Lixamento** → … → **Aprovar → Embalagem** → **Pronto**.
6. No **controle de qualidade**, **Reprovar…** devolve o item para uma etapa anterior, com o motivo (conta como retrabalho).
7. Ao sair do CNC, o material da ficha é **descontado do estoque de chapas** automaticamente.
8. Quando todos os itens de um pedido ficam prontos, o pedido vai para **Pronto para envio**.

**Chegou chapa?** Materiais → material → **Movimentar saldo** → Entrada, com o motivo (ex.: "NF 1234").

Detalhes: [14 — Produção](14-producao.md).

## 2.11 Expedição (produção ou gestor)

Menu **Expedição**:

1. Pedidos **prontos** aparecem com endereço, itens e peso estimado.
2. **Romaneio**: imprima e coloque na caixa (destinatário em destaque, itens com personalização e caixa de conferência).
3. **Despachar**: transportadora, código e link de rastreio. O cliente recebe por e-mail.
4. Quando chegar: **Marcar entregue**.

## 2.12 Atendimento (atendimento, gestor)

- **Pedidos**: busque por número (`GN-2026-000123`), nome, e-mail, CPF ou telefone. A coluna *Prazo de produção* fica
  vermelha quando atrasada.
- No pedido:
  - **Mensagem ao cliente**: vai por e-mail **e** aparece na página do pedido do cliente;
  - **Nota interna**: só a equipe vê;
  - **Reenviar link**: o cliente perdeu o e-mail do pedido;
  - **WhatsApp**: abre a conversa com o número do pedido já na mensagem.
- **Clientes**: histórico de pedidos, total gasto e endereços.

## 2.13 Cancelar um pedido e estornar (gestor)

Pedido → **Cancelar**, com o motivo (o cliente recebe). Dá para cancelar até "Pronto para envio".

- **Não pago**: cancela e devolve o estoque reservado.
- **Pago**: escolha
  - **Estornar agora pelo provedor de pagamento** (estorno total automático no Mercado Pago). Se o Mercado Pago recusar, nada é cancelado e aparece o aviso;
  - **Já estornei por fora**: você fez o estorno direto no Mercado Pago (ex.: estorno parcial) e só registra.

## 2.14 Pedidos de dados pessoais — LGPD (proprietário)

Quando um cliente pede, **pelo e-mail cadastrado**, uma cópia ou a exclusão dos dados:

1. Confirme a identidade (responda ao e-mail cadastrado).
2. **Clientes → cliente → Dados pessoais (LGPD)**:
   - **Exportar dados (JSON)**: arquivo para enviar ao cliente;
   - **Anonimizar cadastro**: digite `ANONIMIZAR`. Não é possível com pedido em andamento. Os pedidos recentes são
     mantidos por obrigação fiscal.

Detalhes: [16 — Segurança, LGPD e testes](16-seguranca-e-testes.md) §2.

## 2.15 Senhas e acessos

- **Esqueceu a senha?** Tela de login → **Esqueci minha senha**. O link chega por e-mail e vale 60 minutos.
- **Alguém saiu da equipe?** Proprietário → Usuários → pessoa → desmarque **Acesso ativo**. Vale na hora.
- Trocar a senha encerra as sessões abertas em outros aparelhos.
- Depois de 5 senhas erradas, o login daquele e-mail fica bloqueado por 15 minutos.
- **Auditoria** (proprietário) mostra quem fez o quê, quando e de onde (preços, estoque, cancelamentos, downloads de backup…).

## 2.16 Rotinas

| Quando | Quem | O quê |
|---|---|---|
| **Todo dia** | produção | abrir **Produção**, começar pelos cartões vermelhos e amarelos |
| **Todo dia** | produção | **Expedição**: despachar os prontos, marcar os entregues |
| **Todo dia** | atendimento | **Pedidos**: responder mensagens, conferir atrasados |
| **Toda semana** | proprietário | **Sistema → Backups → Baixar** o último `banco` (e `arquivos` se houve fotos ou arquivos CNC novos) e guardar fora do servidor |
| **Toda semana** | proprietário | **Configurações → Exportar configuração (.yaml)** e guardar junto com o backup |
| **Toda semana** | gestor | **Materiais**: repor o que está marcado **Repor** |
| **Todo mês** | proprietário | testar a restauração de um backup numa instalação no computador ([17](17-deploy-e-operacao.md) §7) |
| **Todo mês** | proprietário | **Auditoria**: olhar acessos e mudanças de preço fora do comum |
| **A cada atualização** | quem instala | seguir o roteiro 2.17 |

## 2.17 Atualizar a loja (nova versão)

Quando houver uma versão nova, você recebe um **novo pacote** `gnesting-….zip` na pasta `PACOTE-CPANEL` (quem desenvolve
gera com dois cliques em `gerar-pacote.cmd`, na pasta do projeto). A atualização é feita toda pelo navegador:

1. **Guarde o pacote anterior**: é para ele que você volta se algo der errado.
2. Painel → **Sistema** → **Ligar manutenção**. Os clientes veem "Voltamos já"; você continua entrando neste navegador.
3. Sistema → **Fazer backup agora**.
4. Gerenciador de Arquivos → pasta `public_html` → **Carregar** o `.zip` novo → botão direito → **Extract** (destino
   `/home/USUARIO/public_html`) → confirme a substituição dos arquivos → apague o `.zip`.
   - O `.env`, os backups e as fotos **não** estão no pacote: nada disso é tocado.
5. Recarregue a tela **Sistema**. Se aparecer **Atualização do banco de dados pendente**, clique em
   **Atualizar banco de dados** (o sistema faz outro backup antes, sozinho).
6. Confira a **Lista de verificação** e a versão no topo da tela Sistema (deve ser a do pacote novo).
7. Navegue pela loja (home, um produto, carrinho) e pelo painel. Tudo certo: **Desligar e voltar ao ar**.

**Deu errado?** Com a manutenção ainda ligada, extraia o pacote anterior. Se o banco foi atualizado, restaure o backup do
passo 3 (passo 2.20).

## 2.18 Problemas comuns

| Sintoma | O que fazer |
|---|---|
| Cliente não recebeu e-mail | Pedido → **Reenviar link**. Peça para olhar o spam. Se nenhum e-mail sai, confira `MAIL_*` no `.env` (1.10) e o SPF/DKIM (1.2) |
| Cliente pagou, mas o pedido segue "Aguardando pagamento" | Confira o **webhook** no Mercado Pago (URL e assinatura secreta, 1.12). O pagamento é conferido de novo quando o cliente volta à página do pedido |
| Pedido cancelado, mas o cliente diz que pagou | Pagamento que chega depois do cancelamento não reativa o pedido: há uma **nota interna** no pedido. Estorne pelo Mercado Pago |
| Monitor avisou "atencao" | **Sistema → Saúde** mostra o motivo: tarefas automáticas paradas (1.11), backup atrasado, pouco disco, atualização do banco pendente (2.17), manutenção ligada |
| Loja mostra "Voltamos já" | O modo manutenção ficou ligado (por você ou por uma restauração): **Sistema → Desligar e voltar ao ar** |
| O painel também mostra "Voltamos já" | Você está em outro navegador ou aparelho, sem a passagem da manutenção. Para sair dela sem painel: Gerenciador de Arquivos → apague `public_html/storage/maintenance.json` |
| "Algo deu errado" com um código (ex.: `A1B2C3`) | Você recebeu um alerta com esse código. O detalhe está em `storage/logs/app-AAAA-MM-DD.log` (Gerenciador de Arquivos) |
| Produto não aparece na loja | Precisa estar **ativo**, com a categoria (e a categoria-mãe) ativas e a variação padrão ativa |
| Foto recusada | Menor que 500 px, maior que 5 MB, ou arquivo que não é imagem de verdade |
| Frete estranho | Revise o peso e as medidas da embalagem da variação e a tabela de frete (passo 1.13) |
| Importação do YAML recusada | Nada foi gravado. A mensagem diz o caminho do erro (ex.: `categorias[2].slug`): corrija o arquivo e importe de novo |
| Esqueci a senha e o e-mail da loja não funciona | Com outro proprietário: Usuários → trocar a senha. Sendo o único: corrija o e-mail no `.env` (1.10) e use "Esqueci minha senha" |
| Perdi dados / algo foi apagado | Restaure o backup mais recente de antes do problema (passo 2.20) |

## 2.19 Configuração em arquivo (YAML)

Painel → **Configurações** (proprietário), no fim da página. Um arquivo de texto com: dados da loja (WhatsApp, contato,
faixa de avisos), **tabela de frete**, **categorias** e **materiais**.

- **Exportar configuração (.yaml)**: baixa o arquivo. Guarde junto com os backups.
- **Importar configuração**: aplica um arquivo editado, ou um exportado de outra instalação (por exemplo, a que você
  montou no computador antes de publicar).
- Importar **nunca apaga**: categorias são encontradas pelo `slug` e materiais pelo `codigo`, e o que não estiver no
  arquivo fica como está. Para tirar algo da loja, use `ativa: false`.
- O arquivo é conferido inteiro antes; se houver erro, **nada** é gravado e a mensagem diz onde.
- O saldo dos materiais não muda pelo arquivo (use Materiais → Movimentar saldo). Produtos, pedidos e clientes não entram:
  estão no backup do banco.

Detalhes e exemplo: [15 — Marketing](15-marketing.md) §7.

## 2.20 Restaurar um backup (proprietário)

Para voltar a loja a um ponto anterior (algo apagado por engano, atualização que deu errado):

1. **Sistema → Backups** → na linha do backup escolhido, **Restaurar…**
2. Marque "Restaurar também fotos e arquivos de produção" se precisar.
3. Digite **RESTAURAR** e clique em *Restaurar este backup*.
4. O sistema:
   - liga a manutenção;
   - guarda uma cópia do estado atual (aparece na lista, caso você tenha escolhido o backup errado);
   - substitui **todo** o banco pelo do backup.
5. Confira pedidos, produtos e painel. Tudo certo: **Desligar e voltar ao ar**.

Atenção: pedidos pagos depois da data do backup somem da loja, mas continuam no Mercado Pago. Confira por lá. Os usuários
e senhas do painel também voltam a ser os do backup. Detalhes: [17](17-deploy-e-operacao.md) §7.

---

Referência técnica completa: [README](../README.md) e documentos 00 a 17.
