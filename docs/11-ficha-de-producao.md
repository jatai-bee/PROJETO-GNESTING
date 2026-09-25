# 11 — Ficha de produção (etapa 6)

A ficha de produção é o documento **interno** que diz como fabricar cada variação: material, corte,
programa CNC, etapas com tempo estimado e arquivos. Nada daqui aparece na loja.

Acesso: papéis **manager** e **production** (o proprietário acessa tudo). O papel produção passa a ter
no menu apenas **Fichas de produção** e **Materiais**, e no produto vê só a aba **Ficha de produção**.

## 1. Telas

| URL | Conteúdo |
|---|---|
| `/admin/fichas` | todas as variações com material, tempo total, nº de arquivos e situação (completa, incompleta com o que falta, sem ficha); busca por produto ou SKU |
| `/admin/produtos/{id}/ficha-producao` | abre a ficha da variação padrão |
| `/admin/produtos/{id}/ficha-producao/{variante}` | ficha da variação: resumo de tempos e custo, formulário, cópia e arquivos |
| `/admin/materiais` | matéria-prima |
| `/admin/arquivos-producao/{id}` | download autenticado de um arquivo |

## 2. Conteúdo da ficha (uma por variação)

- **Material e corte**: material (só ativos podem ser escolhidos; um material desativado continua na ficha que já o usa), espessura (vazia = a do material), largura/altura de corte, peças por chapa, aproveitamento da chapa (0–100%), referência do programa CNC.
- **Etapas e tempos**, por peça: etapa (CNC, lixamento, pintura/acabamento, secagem, montagem, controle de qualidade, embalagem), descrição, ferramenta, nº de operações, minutos e **passiva**.
  - *Passiva* = não ocupa operador (secagem, cura). O resumo mostra tempo total, tempo de operador e tempo passivo — base para a capacidade produtiva (etapa 9).
  - O formulário traz 3 linhas em branco para novas etapas; linhas vazias são ignoradas. Para reordenar, altere a "posição"; para apagar, marque "remover". Até 30 etapas.
  - Ficha e etapas são salvas juntas: se algo estiver inválido, nada é gravado e o formulário volta com o que foi digitado.
- **Acabamento e observações**: instruções de acabamento e observações internas (componentes, montagem, cuidados).
- **Custo de material por peça** = custo da chapa ÷ peças por chapa (quando o material é vendido por chapa e ambos estão preenchidos).
- **Copiar de outra variação**: copia material, medidas, observações e etapas (arquivos não — cada variação tem os seus).

**Ficha completa** quando tem material, ao menos uma etapa com tempo, e programa CNC (referência preenchida **ou** arquivo do tipo "Programa CNC").

Toda alteração é auditada (`production_spec`: antes/depois, com resumo das etapas), assim como envio e exclusão de arquivos e alterações de material (custo como `price_change`, saldo como `stock_change`).

## 3. Arquivos de produção

- Tipos: programa CNC, projeto, desenho técnico, outro.
- Extensões aceitas: `nc`, `tap`, `gcode`, `dxf`, `svg`, `pdf`, `crv`, `crv3d`, `zip`. Tamanho máximo: `UPLOAD_MAX_PRODUCTION_FILE_MB` (padrão 20 MB) — o `upload_max_filesize`/`post_max_size` do servidor precisam acompanhar.
- Gravados em `storage/private/production_files/{ficha}/` com **nome aleatório** (o nome original só é exibido), fora da pasta pública.
- **SHA-256** registrado e exibido (conferência de integridade antes de rodar na máquina).
- Enviar um arquivo com o **mesmo nome** cria uma nova **versão** (v2, v3…); as anteriores continuam disponíveis até serem excluídas.
- Download só pelo painel, sempre como anexo (`application/octet-stream`), com o nome `arquivo-vN.ext`. Um SVG ou PDF nunca é aberto no navegador dentro do painel.
- O arquivo só é servido se o caminho real estiver dentro da pasta privada (proteção contra caminhos manipulados).

## 4. Materiais

Código único (guardado em maiúsculas), nome, espessura, unidade (chapa, m², unidade), medidas da chapa, custo, saldo e estoque mínimo
(a lista marca **Repor** quando o saldo chega ao mínimo). Material usado por alguma ficha não pode ser excluído — desative-o.

> Movimentação de chapas (entrada manual e consumo automático ao sair do CNC): ver [14 — Produção](14-producao.md) §4.

## 5. Onde está no código

| Responsabilidade | Arquivo |
|---|---|
| Ficha e etapas, cópia, completude, custo por peça | `app/Services/ProductionSpecService.php` |
| Arquivos privados | `app/Services/ProductionFileService.php` (+ `Response::download`) |
| Materiais | `app/Services/MaterialService.php` |
| Consultas | `app/Repositories/ProductionSpecRepository.php`, `MaterialRepository.php` |
| Painel | `app/Controllers/Admin/ProductionSpecController.php`, `MaterialController.php`, views em `app/Views/admin/production/` e `materials/` |

`ProductionSpecRepository::minutes($variantId)` devolve o tempo por unidade — é o valor que o checkout (etapa 7) vai congelar em `order_items.production_minutes_estimate`.

## 6. Testes

`tests/Integration/ProductionSpecTest.php`: tempos do seed (total/operador/passivo), completude, custo por peça, gravação com linhas em branco/remoção/ordem, validação por campo e transação, material inativo, cópia entre variações, materiais (código único, auditoria, exclusão bloqueada), arquivos (nome aleatório, checksum, versões, lista branca, tamanho, arquivo vazio, exclusão), papéis (produção vê só fichas/materiais; suporte e visitante bloqueados), ida e volta do formulário, download com cabeçalhos corretos e garantia de que a loja não exibe dados de produção.
Helpers novos (`parse_decimal`, `format_decimal`, `format_minutes`) em `tests/Unit/HelpersTest.php`.
