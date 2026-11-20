ALTER TABLE pos_dining_areas
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER branch_id,
  ADD UNIQUE KEY uq_dining_areas_public_id (public_id),
  ADD UNIQUE KEY uq_dining_areas_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_dining_areas_branch_active (business_id, branch_id, is_active, sort_order),
  ADD CONSTRAINT fk_dining_areas_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_dining_areas_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_dining_tables
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER branch_id,
  ADD UNIQUE KEY uq_dining_tables_public_id (public_id),
  ADD UNIQUE KEY uq_dining_tables_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_dining_tables_branch_active (business_id, branch_id, is_active, area_id),
  ADD CONSTRAINT fk_dining_tables_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_dining_tables_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_dining_tables_area_scope FOREIGN KEY (business_id, branch_id, area_id)
    REFERENCES pos_dining_areas (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_table_sessions
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_table_sessions_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_table_sessions_branch_status (business_id, branch_id, status, opened_at),
  ADD CONSTRAINT fk_table_sessions_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_table_sessions_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_table_sessions_table_scope FOREIGN KEY (business_id, branch_id, table_id)
    REFERENCES pos_dining_tables (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_preparation_stations
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER branch_id,
  ADD UNIQUE KEY uq_preparation_stations_public_id (public_id),
  ADD UNIQUE KEY uq_preparation_stations_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_preparation_stations_branch_active (business_id, branch_id, is_active, sort_order),
  ADD CONSTRAINT fk_preparation_stations_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_preparation_stations_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_orders
  DROP INDEX uq_orders_number,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_orders_business_branch_id (business_id, branch_id, id),
  ADD UNIQUE KEY uq_orders_business_branch_number (business_id, branch_id, order_number),
  ADD KEY idx_orders_business_branch_status (business_id, branch_id, order_status, created_at, id),
  ADD KEY idx_orders_business_branch_channel (business_id, branch_id, source_channel, created_at, id),
  ADD CONSTRAINT fk_orders_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_orders_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_orders_customer_business FOREIGN KEY (business_id, customer_id)
    REFERENCES pos_customers (business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_orders_table_session_scope FOREIGN KEY (business_id, branch_id, table_session_id)
    REFERENCES pos_table_sessions (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_items
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_order_items_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_order_items_business_branch_order (business_id, branch_id, order_id, id),
  ADD CONSTRAINT fk_order_items_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_items_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_items_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_items_product_business FOREIGN KEY (business_id, product_id)
    REFERENCES pos_products (business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_items_variant_business FOREIGN KEY (business_id, variant_id)
    REFERENCES pos_product_variants (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_item_options
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_order_item_options_business_branch_item (business_id, branch_id, order_item_id, id),
  ADD CONSTRAINT fk_order_item_options_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_options_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_options_item_scope FOREIGN KEY (business_id, branch_id, order_item_id)
    REFERENCES pos_order_items (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_options_group_business FOREIGN KEY (business_id, option_group_id)
    REFERENCES pos_option_groups (business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_options_option_business FOREIGN KEY (business_id, option_id)
    REFERENCES pos_options (business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_status_events
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_order_status_events_scope_time (business_id, branch_id, order_id, created_at, id),
  ADD CONSTRAINT fk_order_status_events_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_status_events_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_status_events_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_fulfillments
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD CONSTRAINT fk_fulfillments_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fulfillments_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_fulfillments_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_item_preparations
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_preparations_scope_queue (business_id, branch_id, station_id, status, created_at, id),
  ADD CONSTRAINT fk_preparations_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_preparations_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_preparations_item_scope FOREIGN KEY (business_id, branch_id, order_item_id)
    REFERENCES pos_order_items (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_preparations_station_scope FOREIGN KEY (business_id, branch_id, station_id)
    REFERENCES pos_preparation_stations (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_adjustments
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_order_adjustments_scope_order (business_id, branch_id, order_id, created_at),
  ADD CONSTRAINT fk_order_adjustments_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_adjustments_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_adjustments_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_adjustments_item_scope FOREIGN KEY (business_id, branch_id, order_item_id)
    REFERENCES pos_order_items (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_order_item_recipe_lines
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_order_item_recipe_lines_scope (business_id, branch_id, order_item_id, id),
  ADD CONSTRAINT fk_order_item_recipe_lines_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_recipe_lines_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_order_item_recipe_lines_item_scope FOREIGN KEY (business_id, branch_id, order_item_id)
    REFERENCES pos_order_items (business_id, branch_id, id) ON DELETE RESTRICT;
