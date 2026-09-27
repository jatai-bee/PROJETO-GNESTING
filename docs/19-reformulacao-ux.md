# 19 — Reformulação de UX (etapa 14)

Referência de experiência: DeliveryPremiumBR (menu superior com categorias, produto visual, ficha clara, compra em poucos passos, painel organizado). A identidade continua a da G-Nesting (docs/06). Prioridade de decisão: **UX → arquitetura → navegação → clareza → estética → funcionalidades**.

A reformulação acontece em fases, cada uma revisada com capturas de tela antes da próxima:

| Fase | Escopo | Situação |
|---|---|---|
| 1 | Auditoria, design system v2, loja inteira, dados de demonstração | concluída |
| 2 | Painel: menu agrupado, trilha de navegação, dashboard com indicadores e gráficos | concluída |
| 3 | Produtos (abas, custo e margem, SEO), categorias em árvore, clientes | concluída |
| 4 | Pedidos (tabela, cartões, detalhe), fila e ordens de produção, estoque, expedição, relatórios | concluída |
| 5 | Revisão final de design e acessibilidade | concluída |

## 1. Auditoria (antes)

| Onde | Problema | Correção |
|---|---|---|
| Loja, celular | parecia estourar a largura | era efeito da captura (o Edge headless desenha no mínimo ~500 px); as capturas agora usam um iframe de 390 px. `overflow-x: clip` no `body` fica como proteção |
| Home | título sem imagem e espaço vazio | apresentação com colagem de produtos, faixa de garantias, categorias com foto, destaques, ofertas, personalização, mais vendidos, novidades e "como fazemos" |
| Catálogo | só filtro de preço | filtros rápidos (oferta, pronta entrega, personalizável, destaques), árvore de categorias com contagem, etiquetas removíveis, ordenação na barra |
| Ficha | foto quebrada sem imagem, sem quantidade − +, sem favorito nem compartilhar | galeria, caixa de preço, disponibilidade, − +, favoritar, compartilhar, prazos, especificações, montagem e cuidados |
| Carrinho e checkout | sem indicação de etapa | barra de etapas (Carrinho → Entrega e dados → Pagamento → Confirmação); o checkout continua numa página só, sem cliques extras |
| Pedido | status só em texto | barra de progresso para o cliente (Pedido feito → Pagamento → Produção → Enviado → Entregue) |
| Visual | cantos retos, fontes que não existiam no projeto | cantos suaves, sombras quentes, Manrope e Newsreader auto-hospedadas |
| Dados | um produto de exemplo | 34 produtos com fotos, clientes e pedidos em todas as etapas |

## 2. Design system v2

Arquivos: `public/assets/css/tokens.css` (variáveis), `app.css` (componentes comuns à loja e ao painel), `store.css` (loja).

- **Cores:** papel `#F6F3EE` (fundo), grafite (texto), kerf `#C4532D` (ação principal), kerf-escuro (links), carvalho-claro (fundos de destaque), oliva (positivo: pronta entrega, pago). Feedback com fundo claro: sucesso, alerta, erro, informação.
- **Tipografia:** Manrope (interface) e Newsreader (títulos), em `public/assets/fonts/` (OFL, peso variável, sem requisição a terceiros).
- **Forma:** raios 6 / 10 / 16 / 24 px e pílula; sombras quentes xs–lg.
- **Componentes:** botões (primário, escuro, secundário, fantasma, perigo; tamanhos sm/lg; bloco; ícone), selos, cartões, campos com select estilizado, − + de quantidade, lista de escolhas, alertas com ícone, trilha, paginação, estado vazio, esqueleto, etapas, linha do tempo, abas, tabela de especificações.
- **Ícones:** `partials/icon` (traço 1,8, 24×24, sempre `aria-hidden`; o texto acessível fica no elemento).
- **Estados:** vazio (carrinho, favoritos, busca, filtros, pedidos), erro por campo, esgotado, sob encomenda, pronta entrega, carregando (`.skeleton`, `.is-loading`).

## 3. Loja

