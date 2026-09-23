# 04 — Mapa de rotas

URLs em **português** (SEO e clareza para o cliente brasileiro). Controllers em inglês.
Arquivos: `routes/web.php` (loja), `routes/admin.php`, `routes/api.php`.

Legenda de middleware: **S** = SecurityHeaders + Session (todas as rotas HTML) · **C** = CSRF (todo POST de formulário) · **A** = cliente autenticado · **R(papéis)** = admin com papel · **L** = rate limit

## 1. Loja pública (`routes/web.php`)

| Método | URL | Controller@ação | Middleware | Etapa |
|---|---|---|---|---|
| GET | `/` | `Store\HomeController@index` | S | 4 |
| GET | `/produtos` | `Store\CatalogController@index` (filtros, ordenação, paginação via query string) | S | 4 |
| GET | `/categoria/{slug}` | `Store\CatalogController@category` | S | 4 |
| GET | `/produto/{slug}` | `Store\ProductController@show` | S | 4 |
| GET | `/busca?q=` | `Store\CatalogController@search` | S, L | 4 |
| GET | `/carrinho` | `Store\CartController@show` | S | 4 |
| POST | `/carrinho/itens` | `Store\CartController@add` | S, C | 4/5 |
| POST | `/carrinho/itens/{id}` | `Store\CartController@update` | S, C | 4 |
| POST | `/carrinho/itens/{id}/remover` | `Store\CartController@remove` | S, C | 4 |
| POST | `/carrinho/cupom` | `Store\CartController@applyCoupon` | S, C, L | 10 |
| GET | `/checkout` | `Store\CheckoutController@show` | S | 7 |
| POST | `/checkout` | `Store\CheckoutController@place` | S, C, L | 7 |
| GET | `/pedido/{numero}/confirmacao` | `Store\CheckoutController@confirmation` | S | 7 |
| GET | `/sobre` · `/como-fazemos` · `/trocas-e-devolucoes` · `/privacidade` · `/termos` | `Store\PageController@show` | S | 4 |
| GET | `/sitemap.xml` | `Store\SeoController@sitemap` | — | 10 |
| GET | `/robots.txt` | `Store\SeoController@robots` | — | 10 |

## 2. Conta do cliente

| Método | URL | Controller@ação | Middleware |
|---|---|---|---|
| GET/POST | `/entrar` | `Store\AuthController@login` | S, C, L |
| GET/POST | `/cadastro` | `Store\AuthController@register` | S, C, L |
| POST | `/sair` | `Store\AuthController@logout` | S, C, A |
| GET/POST | `/recuperar-senha` | `Store\PasswordController@request` | S, C, L |
| GET/POST | `/redefinir-senha/{token}` | `Store\PasswordController@reset` | S, C, L |
| GET | `/conta` | `Store\AccountController@index` | S, A |
| GET | `/conta/pedidos` | `Store\AccountController@orders` | S, A |
| GET | `/conta/pedidos/{numero}` | `Store\AccountController@order` (verifica se o pedido é do cliente) | S, A |
| GET/POST | `/conta/enderecos` | `Store\AddressController@*` | S, C, A |
| GET/POST | `/conta/favoritos` | `Store\WishlistController@*` | S, C, A |

## 3. Administração (`routes/admin.php`, prefixo `/admin`)

Todas as rotas exigem **S + C + admin autenticado**, exceto o login. A coluna "Papéis" indica quem acessa (`owner` acessa tudo).

> **Implementadas (etapa 3):** login, painel, categorias, produtos, imagens, usuários e logs. Arquivo: `routes/admin.php`.
> Convenção: formulários usam a mesma URL no GET e no POST (`/novo`, `/{id}/editar`). Ações usam POST em subcaminhos (`/{id}/status`, `/{id}/excluir`, `/imagens/{imageId}/capa|mover|texto|excluir`).

| URL | Função | Papéis |
|---|---|---|
| `GET/POST /admin/login` | login (com L) | público |
| `POST /admin/sair` | logout | todos |
| `GET /admin` | dashboard e indicadores | todos |
| `/admin/produtos` · `/novo` · `/{id}/editar` · `POST /{id}/status` | CRUD, ativar/desativar | manager |
| `/admin/produtos/{id}/variantes` | variantes, SKU, preço, medidas | manager |
| `/admin/produtos/{id}/imagens` | upload/ordenação | manager |
| `/admin/produtos/{id}/personalizacao` | regras e opções | manager |
| `/admin/produtos/{id}/ficha-producao` | ficha, etapas, arquivos | manager, production |
| `GET /admin/arquivos-producao/{id}` | download autenticado de arquivo privado | manager, production |
| `/admin/categorias` | CRUD | manager |
| `/admin/materiais` | matéria-prima | manager, production |
| `/admin/estoque` | saldos e movimentações | manager, production |
| `/admin/pedidos` · `/{id}` · `POST /{id}/status` · `POST /{id}/nota` | gestão e status | manager, production (status de produção), support (leitura + nota) |
| `/admin/producao` | fila de produção (quadro por status) | manager, production |
| `/admin/expedicao` | remessas e rastreio | manager, production |
| `/admin/pagamentos` | acompanhamento | manager |
| `/admin/clientes` | consulta | manager, support |
| `/admin/cupons` | CRUD | manager |
| `/admin/avaliacoes` | moderação | manager, support |
| `/admin/usuarios` | administradores e papéis | **owner** |
| `/admin/configuracoes` | WhatsApp, textos, prazos | owner |
| `/admin/logs` | auditoria | owner |

## 4. API e webhooks (`routes/api.php`)

| Método | URL | Função | Proteção |
|---|---|---|---|
| POST | `/api/frete/cotar` | cotação por CEP (JSON) | S, C (token no header `X-CSRF-Token`), L |
| POST | `/api/carrinho/resumo` | recalcula totais do carrinho (JSON) | S, C |
| POST | `/webhooks/pagamento/{provedor}` | notificação do gateway | **sem CSRF/sessão**; assinatura HMAC validada; idempotência por `payment_events` |

## 5. Convenções de rota

- Slugs: minúsculas, sem acento, hífens (`relogio-geometrico-g-nesting`), únicos por tabela.
- URLs canônicas: sem barra final; filtros em query string recebem `<link rel="canonical">` para a URL limpa da categoria.
- Formulários HTML usam só GET/POST. Ações destrutivas são `POST` com CSRF, nunca `GET`.
- Rotas por ID numérico só no admin. A loja usa slug ou número do pedido.
- Erros: 404 e 500 com páginas próprias da marca (`app/Views/errors`).
