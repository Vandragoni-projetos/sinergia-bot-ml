-- SINERGIA BOT ML — 0013 vínculo link ↔ oferta (etapa 12C)
-- O link de afiliado é gerado para UM anúncio (item_id) de um produto de catálogo. Guardar esse item_id no item do
-- lote exportado e no link permite ao envio publicar SOMENTE o preço atual daquele mesmo anúncio. Nunca é trocado por
-- outro anúncio. Colunas anuláveis: links antigos (sem item) ficam bloqueados para envio até receberem link novo.

ALTER TABLE affiliate_link_batch_items
    ADD COLUMN IF NOT EXISTS offer_item_id VARCHAR(32) NULL AFTER ml_product_id,
    ADD CONSTRAINT IF NOT EXISTS ck_affiliate_link_batch_items_offer CHECK (offer_item_id IS NULL OR offer_item_id REGEXP '^MLB[0-9]{1,20}$');

ALTER TABLE affiliate_links
    ADD COLUMN IF NOT EXISTS offer_item_id VARCHAR(32) NULL AFTER ml_product_id,
    ADD CONSTRAINT IF NOT EXISTS ck_affiliate_links_offer CHECK (offer_item_id IS NULL OR offer_item_id REGEXP '^MLB[0-9]{1,20}$');