- **Cabeçalho fixo:** marca, busca, favoritos (com contador), conta, carrinho (com contador). No computador, menu de categorias com painel suspenso (subcategorias e foto; abre também pelo teclado) e atalhos Novidades e Ofertas. No celular, gaveta (`<details>`, funciona sem JavaScript).
- **Cartão de produto:** foto, selos (−%, Novo, Esgotado), coração, categoria, nome, prazo ou "Pronta entrega", "Personalizável", preço e ação: *Adicionar* quando não há escolha a fazer; *Escolher opções* ou *Personalizar* quando há.
- **Filtros rápidos:** `?oferta=1`, `?pronta=1`, `?personalizavel=1`, `?destaque=1` (lista branca em `CatalogRepository::FLAGS`). Listagem filtrada leva `noindex`.
- **Favoritos:** `POST /favoritos/{id}` alterna; sem conta, leva ao login e volta à página. Lista em `/conta/favoritos`.
- **Conta:** menu lateral (Resumo, Meus pedidos, Favoritos, Sair).
- **Personalização continua controlada:** só as opções que o ateliê cadastrou; o preço final aparece antes de comprar.
- **Sem JavaScript tudo funciona.** O `store.js` só melhora: − +, compartilhar (menu do celular ou copiar link), filtros sempre abertos no computador, troca de variação sem recarregar, frete ao sair do CEP.

## 4. Dados de demonstração

Tudo o que a demonstração cria fica registrado em `demo_records` e sai inteiro depois.

| | Quantidade |
|---|---|
| Categorias | 7 principais, 25 subcategorias |
| Produtos | 34 com duas ilustrações cada (geradas por `ProductIllustrator`), variações, estoque, ficha de produção e personalização; 1 inativo e 2 esgotados de propósito |
| Matérias-primas | 12 (uma abaixo do mínimo e uma zerada, para os alertas) |
| Clientes | 16 (11 com conta; senha `cliente-demo-123`) |
| Pedidos | 29, em todas as etapas: aguardando pagamento, pago, corte, lixamento, pintura, controle de qualidade, embalagem, pronto, enviado, entregue, cancelado e estornado |
| Cupons | `BEMVINDO10` e `FRETEGRATIS` |

- **Instalar:** opção "Instalar dados de demonstração" no assistente de instalação (permitida também em produção) ou `php bin/demo.php instalar`. Nenhum e-mail é enviado.
- **Remover:** Painel → Sistema → "Remover dados de demonstração" (proprietário, digitando REMOVER; um backup do banco é feito antes) ou `php bin/demo.php remover`. Produto de demonstração que entrou num pedido real é desativado em vez de apagado; cliente ou cupom com pedido real fica.

## 5. Painel

- **Menu por área:** Visão geral · Vendas (Pedidos, Clientes, Cupons) · Produção (Fila de produção, Expedição, Fichas de produção, Matérias-primas) · Catálogo (Produtos, Categorias) · Administração (Configurações, Usuários, Auditoria, Sistema). Cada papel só vê os módulos que pode abrir; área vazia some. No celular o menu vira gaveta.
- **Trilha de navegação** na barra superior: Painel › área › módulo › página. Sai do menu e do título; uma tela pode acrescentar passos com `$breadcrumbs`.
- **Visão geral** (`DashboardService`), com período de 7, 30 ou 90 dias ou o mês corrente, comparado com o período anterior de mesma duração:
  - gestão e proprietário: faturamento, pedidos pagos, ticket médio, novos clientes, vendas por dia (gráfico de colunas), vendas por categoria e mais vendidos;
  - quem cuida de pedidos: pedidos por etapa (cada etapa abre a lista filtrada) e últimos pedidos;
  - gestão e produção: "Precisa de atenção" (matéria-prima no ponto de reposição, pronta entrega zerada, pagamento parado há mais de um dia, produto sem foto);
  - proprietário: atividade da equipe.
- Venda conta pela data do pagamento, no fuso da loja; pedido cancelado não conta.
- Os gráficos são SVG desenhado no servidor (sem biblioteca e sem JavaScript); os rótulos ficam em HTML para continuarem legíveis no celular.
- A demonstração não mexe no estoque de matéria-prima que a loja já tinha e deixa as suas no nível planejado.

## 6. Catálogo e clientes no painel

- **Produto em abas:** Geral · Comercial · Estoque e envio · Variações · Imagens · Personalização · Produção · SEO.
  - Geral, Comercial, Estoque e envio e SEO são um único formulário: a troca de aba não recarrega e "Salvar" grava tudo. Sem JavaScript as seções aparecem uma embaixo da outra; com erro de validação, abre a aba do primeiro campo com erro.
  - As demais abas são as telas que já existiam.
