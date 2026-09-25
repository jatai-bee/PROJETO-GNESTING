# 10 — Variações e personalização (etapa 5)

O cliente só escolhe o que foi parametrizado: **combinações pré-cadastradas** (variações com SKU próprio)
e **campos de personalização** com tipo, limites e acréscimo definidos no painel. Não existe campo livre
de "descreva o que quer alterar" (decisão de negócio — [02 — Banco de dados](02-banco-de-dados.md)).

## 1. Variações (aba "Variações" do produto)

`/admin/produtos/{id}/variantes` — papel **manager**.

**Conceitos**

| Termo | Exemplo | Tabela |
|---|---|---|
| Opção (eixo) | Acabamento, Tamanho | `product_options` |
| Valor | Natural, Preto · 30 cm, 45 cm | `product_option_values` |
| Variação (SKU) | Preto / 45 cm — `REL-GEO-001-PRET-45CM` | `product_variants` + `variant_option_values` |

**Fluxo de uso**

1. Crie a opção com os valores separados por vírgula ("Natural, Preto"). As variações existentes recebem o primeiro valor.
2. Clique em **Gerar variações**: cria as combinações que faltam, copiando preço, material e medidas da variação padrão.
   SKU gerado = SKU padrão + sufixo dos valores. Estoque: mesmo modo da padrão, quantidade 0.
3. Revise cada variação (**Editar**): SKU, preço, preço "de", material, medidas, embalagem, estoque, ativa/inativa.

**Regras**

- Limites: 3 opções por produto, 10 valores por opção, 50 variações.
- A **variação padrão** é a que aparece selecionada na loja e é editada na aba **Dados**. Não pode ser desativada nem excluída — escolha outra com **Tornar padrão** antes.
- A combinação de uma variação é fixa. Para mudar, exclua a variação e gere de novo.
- Valor em uso por variação não pode ser excluído. Opção só pode ser excluída com um único valor (senão as combinações colidiriam).
- Excluir variação é exclusão lógica: some da loja e dos carrinhos (marcada como indisponível) e o **SKU continua reservado** para sempre.
- O nome da variação ("Preto / 45 cm") é recalculado automaticamente e é o que aparece no carrinho e, no futuro, no pedido.
- Alterações de preço e estoque são auditadas (`price_change`, `stock_change`) e o estoque gera movimentação.

**Na loja**

- Produto com mais de uma variação mostra um seletor "Acabamento / Tamanho" com preço de cada combinação; combinações esgotadas aparecem desabilitadas.
- Trocar a variação atualiza preço, SKU, medidas e disponibilidade na hora (JavaScript). Sem JavaScript o seletor funciona igual — o carrinho sempre recalcula no servidor.
- Link direto para uma variação: `/produto/{slug}?variante={id}`.
- Listagens mostram **"a partir de"** o menor preço quando as variações têm preços diferentes; filtros e ordenação por preço usam esse menor preço. O produto só aparece como **esgotado** quando nenhuma variação pode ser vendida.
- A busca por SKU encontra qualquer variação ativa.

## 2. Personalização (aba "Personalização" do produto)

`/admin/produtos/{id}/personalizacao` — papel **manager**. Até 10 campos por produto.

| Tipo | O cliente informa | Limites configuráveis | Valor guardado |
|---|---|---|---|
| Texto curto | texto | mín./máx. de caracteres (máx. até 100) + caracteres aceitos | texto normalizado |
| Inicial | 1 a 3 letras | máximo de letras | MAIÚSCULAS, sem espaços |
| Data | data (dd/mm/aaaa) | — | `AAAA-MM-DD` (exibida dd/mm/aaaa) |
| Opção pré-definida | uma opção da lista | opções "Rótulo \| acréscimo", uma por linha (até 30) | id da opção |

**Caracteres aceitos (presets).** O administrador escolhe um preset em vez de escrever uma expressão regular
(evita regex mal escrita e ReDoS, e mantém a gravação viável na produção):

