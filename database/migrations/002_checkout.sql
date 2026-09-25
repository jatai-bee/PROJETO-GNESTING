-- ============================================================================
-- G-Nesting — Migration 002: checkout (etapa 7)
--
-- * orders.access_token_hash: link privado do pedido para quem comprou sem conta
--   (/pedido/{numero}?chave=...). Guarda-se só o SHA-256 do token.
-- * payments.checkout_reference / checkout_url: preferência do checkout do provedor
--   (Mercado Pago Checkout Pro), para retomar o pagamento de um pedido pendente.
-- * payments.method passa a aceitar 'checkout' enquanto o cliente não escolheu o meio
--   (o provedor informa pix | credit_card | boleto ao aprovar).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE orders
    ADD COLUMN access_token_hash CHAR(64) NULL AFTER number;

ALTER TABLE payments
    ADD COLUMN checkout_reference VARCHAR(100) NULL AFTER provider_payment_id,
    ADD COLUMN checkout_url       VARCHAR(500) NULL AFTER checkout_reference,
    ADD KEY idx_payments_checkout (provider, checkout_reference);
