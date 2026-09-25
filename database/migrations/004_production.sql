-- ============================================================================
-- G-Nesting — Migration 004: fila de produção e consumo de material (etapa 9)
--
-- production_jobs: uma ordem de produção por item do pedido (as unidades de uma linha
--   são feitas juntas). route = etapas do job, congeladas na criação a partir da ficha
--   (CSV na ordem canônica). stage: queued → etapas da rota → done.
-- production_job_events: cada passagem de etapa (quem, quando, retrabalho, observação);
--   é daqui que saem os tempos reais.
-- material_movements: razão do estoque de matéria-prima (entrada manual, consumo no CNC).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE production_jobs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            BIGINT UNSIGNED NOT NULL,
    order_item_id       BIGINT UNSIGNED NOT NULL,
    variant_id          BIGINT UNSIGNED NOT NULL,
    quantity            SMALLINT UNSIGNED NOT NULL,
    route               VARCHAR(120)    NOT NULL,             -- ex.: cnc,sanding,painting,drying,assembly,quality,packaging
    stage               VARCHAR(20)     NOT NULL DEFAULT 'queued',
    operator_user_id    BIGINT UNSIGNED NULL,
    estimated_minutes   INT UNSIGNED    NULL,                 -- total da linha (snapshot da ficha × quantidade)
    rework_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    stage_started_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at          DATETIME        NULL,                 -- saiu da fila
    finished_at         DATETIME        NULL,                 -- chegou a "done"
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_production_jobs_item (order_item_id),
    KEY idx_production_jobs_stage (stage),
    KEY idx_production_jobs_order (order_id),
    CONSTRAINT fk_pjobs_order    FOREIGN KEY (order_id)         REFERENCES orders (id)           ON DELETE CASCADE,
    CONSTRAINT fk_pjobs_item     FOREIGN KEY (order_item_id)    REFERENCES order_items (id)      ON DELETE CASCADE,
    CONSTRAINT fk_pjobs_variant  FOREIGN KEY (variant_id)       REFERENCES product_variants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_pjobs_operator FOREIGN KEY (operator_user_id) REFERENCES users (id)            ON DELETE SET NULL,
    CONSTRAINT chk_pjobs_stage CHECK (stage IN ('queued','cnc','sanding','painting','drying','assembly','quality','packaging','done','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE production_job_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id      BIGINT UNSIGNED NOT NULL,
    from_stage  VARCHAR(20)     NULL,
    to_stage    VARCHAR(20)     NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    is_rework   TINYINT(1)      NOT NULL DEFAULT 0,
    note        VARCHAR(500)    NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pjob_events_job (job_id, created_at),
    CONSTRAINT fk_pjob_events_job  FOREIGN KEY (job_id)  REFERENCES production_jobs (id) ON DELETE CASCADE,
    CONSTRAINT fk_pjob_events_user FOREIGN KEY (user_id) REFERENCES users (id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE material_movements (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    material_id     BIGINT UNSIGNED NOT NULL,
    quantity        DECIMAL(10,2)   NOT NULL,                 -- com sinal: entrada +, consumo −
    reason          VARCHAR(200)    NOT NULL,
    reference_type  VARCHAR(30)     NULL,                     -- production_job
    reference_id    BIGINT UNSIGNED NULL,
    user_id         BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_material_movements (material_id, created_at),
    CONSTRAINT fk_mmov_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT fk_mmov_user     FOREIGN KEY (user_id)     REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
