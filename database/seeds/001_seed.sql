-- ============================================================================
-- G-Nesting — Seed inicial (desenvolvimento)
-- Categorias, matéria-prima, configurações e o produto de referência
-- "Relógio Geométrico G-Nesting" (REL-GEO-001), conforme o briefing.
--
-- O administrador inicial NÃO é criado aqui (sem senha em arquivo):
-- será criado pelo comando CLI `php bin/create-admin.php` (etapa 2).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- Categorias
-- ----------------------------------------------------------------------------
INSERT INTO categories (name, slug, description, sort_order, is_active, meta_title, meta_description) VALUES
('Relógios',            'relogios',            'Relógios de parede com desenho geométrico e fabricação digital.',   10, 1, 'Relógios de parede | G-Nesting',       'Relógios de parede em MDF e madeira produzidos com precisão CNC.'),
('Quadros e painéis',   'quadros-e-paineis',   'Quadros, painéis e composições para parede.',                      20, 1, 'Quadros e painéis | G-Nesting',        'Quadros e painéis decorativos com recortes de precisão.'),
('Decoração',           'decoracao',           'Objetos decorativos que transformam espaços.',                     30, 1, 'Objetos decorativos | G-Nesting',      'Objetos decorativos contemporâneos produzidos em CNC.'),
('Organizadores',       'organizadores',       'Organizadores funcionais para casa e escritório.',                 40, 1, 'Organizadores | G-Nesting',            'Organizadores em MDF e madeira com encaixes precisos.'),
('Cozinha',             'cozinha',             'Acessórios para cozinha: suportes, tábuas e porta-utensílios.',    50, 1, 'Acessórios para cozinha | G-Nesting',  'Acessórios de cozinha com design e fabricação digital.'),
('Escritório',          'escritorio',          'Objetos para a mesa de trabalho.',                                 60, 1, 'Objetos para escritório | G-Nesting',  'Suportes, porta-objetos e acessórios para escritório.'),
('Caixas e presentes',  'caixas-e-presentes',  'Caixas, kits e objetos para presentear.',                          70, 1, 'Caixas e presentes | G-Nesting',       'Caixas e presentes com personalização controlada.');

-- ----------------------------------------------------------------------------
-- Matéria-prima
-- ----------------------------------------------------------------------------
INSERT INTO materials (code, name, thickness_mm, sheet_width_mm, sheet_length_mm, unit, cost_cents, stock_qty, reorder_level) VALUES
('MDF-AMD-06', 'MDF amadeirado',  6.00, 1830, 2750, 'sheet', NULL, 0, 2),
('MDF-CRU-03', 'MDF cru',         3.00, 1830, 2750, 'sheet', NULL, 0, 2),
('MDF-CRU-06', 'MDF cru',         6.00, 1830, 2750, 'sheet', NULL, 0, 2);

-- ----------------------------------------------------------------------------
-- Configurações da loja (valores não sensíveis)
-- ----------------------------------------------------------------------------
INSERT INTO settings (setting_key, setting_value) VALUES
('store.name',                'G-Nesting'),
('store.tagline',             'Objetos que transformam espaços.'),
('store.contact_email',       'contato@gnesting.com.br'),
('whatsapp.number',           ''),          -- E.164 sem '+', preenchido pelo admin
('whatsapp.default_message',  'Olá! Tenho uma dúvida sobre um produto da G-Nesting.'),
('orders.payment_expiry_hours','48'),
('production.default_lead_days','3');

-- ----------------------------------------------------------------------------
-- Produto de referência: Relógio Geométrico G-Nesting
-- ----------------------------------------------------------------------------
INSERT INTO products (category_id, name, slug, short_description, description, highlights,
                      production_lead_days, personalization_enabled, is_active, is_featured, is_new,
                      meta_title, meta_description, published_at)
SELECT id,
       'Relógio Geométrico G-Nesting',
       'relogio-geometrico-g-nesting',
       'Relógio de parede com composição geométrica recortada em MDF amadeirado.',
       'Um relógio de parede que une geometria e precisão. Cada peça é recortada em CNC a partir de uma chapa de MDF amadeirado de 6 mm, com acabamento natural e máquina silenciosa.',
       'Máquina de ponteiro silenciosa\nRecorte CNC de precisão\nAcabamento natural\nPronto para pendurar',
       3, 1, 1, 1, 1,
       'Relógio Geométrico | G-Nesting',
       'Relógio de parede geométrico em MDF amadeirado 6 mm, 35 × 35 cm. Personalize com um nome gravado.',
       UTC_TIMESTAMP()
FROM categories WHERE slug = 'relogios';

SET @product_id = LAST_INSERT_ID();

INSERT INTO product_variants (product_id, sku, name, price_cents, material_label, finish_label,
                              width_mm, height_mm, depth_mm, weight_g,
                              package_width_mm, package_height_mm, package_length_mm, package_weight_g,
                              is_default, is_active)
VALUES (@product_id, 'REL-GEO-001', NULL, 12990, 'MDF amadeirado 6 mm', 'Natural',
        350, 350, 6, 600,
        380, 60, 380, 900,
        1, 1);

SET @variant_id = LAST_INSERT_ID();

INSERT INTO inventory (variant_id, stock_mode, quantity_on_hand, quantity_reserved)
VALUES (@variant_id, 'made_to_order', 0, 0);

-- Personalização controlada: nome gravado, até 20 caracteres, + R$ 15,00
INSERT INTO personalization_rules (product_id, field_key, label, help_text, type, is_required,
                                   min_length, max_length, charset, max_size_mm, price_delta_cents, sort_order)
VALUES (@product_id, 'nome_gravado', 'Nome gravado',
        'Opcional. Até 20 caracteres: letras, números e espaços.',
        'text', 0, 1, 20, 'letters_numbers', 180, 1500, 10);

-- Ficha de produção (interna)
INSERT INTO production_specs (variant_id, material_id, thickness_mm, cut_width_mm, cut_height_mm,
                              pieces_per_sheet, sheet_yield_percent, cnc_program_ref, finish_notes, internal_notes)
SELECT @variant_id, id, 6.00, 350, 350,
       28, 82.50, 'CNC-REL-GEO-001-v1',
       'Lixar faces e bordas (grão 220). Aplicar seladora fosca em 1 demão.',
       'Máquina de relógio 12 mm eixo + ponteiros pretos modelo P-01. Gravação do nome na área inferior (máx. 180 mm).'
FROM materials WHERE code = 'MDF-AMD-06';

SET @spec_id = LAST_INSERT_ID();

INSERT INTO production_spec_steps (spec_id, stage, description, tool, operations_count, estimated_minutes, is_passive, sort_order) VALUES
(@spec_id, 'cnc',       'Recorte e gravação',                 'Fresa 1/8" 2 cortes + gravação V 30°', 3, 35, 0, 10),
(@spec_id, 'sanding',   'Lixamento de faces e bordas',        'Lixa grão 220',                        NULL, 10, 0, 20),
(@spec_id, 'painting',  'Aplicação de seladora fosca',        NULL,                                   1,  5, 0, 30),
(@spec_id, 'drying',    'Secagem da seladora',                NULL,                                   NULL, 60, 1, 40),
(@spec_id, 'assembly',  'Montagem da máquina e ponteiros',    NULL,                                   NULL,  8, 0, 50),
(@spec_id, 'quality',   'Conferência visual e funcionamento', NULL,                                   NULL,  3, 0, 60),
(@spec_id, 'packaging', 'Embalagem com proteção de cantos',   NULL,                                   NULL,  5, 0, 70);

COMMIT;
