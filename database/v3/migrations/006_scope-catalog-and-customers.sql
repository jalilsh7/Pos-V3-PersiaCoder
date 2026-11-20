ALTER TABLE pos_categories
  DROP FOREIGN KEY fk_categories_parent,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_categories_public_id (public_id),
  ADD UNIQUE KEY uq_categories_business_id_id (business_id, id),
  ADD KEY idx_categories_business_parent (business_id, parent_id, is_active, sort_order),
  ADD CONSTRAINT fk_categories_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;

-- MariaDB 10.6 requires the composite self-reference to be added after
-- the new columns and referenced unique index exist on the altered table.
ALTER TABLE pos_categories
  ADD CONSTRAINT fk_categories_parent_business FOREIGN KEY (business_id, parent_id)
    REFERENCES pos_categories (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_products
  DROP INDEX uq_products_sku,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_products_business_id_id (business_id, id),
  ADD UNIQUE KEY uq_products_business_sku (business_id, sku),
  ADD KEY idx_products_business_category (business_id, category_id, is_active, name),
  ADD CONSTRAINT fk_products_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_products_category_business FOREIGN KEY (business_id, category_id)
    REFERENCES pos_categories (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_product_variants
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_variants_business_id_id (business_id, id),
  ADD CONSTRAINT fk_variants_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_variants_product_business FOREIGN KEY (business_id, product_id)
    REFERENCES pos_products (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_option_groups
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_option_groups_business_id_id (business_id, id),
  ADD CONSTRAINT fk_option_groups_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;

ALTER TABLE pos_options
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_options_business_id_id (business_id, id),
  ADD CONSTRAINT fk_options_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_options_group_business FOREIGN KEY (business_id, group_id)
    REFERENCES pos_option_groups (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_product_option_groups
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL FIRST,
  ADD CONSTRAINT fk_product_option_groups_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_product_option_groups_product_business FOREIGN KEY (business_id, product_id)
    REFERENCES pos_products (business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_product_option_groups_group_business FOREIGN KEY (business_id, option_group_id)
    REFERENCES pos_option_groups (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_customers
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_customers_business_id_id (business_id, id),
  ADD KEY idx_customers_business_phone (business_id, phone_normalized),
  ADD CONSTRAINT fk_customers_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;
