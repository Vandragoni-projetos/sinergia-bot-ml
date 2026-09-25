-- SINERGIA BOT ML — 0006 catálogo de nichos, curadoria v1 (F1, etapa 3)
-- Evidência completa (amostras de produtos por categoria): docs/curadoria/catalogo-v1-2026-09-25.md
-- Aprovadas: árvore oficial compatível + amostra /highlights → /products com ≥ 8 produtos de catálogo e ≥ 85%
-- em domínios compatíveis. Rejeitadas: caminho incompatível na árvore oficial (brinquedo, pet, bebê, indústria…).

INSERT IGNORE INTO niches (slug, name, sort) VALUES
    ('casa-cozinha', 'Casa e cozinha', 10),
    ('beleza-cuidados', 'Beleza e cuidados', 20),
    ('eletronicos', 'Eletrônicos', 30),
    ('esporte-saude', 'Esporte e saúde', 40);

INSERT IGNORE INTO subniches (niche_id, slug, name, sort)
WITH s (niche, slug, name, sort) AS (
    VALUES
    ('casa-cozinha', 'air-fryers', 'Air fryers', 10),
    ('casa-cozinha', 'panelas', 'Panelas', 20),
    ('casa-cozinha', 'cafeteiras', 'Cafeteiras', 30),
    ('casa-cozinha', 'eletroportateis', 'Eletroportáteis', 40),
    ('casa-cozinha', 'organizacao-cozinha', 'Organização da cozinha', 50),
    ('beleza-cuidados', 'perfumes', 'Perfumes', 10),
    ('beleza-cuidados', 'maquiagem', 'Maquiagem', 20),
    ('beleza-cuidados', 'cabelos', 'Cabelos', 30),
    ('beleza-cuidados', 'barbear', 'Barbear e aparadores', 40),
    ('beleza-cuidados', 'pele', 'Cuidados com a pele', 50),
    ('eletronicos', 'fones', 'Fones de ouvido', 10),
    ('eletronicos', 'smartwatches', 'Smartwatches', 20),
    ('eletronicos', 'caixas-de-som', 'Caixas de som', 30),
    ('eletronicos', 'carregadores', 'Carregadores e cabos', 40),
    ('esporte-saude', 'suplementos', 'Suplementos', 10),
    ('esporte-saude', 'treino-em-casa', 'Treino em casa', 20)
)
SELECT n.id, s.slug, s.name, s.sort FROM s JOIN niches n ON n.slug = s.niche;

INSERT IGNORE INTO subniche_categories
    (subniche_id, site_id, ml_category_id, status, expected_domains, sample_products, sample_matching, note, curation_version, validated_at)
