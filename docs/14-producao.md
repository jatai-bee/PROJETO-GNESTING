# 14 — Fila de produção e expedição (etapa 9)

Acesso: **gestor** e **produção** (o proprietário acessa tudo). Menu: **Produção** e **Expedição**.

## 1. Como funciona

1. **Pagamento aprovado** → o pedido vai para "Produção pendente" e cada **item** vira uma **ordem de produção** (job).
   As unidades de uma mesma linha são feitas juntas.
2. A **rota** do job vem da ficha da variação (etapas cadastradas) + **controle de qualidade** e **embalagem**, que são
   sempre obrigatórios, na ordem: CNC → lixamento → pintura/acabamento → secagem → montagem → CQ → embalagem.
   A rota é **congelada** quando o job nasce: mudar a ficha depois não altera o que já está na oficina.
   Sem ficha, a rota é só CQ → embalagem (e a fila avisa com o link "Criar ficha").
3. Na fila, cada cartão tem **um botão**: "Iniciar CNC", "Concluir → Lixamento", "Aprovar → Embalagem"…
   A etapa final leva o job a **Pronto**.
4. O **status do pedido acompanha o job mais atrasado** (docs/03 §6): basta um item ainda no CNC para o pedido estar
   "Em produção". Quando todos os itens ficam prontos, o pedido vai para **Pronto para envio**.
   As etapas de produção **não** são mais botões na página do pedido — lá aparece o resumo dos jobs.
5. **Retrabalho**: no controle de qualidade, "Reprovar…" manda o job de volta para uma etapa anterior da rota, com
   **motivo obrigatório**. Fica contado no cartão (retrabalho ×N) e no histórico do pedido.
6. O cliente recebe **um** e-mail quando a produção começa ("Seu pedido está em produção").

## 2. Telas

- **Produção** (`/admin/producao`): abas por etapa com contagem e "Só os meus". Cada cartão: pedido, quantidade × produto,
  variação, **personalização em destaque**, prazo prometido, previsão, minutos que faltam, responsável. Borda **vermelha** =
  atrasado; **amarela** = em risco. Funciona no celular (botões grandes para uso na oficina).
- **Ordem de produção** (`/admin/producao/{id}`): rota com a etapa atual, personalização em letras grandes ("conferir antes
  de gravar"), a ficha (material, corte, programa CNC, instruções por etapa, arquivos para baixar), **tempos reais** de cada
  etapa (quem, quando, quanto tempo ficou), avançar, assumir e reprovar.
- **Expedição** (`/admin/expedicao`): pedidos prontos com endereço, itens e peso estimado; **romaneio** para imprimir
  (destinatário em destaque, itens com personalização e caixa de conferência); **Despachar** com transportadora, código
  e link de rastreio (o cliente recebe por e-mail); pedidos em trânsito com **Marcar entregue**.

Quem pode enviar/entregar: gestor e produção. Cancelar: só o gestor (página do pedido).

## 3. Prazo, carga e previsão

- **Prazo prometido** = data do pagamento + dias úteis de produção do pedido (maior prazo entre os itens).
- **Minutos que faltam** = minutos de **operador** das etapas que faltam (ficha atual × quantidade). Etapas passivas (secagem)
  não ocupam operador e não entram na carga.
- **Previsão**: a fila é atendida do prazo mais apertado para o mais folgado, com a **capacidade diária** configurada
  (`PRODUCTION_DAILY_MINUTES`, padrão 420 = 7 h de uma pessoa). O dia em que a carga acumulada termina é a previsão.
  Previsão depois do prazo = **em risco**; prazo já vencido = **atrasado**.
- Limites (intencionais para uma oficina pequena): sem feriados, sem máquinas separadas, sem várias pessoas por etapa.
  Se a oficina crescer, entram `workstations` e calendário de capacidade (docs/02 §4) sem mudar a fila.

## 4. Matéria-prima

- Quando um job **sai do CNC**, o material da ficha é consumido: **quantidade ÷ peças por chapa** (centésimos de chapa,
  arredondado para cima). Retrabalho que volta ao CNC consome de novo (é um novo recorte).
- **Materiais → editar → Movimentar saldo**: entrada (compra) ou saída/ajuste, com motivo obrigatório. Todas as
  movimentações ficam no razão `material_movements` (quem, quando, por quê, qual ordem de produção).
- O saldo nunca fica negativo (para em zero); o razão guarda o consumo real para conferência.

## 5. Onde está no código

| Responsabilidade | Arquivo |
|---|---|
| Regras puras (rota, próxima etapa, retrabalho, status derivado) | `app/Services/Production/ProductionFlow.php` |
| Jobs, avançar/retrabalho/assumir, consumo, sincronizar pedido | `app/Services/Production/ProductionService.php` |
| Carga e previsão | `app/Services/Production/ProductionPlanner.php`, `config/production.php` |
| Status do pedido pela fila | `OrderStatusService::syncProduction()` (único ponto que altera o status) |
| Painel | `app/Controllers/Admin/ProductionController.php`, `ShippingDeskController.php`, views em `app/Views/admin/production/` e `shipping/` |
| Banco | `database/migrations/004_production.sql` (`production_jobs`, `production_job_events`, `material_movements`) |

## 6. Testes

- `tests/Unit/ProductionFlowTest.php`: rota canônica com CQ/embalagem obrigatórios, próxima etapa, alvos de retrabalho,
  status derivado do job mais atrasado, textos dos botões.
- `tests/Integration/ProductionQueueTest.php`: um job por item e pedido seguindo o mais lento, regras de retrabalho,
  cancelamento tirando da fila, previsão por prazo com capacidade, atrasados, permissões, backfill de pedidos antigos,
  assumir e "Só os meus", consumo de material e razão, entrada de chapas, romaneio e expedição.
- `tests/Integration/OrderManagementTest.php` (atualizado): fluxo completo pela fila com retrabalho, consumo e expedição pela produção.
