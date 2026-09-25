# G-Nesting

**Objetos que transformam espaços.**

E-commerce próprio da G-Nesting, marca de objetos produzidos por fabricação digital (CNC).
Modelo de negócio: **produtos padronizados + produção repetível + personalização controlada**.

## Stack

PHP 8.2+ · MVC próprio · MySQL 8 / MariaDB 10.6+ (PDO) · Composer (phpdotenv, FastRoute, PHPUnit) · HTML/CSS/JS sem build · hospedagem compartilhada (cPanel).

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

## Início rápido

```bash
composer install
cp .env.example .env
php bin/generate-key.php
composer migrate -- --create-db --seed
php bin/create-admin.php --email=voce@gnesting.com.br --name="Seu Nome" --role=owner
composer serve        # http://localhost:8000  ·  painel em /admin
composer test
```

Detalhes em [docs/00-setup-laragon.md](docs/00-setup-laragon.md).

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
| 10 | Marketing: cupons, SEO, WhatsApp, relacionados | ⏭ próxima |
| 11 | Segurança e testes | — |
| 12 | Produção: servidor, HTTPS, backups, monitoramento | — |
