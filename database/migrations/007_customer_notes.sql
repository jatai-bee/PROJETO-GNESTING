-- ============================================================================
-- 007 — Observações internas na ficha do cliente (etapa 14, fase 3)
--
-- customers.notes: anotações da equipe ("prefere WhatsApp", "compra para revenda").
--           Nunca aparecem para o cliente; entram na exportação LGPD e são apagadas
--           na anonimização.
--
-- Compatível com MySQL 5.7.8+ e 8.x.
-- ============================================================================

ALTER TABLE customers ADD COLUMN notes TEXT NULL AFTER marketing_opt_in;