| Preset | Aceita |
|---|---|
| Somente letras e espaços | letras do alfabeto latino (com acentos) |
| Letras, números e espaços | + 0–9 |
| Texto com pontuação simples | + `. , - & ' ! ? ( ) / :` |

Emojis, `<`, `>`, aspas duplas e outros símbolos são recusados em todos os presets.

**Preço.** Cada campo tem um acréscimo por unidade, cobrado quando o campo é preenchido. Em opções pré-definidas, soma-se o acréscimo do campo e o da opção escolhida.
O valor enviado pelo navegador **nunca** define preço: o acréscimo é sempre lido da regra no banco.

**Outras regras**

- **Obrigatório**: sem o campo preenchido o produto não entra no carrinho.
- **Ativo na loja**: desmarcar esconde o campo sem apagá-lo.
- A chave interna (`field_key`, ex.: `nome_gravado`) é gerada do rótulo na criação e não muda — ela identifica o campo para a produção.
- Opções removidas da lista são **desativadas**, não apagadas (carrinhos e pedidos as referenciam).
- Excluir o campo remove-o dos carrinhos; pedidos já feitos guardam o que foi escolhido (snapshot — etapa 7).
- `products.personalization_enabled` é mantido automaticamente (há campo ativo ou não).
- "Área máxima de gravação" é informativa para a produção.

## 3. Carrinho com personalização

- Validação no servidor pelo `PersonalizationService`: tipo, obrigatoriedade, tamanho, preset, data válida (1900–2100), opção existente e ativa. Campos de outros produtos ou desconhecidos são ignorados.
- Erro de personalização volta para a página do produto com a mensagem em cada campo e o que foi digitado; nenhum carrinho é criado.
- A mesma variação com personalizações diferentes vira **linhas diferentes** (`cart_items.personalization_hash`); a mesma personalização soma na mesma linha. Maiúsculas contam ("Ana" ≠ "ana"); espaços extras são normalizados.
- Preço unitário = preço da variação + acréscimos **atuais**. O carrinho mostra a composição ("R$ 99,90 + R$ 25,00 de personalização").
- Toda leitura do carrinho revalida a personalização contra as regras atuais. Se um campo foi desativado, virou obrigatório, mudou de limite ou a opção foi retirada, o item fica marcado ("A personalização deste item mudou na loja") e fora do total — nunca some em silêncio.
- Estoque "pronta entrega" é somado entre todas as linhas da mesma variação.

## 4. Onde está no código

| Responsabilidade | Arquivo |
|---|---|
| Regras de variações | `app/Services/VariantService.php` |
| Cadastro de campos | `app/Services/PersonalizationRuleService.php` |
| Validação do que o cliente envia, presets, hash da linha | `app/Services/PersonalizationService.php` |
| Carrinho | `app/Services/CartService.php` |
| Painel | `app/Controllers/Admin/VariantController.php`, `PersonalizationController.php`, views em `app/Views/admin/products/` |
| Loja | `app/Controllers/Store/ProductController.php`, `app/Views/store/product/show.php`, `public/assets/js/store.js` |

## 5. Testes

- `tests/Integration/VariantsAndPersonalizationTest.php` — geração de combinações e SKUs, duplicidades e limites, exclusões bloqueadas, variação padrão, auditoria/movimentação, validação por tipo no cadastro, desativação de opções, validação do cliente (normalização, presets, datas, emoji/HTML, acréscimos, hash).
- `tests/Integration/StorefrontHttpTest.php` — erros voltando ao produto, preço calculado no servidor (campos forjados ignorados), linhas separadas por personalização, mudança de acréscimo/desativação refletida no carrinho, personalização de outro produto ignorada, escolha de variação, estoque compartilhado entre linhas.
- `tests/Integration/AdminPanelHttpTest.php` — permissões (support recebe 403), fluxo de opções/geração/edição e criação de campo pelo painel.

## 6. Fica para depois

- Foto específica por variação (`product_images.variant_id` já existe) — trocar a galeria ao escolher a variação.
- Renomear opção/valor (hoje: excluir e recriar).
- Mostrar/ocultar os campos de limite conforme o tipo no formulário do painel.
- Pré-visualização da gravação.
