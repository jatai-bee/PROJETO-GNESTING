# 09 — Catálogo e carrinho (etapa 4)

Vitrine pública: home, listagens, busca, página de produto, páginas institucionais e carrinho.
Rotas em `routes/web.php` (mapa em [04 — Rotas](04-rotas.md)).

## 1. Quando um produto aparece na loja

Um produto é **visível** quando, ao mesmo tempo:

- está ativo (`is_active = 1`) e não excluído;
- tem variante padrão ativa;
- a categoria está ativa e não excluída — e, se for subcategoria, a categoria-mãe também.

Fora disso, `/produto/{slug}` responde **404** e o produto some das listagens, da busca e dos relacionados.
A regra fica num só lugar: `CatalogRepository` (`VISIBLE_FROM` + `VISIBLE_WHERE`).

Produto sem foto aparece com um marcador neutro (logotipo), mas o painel já exige ao menos uma imagem para ativar.

## 2. Listagens

| URL | Conteúdo |
|---|---|
| `/` | destaques (`is_featured`), categorias com produtos, novidades (`is_new`, sem repetir destaques) |
| `/produtos` | todos os produtos visíveis |
| `/categoria/{slug}` | a categoria **e suas subcategorias** |
| `/busca?q=` | busca (ver §3) |

Parâmetros na query string (todas as listagens): `min` e `max` (preço em reais, ex.: `89,90`),
`ordem` (`relevancia` · `novidades` · `menor-preco` · `maior-preco` · `mais-vendidos`), `pagina`.
Valores inválidos são ignorados (ordenação desconhecida volta ao padrão). 12 produtos por página.

SEO: toda listagem tem `<link rel="canonical">` para a URL **sem filtros**; páginas filtradas, a busca e o carrinho recebem `noindex, follow`.

## 3. Busca

- Termos com 2+ letras (até 5); **todos** precisam aparecer em algum campo: nome, resumo, categoria ou SKU.
- Sem acento e sem diferença de maiúsculas (collation `utf8mb4_unicode_ci`): "relogio" encontra "Relógio".
- Na ordem "Mais relevantes", nomes que **começam** com o primeiro termo vêm primeiro.
- Busca vazia ou curta não lista o catálogo; mostra o formulário.
- Limite: 60 buscas por minuto por IP (`security.rate_limits.search`) → 429.

**Por que `LIKE` e não o índice FULLTEXT?** O catálogo é pequeno (dezenas a poucas centenas de itens), o `LIKE` busca
trechos de palavra ("colme" → "Colmeia") e SKU, e o FULLTEXT do InnoDB ignora palavras curtas e não enxerga linhas
dentro de transações não confirmadas (os testes rodam assim). Se o catálogo passar de alguns milhares de produtos, reavaliar.

## 4. Página de produto

Galeria (miniaturas trocam a foto sem recarregar; sem JS, abrem a imagem), preço com preço "de" e % de desconto,
características (uma por linha no admin), material, acabamento, medidas em cm, peso, SKU, descrição e até 4 relacionados da mesma categoria.

Disponibilidade:

| Modo de estoque | Exibição | Compra |
|---|---|---|
| `made_to_order` | "Produzido sob encomenda: fica pronto em até N dias úteis" | até 99 por item |
| `stock` com saldo | "Pronta entrega" (+ "Restam N" quando ≤ 5) | até o saldo (`em estoque − reservado`) |
| `stock` sem saldo | "Esgotado", sem botão de compra | bloqueada |

Variações e personalização (seletor de variação, campos de personalização, preços e regras no carrinho):
ver [10 — Variações e personalização](10-variacoes-e-personalizacao.md).

## 5. Carrinho

**Identificação.** Cookie `gn_cart` com um token aleatório de 32 bytes (HttpOnly, SameSite=Lax, Secure em produção, 30 dias).
O banco guarda só o SHA-256 (`carts.token_hash`). Token com formato inválido é ignorado. O cookie é gravado pelo
middleware global `cart` (`LoadCart`), que lê o token para o `CartContext`; o `CartService` não conhece HTTP.

**Regras (`CartService`):**

- Preço e disponibilidade são **sempre lidos na hora** — mudar o preço no painel muda o carrinho.
- Adicionar a mesma variante soma na mesma linha. Quantidade 1–99; até 30 linhas; quantidade 0 remove.
- Produto em estoque não passa do saldo disponível.
- Se o produto sair da loja ou o saldo cair depois, o item continua listado, **marcado** e **fora do subtotal**
  ("Ajuste os itens destacados para continuar"). O checkout (etapa 7) vai exigir carrinho sem pendências.
- Cliente logado: o carrinho é associado a ele (`carts.customer_id`). A fusão de carrinhos ao entrar fica para a etapa 7.
- Toda alteração é POST com CSRF e termina em redirecionamento (PRG). Um visitante não consegue alterar item de outro
  carrinho: o item precisa pertencer ao carrinho do cookie.
- Carrinhos expirados são apagados pelo `bin/cron.php`.

O botão "Finalizar compra" fica desativado até a etapa 7 (checkout).

## 6. Páginas institucionais

`/sobre`, `/como-fazemos`, `/trocas-e-devolucoes`, `/privacidade`, `/termos` — texto em `app/Views/store/pages/`.
Para criar outra página: adicione a entrada em `PageController::PAGES` e o template; a rota é criada automaticamente.

> **Revisar antes de publicar:** os textos de privacidade, termos e trocas são uma base redigida a partir do CDC e da LGPD
> e devem ser revisados por quem responde juridicamente pela loja (prazos, e-mail de contato, dados coletados).

## 7. Front-end

- `public/assets/css/store.css` — estilos da vitrine (mobile-first, só tokens de `tokens.css`).
- `public/assets/js/store.js` — melhoria progressiva: auto-envio da ordenação e da quantidade no carrinho, troca de foto na galeria. Tudo funciona sem JavaScript.
- Grades de produtos e categorias usam linhas finas de 1px entre as peças (referência ao *nesting*).

## 8. Testes

`tests/Integration/StorefrontHttpTest.php` (17 testes, pipeline HTTP completo): visibilidade, subcategorias, filtros,
ordenação, paginação, canônica/noindex, busca (nome, SKU, curinga, termo curto), limite de buscas, página de produto,
esgotado, páginas institucionais e carrinho (cookie, soma, atualização, remoção, preço recalculado, estoque,
item indisponível, personalização obrigatória, CSRF, isolamento entre visitantes, vínculo com cliente).
