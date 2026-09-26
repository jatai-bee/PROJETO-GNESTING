# 08 — Painel administrativo (Etapa 3)

Guia de uso do painel em `/admin` e das regras que ele aplica.

## 1. Quem acessa o quê

| Papel | Painel | Produtos, categorias, imagens | Usuários do painel | Auditoria |
|---|---|---|---|---|
| Proprietário | ✔ | ✔ | ✔ | ✔ |
| Gestor | ✔ | ✔ | — | — |
| Produção | ✔ | — (ficha de produção na etapa 6) | — | — |
| Atendimento | ✔ | — (pedidos e clientes na etapa 8) | — | — |

O proprietário também vê **Sistema** (`/admin/sistema`): saúde da loja, lista de verificação de produção, backups (fazer e baixar), modo manutenção e último cron. Guia: [17 — Deploy e operação](17-deploy-e-operacao.md).

O menu mostra só o que o papel pode usar. Acessos diretos por URL fora da permissão retornam "Acesso negado" (403).

## 2. Cadastrar um produto (passo a passo)

1. **Produtos → Novo produto.** Preencha nome, categoria, SKU, preço e prazo de produção. Material e medidas aparecem na loja; as medidas de embalagem serão usadas no frete (etapa 7).
2. Ao salvar, o produto é criado **inativo** e você vai direto para **Imagens**.
3. **Envie as fotos:** JPG, PNG ou WebP, até 5 MB cada, com o menor lado de pelo menos 500 px (ideal: 1600 px ou mais). A primeira vira a capa.
4. Ajuste a **descrição de cada imagem** (texto alternativo), a capa e a ordem.
5. Clique em **Ativar produto.** A ativação exige pelo menos uma imagem, preço e categoria ativa.

### Regras importantes

- **SKU** é único e permanente: não pode ser reutilizado nem depois que o produto é excluído, porque os pedidos antigos dependem dele. Letras são convertidas para maiúsculas.
- **Preço** em reais ("129,90"). O preço "de" (promoção) precisa ser maior que o preço de venda.
- **Endereço (slug):** gerado automaticamente a partir do nome na criação. Na edição, campo vazio **mantém** o endereço atual. Alterar o endereço quebra links já divulgados.
- **Estoque:**
  - "Produzido sob pedido" é o padrão da G-Nesting: a quantidade física não limita a venda.
  - "Pronta entrega" controla quantidade. Cada ajuste gera uma movimentação e um registro de auditoria.
- **Excluir** tira o produto da loja e do painel, mas preserva o histórico. Prefira **Desativar** quando for temporário.
- Variações (cores, acabamentos, tamanhos) e personalização ficam nas abas **Variações** e **Personalização** do produto — guia em [10 — Variações e personalização](10-variacoes-e-personalizacao.md). A ficha de produção fica na aba **Ficha de produção** e no menu **Fichas de produção** / **Materiais** — guia em [11 — Ficha de produção](11-ficha-de-producao.md).

## 3. Imagens: o que o sistema faz

- Confere o **conteúdo real** do arquivo. Um arquivo renomeado para `.jpg` é recusado.
- **Regrava** a imagem: remove metadados (GPS, modelo da câmera) e qualquer conteúdo escondido.
- Gera 3 tamanhos em WebP (400, 800 e 1600 px) para a loja carregar rápido no celular. Imagens menores não são ampliadas.
- Limite de 12 imagens por produto. Arquivos inválidos são listados sem impedir o envio dos demais.

## 4. Categorias

- Até **dois níveis**: categoria principal → subcategoria.
- Não é possível excluir uma categoria com produtos ou subcategorias: mova-os antes.
- Uma categoria inativa impede a ativação de produtos dela.

## 5. Usuários do painel (somente proprietário)

- Crie cada pessoa com o papel adequado e uma senha inicial de pelo menos 12 caracteres. Envie a senha por um canal seguro.
- Para bloquear um acesso, desmarque **Acesso ativo**. O bloqueio vale na hora e o histórico é preservado.
- Proteções:
  - ninguém altera o próprio papel nem desativa a própria conta;
  - sempre existe pelo menos um proprietário ativo.

## 6. Auditoria (somente proprietário)

**Auditoria** lista quem fez o quê, quando e de qual IP, com os valores de antes e depois. O sistema registra:
- login e logout;
- criação, alteração e exclusão;
- alteração de preço e de estoque;
- ativação e desativação.

Senhas nunca aparecem, nem mascaradas.

## 7. Notas técnicas

| Item | Onde |
|---|---|
| Rotas e papéis | `routes/admin.php` (grupos `role:manager` e `role:owner`) |
| Regras de negócio | `app/Services/{CategoryService,ProductService,ProductImageService,AdminUserService}.php` |
| Imagens | `app/Services/ImageProcessor.php`: GD, WebP 82%, fallback JPEG; limites em `config/uploads.php` |
| Componentes de formulário | `app/Views/partials/{field,textarea,select,checkbox,pagination}.php` |
| Confirmação e envio duplo | `public/assets/js/admin.js` (o painel funciona sem JavaScript) |
| Auditoria só do que mudou | `AuditService::recordChanges()` |

- Formulários usam a mesma URL no GET e no POST. Assim, erros de validação voltam ao formulário com tudo que foi digitado (menos senhas).
- Um envio acima do `post_max_size` do servidor mostra uma mensagem clara, em vez de "sessão expirada".
- Com `APP_ENV=testing`, todos os logs vão para a pasta temporária do sistema.
