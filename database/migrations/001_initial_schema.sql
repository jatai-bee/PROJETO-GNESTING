-- ============================================================================
-- G-Nesting — Objetos que transformam espaços.
-- Migration 001: esquema inicial
--
-- Compatível com MySQL 8.0.16+ e MariaDB 10.6+
-- Convenções (ver docs/02-banco-de-dados.md):
--   * InnoDB, utf8mb4_unicode_ci, PK BIGINT UNSIGNED AUTO_INCREMENT
--   * Valores monetários em CENTAVOS (INT) -> colunas *_cents
--   * Medidas físicas em milímetros (INT) -> colunas *_mm; peso em gramas (*_g)
--   * Datas em UTC (a conexão PDO executa SET time_zone = '+00:00')
--   * Status em VARCHAR validado pelos enums PHP em app/Enums
--   * Catálogo usa exclusão lógica (deleted_at)
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ----------------------------------------------------------------------------
-- Controle de migrations
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration   VARCHAR(190) NOT NULL,
    applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 1. IDENTIDADE E ACESSO
-- ============================================================================

-- Credenciais de acesso (clientes e administradores)
CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email             VARCHAR(190)    NOT NULL,
    password_hash     VARCHAR(255)    NOT NULL,
    type              VARCHAR(20)     NOT NULL,              -- customer | admin
    status            VARCHAR(20)     NOT NULL DEFAULT 'active', -- active | blocked
    email_verified_at DATETIME        NULL,
    last_login_at     DATETIME        NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT chk_users_type   CHECK (type IN ('customer','admin')),
    CONSTRAINT chk_users_status CHECK (status IN ('active','blocked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil administrativo (RBAC por papel)
CREATE TABLE admins (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(120)    NOT NULL,
    role        VARCHAR(20)     NOT NULL,                   -- owner | manager | production | support
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_user (user_id),
    CONSTRAINT fk_admins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_admins_role CHECK (role IN ('owner','manager','production','support'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfil de cliente. user_id NULL = compra como visitante (checkout sem cadastro)
CREATE TABLE customers (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NULL,
    name              VARCHAR(120)    NOT NULL,
    email             VARCHAR(190)    NOT NULL,
    cpf               CHAR(11)        NULL,                 -- somente dígitos
    phone             VARCHAR(20)     NULL,                 -- E.164 sem '+', ex.: 5511999998888
    whatsapp_opt_in   TINYINT(1)      NOT NULL DEFAULT 0,
    marketing_opt_in  TINYINT(1)      NOT NULL DEFAULT 0,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_user (user_id),
    KEY idx_customers_email (email),
    CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id     BIGINT UNSIGNED NOT NULL,
    label           VARCHAR(40)     NULL,                   -- "Casa", "Trabalho"
    recipient_name  VARCHAR(120)    NOT NULL,
    zip_code        CHAR(8)         NOT NULL,               -- somente dígitos
    street          VARCHAR(160)    NOT NULL,
    number          VARCHAR(20)     NOT NULL,
    complement      VARCHAR(80)     NULL,
    district        VARCHAR(80)     NOT NULL,
    city            VARCHAR(80)     NOT NULL,
    state           CHAR(2)         NOT NULL,
    is_default      TINYINT(1)      NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_addresses_customer (customer_id),
    CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de redefinição de senha (armazenados como hash SHA-256)
CREATE TABLE password_resets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expires_at  DATETIME        NOT NULL,
    used_at     DATETIME        NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting em banco (hospedagem compartilhada não tem Redis)
-- bucket_key ex.: "login:ip:203.0.113.5", "login:email:<sha256>"
CREATE TABLE rate_limits (
    bucket_key     VARCHAR(190) NOT NULL,
    hits           INT UNSIGNED NOT NULL DEFAULT 0,
    window_start   DATETIME     NOT NULL,
    blocked_until  DATETIME     NULL,
    PRIMARY KEY (bucket_key),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. CATÁLOGO
-- ============================================================================

CREATE TABLE categories (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id         BIGINT UNSIGNED NULL,
    name              VARCHAR(100)    NOT NULL,
    slug              VARCHAR(120)    NOT NULL,
    description       TEXT            NULL,
    image_path        VARCHAR(255)    NULL,
    sort_order        INT             NOT NULL DEFAULT 0,
    is_active         TINYINT(1)      NOT NULL DEFAULT 1,
    meta_title        VARCHAR(70)     NULL,
    meta_description  VARCHAR(160)    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados comerciais do produto. SKU, preço e medidas ficam na variante.
CREATE TABLE products (
    id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id              BIGINT UNSIGNED NOT NULL,
    name                     VARCHAR(150)    NOT NULL,
    slug                     VARCHAR(170)    NOT NULL,
    short_description        VARCHAR(300)    NULL,
    description              TEXT            NULL,
    highlights               TEXT            NULL,          -- características, uma por linha
    production_lead_days     SMALLINT UNSIGNED NOT NULL DEFAULT 3,  -- prazo de produção exibido ao cliente
    personalization_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
    is_active                TINYINT(1)      NOT NULL DEFAULT 0,    -- nasce inativo até revisão
    is_featured              TINYINT(1)      NOT NULL DEFAULT 0,
    is_new                   TINYINT(1)      NOT NULL DEFAULT 0,
    sales_count              INT UNSIGNED    NOT NULL DEFAULT 0,    -- desnormalizado p/ "mais vendidos"
    meta_title               VARCHAR(70)     NULL,
    meta_description         VARCHAR(160)    NULL,
    published_at             DATETIME        NULL,
    created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at               DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_category (category_id),
    KEY idx_products_listing (is_active, deleted_at, is_featured, is_new),
    FULLTEXT KEY ft_products_search (name, short_description, description),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eixos de variação PRÉ-DEFINIDOS pelo admin (ex.: "Acabamento": Natural, Preto)
CREATE TABLE product_options (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(60)     NOT NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_options_name (product_id, name),
    CONSTRAINT fk_product_options_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_option_values (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    option_id   BIGINT UNSIGNED NOT NULL,
    value       VARCHAR(60)     NOT NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_option_values (option_id, value),
    CONSTRAINT fk_option_values_option FOREIGN KEY (option_id) REFERENCES product_options (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada combinação vendável = uma variante com SKU próprio.
-- Todo produto possui ao menos uma variante (is_default = 1).
CREATE TABLE product_variants (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id             BIGINT UNSIGNED NOT NULL,
    sku                    VARCHAR(40)     NOT NULL,
    name                   VARCHAR(100)    NULL,             -- ex.: "Natural" (NULL na variante única)
    price_cents            INT UNSIGNED    NOT NULL,
    compare_at_price_cents INT UNSIGNED    NULL,             -- preço "de" (promoção)
    material_label         VARCHAR(100)    NULL,             -- público: "MDF amadeirado 6 mm"
    finish_label           VARCHAR(60)     NULL,             -- público: "Natural"
    width_mm               INT UNSIGNED    NULL,
    height_mm              INT UNSIGNED    NULL,
    depth_mm               INT UNSIGNED    NULL,
    weight_g               INT UNSIGNED    NULL,
    package_width_mm       INT UNSIGNED    NULL,             -- medidas de embalagem para frete
    package_height_mm      INT UNSIGNED    NULL,
    package_length_mm      INT UNSIGNED    NULL,
    package_weight_g       INT UNSIGNED    NULL,
    is_default             TINYINT(1)      NOT NULL DEFAULT 0,
    is_active              TINYINT(1)      NOT NULL DEFAULT 1,
    sort_order             INT             NOT NULL DEFAULT 0,
    created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at             DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_variants_sku (sku),
    KEY idx_product_variants_product (product_id),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
    CONSTRAINT chk_variants_compare_price CHECK (compare_at_price_cents IS NULL OR compare_at_price_cents > price_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE variant_option_values (
    variant_id       BIGINT UNSIGNED NOT NULL,
    option_value_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (variant_id, option_value_id),
    KEY idx_vov_option_value (option_value_id),
    CONSTRAINT fk_vov_variant      FOREIGN KEY (variant_id)      REFERENCES product_variants (id)      ON DELETE CASCADE,
    CONSTRAINT fk_vov_option_value FOREIGN KEY (option_value_id) REFERENCES product_option_values (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  BIGINT UNSIGNED NOT NULL,
    variant_id  BIGINT UNSIGNED NULL,                        -- imagem específica de uma variante
    path        VARCHAR(255)    NOT NULL,                    -- relativo a /public/uploads
    alt_text    VARCHAR(150)    NOT NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    is_cover    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_images_product (product_id, sort_order),
    CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products (id)         ON DELETE CASCADE,
    CONSTRAINT fk_images_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. PERSONALIZAÇÃO CONTROLADA
-- Não existe campo livre de "descreva o que quer alterar".
-- Cada campo é uma regra com tipo, limites e acréscimo definidos pelo admin.
-- ============================================================================

CREATE TABLE personalization_rules (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id         BIGINT UNSIGNED NOT NULL,
    field_key          VARCHAR(50)     NOT NULL,             -- ex.: "nome_gravado"
    label              VARCHAR(80)     NOT NULL,             -- ex.: "Nome gravado"
    help_text          VARCHAR(200)    NULL,
    type               VARCHAR(20)     NOT NULL,             -- text | initial | date | select
    is_required        TINYINT(1)      NOT NULL DEFAULT 0,
    min_length         SMALLINT UNSIGNED NULL,
    max_length         SMALLINT UNSIGNED NULL,
    charset            VARCHAR(30)     NULL,                 -- preset: letters | letters_numbers | text_basic
    max_size_mm        INT UNSIGNED    NULL,                 -- largura máxima da gravação (informativo/produção)
    price_delta_cents  INT UNSIGNED    NOT NULL DEFAULT 0,
    sort_order         INT             NOT NULL DEFAULT 0,
    is_active          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_personalization_rules_key (product_id, field_key),
    CONSTRAINT fk_prules_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT chk_prules_type    CHECK (type IN ('text','initial','date','select')),
    CONSTRAINT chk_prules_lengths CHECK (min_length IS NULL OR max_length IS NULL OR min_length <= max_length)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opções pré-cadastradas para regras do tipo "select" (modelos, fontes, ícones...)
CREATE TABLE personalization_values (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_id            BIGINT UNSIGNED NOT NULL,
    code               VARCHAR(50)     NOT NULL,
    label              VARCHAR(80)     NOT NULL,
    image_path         VARCHAR(255)    NULL,
    price_delta_cents  INT UNSIGNED    NOT NULL DEFAULT 0,
    sort_order         INT             NOT NULL DEFAULT 0,
    is_active          TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_personalization_values_code (rule_id, code),
    CONSTRAINT fk_pvalues_rule FOREIGN KEY (rule_id) REFERENCES personalization_rules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. FICHA DE PRODUÇÃO (INTERNA — nunca exposta na loja)
-- ============================================================================

-- Matéria-prima (base para estoque de chapas e custo)
CREATE TABLE materials (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code             VARCHAR(40)     NOT NULL,               -- ex.: "MDF-AMD-06"
    name             VARCHAR(100)    NOT NULL,               -- ex.: "MDF amadeirado"
    thickness_mm     DECIMAL(5,2)    NOT NULL,
    sheet_width_mm   INT UNSIGNED    NULL,
    sheet_length_mm  INT UNSIGNED    NULL,
    unit             VARCHAR(10)     NOT NULL DEFAULT 'sheet', -- sheet | m2 | unit
    cost_cents       INT UNSIGNED    NULL,                   -- custo por unidade
    stock_qty        DECIMAL(10,2)   NOT NULL DEFAULT 0,
    reorder_level    DECIMAL(10,2)   NULL,
    is_active        TINYINT(1)      NOT NULL DEFAULT 1,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_materials_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma ficha por variante (materiais/medidas podem mudar entre variantes)
CREATE TABLE production_specs (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id           BIGINT UNSIGNED NOT NULL,
    material_id          BIGINT UNSIGNED NULL,
    thickness_mm         DECIMAL(5,2)    NULL,
    cut_width_mm         INT UNSIGNED    NULL,
    cut_height_mm        INT UNSIGNED    NULL,
    pieces_per_sheet     SMALLINT UNSIGNED NULL,
    sheet_yield_percent  DECIMAL(5,2)    NULL,               -- aproveitamento estimado da chapa
    cnc_program_ref      VARCHAR(100)    NULL,               -- código/referência do arquivo CNC
    finish_notes         TEXT            NULL,
    internal_notes       TEXT            NULL,
    updated_by_user_id   BIGINT UNSIGNED NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_production_specs_variant (variant_id),
    KEY idx_production_specs_material (material_id),
    CONSTRAINT fk_pspecs_variant  FOREIGN KEY (variant_id)         REFERENCES product_variants (id) ON DELETE CASCADE,
    CONSTRAINT fk_pspecs_material FOREIGN KEY (material_id)        REFERENCES materials (id)        ON DELETE SET NULL,
    CONSTRAINT fk_pspecs_user     FOREIGN KEY (updated_by_user_id) REFERENCES users (id)            ON DELETE SET NULL,
    CONSTRAINT chk_pspecs_yield CHECK (sheet_yield_percent IS NULL OR (sheet_yield_percent >= 0 AND sheet_yield_percent <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etapas de produção com tempos. Tempo total = SUM(estimated_minutes).
-- is_passive = 1 para etapas que não ocupam operador (ex.: secagem),
-- importante para cálculo futuro de capacidade produtiva.
CREATE TABLE production_spec_steps (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    spec_id            BIGINT UNSIGNED NOT NULL,
    stage              VARCHAR(20)     NOT NULL,  -- cnc | sanding | painting | drying | assembly | quality | packaging
    description        VARCHAR(200)    NULL,
    tool               VARCHAR(100)    NULL,      -- ex.: "Fresa 1/8\" 2 cortes"
    operations_count   SMALLINT UNSIGNED NULL,
    estimated_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_passive         TINYINT(1)      NOT NULL DEFAULT 0,
    sort_order         INT             NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_pspec_steps_spec (spec_id, sort_order),
    CONSTRAINT fk_psteps_spec FOREIGN KEY (spec_id) REFERENCES production_specs (id) ON DELETE CASCADE,
    CONSTRAINT chk_psteps_stage CHECK (stage IN ('cnc','sanding','painting','drying','assembly','quality','packaging'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arquivos de produção guardados em storage/private (fora do webroot)
CREATE TABLE production_files (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    spec_id             BIGINT UNSIGNED NOT NULL,
    file_type           VARCHAR(20)     NOT NULL,            -- cnc | design | drawing | other
    original_name       VARCHAR(190)    NOT NULL,
    stored_path         VARCHAR(255)    NOT NULL,            -- relativo a storage/private/production_files
    mime_type           VARCHAR(100)    NOT NULL,
    size_bytes          INT UNSIGNED    NOT NULL,
    checksum_sha256     CHAR(64)        NOT NULL,
    version             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    uploaded_by_user_id BIGINT UNSIGNED NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_production_files_spec (spec_id),
    CONSTRAINT fk_pfiles_spec FOREIGN KEY (spec_id)             REFERENCES production_specs (id) ON DELETE CASCADE,
    CONSTRAINT fk_pfiles_user FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. ESTOQUE
-- ============================================================================

-- stock_mode: 'stock' = pronta entrega (consome quantity_on_hand)
--             'made_to_order' = produzido após o pedido (não bloqueia venda por quantidade)
CREATE TABLE inventory (
    variant_id         BIGINT UNSIGNED NOT NULL,
    stock_mode         VARCHAR(20)     NOT NULL DEFAULT 'made_to_order',
    quantity_on_hand   INT             NOT NULL DEFAULT 0,
    quantity_reserved  INT             NOT NULL DEFAULT 0,
    reorder_level      INT             NULL,
    updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (variant_id),
    CONSTRAINT fk_inventory_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
    CONSTRAINT chk_inventory_mode CHECK (stock_mode IN ('stock','made_to_order')),
    CONSTRAINT chk_inventory_qty  CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Razão imutável de movimentações (nunca UPDATE/DELETE)
CREATE TABLE inventory_movements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    variant_id       BIGINT UNSIGNED NOT NULL,
    type             VARCHAR(20)     NOT NULL,   -- in | out | reserve | release | adjust | production
    quantity         INT             NOT NULL,   -- com sinal
    reason           VARCHAR(200)    NULL,
    reference_type   VARCHAR(30)     NULL,       -- ex.: "order"
    reference_id     BIGINT UNSIGNED NULL,
    user_id          BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inv_mov_variant (variant_id, created_at),
    KEY idx_inv_mov_reference (reference_type, reference_id),
    CONSTRAINT fk_inv_mov_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_inv_mov_user    FOREIGN KEY (user_id)    REFERENCES users (id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. CUPONS
-- ============================================================================

CREATE TABLE coupons (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                      VARCHAR(40)     NOT NULL,      -- armazenado em MAIÚSCULAS
    description               VARCHAR(200)    NULL,
    type                      VARCHAR(20)     NOT NULL,      -- percent | fixed | free_shipping
    value                     INT UNSIGNED    NOT NULL DEFAULT 0, -- percent: basis points (1000 = 10%); fixed: centavos
    min_subtotal_cents        INT UNSIGNED    NULL,
    max_discount_cents        INT UNSIGNED    NULL,
    starts_at                 DATETIME        NULL,
    ends_at                   DATETIME        NULL,
    usage_limit               INT UNSIGNED    NULL,
    usage_limit_per_customer  INT UNSIGNED    NULL,
    times_used                INT UNSIGNED    NOT NULL DEFAULT 0,
    is_active                 TINYINT(1)      NOT NULL DEFAULT 1,
    created_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupons_code (code),
    CONSTRAINT chk_coupons_type    CHECK (type IN ('percent','fixed','free_shipping')),
    CONSTRAINT chk_coupons_percent CHECK (type <> 'percent' OR value <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. CARRINHO
-- ============================================================================

CREATE TABLE carts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash   CHAR(64)        NOT NULL,                   -- SHA-256 do token do cookie
    customer_id  BIGINT UNSIGNED NULL,
    coupon_id    BIGINT UNSIGNED NULL,
    status       VARCHAR(20)     NOT NULL DEFAULT 'active',  -- active | converted | abandoned
    expires_at   DATETIME        NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_carts_token (token_hash),
    KEY idx_carts_customer (customer_id),
    KEY idx_carts_status_expires (status, expires_at),
    CONSTRAINT fk_carts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL,
    CONSTRAINT fk_carts_coupon   FOREIGN KEY (coupon_id)   REFERENCES coupons (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mesma variante com personalizações diferentes = linhas diferentes.
-- personalization_hash = SHA-256 dos valores normalizados ('' se não houver)
CREATE TABLE cart_items (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_id               BIGINT UNSIGNED NOT NULL,
    variant_id            BIGINT UNSIGNED NOT NULL,
    quantity              SMALLINT UNSIGNED NOT NULL,
    personalization_hash  CHAR(64)        NOT NULL DEFAULT '',
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_items_line (cart_id, variant_id, personalization_hash),
    KEY idx_cart_items_variant (variant_id),
    CONSTRAINT fk_cart_items_cart    FOREIGN KEY (cart_id)    REFERENCES carts (id)            ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
    CONSTRAINT chk_cart_items_qty CHECK (quantity BETWEEN 1 AND 99)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_item_personalizations (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cart_item_id  BIGINT UNSIGNED NOT NULL,
    rule_id       BIGINT UNSIGNED NOT NULL,
    value_id      BIGINT UNSIGNED NULL,                      -- para regras "select"
    value_text    VARCHAR(255)    NULL,                      -- para text/initial/date
    PRIMARY KEY (id),
    UNIQUE KEY uq_cart_item_pers (cart_item_id, rule_id),
    CONSTRAINT fk_cip_item  FOREIGN KEY (cart_item_id) REFERENCES cart_items (id)             ON DELETE CASCADE,
    CONSTRAINT fk_cip_rule  FOREIGN KEY (rule_id)      REFERENCES personalization_rules (id)  ON DELETE CASCADE,
    CONSTRAINT fk_cip_value FOREIGN KEY (value_id)     REFERENCES personalization_values (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. PEDIDOS
-- Pedido guarda SNAPSHOTS: mudanças futuras no catálogo, preço, endereço ou
-- regras de personalização não alteram o que foi comprado.
-- ============================================================================

CREATE TABLE orders (
    id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    number                     VARCHAR(20)     NOT NULL,     -- público: GN-2026-000123
    customer_id                BIGINT UNSIGNED NOT NULL,
    status                     VARCHAR(30)     NOT NULL DEFAULT 'awaiting_payment',
    payment_status             VARCHAR(20)     NOT NULL DEFAULT 'pending',
    -- valores (centavos)
    subtotal_cents             INT UNSIGNED    NOT NULL,     -- itens + personalizações
    discount_cents             INT UNSIGNED    NOT NULL DEFAULT 0,
    shipping_cents             INT UNSIGNED    NOT NULL DEFAULT 0,
    total_cents                INT UNSIGNED    NOT NULL,
    coupon_id                  BIGINT UNSIGNED NULL,
    coupon_code                VARCHAR(40)     NULL,
    -- snapshot do cliente
    customer_name              VARCHAR(120)    NOT NULL,
    customer_email             VARCHAR(190)    NOT NULL,
    customer_phone             VARCHAR(20)     NULL,
    customer_cpf               CHAR(11)        NULL,
    -- snapshot do endereço de entrega
    ship_recipient             VARCHAR(120)    NOT NULL,
    ship_zip_code              CHAR(8)         NOT NULL,
    ship_street                VARCHAR(160)    NOT NULL,
    ship_number                VARCHAR(20)     NOT NULL,
    ship_complement            VARCHAR(80)     NULL,
    ship_district              VARCHAR(80)     NOT NULL,
    ship_city                  VARCHAR(80)     NOT NULL,
    ship_state                 CHAR(2)         NOT NULL,
    -- snapshot do frete e prazos
    shipping_carrier           VARCHAR(60)     NULL,
    shipping_service           VARCHAR(60)     NULL,
    shipping_days              SMALLINT UNSIGNED NULL,
    production_days            SMALLINT UNSIGNED NULL,       -- maior prazo entre os itens
    -- controle
    admin_notes                TEXT            NULL,         -- interno
    placed_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at                    DATETIME        NULL,
    cancelled_at               DATETIME        NULL,
    cancel_reason              VARCHAR(200)    NULL,
    created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_orders_number (number),
    KEY idx_orders_customer (customer_id, placed_at),
    KEY idx_orders_status (status, placed_at),
    KEY idx_orders_payment_status (payment_status),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_coupon   FOREIGN KEY (coupon_id)   REFERENCES coupons (id)   ON DELETE SET NULL,
    CONSTRAINT chk_orders_total CHECK (total_cents = subtotal_cents - discount_cents + shipping_cents),
    CONSTRAINT chk_orders_status CHECK (status IN (
        'awaiting_payment','paid','production_pending','in_production','finishing',
        'quality_control','packaging','ready_to_ship','shipped','delivered','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id                    BIGINT UNSIGNED NOT NULL,
    product_id                  BIGINT UNSIGNED NOT NULL,
    variant_id                  BIGINT UNSIGNED NOT NULL,
    -- snapshot
    sku                         VARCHAR(40)     NOT NULL,
    product_name                VARCHAR(150)    NOT NULL,
    variant_name                VARCHAR(100)    NULL,
    unit_price_cents            INT UNSIGNED    NOT NULL,    -- preço base da variante
    personalization_cents       INT UNSIGNED    NOT NULL DEFAULT 0, -- acréscimo por unidade
    quantity                    SMALLINT UNSIGNED NOT NULL,
    line_total_cents            INT UNSIGNED    NOT NULL,
    production_minutes_estimate INT UNSIGNED    NULL,        -- snapshot da ficha, por unidade
    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_variant (variant_id),
    CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id)   REFERENCES orders (id)           ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id)         ON DELETE RESTRICT,
    CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT,
    CONSTRAINT chk_order_items_qty   CHECK (quantity >= 1),
    CONSTRAINT chk_order_items_total CHECK (line_total_cents = (unit_price_cents + personalization_cents) * quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exatamente o que o cliente comprou (texto gravado, opção escolhida, acréscimo)
CREATE TABLE order_item_personalizations (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_item_id      BIGINT UNSIGNED NOT NULL,
    rule_id            BIGINT UNSIGNED NULL,                 -- referência; snapshot abaixo é o que vale
    field_key          VARCHAR(50)     NOT NULL,
    label              VARCHAR(80)     NOT NULL,
    type               VARCHAR(20)     NOT NULL,
    value_text         VARCHAR(255)    NOT NULL,             -- valor digitado ou código da opção
    value_label        VARCHAR(80)     NULL,                 -- rótulo da opção (select)
    price_delta_cents  INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_oip_item (order_item_id),
    CONSTRAINT fk_oip_item FOREIGN KEY (order_item_id) REFERENCES order_items (id)           ON DELETE CASCADE,
    CONSTRAINT fk_oip_rule FOREIGN KEY (rule_id)       REFERENCES personalization_rules (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            BIGINT UNSIGNED NOT NULL,
    from_status         VARCHAR(30)     NULL,
    to_status           VARCHAR(30)     NOT NULL,
    source              VARCHAR(20)     NOT NULL,             -- admin | system | webhook | customer
    changed_by_user_id  BIGINT UNSIGNED NULL,
    note                VARCHAR(500)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_osh_order (order_id, created_at),
    CONSTRAINT fk_osh_order FOREIGN KEY (order_id)           REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_osh_user  FOREIGN KEY (changed_by_user_id) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. PAGAMENTOS
-- NUNCA armazenar número de cartão, CVV ou validade. Apenas referências
-- do gateway e dados não sensíveis (bandeira, 4 últimos dígitos).
-- ============================================================================

CREATE TABLE payments (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id             BIGINT UNSIGNED NOT NULL,
    provider             VARCHAR(30)     NOT NULL,            -- ex.: mercadopago, pagarme
    provider_payment_id  VARCHAR(100)    NULL,
    method               VARCHAR(20)     NOT NULL,            -- pix | credit_card | boleto
    status               VARCHAR(20)     NOT NULL DEFAULT 'pending',
    amount_cents         INT UNSIGNED    NOT NULL,
    refunded_cents       INT UNSIGNED    NOT NULL DEFAULT 0,
    installments         TINYINT UNSIGNED NULL,
    card_brand           VARCHAR(20)     NULL,
    card_last4           CHAR(4)         NULL,
    expires_at           DATETIME        NULL,                -- validade do Pix/boleto
    paid_at              DATETIME        NULL,
    refunded_at          DATETIME        NULL,
    failure_reason       VARCHAR(200)    NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_provider_ref (provider, provider_payment_id),
    KEY idx_payments_order (order_id),
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
    CONSTRAINT chk_payments_status CHECK (status IN ('pending','authorized','paid','refunded','partially_refunded','failed','cancelled')),
    CONSTRAINT chk_payments_refund CHECK (refunded_cents <= amount_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhooks recebidos: idempotência por (provider, event_id)
CREATE TABLE payment_events (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id       BIGINT UNSIGNED NULL,
    provider         VARCHAR(30)     NOT NULL,
    event_id         VARCHAR(100)    NOT NULL,
    event_type       VARCHAR(60)     NOT NULL,
    payload          JSON            NOT NULL,
    signature_valid  TINYINT(1)      NOT NULL DEFAULT 0,
    processed_at     DATETIME        NULL,
    error            VARCHAR(500)    NULL,
    received_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_events (provider, event_id),
    KEY idx_payment_events_payment (payment_id),
    CONSTRAINT fk_pevents_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. EXPEDIÇÃO
-- ============================================================================

CREATE TABLE shipments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id       BIGINT UNSIGNED NOT NULL,
    carrier        VARCHAR(60)     NOT NULL,                  -- Correios, Jadlog, retirada...
    service        VARCHAR(60)     NULL,                      -- PAC, SEDEX...
    tracking_code  VARCHAR(60)     NULL,
    tracking_url   VARCHAR(255)    NULL,
    cost_cents     INT UNSIGNED    NULL,                      -- custo real (≠ frete cobrado)
    status         VARCHAR(20)     NOT NULL DEFAULT 'pending',-- pending | label_created | posted | in_transit | delivered | returned
    label_path     VARCHAR(255)    NULL,                      -- storage/private
    shipped_at     DATETIME        NULL,
    delivered_at   DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_shipments_order (order_id),
    KEY idx_shipments_tracking (tracking_code),
    CONSTRAINT fk_shipments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    coupon_id       BIGINT UNSIGNED NOT NULL,
    order_id        BIGINT UNSIGNED NOT NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    discount_cents  INT UNSIGNED    NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_coupon_redemptions_order (order_id),
    KEY idx_coupon_redemptions_customer (coupon_id, customer_id),
    CONSTRAINT fk_credemptions_coupon   FOREIGN KEY (coupon_id)   REFERENCES coupons (id)   ON DELETE RESTRICT,
    CONSTRAINT fk_credemptions_order    FOREIGN KEY (order_id)    REFERENCES orders (id)    ON DELETE CASCADE,
    CONSTRAINT fk_credemptions_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. RELACIONAMENTO COM O CLIENTE
-- ============================================================================

CREATE TABLE reviews (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id            BIGINT UNSIGNED NOT NULL,
    customer_id           BIGINT UNSIGNED NOT NULL,
    order_item_id         BIGINT UNSIGNED NULL,               -- compra verificada
    rating                TINYINT UNSIGNED NOT NULL,
    title                 VARCHAR(120)    NULL,
    body                  TEXT            NULL,
    status                VARCHAR(20)     NOT NULL DEFAULT 'pending', -- pending | approved | rejected
    moderated_by_user_id  BIGINT UNSIGNED NULL,
    moderated_at          DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reviews_customer_product (customer_id, product_id),
    KEY idx_reviews_product_status (product_id, status),
    CONSTRAINT fk_reviews_product    FOREIGN KEY (product_id)           REFERENCES products (id)    ON DELETE CASCADE,
    CONSTRAINT fk_reviews_customer   FOREIGN KEY (customer_id)          REFERENCES customers (id)   ON DELETE CASCADE,
    CONSTRAINT fk_reviews_order_item FOREIGN KEY (order_item_id)        REFERENCES order_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_reviews_moderator  FOREIGN KEY (moderated_by_user_id) REFERENCES users (id)       ON DELETE SET NULL,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wishlists (
    customer_id  BIGINT UNSIGNED NOT NULL,
    product_id   BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id, product_id),
    KEY idx_wishlists_product (product_id),
    CONSTRAINT fk_wishlists_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlists_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. CONFIGURAÇÕES E AUDITORIA
-- ============================================================================

-- Configurações editáveis pelo admin (WhatsApp, textos, prazos padrão...)
-- Segredos NÃO ficam aqui: ficam no .env
CREATE TABLE settings (
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  TEXT         NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trilha de auditoria (somente INSERT)
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NULL,
    action       VARCHAR(50)     NOT NULL,   -- login | logout | login_failed | create | update | delete | status_change | price_change | stock_change
    entity_type  VARCHAR(50)     NULL,       -- product | variant | production_spec | order | coupon ...
    entity_id    BIGINT UNSIGNED NULL,
    old_values   JSON            NULL,
    new_values   JSON            NULL,
    ip_address   VARCHAR(45)     NULL,
    user_agent   VARCHAR(255)    NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O registro em schema_migrations é feito pelo runner (database/migrate.php).
