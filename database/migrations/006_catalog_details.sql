-- ============================================================================
-- 006 — Ficha do produto completa e dados de demonstração (etapa 14)
--
-- products: palavras-chave (busca e SEO), cuidados, montagem e prazo de expedição
--           (dias úteis entre ficar pronto e sair para entrega), exibidos na ficha da loja.
-- product_variants: custo (margem no painel; nunca aparece na loja).
-- demo_records: o que o carregador de dados de demonstração criou, para o botão
--           "Remover dados de demonstração" apagar exatamente isso e nada mais.
--
-- Compatível com MySQL 5.7.8+ e 8.x.
-- ============================================================================

ALTER TABLE products
    ADD COLUMN dispatch_days      TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER production_lead_days,
    ADD COLUMN keywords           VARCHAR(255)     NULL AFTER highlights,
    ADD COLUMN care_instructions  TEXT             NULL AFTER keywords,
    ADD COLUMN assembly_info      TEXT             NULL AFTER care_instructions;

ALTER TABLE product_variants
    ADD COLUMN cost_cents INT UNSIGNED NULL AFTER compare_at_price_cents;

CREATE TABLE demo_records (
    entity     VARCHAR(40)     NOT NULL,   -- nome da tabela (products, customers, orders…)
    entity_id  BIGINT UNSIGNED NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
