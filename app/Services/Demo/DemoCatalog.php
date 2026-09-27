<?php

declare(strict_types=1);

namespace GNesting\Services\Demo;

/**
 * Conteúdo da loja de demonstração: categorias, matéria-prima, produtos, clientes e pedidos.
 * Só dados (sem regra): DemoDataService transforma isto em registros pelos services da loja.
 *
 * Preços em centavos; medidas em mm; peso em g.
 */
final class DemoCatalog
{
    /**
     * Categoria principal => [descrição, [subcategorias slug => nome]].
     *
     * @return array<string, array{name: string, description: string, children: array<string, string>}>
     */
    public static function categories(): array
    {
        return [
            'decoracao' => ['name' => 'Decoração', 'description' => 'Quadros, painéis, mandalas e objetos que dão personalidade aos ambientes.', 'children' => [
                'quadros-decorativos' => 'Quadros decorativos', 'paineis' => 'Painéis', 'mandalas' => 'Mandalas', 'objetos-decorativos' => 'Objetos decorativos',
            ]],
            'organizacao' => ['name' => 'Organização', 'description' => 'Organizadores, nichos e suportes para cada coisa ter o seu lugar.', 'children' => [
                'organizadores' => 'Organizadores', 'porta-objetos' => 'Porta-objetos', 'nichos' => 'Nichos', 'suportes' => 'Suportes',
            ]],
            'cozinha' => ['name' => 'Cozinha', 'description' => 'Porta-temperos, tábuas e organizadores feitos para o dia a dia da cozinha.', 'children' => [
                'porta-temperos' => 'Porta-temperos', 'organizadores-de-cozinha' => 'Organizadores de cozinha', 'tabuas' => 'Tábuas', 'suportes-de-cozinha' => 'Suportes de cozinha',
            ]],
            'escritorio' => ['name' => 'Escritório', 'description' => 'Mesa organizada, trabalho melhor: organizadores, porta-canetas e suportes.', 'children' => [
                'organizadores-de-mesa' => 'Organizadores de mesa', 'porta-canetas' => 'Porta-canetas', 'suportes-para-notebook' => 'Suportes para notebook', 'porta-documentos' => 'Porta-documentos',
            ]],
            'relogios' => ['name' => 'Relógios', 'description' => 'Relógios de parede e de mesa com desenho geométrico e máquina silenciosa.', 'children' => [
                'relogios-de-parede' => 'Relógios de parede', 'relogios-decorativos' => 'Relógios decorativos', 'relogios-personalizados' => 'Relógios personalizados',
            ]],
            'presentes' => ['name' => 'Presentes', 'description' => 'Kits, lembranças e peças comemorativas com gravação personalizada.', 'children' => [
                'kits' => 'Kits', 'lembrancas' => 'Lembranças', 'comemorativos' => 'Comemorativos',
            ]],
            'linha-infantil' => ['name' => 'Linha Infantil', 'description' => 'Decoração e organização para quartos infantis, com cores suaves e cantos arredondados.', 'children' => [
                'decoracao-infantil' => 'Decoração infantil', 'organizadores-infantis' => 'Organizadores infantis', 'nome-decorativo' => 'Nome decorativo',
            ]],
        ];
    }

    /**
     * Matéria-prima: código => [nome, espessura, unidade, largura, comprimento, custo, saldo inicial, mínimo].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?int, 4: ?int, 5: int, 6: string, 7: string}>
     */
    public static function materials(): array
    {
        return [
            'MDF-CRU-03' => ['MDF cru', '3.00', 'sheet', 1830, 2750, 8990, '14.00', '4.00'],
            'MDF-CRU-06' => ['MDF cru', '6.00', 'sheet', 1830, 2750, 12990, '9.00', '4.00'],
            'MDF-AMD-06' => ['MDF amadeirado', '6.00', 'sheet', 1830, 2750, 18990, '6.00', '3.00'],
            'MDF-BRA-06' => ['MDF branco TX', '6.00', 'sheet', 1830, 2750, 17490, '2.00', '3.00'],
            'MDF-CRU-15' => ['MDF cru', '15.00', 'sheet', 1830, 2750, 21990, '4.00', '2.00'],
            'COMP-10' => ['Compensado naval', '10.00', 'sheet', 1600, 2200, 24990, '3.00', '1.00'],
            'PINUS-20' => ['Madeira pinus aparelhada', '20.00', 'sheet', 200, 3000, 3890, '18.00', '6.00'],
            'COLA-PVA' => ['Cola PVA (kg)', '0.00', 'unit', null, null, 2890, '5.00', '2.00'],
            'VERNIZ-PU' => ['Verniz PU fosco (L)', '0.00', 'unit', null, null, 6490, '1.00', '2.00'],
            'MAQ-REL' => ['Máquina de relógio com ponteiros', '0.00', 'unit', null, null, 1890, '22.00', '10.00'],
            'EMB-CX-M' => ['Caixa de papelão média', '0.00', 'unit', null, null, 390, '60.00', '30.00'],
            'GANCHO-MET' => ['Gancho metálico (cento)', '0.00', 'unit', null, null, 3500, '0.00', '1.00'],
        ];
    }