- **Campos novos (migration 006):**
  - custo unitário por variação, com a margem calculada ao vivo e também na lista de produtos (em alerta abaixo de 20%). O custo nunca aparece na loja;
  - prazo de postagem;
  - montagem e cuidados, exibidos na ficha da loja;
  - palavras-chave, usadas pela busca da loja, e prévia do resultado no Google.
- **Duplicar produto:**
  - copia o cadastro, as variações com opções, a personalização e as fichas de produção;
  - a cópia nasce inativa, com estoque zerado, SKUs `-C`, `-C2`… e endereço novo;
  - fotos, arquivos de produção e imagens das opções não são copiados: um arquivo compartilhado sumiria dos dois produtos quando um deles fosse apagado.
- **Categorias em árvore:** cada principal num bloco com foto, total de produtos (somando as filhas), situação, "+ Subcategoria" (já chega com a mãe escolhida) e as subcategorias dentro.
- **Ficha do cliente:**
  - ticket médio, preferências de contato e favoritos na loja;
  - observações da equipe (migration 007, `customers.notes`), editadas por gestão e atendimento e registradas na auditoria. Entram na exportação LGPD e são apagadas na anonimização.

## 7. Operação: pedidos, produção, estoque, expedição e relatórios

- **Pedidos:**
  - a lista alterna entre tabela e cartões (a borda colorida indica a fase, e o prazo vencido aparece em vermelho);
  - o detalhe mostra o andamento completo, com todas as etapas internas; o cliente vê só os cinco marcos.
- **Produção:**
  - além dos cartões, um quadro com uma coluna por etapa (fila, CNC, lixamento, pintura, secagem, montagem, controle de qualidade, embalagem);
  - cada ordem tem a versão para imprimir (`/admin/producao/{id}/imprimir`), com a ficha, as etapas para marcar, espaço para o tempo real e a personalização em destaque para conferir antes de gravar.
- **Estoque** (`/admin/estoque`, gestão e produção):
  - produto acabado de pronta entrega e matéria-prima numa tela, com o que está em falta primeiro e o valor em estoque pelo custo;
  - o acerto de contagem é feito na própria linha (quantidade física, mínimo e motivo). Não aceita ficar abaixo do que está reservado em pedidos e registra movimentação e auditoria.
- **Expedição:** faixa com o caminho do pedido (ainda na produção → prontos → em trânsito → entregues) e os últimos entregues.
- **Relatórios** (`/admin/relatorios`, gestão e proprietário):
  - período por atalho (7, 30 ou 90 dias, este mês, mês anterior, este ano) ou por datas, até 3 anos;
  - abas: vendas (dia a dia; agrupado por mês acima de 92 dias), produtos (com custo e margem), categorias, clientes e produção (peças concluídas, % no prazo, tempo médio do pagamento ao pronto, retrabalho);
  - "Baixar CSV" gera o arquivo com `;` e acento correto para o Excel. Texto que começa com `=`, `+`, `-` ou `@` recebe um apóstrofo para não virar fórmula. A exportação fica na auditoria.

## 8. Revisão final: acessibilidade e celular

- **Auditoria automática** do HTML de 50 telas (loja, conta do cliente e painel). Verifica:
  - idioma e `<main>`;
  - um único h1 e títulos sem pular nível;
  - imagem com `alt`, campo com rótulo, botão e link com nome;
  - id único e nenhum `style=""`.

  Ficou como teste permanente: `AccessibilityTest`, com 30 telas.
- **Contraste (WCAG AA, 4,5:1 para texto):**
  - o tom "sutil" (`--color-text-subtle`) passava só 2,4:1 sobre o fundo papel. Agora usa o mesmo cinza do texto secundário (5,15:1); o cinza claro ficou só para bordas e separadores;
  - o terracota `#C4532D` (4,1:1) não é usado como texto, só em botões (texto branco, 4,54:1), ícones e bordas (mínimo 3:1). Texto de destaque usa o `kerf-escuro` (5,44:1).
