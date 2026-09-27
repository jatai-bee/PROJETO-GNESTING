# G-Nesting

**Objetos que transformam espaços.**

E-commerce próprio da G-Nesting, marca de objetos produzidos por fabricação digital (CNC).
Modelo de negócio: **produtos padronizados + produção repetível + personalização controlada**.

## Stack

PHP 8.2+ · MVC próprio · MySQL 5.7.8+ / 8.x ou MariaDB 10.6+ (PDO) · Composer (phpdotenv, FastRoute, Symfony YAML, PHPUnit) · HTML/CSS/JS sem build · hospedagem compartilhada (cPanel) · **instalação e operação pelo navegador, sem Terminal/SSH**.

## Documentação

| Documento | Conteúdo |
|---|---|
| [00 — Setup Laragon](docs/00-setup-laragon.md) | ambiente local, banco, Git |
| [01 — Arquitetura](docs/01-arquitetura.md) | estrutura, camadas, convenções, restrições da hospedagem |
| [02 — Banco de dados](docs/02-banco-de-dados.md) | modelo, decisões, evolução |
| [03 — Status e produção](docs/03-status-e-fluxo-de-producao.md) | status do pedido, transições, papéis |
| [04 — Rotas](docs/04-rotas.md) | mapa de URLs, middleware, permissões |
| [05 — Segurança](docs/05-seguranca.md) | política e checklist |
| [06 — Identidade visual](docs/06-identidade-visual.md) | marca, cores, tipografia, tom ([prévia](docs/identidade-visual-preview.html)) |
| [07 — Fundação](docs/07-fundacao.md) | como usar o núcleo: rotas, controllers, validação, auth, testes |
| [08 — Painel administrativo](docs/08-painel-administrativo.md) | guia de uso do painel e regras do catálogo |
| [09 — Catálogo e carrinho](docs/09-catalogo-e-carrinho.md) | vitrine, busca, filtros, página de produto, carrinho |
| [10 — Variações e personalização](docs/10-variacoes-e-personalizacao.md) | opções, SKUs, campos de personalização, acréscimos |
| [11 — Ficha de produção](docs/11-ficha-de-producao.md) | materiais, etapas e tempos, arquivos CNC, acesso da produção |
| [12 — Checkout](docs/12-checkout.md) | compra com/sem conta, frete, Mercado Pago, webhook, expiração |
| [13 — Pedidos](docs/13-pedidos.md) | gestão no painel, papéis, cancelamento e estorno, e-mails, SMTP, clientes |
| [14 — Produção](docs/14-producao.md) | fila por etapa, retrabalho, previsão de carga, consumo de chapas, expedição e romaneio |
| [15 — Marketing](docs/15-marketing.md) | cupons, WhatsApp e configurações da loja, sitemap, robots, Open Graph, JSON-LD, relacionados, **configuração em YAML** (loja, frete, categorias, materiais) |
| [16 — Segurança, LGPD e testes](docs/16-seguranca-e-testes.md) | recuperação de senha, sessões, exportação/anonimização (LGPD), checklist de segurança → testes, `composer check`, CI |
| [17 — Deploy e operação](docs/17-deploy-e-operacao.md) | pacote (`gerar-pacote.cmd`), instalação pelo navegador, HTTPS, backups e restauração pelo painel, atualização do banco, manutenção, cron (Cron Jobs ou URL), `/saude` e alertas |
| [18 — Guia de instalação e uso](docs/18-guia-de-instalacao-e-uso.md) | **passo a passo** para colocar a loja no ar e usá-la no dia a dia (por papel), rotinas e problemas comuns |
| [19 — Reformulação de UX](docs/19-reformulacao-ux.md) | auditoria, design system v2, nova loja (menu de categorias, filtros rápidos, favoritos, etapas da compra) e dados de demonstração removíveis |

## Início rápido

**Na hospedagem (sem Terminal):** gere o pacote com dois cliques em `gerar-pacote.cmd` (vai para a pasta
`../PACOTE-CPANEL`), envie pelo Gerenciador de Arquivos para o `public_html`, extraia e abra
`https://SEU-DOMINIO/instalar.php`. Depois, aponte a raiz do domínio para `public_html/public`. Passo a passo em
[docs/18](docs/18-guia-de-instalacao-e-uso.md).

**No computador (desenvolvimento):**

```bash
composer install
cp .env.example .env
php bin/generate-key.php
composer migrate -- --create-db --seed
php bin/create-admin.php --email=voce@gnesting.com.br --name="Seu Nome" --role=owner
composer serve        # http://localhost:8000  ·  painel em /admin
composer check        # sintaxe + PHPStan + vulnerabilidades + testes (o mesmo que o CI roda)
```

Detalhes em [docs/00-setup-laragon.md](docs/00-setup-laragon.md). Publicar e usar: [docs/18-guia-de-instalacao-e-uso.md](docs/18-guia-de-instalacao-e-uso.md) (passo a passo) e [docs/17-deploy-e-operacao.md](docs/17-deploy-e-operacao.md) (referência técnica).

## Roadmap

Cada etapa segue: analisar → planejar → implementar → testar → corrigir → documentar → avançar.

| # | Etapa | Status |
|---|---|---|
| 1 | Arquitetura: estrutura, banco, padrões, rotas, segurança, identidade | ✅ concluída (SQL validado no MySQL 8.4) |
| 2 | Fundação: Composer, dotenv, PDO, núcleo MVC, autenticação, migrations | ✅ concluída |
| 3 | Administração: login, dashboard, usuários, categorias, produtos, imagens | ✅ concluída (106 testes no total) |
| 4 | Catálogo: home, categorias, busca, filtros, produto, carrinho | ✅ concluída (123 testes no total) |
| 5 | Produtos e personalização: SKUs, opções, regras, acréscimos | ✅ concluída (138 testes no total) |
| 6 | Ficha de produção: especificações, materiais, arquivos, tempos | ✅ concluída (150 testes no total) |
| 7 | Checkout: cadastro, endereço, frete, pagamento, confirmação | ✅ concluída (167 testes no total) |
| 8 | Pedidos: gestão, status, histórico, comunicação | ✅ concluída (177 testes no total) |
| 9 | Produção: fila, CNC, acabamento, CQ, embalagem, expedição | ✅ concluída (185 testes no total) |
| 10 | Marketing: cupons, SEO, WhatsApp, relacionados | ✅ concluída (195 testes no total) |
| 11 | Segurança e testes | ✅ concluída (215 testes no total + PHPStan nível 6 + CI) |
| 12 | Produção: servidor, HTTPS, backups, monitoramento | ✅ concluída (230 testes no total) |
| 13 | Sem Terminal: instalador web, cron por URL, banco e restauração pelo painel, configuração em YAML, pacote pronto para o cPanel (`gerar-pacote.cmd`), compatível com MySQL 5.7 | ✅ concluída (255 testes no total) |
