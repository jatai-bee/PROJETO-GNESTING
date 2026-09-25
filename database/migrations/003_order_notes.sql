-- ============================================================================
-- G-Nesting — Migration 003: notas e mensagens do pedido (etapa 8)
--
-- visibility = internal : anotação da equipe (nunca aparece para o cliente)
-- visibility = customer : mensagem ao cliente (enviada por e-mail e exibida na página do pedido)
-- user_id NULL = registrada pelo sistema (ex.: pagamento com valor divergente).
-- Substitui o campo livre orders.admin_notes (mantido só por compatibilidade).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE order_notes (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id     BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NULL,
    visibility   VARCHAR(20)     NOT NULL,
    body         TEXT            NOT NULL,
    emailed_at   DATETIME        NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_notes_order (order_id, created_at),
    CONSTRAINT fk_order_notes_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_order_notes_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE SET NULL,
    CONSTRAINT chk_order_notes_visibility CHECK (visibility IN ('internal', 'customer'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notas de sistema gravadas na etapa 7 em orders.admin_notes
INSERT INTO order_notes (order_id, visibility, body)
SELECT id, 'internal', admin_notes FROM orders WHERE admin_notes IS NOT NULL AND admin_notes <> '';
