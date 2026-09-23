# 01 — Arquitetura

> **G-Nesting — Objetos que transformam espaços.**
> Documento de referência técnica (Etapa 1). Toda etapa seguinte deve respeitar as decisões aqui registradas; mudanças devem ser feitas neste documento primeiro.

## 1. Princípio que guia a arquitetura

O negócio é **produto padronizado + produção repetível + personalização controlada**. A arquitetura reflete isso:

- o cliente só escolhe o que o administrador parametrizou (variantes e regras de personalização);
- a ficha de produção é separada da vitrine e nunca é exposta na loja;
- o pedido guarda *snapshots* imutáveis do que foi vendido;
- o status do pedido já nasce preparado para virar um fluxo de produção.

Pergunta de corte para qualquer funcionalidade:
**isso ajuda a vender produtos padronizados, produzir com previsibilidade e crescer com eficiência?** Se não ajudar, fica fora do MVP.

## 2. Decisões técnicas

| Tema | Decisão | Motivo |
|---|---|---|
| Linguagem | PHP 8.2+ com `declare(strict_types=1)` | Enums nativos, readonly, tipagem; disponível em hospedagem compartilhada |
| Base | MVC próprio e enxuto | Estrutura do briefing, controle total, sem dependência de framework pesado |
| Dependências | `vlucas/phpdotenv`, `nikic/fast-route`, `phpunit/phpunit` (dev) | Cada pacote resolve um problema claro; nada além disso sem justificativa |
| Banco | MySQL 8.0.16+ / MariaDB 10.6+ via PDO | Suportado pelas hospedagens; `CHECK` e `JSON` disponíveis |
| Views | PHP nativo com escape obrigatório `e()` | Sem template engine extra; regra de escape documentada em [05-seguranca.md](05-seguranca.md) |
| Front-end | HTML5 + CSS com tokens + JS puro (progressive enhancement) | Leve, rápido no mobile, sem build step |
| Hospedagem | Compartilhada (cPanel) | Tudo síncrono; cron do cPanel; sem workers/filas persistentes |

## 3. Estrutura de diretórios

```text
/public                  ← ÚNICO diretório servido pela web
    index.php            front controller (etapa 2)
    .htaccess            rewrite para index.php + headers
    /assets/{css,js,img} estáticos da marca
    /uploads             imagens de produto (nunca executa PHP)
/app
    /Core                Router, Request, Response, View, Database, Session, Csrf, Container
    /Controllers
        /Store           loja pública e área do cliente
        /Admin           painel administrativo
        /Api             endpoints JSON e webhooks
    /Middleware          Auth, AdminRole, Csrf, RateLimit, SecurityHeaders
    /Services            regras de negócio (Pricing, Personalization, Cart, Checkout, OrderStatus, Audit...)
    /Repositories        TODO o SQL da aplicação
    /Models              objetos de dados (readonly/DTO)
    /Enums               OrderStatus, PaymentStatus, PersonalizationType, AdminRole, ProductionStage
    /Helpers             funções utilitárias (e, money, slug, url, csrf_field)
    /Views
        /layouts /store /admin /partials /errors
/config                  app.php, database.php, security.php (leem apenas do .env)
/routes                  web.php, admin.php, api.php
/storage                 ← NUNCA servido pela web
    /logs /cache /sessions
    /private/production_files   arquivos CNC e desenhos
/database
    /migrations          NNN_descricao.sql (aplicadas em ordem, registradas em schema_migrations)
    /seeds               dados de desenvolvimento
    migrate.php          runner simples (etapa 2)
/bin                     scripts CLI: create-admin.php, cron.php
/docs                    documentação por etapa
/tests                   PHPUnit
.env.example  composer.json  .htaccess (raiz)  README.md
```

### Hospedagem compartilhada e document root

1. **Preferencial:** apontar o domínio para `/public` no cPanel.
2. **Alternativa:** manter o projeto na raiz do domínio. O `.htaccess` da raiz redireciona tudo para `/public` e retorna **403** para `app/`, `bin/`, `config/`, `database/`, `docs/`, `routes/`, `storage/`, `tests/`, `vendor/`, arquivos ocultos (`.env`) e `composer.*`. `storage/` tem também seu próprio `.htaccess` com `Require all denied`, como defesa em profundidade.

## 4. Fluxo de uma requisição