- **Teclado:** foco visível em tudo; menu de categorias abre pelo teclado; Esc fecha as gavetas da loja e do painel e devolve o foco ao botão.
- **Celular (390 px):** estoque vira cartões, preço do frete não quebra, as quatro etapas da compra cabem na tela e o exemplo da busca ficou curto.

## 9. Aplicativo no celular, faixa de categorias e cache

- **Faixa de categorias no celular:** abaixo de 1024 px, logo abaixo da busca e presa no topo com o cabeçalho, uma
  faixa rolável para o lado com *Todos*, as categorias principais, *Ofertas* e *Novidades*. Nas páginas de uma
  subcategoria, marca a principal. A seção "Explore por categoria" da home fica oculta no celular.
- **"Salvar no celular"** (aplicativo web), para a loja e o painel, com ícones próprios em `public/assets/icons/`
  (loja: G escuro sobre papel; painel: G claro sobre grafite; versões *maskable* e para iPhone):
  - `PwaController`: `/manifest.webmanifest` (loja), `/admin/manifest.webmanifest` (painel, público), `/sw.js` e
    `/offline`, todas sem sessão (`middleware.stateless`);
  - o service worker guarda só o que é igual para todos: os CSS e JS versionados (cache primeiro) e as fotos de
    `/uploads` (mostra a guardada e atualiza em segundo plano). **Páginas nunca são guardadas**: trazem o nome do
    cliente, favoritos e tokens de formulário. Sem rede, aparece a página offline;
  - o botão **Instalar** só aparece quando o navegador oferece a instalação (`beforeinstallprompt`); no iPhone aparece
    a instrução Compartilhar → Tela de Início; tudo some quando o app já está instalado.
- **Cache de CSS e JS:** `asset()` acrescenta `?v=<data do arquivo>`. O servidor manda o navegador guardar esses
  arquivos por um mês; sem a versão, quem já tinha visitado a loja via o CSS antigo com o HTML novo depois de uma
  atualização (o layout "desconfigurado" em um navegador e normal em outro).
- O endereço atual (item de menu marcado) vem do Kernel (`currentPath`, `currentQuery`), não de `$_SERVER` nos
  templates.

## 10. Correções encontradas ao produzir o manual do usuário (27/09/2026)

As telas do [manual do usuário](20-manual-do-usuario.md) foram fotografadas uma a uma, e a revisão encontrou:

- **Menu ☰ cortado no celular (loja e painel):** `backdrop-filter` no `.site-header` e na `.admin-topbar` faz o
  cabeçalho virar o bloco de referência dos filhos `position: fixed` (Chrome e Safari). A gaveta ficava presa à
  altura do cabeçalho. O desfoque passou para um `::before` atrás do conteúdo.
- **Texto da página do pedido:** a situação vinha sempre com "Pagamento confirmado. Seu pedido entrou na fila de
  produção…", mesmo para pedidos enviados ou entregues. Agora o texto acompanha a situação (produção, preparando o
  envio, enviado com ou sem rastreio, entregue). Teste em `OrderManagementTest`.
- **Usuários → "O que cada papel pode fazer":** ainda dizia "nas próximas etapas". Reescrito com as permissões reais.
- **Configurações → Importar configuração:** o campo de arquivo estava sem o estilo dos formulários.
- **Variações:** o aviso citava uma "aba Dados" que não existe (agora: abas Comercial e Estoque e envio).
- **Dados de demonstração:** a produção terminava semanas depois do pagamento (relatório de produção com "No
  prazo 0%" e "Tempo médio 27 dias"). Agora leva de 1 a 2,2 vezes o prazo prometido, sem passar do envio.

## 11. Testes

`StorefrontRedesignTest` (favoritos, filtros rápidos, remoção pelo painel), `AdminDashboardTest` (indicadores por papel, períodos, comparação, trilha), `AdminCatalogTest` (abas do produto, custo e margem, duplicação, árvore de categorias, observações do cliente), `AdminOperationsTest` (acerto de estoque, quadro, ordem impressa, pedidos em cartões, relatórios, CSV seguro, períodos), `AccessibilityTest` (regras de acessibilidade em 30 telas), `PwaTest` (manifestos, ícones, service worker, página
offline, faixa de categorias) e `InstallerTest` (instalação com demonstração, volume mínimo, alertas planejados, remoção sem sobras, fotos apagadas do disco).
