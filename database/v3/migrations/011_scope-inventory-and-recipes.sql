-- Inventory and recipe ownership is business/branch scoped.
-- Unit definitions and unit conversion history remain shared reference data.

ALTER TABLE pos_stock_items
  DROP INDEX uq_stock_items_code,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_stock_items_business_id_id (business_id, id),
  ADD UNIQUE KEY uq_stock_items_business_code (business_id, code),
  ADD KEY idx_stock_items_business_active (business_id, is_active, name),
  ADD CONSTRAINT fk_v3_stock_items_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_locations
  DROP INDEX uq_stock_locations_code,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_stock_locations_business_branch_id (business_id, branch_id, id),
  ADD UNIQUE KEY uq_stock_locations_branch_code (business_id, branch_id, code),
  ADD KEY idx_stock_locations_branch_active (business_id, branch_id, is_active, name),
  ADD CONSTRAINT fk_v3_stock_locations_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_locations_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_suppliers
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_suppliers_business_id_id (business_id, id),
  ADD KEY idx_suppliers_business_active_name (business_id, is_active, name),
  ADD CONSTRAINT fk_v3_suppliers_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_documents
  DROP INDEX uq_stock_documents_number,
  DROP FOREIGN KEY fk_stock_documents_location,
  DROP FOREIGN KEY fk_stock_documents_destination,
  DROP FOREIGN KEY fk_stock_documents_supplier,
  DROP FOREIGN KEY fk_stock_documents_order,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_stock_documents_business_branch_id (business_id, branch_id, id),
  ADD UNIQUE KEY uq_stock_documents_branch_number (business_id, branch_id, document_no),
  ADD KEY idx_stock_documents_branch_time (business_id, branch_id, location_id, created_at, id),
  ADD CONSTRAINT fk_v3_stock_documents_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_documents_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_documents_location_scope FOREIGN KEY (business_id, branch_id, location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_documents_destination_scope FOREIGN KEY (business_id, branch_id, destination_location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_documents_supplier_scope FOREIGN KEY (business_id, supplier_id)
    REFERENCES pos_suppliers(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_documents_order_scope FOREIGN KEY (business_id, branch_id, source_order_id)
    REFERENCES pos_orders(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_document_lines
  DROP FOREIGN KEY fk_stock_document_lines_document,
  DROP FOREIGN KEY fk_stock_document_lines_item,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_stock_document_lines_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_stock_document_lines_branch_document (business_id, branch_id, document_id, id),
  ADD CONSTRAINT fk_v3_stock_document_lines_document FOREIGN KEY (business_id, branch_id, document_id)
    REFERENCES pos_stock_documents(business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_document_lines_item FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_ledger
  DROP FOREIGN KEY fk_stock_ledger_item,
  DROP FOREIGN KEY fk_stock_ledger_location,
  DROP FOREIGN KEY fk_stock_ledger_document_line,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_stock_ledger_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_stock_ledger_branch_item_location_time (business_id, branch_id, stock_item_id, location_id, created_at, id),
  ADD CONSTRAINT fk_v3_stock_ledger_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_ledger_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_ledger_item_scope FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_ledger_location_scope FOREIGN KEY (business_id, branch_id, location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_ledger_document_line_scope FOREIGN KEY (business_id, branch_id, document_line_id)
    REFERENCES pos_stock_document_lines(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_balances
  DROP FOREIGN KEY fk_stock_balances_item,
  DROP FOREIGN KEY fk_stock_balances_location,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER stock_item_id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_stock_balances_branch_location (business_id, branch_id, location_id),
  ADD CONSTRAINT fk_v3_stock_balances_item_scope FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_balances_location_scope FOREIGN KEY (business_id, branch_id, location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_reservations
  DROP FOREIGN KEY fk_stock_reservations_order,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_stock_reservations_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_stock_reservations_branch_status_expiry (business_id, branch_id, status, expires_at),
  ADD CONSTRAINT fk_v3_stock_reservations_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_reservations_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_reservations_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_reservation_lines
  ADD COLUMN unit_cost_snapshot DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER base_quantity,
  DROP FOREIGN KEY fk_stock_reservation_lines_reservation,
  DROP FOREIGN KEY fk_stock_reservation_lines_item,
  DROP FOREIGN KEY fk_stock_reservation_lines_location,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_stock_reservation_lines_branch_balance (business_id, branch_id, stock_item_id, location_id),
  ADD CONSTRAINT fk_v3_stock_reservation_lines_reservation_scope FOREIGN KEY (business_id, branch_id, reservation_id)
    REFERENCES pos_stock_reservations(business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_reservation_lines_item_scope FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_stock_reservation_lines_location_scope FOREIGN KEY (business_id, branch_id, location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_product_variants
  ADD UNIQUE KEY uq_variants_business_product_id (business_id, product_id, id);

ALTER TABLE pos_recipes
  DROP FOREIGN KEY fk_recipes_product,
  DROP FOREIGN KEY fk_recipes_variant,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER business_id,
  ADD COLUMN variant_scope_id BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(variant_id, 0)) STORED AFTER variant_id,
  ADD UNIQUE KEY uq_recipes_business_id_id (business_id, id),
  ADD UNIQUE KEY uq_recipes_business_product_variant (business_id, product_id, variant_scope_id),
  ADD KEY idx_recipes_business_product_active (business_id, product_id, is_active),
  ADD CONSTRAINT fk_v3_recipes_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;
UPDATE pos_recipes SET public_id = UUID() WHERE public_id IS NULL;
ALTER TABLE pos_recipes
  MODIFY COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_recipes_public_id (public_id),
  ADD CONSTRAINT fk_v3_recipes_product_scope FOREIGN KEY (business_id, product_id)
    REFERENCES pos_products(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_recipes_variant_product_scope FOREIGN KEY (business_id, product_id, variant_id)
    REFERENCES pos_product_variants(business_id, product_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_recipe_versions
  DROP FOREIGN KEY fk_recipe_versions_recipe,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER business_id,
  ADD UNIQUE KEY uq_recipe_versions_business_id_id (business_id, id),
  ADD KEY idx_recipe_versions_business_status (business_id, recipe_id, status, effective_from),
  ADD CONSTRAINT fk_v3_recipe_versions_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;
UPDATE pos_recipe_versions SET public_id = UUID() WHERE public_id IS NULL;
ALTER TABLE pos_recipe_versions
  MODIFY COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_recipe_versions_public_id (public_id),
  ADD CONSTRAINT fk_v3_recipe_versions_recipe_scope FOREIGN KEY (business_id, recipe_id)
    REFERENCES pos_recipes(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_items
  DROP FOREIGN KEY fk_order_items_recipe_version,
  ADD CONSTRAINT fk_v3_order_items_recipe_version_scope FOREIGN KEY (business_id, recipe_version_id)
    REFERENCES pos_recipe_versions(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_recipe_lines
  DROP FOREIGN KEY fk_recipe_lines_version,
  DROP FOREIGN KEY fk_recipe_lines_stock_item,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_recipe_lines_business_id_id (business_id, id),
  ADD CONSTRAINT fk_v3_recipe_lines_version_scope FOREIGN KEY (business_id, recipe_version_id)
    REFERENCES pos_recipe_versions(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_recipe_lines_stock_item_scope FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_item_recipe_lines
  DROP FOREIGN KEY fk_order_item_recipe_lines_version,
  DROP FOREIGN KEY fk_order_item_recipe_lines_stock,
  DROP FOREIGN KEY fk_order_item_recipe_lines_location,
  ADD CONSTRAINT fk_v3_order_item_recipe_version_scope FOREIGN KEY (business_id, recipe_version_id)
    REFERENCES pos_recipe_versions(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_order_item_recipe_stock_scope FOREIGN KEY (business_id, stock_item_id)
    REFERENCES pos_stock_items(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_v3_order_item_recipe_location_scope FOREIGN KEY (business_id, branch_id, location_id)
    REFERENCES pos_stock_locations(business_id, branch_id, id) ON DELETE RESTRICT;


-- Shared reference units and deterministic base-unit conversion factors.
INSERT IGNORE INTO pos_units (code, name, dimension, decimal_places) VALUES
  ('mg', 'میلی‌گرم', 'mass', 3),
  ('g', 'گرم', 'mass', 3),
  ('kg', 'کیلوگرم', 'mass', 3),
  ('ml', 'میلی‌لیتر', 'volume', 3),
  ('l', 'لیتر', 'volume', 3),
  ('pcs', 'عدد', 'count', 0);

INSERT IGNORE INTO pos_unit_conversions (from_unit_id, to_unit_id, factor, effective_from, created_by)
SELECT f.id, t.id, x.factor, '1970-01-01 00:00:00.000', NULL
FROM (
  SELECT 'mg' AS from_code, 'g' AS to_code, 0.001 AS factor
  UNION ALL SELECT 'g', 'mg', 1000
  UNION ALL SELECT 'g', 'kg', 0.001
  UNION ALL SELECT 'kg', 'g', 1000
  UNION ALL SELECT 'mg', 'kg', 0.000001
  UNION ALL SELECT 'kg', 'mg', 1000000
  UNION ALL SELECT 'ml', 'l', 0.001
  UNION ALL SELECT 'l', 'ml', 1000
) x
INNER JOIN pos_units f ON f.code = x.from_code
INNER JOIN pos_units t ON t.code = x.to_code;