```text
Navegador
  → .htaccess (raiz → /public → index.php)
  → public/index.php
      carrega vendor/autoload + .env + config
      configura erros (log em storage/logs; página genérica ao usuário)
      inicia sessão segura
  → Pipeline de middleware (SecurityHeaders → Session → Csrf → Auth/AdminRole → RateLimit)
  → Router (FastRoute) resolve controller@método
  → Controller     valida entrada, chama Service, escolhe View ou JSON
  → Service        regra de negócio, transações, auditoria
  → Repository     SQL com prepared statements (PDO)
  → View           renderiza HTML escapado dentro do layout
  → Response
```

### Regras de camada (obrigatórias)

- **Controller** não escreve SQL e não contém regra de negócio: recebe `Request`, valida, delega e responde.
- **Service** não conhece HTTP (`$_POST`, `$_SESSION`, headers). Recebe dados já validados e devolve resultados/exceções de domínio.
- **Repository** é o único lugar com SQL. Sempre `prepare()` + parâmetros nomeados.
- **View** só recebe dados prontos; toda saída passa por `e()`. Nenhuma consulta ao banco dentro de view.
- Operações que alteram várias tabelas (checkout, mudança de status + histórico + auditoria) rodam em **transação** dentro do Service.

## 5. Convenções

### Código
- PSR-4: namespace `GNesting\` → `app/`. PSR-12 para estilo.
- Classes em inglês (`OrderStatusService`); textos da interface em **pt-BR**.
- Um enum PHP por conjunto de status; o banco guarda o `value` (string) e o enum fornece o rótulo em português (`label()`).

### Dados
- **Dinheiro sempre em centavos inteiros** (`price_cents`, `total_cents`). Nunca `float`. Formatação apenas na view (`money(12990)` → `R$ 129,90`).
- Medidas em **milímetros** (`*_mm`), peso em **gramas** (`*_g`). A view converte para cm/kg.
- Datas gravadas em **UTC** (a conexão executa `SET time_zone = '+00:00'`) e exibidas em `America/Sao_Paulo`.
- Tabelas no plural, colunas em `snake_case` inglês; FKs `<entidade>_id`; booleanos `is_*`/`*_enabled`.
- Catálogo usa exclusão lógica (`deleted_at`); pedidos e itens nunca são apagados.
- CPF, CEP e telefone gravados **somente com dígitos**.

### Migrations
- Arquivos `database/migrations/NNN_descricao.sql`, **nunca editados depois de aplicados em produção**. Correções entram como nova migration.
- `database/migrate.php` aplica em ordem os arquivos ainda não registrados em `schema_migrations`.

## 6. Restrições da hospedagem compartilhada

| Necessidade | Solução |
|---|---|
| Tarefas periódicas (expirar pedidos não pagos, limpar carrinhos, `rate_limits` antigos, gerar sitemap) | `bin/cron.php` chamado pelo **cron do cPanel** a cada 15 min |
| Confirmação de pagamento | **Webhook** do gateway → `/webhooks/pagamento/{provedor}` (idempotente) |
| E-mail transacional | SMTP configurado no `.env` |
| Rate limiting / cache | Tabela `rate_limits` e cache em arquivo em `storage/cache` |
| Filas | Processamento síncrono e curto; nada de trabalho pesado na requisição |
| Imagens | Redimensionadas no upload (GD) para larguras fixas + WebP |

Se o projeto migrar para VPS no futuro, essas peças podem ser trocadas (Redis, fila, workers) sem alterar Controllers, porque dependem de interfaces nos Services.

## 7. Integrações previstas (interfaces desde o início)

- `PaymentGateway` (interface) → adaptadores por provedor (etapa 7). Pedido e pagamento são entidades separadas.
- `ShippingCalculator` (interface) → cotação por CEP; implementação inicial com tabela fixa, depois API da transportadora.
- `WhatsAppLink` (helper) → links `wa.me` com mensagem pré-preenchida (produto, número do pedido). O WhatsApp **complementa** o fluxo; não substitui carrinho, checkout nem status.

## 8. Documentos relacionados

- [00-setup-laragon.md](00-setup-laragon.md): ambiente local
- [02-banco-de-dados.md](02-banco-de-dados.md): modelo de dados
- [03-status-e-fluxo-de-producao.md](03-status-e-fluxo-de-producao.md): status e transições
- [04-rotas.md](04-rotas.md): mapa de rotas
- [05-seguranca.md](05-seguranca.md): política de segurança
- [06-identidade-visual.md](06-identidade-visual.md): marca e design system
- [07-fundacao.md](07-fundacao.md): guia prático do núcleo implementado na Etapa 2