WITH c (niche, sub, cat, status, domains, products, matching, note) AS (
    VALUES
    -- Casa e cozinha
    ('casa-cozinha', 'air-fryers', 'MLB456045', 'approved', 'MLB-AIR_FRYERS', 20, 20, NULL),
    ('casa-cozinha', 'air-fryers', 'MLB31682', 'rejected', NULL, NULL, NULL, 'Fritadeiras de óleo (OIL_DEEP_FRYERS), não é air fryer'),
    ('casa-cozinha', 'air-fryers', 'MLB49116', 'rejected', NULL, NULL, NULL, 'Ramo Indústria e Comércio (industrial)'),
    ('casa-cozinha', 'panelas', 'MLB107564', 'approved', 'MLB-KITCHEN_COOKWARE_KITS,MLB-KITCHEN_POTS,MLB-FRYING_PANS_WOKS_GRIDDLES_AND_GRILL_PANS', 17, 17, NULL),
    ('casa-cozinha', 'panelas', 'MLB107501', 'approved', 'MLB-KITCHEN_POTS', 20, 20, NULL),
    ('casa-cozinha', 'cafeteiras', 'MLB9188', 'approved', 'MLB-ELECTRIC_COFFEE_MAKERS', 20, 20, NULL),
    ('casa-cozinha', 'cafeteiras', 'MLB439404', 'approved', 'MLB-MANUAL_COFFEE_MAKERS,MLB-ELECTRIC_COFFEE_MAKERS', 9, 9, 'Volume baixo de produtos de catálogo'),
    ('casa-cozinha', 'cafeteiras', 'MLB49092', 'rejected', NULL, NULL, NULL, 'Ramo Indústria e Comércio (industrial)'),
    ('casa-cozinha', 'cafeteiras', 'MLB439773', 'rejected', NULL, NULL, NULL, 'Ramo Antiguidades e Coleções'),
    ('casa-cozinha', 'cafeteiras', 'MLB455148', 'rejected', NULL, NULL, NULL, 'Porta-cápsulas (acessório), não é cafeteira'),
    ('casa-cozinha', 'eletroportateis', 'MLB73055', 'approved', 'MLB-BLENDERS', 18, 18, NULL),
    ('casa-cozinha', 'eletroportateis', 'MLB4339', 'approved', 'MLB-MIXERS,MLB-BLENDERS', 18, 18, NULL),
    ('casa-cozinha', 'eletroportateis', 'MLB263570', 'approved', 'MLB-HAND_BLENDERS', 19, 19, NULL),
    ('casa-cozinha', 'eletroportateis', 'MLB31683', 'approved', 'MLB-ELECTRIC_SANDWICH_MAKERS', 18, 18, NULL),
    ('casa-cozinha', 'eletroportateis', 'MLB270287', 'rejected', NULL, NULL, NULL, 'Geladeiras de brinquedo (Brinquedos de Faz de Conta); caso da Etapa 0'),
    ('casa-cozinha', 'eletroportateis', 'MLB270304', 'rejected', NULL, NULL, NULL, 'Liquidificadores de brinquedo'),
    ('casa-cozinha', 'eletroportateis', 'MLB270293', 'rejected', NULL, NULL, NULL, 'Batedeiras de brinquedo'),
    ('casa-cozinha', 'eletroportateis', 'MLB270087', 'rejected', NULL, NULL, NULL, 'Liquidificadores industriais'),
    ('casa-cozinha', 'eletroportateis', 'MLB40286', 'rejected', NULL, NULL, NULL, 'Ramo Antiguidades e Coleções'),
    ('casa-cozinha', 'eletroportateis', 'MLB277356', 'rejected', NULL, NULL, NULL, 'Misturadores de bebidas (utensílio de bar)'),
    ('casa-cozinha', 'organizacao-cozinha', 'MLB277460', 'approved', 'MLB-KITCHEN_CABINET_ORGANIZERS,MLB-MAKEUP_ORGANIZERS', 15, 15, 'Organizadores multiuso'),
    ('casa-cozinha', 'organizacao-cozinha', 'MLB244658', 'approved', 'MLB-FOOD_STORAGE_CONTAINERS', 17, 16, 'Desvio na amostra: 1 AIR_MATTRESSES'),
    ('casa-cozinha', 'organizacao-cozinha', 'MLB271673', 'approved', 'MLB-FLATWARE_ORGANIZERS', 11, 10, 'Desvio na amostra: 1 FLATWARE_KITS'),
    ('casa-cozinha', 'organizacao-cozinha', 'MLB392281', 'rejected', NULL, NULL, NULL, 'Latas de chimarrão'),
    ('casa-cozinha', 'organizacao-cozinha', 'MLB264036', 'rejected', NULL, NULL, NULL, 'Ramo Bebês'),
    -- Beleza e cuidados
    ('beleza-cuidados', 'perfumes', 'MLB6284', 'approved', 'MLB-PERFUMES', 15, 15, NULL),
    ('beleza-cuidados', 'maquiagem', 'MLB29871', 'approved', 'MLB-FOUNDATIONS,MLB-CONCEALERS', 13, 13, NULL),
    ('beleza-cuidados', 'maquiagem', 'MLB29907', 'approved', 'MLB-EYESHADOWS,MLB-BLUSHES_HIGHLIGHTERS_AND_COUNTOURS,MLB-CONCEALERS,MLB-EYELINERS,MLB-FOUNDATIONS', 18, 18, NULL),
    ('beleza-cuidados', 'maquiagem', 'MLB29901', 'approved', 'MLB-MASCARAS', 18, 18, NULL),
    ('beleza-cuidados', 'maquiagem', 'MLB29883', 'approved', 'MLB-LIPSTICKS', 15, 15, NULL),
    ('beleza-cuidados', 'maquiagem', 'MLB46290', 'rejected', NULL, NULL, NULL, 'Máscaras de festa'),
    ('beleza-cuidados', 'cabelos', 'MLB1265', 'approved', 'MLB-HAIR_SHAMPOOS_AND_CONDITIONERS,MLB-HAIR_TREATMENTS', 20, 20, NULL),
    ('beleza-cuidados', 'cabelos', 'MLB32130', 'approved', 'MLB-HAIR_TREATMENTS,MLB-HAIR_SHAMPOOS_AND_CONDITIONERS', 20, 20, NULL),
    ('beleza-cuidados', 'cabelos', 'MLB5412', 'approved', 'MLB-HAIR_DRYERS,MLB-ELECTRIC_HAIR_BRUSHES', 20, 20, NULL),
    ('beleza-cuidados', 'cabelos', 'MLB44085', 'approved', 'MLB-HAIR_STRAIGHTENERS', 19, 19, NULL),
    ('beleza-cuidados', 'cabelos', 'MLB178927', 'rejected', NULL, NULL, NULL, 'Ramo Pet Shop (cães)'),
    ('beleza-cuidados', 'cabelos', 'MLB251835', 'rejected', NULL, NULL, NULL, 'Ramo Pet Shop (gatos)'),
    ('beleza-cuidados', 'cabelos', 'MLB277729', 'rejected', NULL, NULL, NULL, 'Ramo Bebês'),
    ('beleza-cuidados', 'cabelos', 'MLB272132', 'rejected', NULL, NULL, NULL, 'Suporte de secador (acessório de banheiro)'),
    ('beleza-cuidados', 'barbear', 'MLB5411', 'approved', 'MLB-HAIR_CLIPPERS_ELECTRIC_SHAVERS_AND_HAIR_TRIMMERS', 14, 14, NULL),
    ('beleza-cuidados', 'barbear', 'MLB446228', 'approved', 'MLB-HAIR_CLIPPERS_ELECTRIC_SHAVERS_AND_HAIR_TRIMMERS', 17, 17, NULL),
    ('beleza-cuidados', 'barbear', 'MLB264794', 'approved', 'MLB-HAIR_CLIPPERS_ELECTRIC_SHAVERS_AND_HAIR_TRIMMERS', 16, 16, NULL),
    ('beleza-cuidados', 'barbear', 'MLB33367', 'rejected', NULL, NULL, NULL, 'Aparadores = móvel (Móveis de Armazenamento)'),
    ('beleza-cuidados', 'pele', 'MLB199648', 'approved', 'MLB-SUNSCREENS,MLB-BODY_SKIN_CARE_PRODUCTS', 20, 19, 'Desvio na amostra: 1 WATERPROOFING_PROTECTORS_AND_SURFACE_TREATMENTS'),
    ('beleza-cuidados', 'pele', 'MLB264874', 'approved', 'MLB-FACIAL_SKIN_CARE_PRODUCTS,MLB-BODY_SKIN_CARE_PRODUCTS', 20, 20, NULL),
    -- Eletrônicos
    ('eletronicos', 'fones', 'MLB196208', 'approved', 'MLB-HEADPHONES', 19, 19, NULL),
    ('eletronicos', 'fones', 'MLB1664', 'approved', 'MLB-HEADPHONES', 20, 20, NULL),
    ('eletronicos', 'smartwatches', 'MLB135384', 'approved', 'MLB-SMARTWATCHES', 20, 20, NULL),
    ('eletronicos', 'smartwatches', 'MLB271858', 'approved', 'MLB-SMARTWATCHES', 20, 20, NULL),
    ('eletronicos', 'smartwatches', 'MLB5106', 'rejected', NULL, NULL, NULL, 'Softwares para celular'),
    ('eletronicos', 'smartwatches', 'MLB277951', 'rejected', NULL, NULL, NULL, 'Máquinas POS para cartões'),
    ('eletronicos', 'caixas-de-som', 'MLB3843', 'approved', 'MLB-SPEAKERS', 13, 13, NULL),
    ('eletronicos', 'caixas-de-som', 'MLB3378', 'approved', 'MLB-SPEAKERS', 14, 14, NULL),
    ('eletronicos', 'carregadores', 'MLB430121', 'approved', 'MLB-MOBILE_DEVICE_CHARGERS', 18, 18, NULL),
    ('eletronicos', 'carregadores', 'MLB5080', 'approved', 'MLB-DATA_CABLES_AND_ADAPTERS', 13, 13, NULL),
    ('eletronicos', 'carregadores', 'MLB429318', 'rejected', NULL, NULL, NULL, 'Carregadores de brinquedo (mini veículos)'),
    -- Esporte e saúde
    ('esporte-saude', 'suplementos', 'MLB264201', 'approved', 'MLB-SUPPLEMENTS', 20, 20, NULL),
    ('esporte-saude', 'suplementos', 'MLB122102', 'approved', 'MLB-SUPPLEMENTS', 20, 20, NULL),
    ('esporte-saude', 'suplementos', 'MLB457239', 'rejected', NULL, NULL, NULL, 'Suplementos para animais de produção'),
    ('esporte-saude', 'suplementos', 'MLB271377', 'rejected', NULL, NULL, NULL, 'Suplementos para cavalos'),
    ('esporte-saude', 'suplementos', 'MLB422156', 'rejected', NULL, NULL, NULL, 'Cereais para bebês'),
    ('esporte-saude', 'suplementos', 'MLB269716', 'rejected', NULL, NULL, NULL, 'Cereais (mercearia)'),
    ('esporte-saude', 'treino-em-casa', 'MLB67501', 'approved', 'MLB-DUMBBELLS', 15, 15, NULL),
    ('esporte-saude', 'treino-em-casa', 'MLB438945', 'approved', 'MLB-EXERCISE_AND_YOGA_MATS', 9, 9, 'Volume baixo de produtos de catálogo'),
    ('esporte-saude', 'treino-em-casa', 'MLB123100', 'approved', 'MLB-RESISTANCE_BANDS,MLB-GYM_GLOVES', 15, 15, NULL)
)
SELECT s.id, 'MLB', c.cat, c.status, c.domains, c.products, c.matching, c.note, 'v1', '2026-09-25 13:40:00.000'
FROM c
JOIN niches n ON n.slug = c.niche
JOIN subniches s ON s.niche_id = n.id AND s.slug = c.sub;
