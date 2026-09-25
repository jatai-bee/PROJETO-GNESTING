-- ============================================================================
-- G-Nesting — Migration 005: direitos do titular — LGPD (etapa 11)
--
-- anonymized_at: quando o cadastro foi anonimizado a pedido do titular.
-- Os pedidos continuam ligados ao cadastro (obrigação fiscal); os mais antigos
-- que o prazo de guarda têm os dados pessoais apagados na mesma operação.
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE customers
    ADD COLUMN anonymized_at DATETIME NULL AFTER marketing_opt_in;
