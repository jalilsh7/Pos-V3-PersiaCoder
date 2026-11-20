ALTER TABLE pos_product_variants
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER business_id;
UPDATE pos_product_variants SET public_id = UUID() WHERE public_id IS NULL;
ALTER TABLE pos_product_variants
  MODIFY COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_variants_public_id (public_id),
  ADD KEY idx_variants_business_product_active (business_id, product_id, is_active);

ALTER TABLE pos_option_groups
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER business_id,
  ADD COLUMN is_required_default TINYINT(1) NOT NULL DEFAULT 0 AFTER max_select;
UPDATE pos_option_groups SET public_id = UUID() WHERE public_id IS NULL;
ALTER TABLE pos_option_groups
  MODIFY COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_option_groups_public_id (public_id),
  ADD KEY idx_option_groups_business_active (business_id, is_active, name),
  ADD CONSTRAINT chk_option_groups_required_default CHECK (is_required_default IN (0,1));

ALTER TABLE pos_options
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER business_id;
UPDATE pos_options SET public_id = UUID() WHERE public_id IS NULL;
ALTER TABLE pos_options
  MODIFY COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_options_public_id (public_id),
  ADD KEY idx_options_business_group_active (business_id, group_id, is_active, name);

ALTER TABLE pos_product_option_groups
  ADD KEY idx_product_option_groups_business_product_sort (business_id, product_id, sort_order, option_group_id);
