# 06 — Identidade visual

**G-Nesting**
*Objetos que transformam espaços.*

Arquivos: [`public/assets/css/tokens.css`](../public/assets/css/tokens.css) · [`logo.svg`](../public/assets/img/logo.svg) · [`logo-mark.svg`](../public/assets/img/logo-mark.svg) · prévia: [`identidade-visual-preview.html`](identidade-visual-preview.html)

## 1. Posicionamento

A G-Nesting é uma **marca de objetos e design** que usa fabricação digital como meio, não como vitrine. O cliente compra um relógio, um painel, um organizador: um objeto bonito e bem-acabado. A precisão CNC aparece como **qualidade percebida** (encaixes perfeitos, bordas limpas, repetibilidade), não como estética de máquina.

| Queremos parecer | Não queremos parecer |
|---|---|
| estúdio de design contemporâneo | oficina ou loja de máquinas CNC |
| preciso, calmo, bem-acabado | industrial, técnico, frio |
| artesanal com tecnologia | marketplace genérico, promoção gritante |

## 2. Conceito

- **Nesting**: a disposição eficiente de peças numa chapa. Vira linguagem visual de **composição**: grids que se encaixam, blocos de imagem de tamanhos diferentes que "fecham" o layout sem sobras, como peças numa chapa.
- **Kerf** (a largura do corte da fresa): uma **linha fina** que separa blocos e um **ponto de acento** em terracota, o ponto de entrada da ferramenta. É o único elemento "técnico" e é usado com parcimônia.
- **G** (de G-code): o símbolo é um "G" desenhado como **percurso de ferramenta** dentro de uma chapa: traço contínuo e cantos retos, com o ponto de entrada da fresa em terracota.

## 3. Logotipo

- **Símbolo** (`logo-mark.svg`): chapa + G em percurso + ponto kerf. Uso: favicon, avatar, selo na embalagem, marca d'água em fotos.
- **Assinatura** (`logo.svg`): símbolo + "G-Nesting" em Manrope Bold.
- **Com slogan:** "Objetos que transformam espaços." em Newsreader itálico, abaixo ou ao lado da assinatura.
- Área de proteção: altura do ponto kerf × 2 em volta. Tamanho mínimo do símbolo: 20 px.
- Versões: grafite sobre papel (principal); papel sobre grafite; monocromática grafite (gravação a laser/CNC na própria peça).
- A versão atual é **provisória** (etapa 1), suficiente para desenvolver a interface. Pode ser refinada por um designer sem alterar tokens.

## 4. Cores

| Token | Hex | Uso | Contraste |
|---|---|---|---|
| Grafite `--gn-grafite` | `#1F1E1C` | texto, logotipo, botões secundários | 14.9:1 sobre papel |
| Papel `--gn-papel` | `#F5F2EC` | fundo principal (lembra papel/MDF claro) | — |
| Carvalho `--gn-carvalho` | `#B98A5E` | madeira: ilustrações, fundos, detalhes | 2.7:1: **nunca para texto** |
| Carvalho claro | `#E8DCCB` | cards de categoria, faixas de destaque | — |
| Kerf `--gn-kerf` | `#C4532D` | **CTA principal** (texto branco), ponto do logo, preço promocional | branco sobre kerf 4.5:1 |
| Kerf escuro | `#A8431F` | links e acento **em texto** sobre papel | 5.4:1 |
| Cinza 600 | `#6B665E` | texto secundário | 5.1:1 |

Proporção de uso: **70% papel/branco · 20% grafite · 8% carvalho · 2% kerf**. O kerf aparece em no máximo um elemento de ação por tela, para chamar atenção.

## 5. Tipografia

| Papel | Fonte | Por quê |
|---|---|---|
| Títulos, hero, nomes de coleção | **Newsreader** (serifa contemporânea, 400–600, com itálico) | sofisticação, calor, "objeto de design" |
| Interface, texto, preço, botões | **Manrope** (sans geométrica humanista, 400–700) | precisão e legibilidade no mobile |
| SKU e códigos (admin) | monoespaçada do sistema | alinhamento de códigos |

- Fontes **auto-hospedadas** em `public/assets/fonts` (WOFF2, subset latin), sem requisição ao Google em produção (desempenho e LGPD).
- Preço: Manrope semibold com algarismos tabulares (`font-variant-numeric: tabular-nums`).
- Rótulos pequenos em caixa alta com `--tracking-label`.

## 6. Grid, espaço e forma

- Mobile-first. Breakpoints: 480 / 768 / 1024 / 1280 px. Margem lateral 16 → 24 → 32 px.
- Espaçamento em base 8 (`--space-*`).
- **Cantos quase retos** (`2–4px`): precisão de corte. Nada de cantos muito arredondados ou "bolhas".
- Linhas finas (`1px --color-line`) separam blocos, como o kerf. Sombras discretas, só em elementos flutuantes.
- Grids de produto "encaixados": na home, blocos de 1×1, 2×1 e 1×2 compõem a vitrine como peças numa chapa (nesting), sem espaço morto.

## 7. Fotografia

- Produto **em ambiente real** (parede, mesa, cozinha): é o "transformar espaços".
- Luz natural lateral, que evidencia textura da madeira e profundidade do recorte.
- Uma foto **macro** por produto mostrando borda/encaixe (a precisão como detalhe de qualidade).
- Fundo neutro papel/carvalho claro para fotos de catálogo; proporção 4:5 no card, 1:1 na galeria.
- Evitar fotos de máquina na vitrine. A página "Como fazemos" pode mostrar o processo com estética editorial.

## 8. Tom de voz

- Frases curtas, concretas, sem jargão técnico na loja ("recortado com precisão" em vez de "usinado com fresa de 1/8").
- Ficha técnica pública com **medidas, material e acabamento**; tempos e ferramentas ficam na ficha interna.
- Exemplos: "Feito para durar e para encaixar no seu espaço." · "Produzido sob pedido em até 3 dias úteis." · "Personalize com um nome: até 20 caracteres."

## 9. Componentes-base (a construir na etapa 4)

Botão primário (kerf, texto branco) · botão secundário (contorno grafite) · card de produto (imagem 4:5, nome, preço, selo "Personalizável") · selo de prazo de produção · campo de personalização com contador de caracteres e prévia · faixa de benefícios · header com busca e carrinho · rodapé com WhatsApp.

## 10. Acessibilidade

- Contraste mínimo AA (4.5:1 texto, 3:1 elementos gráficos) já garantido pelos tokens.
- Foco visível: contorno de 2 px `--color-focus` com deslocamento.
- Alvos de toque ≥ 44 px no mobile.
- `prefers-reduced-motion` respeitado nos tokens.