    /**
     * Produtos. Campos:
     *  sub, name, slug, sku, kind (ilustração), finish (textura), price, compare (preço "de"), cost,
     *  material, finish_label, dims [l, a, p], weight, lead (dias de produção), dispatch, stock (null = sob encomenda),
     *  featured, new, active, short, description, highlights, keywords, care, assembly,
     *  spec [código do material, peças por chapa, minutos por etapa], variants [opção => [valor => [acréscimo, textura]]],
     *  pers [campos de personalização].
     *
     * @return list<array<string, mixed>>
     */
    public static function products(): array
    {
        $care = 'Limpe com pano seco ou levemente úmido. Evite produtos abrasivos, umidade constante e sol direto.';
        $careKitchen = 'Lave à mão com sabão neutro e seque logo em seguida. Não use lava-louças nem deixe de molho. Hidrate com óleo mineral a cada dois meses.';
        $paint = ['cnc' => 30, 'sanding' => 10, 'painting' => 8, 'drying' => 60, 'quality' => 3, 'packaging' => 5];
        $natural = ['cnc' => 25, 'sanding' => 10, 'painting' => 5, 'drying' => 40, 'quality' => 3, 'packaging' => 5];
        $assembly = ['cnc' => 35, 'sanding' => 12, 'painting' => 6, 'drying' => 45, 'assembly' => 15, 'quality' => 4, 'packaging' => 6];

        return [
            // ---- Relógios ------------------------------------------------------------------
            ['sub' => 'relogios-de-parede', 'name' => 'Relógio Geométrico MDF', 'slug' => 'relogio-geometrico-mdf', 'sku' => 'REL-GEO-35', 'kind' => 'clock_round', 'finish' => 'mdf',
                'price' => 14990, 'compare' => 17990, 'cost' => 5200, 'material' => 'MDF cru 6 mm', 'finish_label' => 'Natural', 'dims' => [350, 350, 45], 'weight' => 650,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Relógio de parede de 35 cm com marcações recortadas e máquina silenciosa.',
                'description' => "Um clássico da casa: disco de MDF recortado em CNC, com marcações geométricas vazadas e ponteiros em aço pintado.\n\nA máquina de varredura contínua não faz tique-taque, ideal para quartos e escritórios.",
                'highlights' => ['Máquina silenciosa (sem tique-taque)', 'Recorte CNC de precisão', 'Pronto para pendurar', 'Funciona com 1 pilha AA'],
                'keywords' => 'relogio parede sala silencioso geometrico', 'care' => $care, 'assembly' => 'Chega montado. Basta colocar a pilha AA (não inclusa) e pendurar num prego ou parafuso.',
                'spec' => ['MDF-CRU-06', 28, $assembly],
                'variants' => ['Acabamento' => ['Natural' => [0, 'mdf'], 'Preto fosco' => [2000, 'preto']]],
                'pers' => [['key' => 'nome_gravado', 'label' => 'Nome gravado', 'type' => 'text', 'required' => false, 'max' => 18, 'charset' => 'letters_numbers', 'price' => 1500, 'help' => 'Opcional. Até 18 letras ou números, gravado na parte inferior.']]],
            ['sub' => 'relogios-de-parede', 'name' => 'Relógio Minimalista Carvalho', 'slug' => 'relogio-minimalista-carvalho', 'sku' => 'REL-MIN-30', 'kind' => 'clock_minimal', 'finish' => 'carvalho',
                'price' => 18990, 'compare' => null, 'cost' => 6400, 'material' => 'MDF amadeirado carvalho 6 mm', 'finish_label' => 'Carvalho', 'dims' => [300, 300, 40], 'weight' => 520,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => true, 'active' => true,
                'short' => 'Quatro marcações, nada mais: o relógio para quem gosta de paredes limpas.',
                'description' => "Face amadeirada com veios de carvalho, quatro pontos indicando as horas principais e ponteiros finos em preto.\n\nCombina com decoração escandinava, industrial e minimalista.",
                'highlights' => ['Visual escandinavo', 'Máquina silenciosa', 'Leve: 520 g'], 'keywords' => 'relogio minimalista escandinavo carvalho', 'care' => $care,
                'assembly' => 'Chega montado. Coloque a pilha AA (não inclusa).', 'spec' => ['MDF-AMD-06', 32, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'relogios-decorativos', 'name' => 'Relógio Hexagonal Nórdico', 'slug' => 'relogio-hexagonal-nordico', 'sku' => 'REL-HEX-40', 'kind' => 'clock_hex', 'finish' => 'preto',
                'price' => 12990, 'compare' => null, 'cost' => 4700, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Preto com triângulos naturais', 'dims' => [400, 346, 45], 'weight' => 700,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Hexágono com composição de triângulos em dois tons.',
                'description' => 'Relógio decorativo em formato hexagonal com mosaico de triângulos em MDF natural sobre fundo preto fosco.',
                'highlights' => ['Dois tons', 'Pintura fosca', 'Máquina silenciosa'], 'keywords' => 'relogio hexagono preto nordico', 'care' => $care,
                'assembly' => 'Chega montado. Coloque a pilha AA (não inclusa).', 'spec' => ['MDF-CRU-06', 24, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'relogios-personalizados', 'name' => 'Relógio Personalizado com Nome', 'slug' => 'relogio-personalizado-com-nome', 'sku' => 'REL-PER-35', 'kind' => 'clock_round', 'finish' => 'amadeirado',
                'price' => 16990, 'compare' => null, 'cost' => 5600, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Amadeirado', 'dims' => [350, 350, 45], 'weight' => 650,
                'lead' => 5, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'O relógio com o nome da família, do casal ou da empresa gravado a laser.',
                'description' => "Escolha o texto e a fonte: gravamos com precisão na parte inferior do relógio.\n\nUm presente que dura: casamentos, inaugurações e aniversários.",
                'highlights' => ['Gravação incluída', 'Três fontes para escolher', 'Máquina silenciosa'], 'keywords' => 'relogio personalizado nome presente casamento empresa', 'care' => $care,
                'assembly' => 'Chega montado. Coloque a pilha AA (não inclusa).', 'spec' => ['MDF-AMD-06', 28, $assembly], 'variants' => [],
                'pers' => [
                    ['key' => 'texto', 'label' => 'Texto gravado', 'type' => 'text', 'required' => true, 'max' => 24, 'charset' => 'text_basic', 'price' => 0, 'help' => 'Até 24 caracteres. Ex.: Família Souza'],
                    ['key' => 'fonte', 'label' => 'Fonte', 'type' => 'select', 'required' => true, 'price' => 0, 'values' => [['classica', 'Clássica', 0], ['moderna', 'Moderna', 0], ['manuscrita', 'Manuscrita', 500]]],
                ]],
            ['sub' => 'relogios-decorativos', 'name' => 'Relógio de Mesa Arco', 'slug' => 'relogio-de-mesa-arco', 'sku' => 'REL-MES-25', 'kind' => 'clock_table', 'finish' => 'nogueira',
                'price' => 9990, 'compare' => null, 'cost' => 3600, 'material' => 'MDF amadeirado nogueira 15 mm', 'finish_label' => 'Nogueira', 'dims' => [250, 150, 60], 'weight' => 480,
                'lead' => 2, 'dispatch' => 1, 'stock' => 0, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Relógio de mesa em arco, para cabeceira, estante ou escrivaninha.',
                'description' => 'Peça de pronta entrega em MDF amadeirado nogueira com mostrador claro. Fica em pé sozinha, sem suporte.',
                'highlights' => ['Pronta entrega', 'Mostrador de fácil leitura', 'Base estável'], 'keywords' => 'relogio mesa cabeceira escrivaninha', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-CRU-15', 20, $natural], 'variants' => [], 'pers' => []],

            // ---- Decoração -----------------------------------------------------------------
            ['sub' => 'quadros-decorativos', 'name' => 'Quadro Decorativo Floral', 'slug' => 'quadro-decorativo-floral', 'sku' => 'QDR-FLO-40', 'kind' => 'frame_floral', 'finish' => 'branco',
                'price' => 8990, 'compare' => null, 'cost' => 3100, 'material' => 'MDF 3 mm em camadas', 'finish_label' => 'Branco com flores terracota', 'dims' => [300, 400, 20], 'weight' => 420,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Quadro em camadas com flores recortadas em relevo.',
                'description' => 'Três camadas de MDF recortado criam profundidade e sombras suaves. Moldura branca e flores em terracota pintadas à mão.',
                'highlights' => ['Efeito 3D em camadas', 'Pintado à mão', 'Gancho de parede incluso'], 'keywords' => 'quadro flores relevo sala quarto', 'care' => $care,
                'assembly' => 'Chega montado, com gancho no verso.', 'spec' => ['MDF-CRU-03', 12, $paint], 'variants' => [], 'pers' => []],
            ['sub' => 'quadros-decorativos', 'name' => 'Quadro Mapa-Múndi Vazado', 'slug' => 'quadro-mapa-mundi-vazado', 'sku' => 'QDR-MAP-90', 'kind' => 'frame_map', 'finish' => 'amadeirado',
                'price' => 21990, 'compare' => 24990, 'cost' => 7800, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Amadeirado', 'dims' => [900, 550, 20], 'weight' => 2100,
                'lead' => 5, 'dispatch' => 2, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Mapa-múndi de 90 cm com continentes gravados em baixo-relevo.',
                'description' => 'Painel de parede com os continentes gravados em baixo-relevo. Perfeito para escritórios, salas e quartos de viajantes.',
                'highlights' => ['90 × 55 cm', 'Gravação em baixo-relevo', 'Kit de fixação incluso'], 'keywords' => 'mapa mundi parede viagem escritorio', 'care' => $care,
                'assembly' => 'Fixação com 2 parafusos e buchas (inclusos). Gabarito de furação na caixa.', 'spec' => ['MDF-AMD-06', 6, $natural], 'variants' => [], 'pers' => []],
            ['sub' => 'paineis', 'name' => 'Painel Ripado Decorativo', 'slug' => 'painel-ripado-decorativo', 'sku' => 'PNL-RIP-120', 'kind' => 'panel_slats', 'finish' => 'carvalho',
                'price' => 34990, 'compare' => null, 'cost' => 13200, 'material' => 'MDF amadeirado carvalho 15 mm', 'finish_label' => 'Carvalho', 'dims' => [900, 1200, 30], 'weight' => 7800,
                'lead' => 7, 'dispatch' => 2, 'stock' => null, 'featured' => false, 'new' => true, 'active' => true,
                'short' => 'Painel de ripas para cabeceira, sala ou parede de TV.',
                'description' => "Nove ripas de MDF carvalho fixadas em base oculta. Transforma uma parede em minutos, sem obra.\n\nMedida padrão de 90 × 120 cm; combine dois painéis lado a lado.",
                'highlights' => ['Instalação sem obra', 'Base oculta', 'Combina lado a lado'], 'keywords' => 'painel ripado cabeceira tv parede', 'care' => $care,
                'assembly' => 'Parafusar a base na parede (6 pontos) e encaixar o painel. Tempo médio: 30 minutos.', 'spec' => ['MDF-CRU-15', 2, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'mandalas', 'name' => 'Mandala Lótus 60 cm', 'slug' => 'mandala-lotus-60', 'sku' => 'MND-LOT-60', 'kind' => 'mandala', 'finish' => 'mdf',
                'price' => 15990, 'compare' => null, 'cost' => 5400, 'material' => 'MDF cru 6 mm', 'finish_label' => 'Natural', 'dims' => [600, 600, 6], 'weight' => 1300,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Mandala vazada de 60 cm com pétalas em três anéis.',
                'description' => 'Recorte detalhado em CNC formando uma flor de lótus em três anéis concêntricos. Linda sobre paredes coloridas.',
                'highlights' => ['60 cm de diâmetro', 'Recorte detalhado', 'Leve e fácil de fixar'], 'keywords' => 'mandala lotus parede zen yoga', 'care' => $care,
                'assembly' => 'Fixe com fita dupla-face de espuma ou com um prego fino no furo central.', 'spec' => ['MDF-CRU-06', 8, $natural],
                'variants' => ['Cor' => ['Natural' => [0, 'mdf'], 'Preta' => [1500, 'preto'], 'Branca' => [1500, 'branco']]], 'pers' => []],
            ['sub' => 'mandalas', 'name' => 'Mandala Flor da Vida', 'slug' => 'mandala-flor-da-vida', 'sku' => 'MND-FDV-45', 'kind' => 'mandala', 'finish' => 'preto',
                'price' => 13990, 'compare' => null, 'cost' => 4800, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Preto fosco', 'dims' => [450, 450, 6], 'weight' => 800,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Geometria sagrada em 45 cm, pintura preta fosca.',
                'description' => 'A clássica Flor da Vida recortada em MDF e pintada em preto fosco. Contraste marcante em paredes claras.',
                'highlights' => ['Pintura fosca', '45 cm', 'Recorte detalhado'], 'keywords' => 'mandala flor da vida geometria sagrada', 'care' => $care,
                'assembly' => 'Fixe com fita dupla-face de espuma.', 'spec' => ['MDF-CRU-06', 12, $paint], 'variants' => [], 'pers' => []],
            ['sub' => 'objetos-decorativos', 'name' => 'Vaso Geométrico Decorativo', 'slug' => 'vaso-geometrico-decorativo', 'sku' => 'OBJ-VAS-30', 'kind' => 'vase', 'finish' => 'terracota',
                'price' => 7990, 'compare' => null, 'cost' => 2700, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Terracota', 'dims' => [180, 300, 180], 'weight' => 900,
                'lead' => 2, 'dispatch' => 1, 'stock' => 14, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Vaso facetado para flores secas, pronta entrega.',
                'description' => 'Vaso de faces geométricas montado em MDF e pintado em terracota. Para flores secas, galhos e pampas.',
                'highlights' => ['Pronta entrega', 'Para flores secas', 'Base com feltro'], 'keywords' => 'vaso geometrico flores secas pampas', 'care' => $care . ' Não use com água.',
                'assembly' => null, 'spec' => ['MDF-CRU-06', 10, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'objetos-decorativos', 'name' => 'Luminária Pinheiro', 'slug' => 'luminaria-pinheiro', 'sku' => 'OBJ-LUM-40', 'kind' => 'lamp_tree', 'finish' => 'mdf',
                'price' => 19990, 'compare' => null, 'cost' => 7400, 'material' => 'MDF cru 6 mm', 'finish_label' => 'Natural', 'dims' => [300, 400, 300], 'weight' => 1200,
                'lead' => 6, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => false,
                'short' => 'Luminária de mesa em camadas com luz quente (fora de linha).',
                'description' => 'Luminária em camadas encaixadas com fita de LED quente. Produto temporariamente fora de linha.',
                'highlights' => ['LED quente', 'Encaixe sem cola'], 'keywords' => 'luminaria pinheiro natal led', 'care' => $care,
                'assembly' => 'Encaixe as camadas na haste central.', 'spec' => ['MDF-CRU-06', 6, $assembly], 'variants' => [], 'pers' => []],

            // ---- Organização ---------------------------------------------------------------
            ['sub' => 'organizadores', 'name' => 'Organizador Modular de Gavetas', 'slug' => 'organizador-modular-de-gavetas', 'sku' => 'ORG-GAV-6', 'kind' => 'drawer_organizer', 'finish' => 'mdf',
                'price' => 6990, 'compare' => null, 'cost' => 2300, 'material' => 'MDF cru 3 mm', 'finish_label' => 'Natural', 'dims' => [400, 70, 300], 'weight' => 700,
                'lead' => 2, 'dispatch' => 1, 'stock' => 24, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Divisórias encaixáveis para gavetas de cozinha, banheiro ou escritório.',
                'description' => 'Seis nichos com divisórias removíveis: reorganize conforme a necessidade. Encaixe sem cola.',
                'highlights' => ['Divisórias removíveis', 'Encaixe sem cola', 'Pronta entrega'], 'keywords' => 'organizador gaveta divisoria talheres', 'care' => $care,
                'assembly' => 'Encaixe as divisórias nas fendas da base. Não precisa de ferramentas.', 'spec' => ['MDF-CRU-03', 10, $natural], 'variants' => [], 'pers' => []],
            ['sub' => 'porta-objetos', 'name' => 'Porta-Chaves Minimalista', 'slug' => 'porta-chaves-minimalista', 'sku' => 'POR-CHV-5', 'kind' => 'key_holder', 'finish' => 'amadeirado',
                'price' => 5990, 'compare' => null, 'cost' => 1900, 'material' => 'MDF 3 ou 6 mm', 'finish_label' => 'Natural ou pintado', 'dims' => [350, 120, 30], 'weight' => 350,
                'lead' => 2, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Cinco ganchos e uma casinha: a entrada da casa organizada.',
                'description' => "Porta-chaves de parede com cinco ganchos metálicos e detalhe de casinha recortada.\n\nEscolha a espessura, o acabamento e, se quiser, o nome da família.",
                'highlights' => ['5 ganchos metálicos', 'Parafusos e buchas inclusos', 'Nome gravado opcional'], 'keywords' => 'porta chaves parede entrada casa familia', 'care' => $care,
                'assembly' => 'Fixação com 2 parafusos e buchas (inclusos).', 'spec' => ['MDF-CRU-06', 30, $assembly],
                'variants' => ['Material' => ['MDF 3 mm' => [0, 'mdf'], 'MDF 6 mm' => [1000, 'amadeirado']], 'Acabamento' => ['Natural' => [0, null], 'Pintado' => [1200, 'preto']]],
                'pers' => [['key' => 'nome', 'label' => 'Nome da família', 'type' => 'text', 'required' => false, 'max' => 16, 'charset' => 'letters', 'price' => 1000, 'help' => 'Opcional. Até 16 letras.']]],
            ['sub' => 'nichos', 'name' => 'Nicho Hexagonal', 'slug' => 'nicho-hexagonal', 'sku' => 'NCH-HEX-30', 'kind' => 'hex_niche', 'finish' => 'branco',
                'price' => 8990, 'compare' => null, 'cost' => 3000, 'material' => 'MDF branco TX 6 mm', 'finish_label' => 'Branco', 'dims' => [300, 260, 120], 'weight' => 1100,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Nicho de parede hexagonal para plantas, livros e objetos.',
                'description' => 'Montado com encaixes precisos e acabamento branco resistente. Use sozinho ou forme colmeias com vários nichos.',
                'highlights' => ['Suporta até 5 kg', 'Montado e colado', 'Combina em colmeia'], 'keywords' => 'nicho hexagonal colmeia parede plantas', 'care' => $care,
                'assembly' => 'Chega montado. Fixação com 2 parafusos e buchas (inclusos).', 'spec' => ['MDF-BRA-06', 8, $assembly],
                'variants' => ['Tamanho' => ['P (30 cm)' => [0, 'branco'], 'M (40 cm)' => [3000, 'branco']]], 'pers' => []],
            ['sub' => 'nichos', 'name' => 'Kit 3 Nichos Colmeia', 'slug' => 'kit-3-nichos-colmeia', 'sku' => 'NCH-KIT-3', 'kind' => 'honeycomb', 'finish' => 'carvalho',
                'price' => 22990, 'compare' => 25990, 'cost' => 8600, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Carvalho', 'dims' => [700, 520, 120], 'weight' => 3200,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Três nichos hexagonais amadeirados para montar a sua colmeia.',
                'description' => 'Kit com três nichos hexagonais em carvalho e gabarito de papel para acertar a composição na parede.',
                'highlights' => ['3 nichos', 'Gabarito de instalação', 'Economize 12%'], 'keywords' => 'kit nichos colmeia hexagonal', 'care' => $care,
                'assembly' => 'Fixação com 6 parafusos e buchas (inclusos) e gabarito de papel.', 'spec' => ['MDF-AMD-06', 3, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'suportes', 'name' => 'Suporte para Fones', 'slug' => 'suporte-para-fones', 'sku' => 'SUP-FON-25', 'kind' => 'headphone_stand', 'finish' => 'nogueira',
                'price' => 4990, 'compare' => null, 'cost' => 1700, 'material' => 'MDF amadeirado nogueira 15 mm', 'finish_label' => 'Nogueira', 'dims' => [150, 260, 150], 'weight' => 600,
                'lead' => 2, 'dispatch' => 1, 'stock' => 0, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Suporte de mesa para headphones, pronta entrega.',
                'description' => 'Base pesada e haste firme para guardar o fone sem amassar o arco. Acabamento nogueira.',
                'highlights' => ['Base antiderrapante', 'Pronta entrega'], 'keywords' => 'suporte fone headphone gamer mesa', 'care' => $care,
                'assembly' => 'Encaixe a haste na base.', 'spec' => ['MDF-CRU-15', 14, $natural], 'variants' => [], 'pers' => []],
            ['sub' => 'suportes', 'name' => 'Organizador de Cabos', 'slug' => 'organizador-de-cabos', 'sku' => 'SUP-CAB-6', 'kind' => 'cable_organizer', 'finish' => 'preto',
                'price' => 3990, 'compare' => null, 'cost' => 1100, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Preto fosco', 'dims' => [200, 40, 50], 'weight' => 180,
                'lead' => 2, 'dispatch' => 1, 'stock' => 40, 'featured' => false, 'new' => true, 'active' => true,
                'short' => 'Seis encaixes para cabos de carregador sempre à mão.',
                'description' => 'Base pesada com seis encaixes para cabos USB, carregadores e fones. Nunca mais cabo caído atrás da mesa.',
                'highlights' => ['6 encaixes', 'Base emborrachada', 'Pronta entrega'], 'keywords' => 'organizador cabos usb carregador mesa', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-CRU-06', 60, $paint], 'variants' => [], 'pers' => []],

            // ---- Cozinha -------------------------------------------------------------------
            ['sub' => 'porta-temperos', 'name' => 'Porta-Tempero de Parede', 'slug' => 'porta-tempero-de-parede', 'sku' => 'COZ-TMP-10', 'kind' => 'spice_rack', 'finish' => 'carvalho',
                'price' => 11990, 'compare' => null, 'cost' => 4100, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Carvalho', 'dims' => [500, 400, 90], 'weight' => 1800,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Duas prateleiras para 10 potes de tempero, fixado na parede.',
                'description' => "Prateleiras com guarda-corpo para os potes não caírem. Libera espaço na bancada e deixa tudo à vista.\n\nPotes não inclusos (compatíveis com potes de até 6 cm de diâmetro).",
                'highlights' => ['Para 10 potes', 'Guarda-corpo frontal', 'Kit de fixação incluso'], 'keywords' => 'porta tempero parede cozinha prateleira potes', 'care' => $care,
                'assembly' => 'Montagem com 4 parafusos (inclusos). Tempo médio: 15 minutos.', 'spec' => ['MDF-AMD-06', 6, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'porta-temperos', 'name' => 'Porta-Temperos Giratório', 'slug' => 'porta-temperos-giratorio', 'sku' => 'COZ-GIR-12', 'kind' => 'spice_carousel', 'finish' => 'mdf',
                'price' => 14990, 'compare' => null, 'cost' => 5300, 'material' => 'MDF cru 6 mm', 'finish_label' => 'Natural envernizado', 'dims' => [300, 280, 300], 'weight' => 1500,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Carrossel de bancada para 12 temperos, gira 360°.',
                'description' => 'Base giratória com rolamento: todos os temperos ao alcance da mão. Verniz protetor contra respingos.',
                'highlights' => ['Gira 360°', 'Para 12 potes', 'Verniz protetor'], 'keywords' => 'porta temperos giratorio bancada', 'care' => $careKitchen,
                'assembly' => 'Encaixe a haste central e a bandeja superior.', 'spec' => ['MDF-CRU-06', 5, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'organizadores-de-cozinha', 'name' => 'Kit Organizadores de Cozinha', 'slug' => 'kit-organizadores-de-cozinha', 'sku' => 'COZ-KIT-2', 'kind' => 'kitchen_kit', 'finish' => 'amadeirado',
                'price' => 18990, 'compare' => 21990, 'cost' => 6800, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Amadeirado', 'dims' => [300, 200, 200], 'weight' => 2000,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Porta-utensílios e caixa multiuso para a bancada.',
                'description' => 'Duas peças que conversam entre si: porta-utensílios com divisória e caixa multiuso para sachês e guardanapos.',
                'highlights' => ['2 peças', 'Economize 14%', 'Verniz protetor'], 'keywords' => 'kit organizador cozinha utensilios bancada', 'care' => $careKitchen,
                'assembly' => null, 'spec' => ['MDF-AMD-06', 6, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'tabuas', 'name' => 'Tábua de Frios Personalizada', 'slug' => 'tabua-de-frios-personalizada', 'sku' => 'COZ-TAB-40', 'kind' => 'cutting_board', 'finish' => 'carvalho',
                'price' => 12990, 'compare' => null, 'cost' => 4200, 'material' => 'Madeira pinus 20 mm', 'finish_label' => 'Óleo mineral', 'dims' => [400, 25, 250], 'weight' => 1100,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Tábua oval de madeira maciça com nome gravado.',
                'description' => 'Tábua de madeira maciça tratada com óleo mineral atóxico, com gravação personalizada. Perfeita para servir frios e petiscos.',
                'highlights' => ['Madeira maciça', 'Acabamento atóxico', 'Gravação a laser'], 'keywords' => 'tabua frios personalizada presente churrasco', 'care' => $careKitchen,
                'assembly' => null, 'spec' => ['PINUS-20', 6, ['cnc' => 30, 'sanding' => 15, 'painting' => 5, 'drying' => 30, 'quality' => 3, 'packaging' => 5]], 'variants' => [],
                'pers' => [['key' => 'nome', 'label' => 'Nome gravado', 'type' => 'text', 'required' => false, 'max' => 20, 'charset' => 'text_basic', 'price' => 1500, 'help' => 'Opcional. Até 20 caracteres.']]],
            ['sub' => 'suportes-de-cozinha', 'name' => 'Suporte para Taças', 'slug' => 'suporte-para-tacas', 'sku' => 'COZ-TAC-6', 'kind' => 'glass_holder', 'finish' => 'nogueira',
                'price' => 9990, 'compare' => null, 'cost' => 3400, 'material' => 'MDF amadeirado nogueira 15 mm', 'finish_label' => 'Nogueira', 'dims' => [500, 60, 250], 'weight' => 1300,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Suporte suspenso para até 9 taças, fixado sob armário.',
                'description' => 'Trilhos recortados para pendurar taças de cabeça para baixo. Economiza espaço e protege os cristais.',
                'highlights' => ['Até 9 taças', 'Fixação sob armário'], 'keywords' => 'suporte tacas vinho bar', 'care' => $care,
                'assembly' => 'Fixação com 4 parafusos (inclusos) sob o armário.', 'spec' => ['MDF-CRU-15', 6, $natural], 'variants' => [], 'pers' => []],
            ['sub' => 'suportes-de-cozinha', 'name' => 'Porta-Guardanapos Vazado', 'slug' => 'porta-guardanapos-vazado', 'sku' => 'COZ-GUA-1', 'kind' => 'napkin_holder', 'finish' => 'mdf',
                'price' => 3490, 'compare' => null, 'cost' => 1000, 'material' => 'MDF cru 6 mm', 'finish_label' => 'Natural', 'dims' => [180, 150, 90], 'weight' => 300,
                'lead' => 1, 'dispatch' => 1, 'stock' => 32, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Porta-guardanapos de mesa, pronta entrega.',
                'description' => 'Duas laterais em formato de casinha e base firme: simples, bonito e barato.',
                'highlights' => ['Pronta entrega', 'Encaixe sem cola'], 'keywords' => 'porta guardanapos mesa', 'care' => $care,
                'assembly' => 'Encaixe as laterais na base.', 'spec' => ['MDF-CRU-06', 40, $natural], 'variants' => [], 'pers' => []],

            // ---- Escritório ----------------------------------------------------------------
            ['sub' => 'organizadores-de-mesa', 'name' => 'Organizador de Mesa Modular', 'slug' => 'organizador-de-mesa-modular', 'sku' => 'ESC-ORG-3', 'kind' => 'desk_organizer', 'finish' => 'carvalho',
                'price' => 13990, 'compare' => null, 'cost' => 4900, 'material' => 'MDF amadeirado 6 mm', 'finish_label' => 'Carvalho', 'dims' => [320, 110, 160], 'weight' => 1000,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => true, 'new' => false, 'active' => true,
                'short' => 'Três compartimentos para canetas, bloco de notas e celular.',
                'description' => 'Organizador de mesa com compartimentos para canetas, post-its, cartões e o celular em pé. Cantos arredondados e acabamento carvalho.',
                'highlights' => ['3 compartimentos', 'Apoio para celular', 'Feltro na base'], 'keywords' => 'organizador mesa escritorio home office canetas', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-AMD-06', 10, $assembly], 'variants' => [],
                'pers' => [['key' => 'iniciais', 'label' => 'Iniciais gravadas', 'type' => 'initial', 'required' => false, 'max' => 3, 'price' => 900, 'help' => 'Opcional. Até 3 letras.']]],
            ['sub' => 'porta-canetas', 'name' => 'Porta-Canetas Executivo', 'slug' => 'porta-canetas-executivo', 'sku' => 'ESC-CAN-1', 'kind' => 'pen_holder', 'finish' => 'preto',
                'price' => 5990, 'compare' => null, 'cost' => 1800, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Preto fosco', 'dims' => [90, 110, 90], 'weight' => 300,
                'lead' => 2, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Porta-canetas preto fosco com plaquinha gravada.',
                'description' => 'Linhas retas, pintura fosca e uma plaquinha amadeirada com as iniciais do dono da mesa.',
                'highlights' => ['Pintura fosca', 'Plaquinha gravada', 'Presente corporativo'], 'keywords' => 'porta canetas executivo presente corporativo', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-CRU-06', 40, $paint], 'variants' => [],
                'pers' => [['key' => 'iniciais', 'label' => 'Iniciais', 'type' => 'initial', 'required' => false, 'max' => 3, 'price' => 700, 'help' => 'Opcional. Até 3 letras.']]],
            ['sub' => 'suportes-para-notebook', 'name' => 'Suporte para Notebook Ajustável', 'slug' => 'suporte-para-notebook-ajustavel', 'sku' => 'ESC-NTB-3', 'kind' => 'laptop_stand', 'finish' => 'carvalho',
                'price' => 17990, 'compare' => null, 'cost' => 6100, 'material' => 'MDF amadeirado 15 mm', 'finish_label' => 'Carvalho', 'dims' => [280, 160, 240], 'weight' => 1400,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => true, 'active' => true,
                'short' => 'Três alturas para trabalhar com a tela na altura dos olhos.',
                'description' => 'Suporte ergonômico com três posições de altura. Ventilação livre por baixo e desmonta para transportar.',
                'highlights' => ['3 alturas', 'Desmontável', 'Até 17"'], 'keywords' => 'suporte notebook ergonomico home office', 'care' => $care,
                'assembly' => 'Encaixe as laterais na base na altura desejada. Sem ferramentas.', 'spec' => ['MDF-CRU-15', 8, $natural], 'variants' => [], 'pers' => []],
            ['sub' => 'porta-documentos', 'name' => 'Porta-Documentos A4', 'slug' => 'porta-documentos-a4', 'sku' => 'ESC-DOC-3', 'kind' => 'document_tray', 'finish' => 'branco',
                'price' => 8990, 'compare' => null, 'cost' => 3000, 'material' => 'MDF branco TX 6 mm', 'finish_label' => 'Branco', 'dims' => [260, 250, 330], 'weight' => 1600,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Três bandejas empilhadas para documentos A4.',
                'description' => 'Bandejas com recorte frontal para pegar as folhas com facilidade. Organização de escritório sem plástico.',
                'highlights' => ['3 bandejas', 'Formato A4', 'Empilhável'], 'keywords' => 'porta documentos a4 bandeja escritorio', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-BRA-06', 6, $assembly], 'variants' => [], 'pers' => []],

            // ---- Presentes -----------------------------------------------------------------
            ['sub' => 'kits', 'name' => 'Kit Presente Café', 'slug' => 'kit-presente-cafe', 'sku' => 'PRE-CAF-3', 'kind' => 'gift_box', 'finish' => 'mdf',
                'price' => 15990, 'compare' => 18990, 'cost' => 5700, 'material' => 'MDF cru 3 e 6 mm', 'finish_label' => 'Natural', 'dims' => [300, 150, 200], 'weight' => 1400,
                'lead' => 3, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Caixa de presente com suporte para cápsulas e porta-copos.',
                'description' => 'Kit para quem ama café: suporte para cápsulas, quatro porta-copos e caixa de presente com fita.',
                'highlights' => ['3 itens', 'Caixa de presente', 'Economize 16%'], 'keywords' => 'kit presente cafe capsulas porta copos', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-CRU-03', 5, $assembly], 'variants' => [],
                'pers' => [['key' => 'mensagem', 'label' => 'Mensagem no cartão', 'type' => 'text', 'required' => false, 'max' => 60, 'charset' => 'text_basic', 'price' => 0, 'help' => 'Opcional. Até 60 caracteres, impressa no cartão.']]],
            ['sub' => 'lembrancas', 'name' => 'Caixa de Lembranças Gravada', 'slug' => 'caixa-de-lembrancas-gravada', 'sku' => 'PRE-CXL-1', 'kind' => 'memory_box', 'finish' => 'nogueira',
                'price' => 9990, 'compare' => null, 'cost' => 3200, 'material' => 'MDF amadeirado nogueira 6 mm', 'finish_label' => 'Nogueira', 'dims' => [250, 120, 180], 'weight' => 900,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Caixa com tampa gravada para guardar cartas, fotos e memórias.',
                'description' => 'Caixa com tampa articulada e coração gravado. Grave uma data especial na lateral.',
                'highlights' => ['Tampa articulada', 'Data gravada opcional'], 'keywords' => 'caixa lembrancas memorias presente namorados', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-AMD-06', 8, $assembly], 'variants' => [],
                'pers' => [['key' => 'data', 'label' => 'Data especial', 'type' => 'date', 'required' => false, 'price' => 800, 'help' => 'Opcional. Gravada na lateral.']]],
            ['sub' => 'comemorativos', 'name' => 'Porta-Retrato Casamento', 'slug' => 'porta-retrato-casamento', 'sku' => 'PRE-RET-15', 'kind' => 'photo_frame', 'finish' => 'branco',
                'price' => 11990, 'compare' => null, 'cost' => 3900, 'material' => 'MDF branco TX 6 mm', 'finish_label' => 'Branco', 'dims' => [200, 250, 15], 'weight' => 600,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Porta-retrato 10 × 15 com os nomes do casal e a data.',
                'description' => 'Porta-retrato de mesa com plaquinha gravada com os nomes e a data do casamento.',
                'highlights' => ['Foto 10 × 15', 'Gravação inclusa', 'Pé de apoio'], 'keywords' => 'porta retrato casamento noivos presente', 'care' => $care,
                'assembly' => null, 'spec' => ['MDF-BRA-06', 20, $paint], 'variants' => [],
                'pers' => [
                    ['key' => 'nomes', 'label' => 'Nomes do casal', 'type' => 'text', 'required' => true, 'max' => 30, 'charset' => 'text_basic', 'price' => 0, 'help' => 'Ex.: Ana & Pedro'],
                    ['key' => 'data', 'label' => 'Data do casamento', 'type' => 'date', 'required' => true, 'price' => 0, 'help' => null],
                ]],

            // ---- Linha Infantil -------------------------------------------------------------
            ['sub' => 'nome-decorativo', 'name' => 'Nome Decorativo Infantil', 'slug' => 'nome-decorativo-infantil', 'sku' => 'INF-NOM-1', 'kind' => 'name_letters', 'finish' => 'mdf',
                'price' => 8990, 'compare' => null, 'cost' => 2800, 'material' => 'MDF 6 mm pintado', 'finish_label' => 'Tons pastel', 'dims' => [600, 200, 6], 'weight' => 500,
                'lead' => 4, 'dispatch' => 1, 'stock' => null, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'O nome da criança em letras recortadas e pintadas em tons pastel.',
                'description' => 'Letras de 20 cm em MDF pintado, para parede do quarto ou festa de aniversário. Preço para até 6 letras.',
                'highlights' => ['Letras de 20 cm', 'Tons pastel', 'Até 6 letras'], 'keywords' => 'nome decorativo infantil quarto bebe festa', 'care' => $care,
                'assembly' => 'Fixe cada letra com fita dupla-face de espuma (inclusa).', 'spec' => ['MDF-CRU-06', 24, $paint], 'variants' => [],
                'pers' => [
                    ['key' => 'nome', 'label' => 'Nome da criança', 'type' => 'text', 'required' => true, 'max' => 6, 'charset' => 'letters', 'price' => 0, 'help' => 'Até 6 letras.'],
                    ['key' => 'cores', 'label' => 'Paleta de cores', 'type' => 'select', 'required' => true, 'price' => 0, 'values' => [['pastel', 'Tons pastel', 0], ['azuis', 'Azuis', 0], ['rosas', 'Rosas', 0], ['natural', 'Natural (sem pintura)', 0]]],
                ]],
            ['sub' => 'organizadores-infantis', 'name' => 'Estante Casinha para Livros', 'slug' => 'estante-casinha-para-livros', 'sku' => 'INF-EST-60', 'kind' => 'house_shelf', 'finish' => 'branco',
                'price' => 24990, 'compare' => null, 'cost' => 9100, 'material' => 'MDF branco TX 15 mm', 'finish_label' => 'Branco', 'dims' => [600, 800, 250], 'weight' => 6400,
                'lead' => 6, 'dispatch' => 2, 'stock' => null, 'featured' => false, 'new' => true, 'active' => true,
                'short' => 'Estante em formato de casinha com duas prateleiras para livros infantis.',
                'description' => 'Cantos arredondados, fixação antitombamento e duas prateleiras na altura das crianças. Incentiva a leitura e a autonomia.',
                'highlights' => ['Cantos arredondados', 'Fixação antitombamento', '2 prateleiras'], 'keywords' => 'estante casinha livros infantil montessori', 'care' => $care,
                'assembly' => 'Montagem com parafusos (inclusos) em cerca de 40 minutos. Fixe na parede com o kit antitombamento.', 'spec' => ['MDF-CRU-15', 2, $assembly], 'variants' => [], 'pers' => []],
            ['sub' => 'decoracao-infantil', 'name' => 'Móbile Nuvens', 'slug' => 'mobile-nuvens', 'sku' => 'INF-MOB-4', 'kind' => 'cloud_mobile', 'finish' => 'mdf',
                'price' => 7990, 'compare' => null, 'cost' => 2400, 'material' => 'MDF 3 mm pintado', 'finish_label' => 'Tons pastel', 'dims' => [450, 400, 30], 'weight' => 250,
                'lead' => 3, 'dispatch' => 1, 'stock' => 9, 'featured' => false, 'new' => false, 'active' => true,
                'short' => 'Móbile com quatro nuvens para o quarto do bebê.',
                'description' => 'Nuvens em MDF 3 mm pintadas em tons pastel, suspensas por fios de algodão. Pronta entrega.',
                'highlights' => ['Pronta entrega', 'Tinta atóxica', 'Fios de algodão'], 'keywords' => 'mobile nuvens bebe quarto infantil', 'care' => $care,
                'assembly' => 'Pendure no teto com o gancho incluso, fora do alcance das crianças.', 'spec' => ['MDF-CRU-03', 20, $paint], 'variants' => [], 'pers' => []],
        ];
    }

    /**
     * Clientes: [nome, e-mail, telefone, CEP, rua, número, bairro, cidade, UF, tem conta?, dias desde o cadastro].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: bool, 10: int}>
     */
    public static function customers(): array
    {
        return [
            ['Mariana Costa', 'mariana.costa@exemplo.com.br', '(71) 99812-4410', '40140-110', 'Rua Direita da Piedade', '120', 'Barris', 'Salvador', 'BA', true, 88],
            ['Rafael Oliveira', 'rafael.oliveira@exemplo.com.br', '(11) 98765-2201', '01310-100', 'Av. Paulista', '1578', 'Bela Vista', 'São Paulo', 'SP', true, 80],
            ['Juliana Martins', 'juliana.martins@exemplo.com.br', '(21) 99654-3398', '22250-040', 'Rua Voluntários da Pátria', '45', 'Botafogo', 'Rio de Janeiro', 'RJ', true, 76],
            ['Carlos Eduardo Lima', 'carlos.lima@exemplo.com.br', '(31) 99123-7785', '30130-010', 'Av. Afonso Pena', '900', 'Centro', 'Belo Horizonte', 'MG', false, 70],
            ['Fernanda Rocha', 'fernanda.rocha@exemplo.com.br', '(41) 99501-6630', '80010-000', 'Rua XV de Novembro', '300', 'Centro', 'Curitiba', 'PR', true, 66],
            ['Bruno Almeida', 'bruno.almeida@exemplo.com.br', '(51) 98444-1022', '90010-150', 'Rua dos Andradas', '1001', 'Centro Histórico', 'Porto Alegre', 'RS', false, 61],
            ['Patrícia Souza', 'patricia.souza@exemplo.com.br', '(81) 99870-5543', '50030-230', 'Rua do Bom Jesus', '220', 'Recife', 'Recife', 'PE', true, 55],
            ['Thiago Ferreira', 'thiago.ferreira@exemplo.com.br', '(85) 98876-3412', '60060-440', 'Av. Santos Dumont', '1510', 'Aldeota', 'Fortaleza', 'CE', true, 50],
            ['Camila Ribeiro', 'camila.ribeiro@exemplo.com.br', '(61) 99345-7781', '70040-010', 'SBS Quadra 2', '12', 'Asa Sul', 'Brasília', 'DF', false, 44],
            ['Lucas Carvalho', 'lucas.carvalho@exemplo.com.br', '(62) 98123-5590', '74003-010', 'Av. Goiás', '400', 'Centro', 'Goiânia', 'GO', true, 40],
            ['Aline Gomes', 'aline.gomes@exemplo.com.br', '(92) 99231-4487', '69005-040', 'Av. Eduardo Ribeiro', '520', 'Centro', 'Manaus', 'AM', false, 33],
            ['Diego Barbosa', 'diego.barbosa@exemplo.com.br', '(91) 98712-6604', '66010-000', 'Av. Presidente Vargas', '640', 'Campina', 'Belém', 'PA', true, 28],
            ['Renata Dias', 'renata.dias@exemplo.com.br', '(48) 99612-3301', '88010-400', 'Rua Felipe Schmidt', '315', 'Centro', 'Florianópolis', 'SC', true, 21],
            ['Gustavo Pereira', 'gustavo.pereira@exemplo.com.br', '(27) 99845-1190', '29010-120', 'Av. Jerônimo Monteiro', '210', 'Centro', 'Vitória', 'ES', false, 15],
            ['Larissa Mendes', 'larissa.mendes@exemplo.com.br', '(19) 99432-8812', '13010-111', 'Rua Barão de Jaguara', '1080', 'Centro', 'Campinas', 'SP', true, 9],
            ['Vinícius Araújo', 'vinicius.araujo@exemplo.com.br', '(75) 99187-2256', '44001-032', 'Rua Conselheiro Franco', '76', 'Centro', 'Feira de Santana', 'BA', true, 4],
        ];
    }

    /**
     * Pedidos: [cliente (índice), linhas [[slug, qtd, variação (texto) ou null, personalização [chave => valor]]], dias atrás, destino, frete, cupom].
     * Destinos: aguardando | cancelado | estornado | pago | cnc | lixamento | pintura | cq | embalagem | pronto | enviado | entregue
     *
     * @return list<array{0: int, 1: list<array{0: string, 1: int, 2: ?string, 3: array<string, string>}>, 2: int, 3: string, 4: string, 5: ?string}>
     */
    public static function orders(): array
    {
        return [
            [0, [['relogio-geometrico-mdf', 1, 'Natural', ['nome_gravado' => 'Casa Costa']], ['porta-guardanapos-vazado', 2, null, []]], 74, 'entregue', 'economico', null],
            [1, [['painel-ripado-decorativo', 1, null, []]], 70, 'entregue', 'expresso', null],
            [2, [['mandala-lotus-60', 1, 'Branca', []], ['vaso-geometrico-decorativo', 1, null, []]], 66, 'entregue', 'economico', 'BEMVINDO10'],
            [3, [['organizador-de-mesa-modular', 2, null, ['iniciais' => 'CEL']]], 62, 'entregue', 'economico', null],
            [4, [['porta-tempero-de-parede', 1, null, []], ['kit-organizadores-de-cozinha', 1, null, []]], 58, 'entregue', 'economico', null],
            [5, [['quadro-mapa-mundi-vazado', 1, null, []]], 54, 'entregue', 'expresso', null],
            [6, [['porta-chaves-minimalista', 1, 'MDF 6 mm / Pintado', ['nome' => 'Souza']]], 49, 'entregue', 'economico', null],
            [7, [['relogio-personalizado-com-nome', 1, null, ['texto' => 'Ferreira & Cia', 'fonte' => 'moderna']], ['organizador-de-cabos', 3, null, []]], 45, 'estornado', 'economico', null],
            [8, [['kit-3-nichos-colmeia', 1, null, []]], 40, 'enviado', 'economico', 'FRETEGRATIS'],
            [9, [['suporte-para-notebook-ajustavel', 1, null, []], ['porta-canetas-executivo', 2, null, ['iniciais' => 'LC']]], 36, 'enviado', 'expresso', null],
            [10, [['nome-decorativo-infantil', 1, null, ['nome' => 'Helena', 'cores' => 'rosas']], ['mobile-nuvens', 1, null, []]], 31, 'enviado', 'economico', null],
            [11, [['tabua-de-frios-personalizada', 2, null, ['nome' => 'Churrasco do Diego']]], 27, 'enviado', 'economico', null],
            [0, [['nicho-hexagonal', 3, 'P (30 cm)', []]], 22, 'pronto', 'economico', null],
            [12, [['porta-retrato-casamento', 1, null, ['nomes' => 'Renata & Paulo', 'data' => '2026-11-14']]], 19, 'pronto', 'economico', null],
            [13, [['relogio-hexagonal-nordico', 1, null, []], ['mandala-flor-da-vida', 1, null, []]], 17, 'pronto', 'expresso', null],
            [1, [['estante-casinha-para-livros', 1, null, []]], 15, 'embalagem', 'economico', null],
            [14, [['caixa-de-lembrancas-gravada', 2, null, ['data' => '2020-03-08']]], 12, 'cq', 'economico', null],
            [15, [['relogio-geometrico-mdf', 2, 'Preto fosco', []]], 10, 'pintura', 'economico', null],
            [3, [['quadro-decorativo-floral', 2, null, []], ['kit-presente-cafe', 1, null, ['mensagem' => 'Feliz aniversário, mãe!']]], 8, 'lixamento', 'economico', null],
            [5, [['porta-temperos-giratorio', 1, null, []]], 6, 'cnc', 'economico', null],
            [9, [['relogio-minimalista-carvalho', 1, null, []], ['porta-documentos-a4', 1, null, []]], 5, 'cnc', 'expresso', null],
            [2, [['suporte-para-tacas', 1, null, []]], 3, 'pago', 'economico', null],
            [12, [['mandala-lotus-60', 2, 'Natural', []]], 1, 'pago', 'economico', null],
            [7, [['organizador-modular-de-gavetas', 4, null, []]], 0, 'pago', 'economico', null],
            [13, [['porta-chaves-minimalista', 2, 'MDF 3 mm / Natural', []]], 2, 'aguardando', 'economico', null],
            [6, [['kit-organizadores-de-cozinha', 1, null, []]], 1, 'aguardando', 'economico', 'BEMVINDO10'],
            [10, [['painel-ripado-decorativo', 1, null, []]], 0, 'aguardando', 'expresso', null],
            [4, [['relogio-hexagonal-nordico', 1, null, []]], 20, 'cancelado', 'economico', null],
            [8, [['quadro-decorativo-floral', 1, null, []]], 11, 'cancelado', 'economico', null],
        ];
    }
}
