-- PersiaCoder POS V3 commercial schema baseline
-- Target: MySQL 8.0.16+ / MariaDB 10.6+
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- IMPORTANT: This is a NEW, isolated V3 database schema. Never import into V2.
-- All timestamps are UTC DATETIME(3). Monetary amounts are integer TOMAN.
-- currency_code='IRT' is a legacy internal marker for TOMAN, NOT ISO Rial semantics.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE app_schema_migrations (
  version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  applied_at DATETIME(3) NOT NULL,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_install_state (
  id TINYINT UNSIGNED NOT NULL,
  setup_completed TINYINT(1) NOT NULL DEFAULT 0,
  installed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  CONSTRAINT chk_install_singleton CHECK (id = 1),
  CONSTRAINT chk_install_completed CHECK (setup_completed IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_install_state (id, setup_completed, installed_at) VALUES (1,0,NULL);

CREATE TABLE app_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  session_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_login_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_users_public_id (public_id),
  UNIQUE KEY uq_app_users_username (username),
  KEY idx_app_users_active (is_active, username),
  CONSTRAINT chk_app_users_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_app_users_password_change CHECK (must_change_password IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(100) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_roles_code (code),
  CONSTRAINT chk_app_roles_flags CHECK (is_system IN (0,1) AND is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  description VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, role_id),
  KEY idx_app_user_roles_role (role_id, user_id),
  CONSTRAINT fk_app_user_roles_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_app_user_roles_role FOREIGN KEY (role_id) REFERENCES app_roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_app_user_roles_assigner FOREIGN KEY (assigned_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  granted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (role_id, permission_id),
  KEY idx_app_role_permissions_permission (permission_id, role_id),
  CONSTRAINT fk_app_role_permissions_role FOREIGN KEY (role_id) REFERENCES app_roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_app_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES app_permissions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_type VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  entity_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason VARCHAR(500) NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ip_address VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_actor_time (actor_user_id, created_at, id),
  KEY idx_audit_entity_time (entity_type, entity_id, created_at, id),
  KEY idx_audit_request (request_id),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_business_settings (
  setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  value_json JSON NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_business_settings_actor FOREIGN KEY (updated_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_feature_flags (
  feature_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (feature_code),
  CONSTRAINT chk_feature_enabled CHECK (is_enabled IN (0,1)),
  CONSTRAINT fk_feature_actor FOREIGN KEY (updated_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_usage_limits (
  resource_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  max_active SMALLINT UNSIGNED NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (resource_code),
  CONSTRAINT fk_usage_limit_actor FOREIGN KEY (updated_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_sequences (
  sequence_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (sequence_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_setting_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  before_json JSON NULL,
  after_json JSON NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_setting_history_key_time (setting_key, created_at, id),
  CONSTRAINT fk_setting_history_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_categories_parent_active (parent_id, is_active, sort_order),
  CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES pos_categories(id) ON DELETE RESTRICT,
  CONSTRAINT chk_categories_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  name VARCHAR(180) NOT NULL,
  sku VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  product_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'prepared',
  base_price_amount BIGINT UNSIGNED NOT NULL,
  tax_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  archived_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_public_id (public_id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_category_active (category_id, is_active, name),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES pos_categories(id) ON DELETE RESTRICT,
  CONSTRAINT chk_products_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_products_tax_bps CHECK (tax_rate_bps <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  sku VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  price_delta_amount BIGINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_variant_product_sku (product_id, sku),
  KEY idx_variants_active (product_id, is_active),
  CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_variants_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_option_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(140) NOT NULL,
  selection_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'multiple',
  min_select SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_select SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT chk_option_group_selection CHECK (selection_type IN ('single','multiple')),
  CONSTRAINT chk_option_group_bounds CHECK (max_select >= min_select),
  CONSTRAINT chk_option_group_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(140) NOT NULL,
  price_delta_amount BIGINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_options_group_active (group_id, is_active),
  CONSTRAINT fk_options_group FOREIGN KEY (group_id) REFERENCES pos_option_groups(id) ON DELETE RESTRICT,
  CONSTRAINT chk_options_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_product_option_groups (
  product_id BIGINT UNSIGNED NOT NULL,
  option_group_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, option_group_id),
  KEY idx_product_option_group_group (option_group_id, product_id),
  CONSTRAINT fk_product_option_groups_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_product_option_groups_group FOREIGN KEY (option_group_id) REFERENCES pos_option_groups(id) ON DELETE RESTRICT,
  CONSTRAINT chk_product_option_required CHECK (is_required IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(160) NOT NULL,
  phone_normalized VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  phone_display VARCHAR(32) NULL,
  notes VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customers_public_id (public_id),
  KEY idx_customers_phone (phone_normalized),
  KEY idx_customers_name (name),
  CONSTRAINT chk_customers_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_customer_consent_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  channel VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  notice_version VARCHAR(80) NULL,
  evidence_ref VARCHAR(190) NULL,
  recipient_phone_normalized VARCHAR(16) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_consent_event_key (business_id, event_key),
  KEY idx_customer_consent_current (business_id, customer_id, purpose, channel, id),
  KEY idx_customer_consent_history (business_id, customer_id, created_at, id),
  CONSTRAINT fk_customer_consent_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_consent_customer FOREIGN KEY (business_id, customer_id)
    REFERENCES pos_customers(business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_consent_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_customer_consent_purpose CHECK (purpose IN ('marketing')),
  CONSTRAINT chk_customer_consent_channel CHECK (channel IN ('sms')),
  CONSTRAINT chk_customer_consent_decision CHECK (decision IN ('granted','withdrawn')),
  CONSTRAINT chk_customer_consent_source CHECK (source IN ('checkout','pos','web','api','import')),
  CONSTRAINT chk_customer_consent_grant_evidence CHECK (
    decision <> 'granted' OR
    (notice_version IS NOT NULL AND CHAR_LENGTH(TRIM(notice_version)) > 0
     AND evidence_ref IS NOT NULL AND CHAR_LENGTH(TRIM(evidence_ref)) > 0
     AND recipient_phone_normalized IS NOT NULL AND recipient_phone_normalized REGEXP '^09[0-9]{9}$'
     AND source <> 'import')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     AND source <> 'import')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_dining_areas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  CONSTRAINT chk_dining_area_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_dining_tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  area_id BIGINT UNSIGNED NOT NULL,
  table_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dining_table_code (area_id, table_code),
  KEY idx_dining_tables_active (area_id, is_active),
  CONSTRAINT fk_dining_tables_area FOREIGN KEY (area_id) REFERENCES pos_dining_areas(id) ON DELETE RESTRICT,
  CONSTRAINT chk_dining_table_capacity CHECK (capacity > 0),
  CONSTRAINT chk_dining_table_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_table_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  table_id BIGINT UNSIGNED NOT NULL,
  opened_by BIGINT UNSIGNED NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
  opened_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  closed_at DATETIME(3) NULL,
  active_table_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN table_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_table_sessions_public_id (public_id),
  UNIQUE KEY uq_table_sessions_one_active (active_table_id),
  KEY idx_table_sessions_table_time (table_id, opened_at),
  CONSTRAINT fk_table_sessions_table FOREIGN KEY (table_id) REFERENCES pos_dining_tables(id) ON DELETE RESTRICT,
  CONSTRAINT fk_table_sessions_opener FOREIGN KEY (opened_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_table_session_status CHECK (status IN ('open','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_preparation_stations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  station_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  CONSTRAINT chk_preparation_station_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_number BIGINT UNSIGNED NOT NULL,
  source_channel VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payment_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unpaid',
  customer_id BIGINT UNSIGNED NULL,
  table_session_id BIGINT UNSIGNED NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IRT',
  subtotal_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  delivery_fee_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  accepted_at DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_id (public_id),
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_status_created (order_status, created_at, id),
  KEY idx_orders_channel_created (source_channel, created_at, id),
  KEY idx_orders_customer_created (customer_id, created_at, id),
  KEY idx_orders_table_session (table_session_id, created_at),
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES pos_customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_table_session FOREIGN KEY (table_session_id) REFERENCES pos_table_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_creator FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_orders_channel CHECK (source_channel IN ('pos','phone','manual','table','takeaway','storefront')),
  CONSTRAINT chk_orders_status CHECK (order_status IN ('draft','awaiting_payment','accepted','preparing','ready','completed','cancelled','rejected')),
  CONSTRAINT chk_orders_payment_status CHECK (payment_status IN ('unpaid','partially_paid','paid','refunded','partially_refunded','failed','pending')),
  CONSTRAINT chk_orders_currency CHECK (currency_code = 'IRT'),
  CONSTRAINT chk_orders_discount CHECK (discount_amount <= subtotal_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  variant_id BIGINT UNSIGNED NULL,
  product_name_snapshot VARCHAR(180) NOT NULL,
  variant_name_snapshot VARCHAR(140) NULL,
  sku_snapshot VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  quantity DECIMAL(12,3) NOT NULL,
  unit_price_amount BIGINT UNSIGNED NOT NULL,
  discount_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tax_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  line_total_amount BIGINT UNSIGNED NOT NULL,
  recipe_version_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id, id),
  KEY idx_order_items_product (product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES pos_product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT chk_order_items_qty CHECK (quantity > 0),
  CONSTRAINT chk_order_items_tax CHECK (tax_rate_bps <= 10000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_item_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id BIGINT UNSIGNED NOT NULL,
  option_group_id BIGINT UNSIGNED NULL,
  option_id BIGINT UNSIGNED NULL,
  group_name_snapshot VARCHAR(140) NOT NULL,
  option_name_snapshot VARCHAR(140) NOT NULL,
  price_delta_amount BIGINT NOT NULL DEFAULT 0,
  quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_order_item_options_item (order_item_id, id),
  CONSTRAINT fk_order_item_options_item FOREIGN KEY (order_item_id) REFERENCES pos_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_options_group FOREIGN KEY (option_group_id) REFERENCES pos_option_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_options_option FOREIGN KEY (option_id) REFERENCES pos_options(id) ON DELETE RESTRICT,
  CONSTRAINT chk_order_item_options_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_status_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  to_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  reason_text VARCHAR(500) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  source_channel VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  metadata JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_status_idempotency (order_id, idempotency_key),
  KEY idx_order_status_events_order_time (order_id, created_at, id),
  CONSTRAINT fk_order_status_events_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_status_events_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_fulfillments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  recipient_name_snapshot VARCHAR(160) NULL,
  recipient_phone_snapshot VARCHAR(32) NULL,
  address_snapshot JSON NULL,
  delivery_fee_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_for DATETIME(3) NULL,
  completed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_fulfillment_order (order_id),
  CONSTRAINT fk_fulfillments_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT chk_fulfillment_method CHECK (method IN ('dine_in','takeaway','delivery')),
  CONSTRAINT chk_fulfillment_status CHECK (status IN ('pending','accepted','preparing','ready','dispatched','completed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_item_preparations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  quantity DECIMAL(12,3) NOT NULL,
  started_at DATETIME(3) NULL,
  ready_at DATETIME(3) NULL,
  started_by BIGINT UNSIGNED NULL,
  completed_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_preparation_queue (station_id, status, created_at, id),
  KEY idx_preparation_order_item (order_item_id, id),
  CONSTRAINT fk_preparations_item FOREIGN KEY (order_item_id) REFERENCES pos_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_preparations_station FOREIGN KEY (station_id) REFERENCES pos_preparation_stations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_preparations_started_by FOREIGN KEY (started_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_preparations_completed_by FOREIGN KEY (completed_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_preparations_status CHECK (status IN ('queued','started','ready','cancelled')),
  CONSTRAINT chk_preparations_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_adjustments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  adjustment_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount_delta BIGINT NOT NULL DEFAULT 0,
  quantity_delta DECIMAL(12,3) NOT NULL DEFAULT 0,
  reason VARCHAR(500) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_adjustment_idempotency (order_id, idempotency_key),
  KEY idx_order_adjustments_item (order_item_id, created_at),
  CONSTRAINT fk_order_adjustments_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_adjustments_item FOREIGN KEY (order_item_id) REFERENCES pos_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_adjustments_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_order_adjustment_type CHECK (adjustment_type IN ('cancel_item','discount','surcharge','quantity_change','correction'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_terminal_agents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  agent_version VARCHAR(40) NULL,
  last_seen_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_terminal_agents_public_id (public_id),
  UNIQUE KEY uq_terminal_agents_token_hash (token_hash),
  CONSTRAINT chk_terminal_agents_enabled CHECK (enabled IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_payment_terminals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  agent_id BIGINT UNSIGNED NOT NULL,
  local_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  provider_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  model_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  driver_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  connection_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  host VARCHAR(255) NULL,
  port SMALLINT UNSIGNED NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_terminals_public_id (public_id),
  UNIQUE KEY uq_payment_terminal_agent_key (agent_id, local_key),
  KEY idx_payment_terminals_enabled (enabled, is_default),
  CONSTRAINT fk_payment_terminals_agent FOREIGN KEY (agent_id) REFERENCES app_terminal_agents(id) ON DELETE RESTRICT,
  CONSTRAINT chk_payment_terminal_connection CHECK (connection_type IN ('lan','wifi','usb','serial','agent')),
  CONSTRAINT chk_payment_terminal_flags CHECK (enabled IN (0,1) AND is_default IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_terminal_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  terminal_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IRT',
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  trace_number VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  safe_response JSON NULL,
  created_by BIGINT UNSIGNED NULL,
  requested_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_terminal_tx_public_id (public_id),
  UNIQUE KEY uq_terminal_tx_idempotency (terminal_id, idempotency_key),
  KEY idx_terminal_tx_status_time (status, requested_at),
  KEY idx_terminal_tx_terminal_time (terminal_id, requested_at, id),
  KEY idx_terminal_tx_order (order_id, requested_at),
  CONSTRAINT fk_terminal_tx_terminal FOREIGN KEY (terminal_id) REFERENCES pos_payment_terminals(id) ON DELETE RESTRICT,
  CONSTRAINT fk_terminal_tx_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_terminal_tx_actor FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_terminal_tx_status CHECK (status IN ('created','sent','approved','declined','cancelled','unknown','reversed')),
  CONSTRAINT chk_terminal_tx_currency CHECK (currency_code = 'IRT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  method VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  source_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IRT',
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  terminal_transaction_id BIGINT UNSIGNED NULL,
  provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  captured_at DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_public_id (public_id),
  UNIQUE KEY uq_payment_order_idempotency (order_id, idempotency_key),
  UNIQUE KEY uq_payment_terminal_tx (terminal_transaction_id),
  KEY idx_payments_order_status_time (order_id, status, created_at, id),
  UNIQUE KEY uq_payments_provider_reference (provider_code, provider_reference),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_terminal_tx FOREIGN KEY (terminal_transaction_id) REFERENCES pos_terminal_transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_creator FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_payments_method CHECK (method IN ('cash','bank_terminal','online','credit','other')),
  CONSTRAINT chk_payments_source CHECK (source_type IN ('pos','storefront','manual','refund_reversal')),
  CONSTRAINT chk_payments_status CHECK (status IN ('pending','authorized','captured','failed','cancelled','unknown','partially_refunded','refunded')),
  CONSTRAINT chk_payments_currency CHECK (currency_code = 'IRT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_payment_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  from_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  to_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT UNSIGNED NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  safe_payload JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_event_idempotency (payment_id, idempotency_key),
  KEY idx_payment_events_payment_time (payment_id, created_at, id),
  CONSTRAINT fk_payment_events_payment FOREIGN KEY (payment_id) REFERENCES pos_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payment_events_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  method VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(500) NOT NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  requested_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  completed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_public_id (public_id),
  UNIQUE KEY uq_refunds_idempotency (payment_id, idempotency_key),
  KEY idx_refunds_payment_status (payment_id, status, created_at),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES pos_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_refunds_requester FOREIGN KEY (requested_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_refunds_approver FOREIGN KEY (approved_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_refunds_status CHECK (status IN ('requested','approved','processing','succeeded','failed','cancelled')),
  CONSTRAINT chk_refunds_method CHECK (method IN ('cash','bank_terminal','online','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_settlement_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  terminal_id BIGINT UNSIGNED NULL,
  business_date DATE NOT NULL,
  file_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'imported',
  imported_by BIGINT UNSIGNED NULL,
  imported_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_settlement_batch_public_id (public_id),
  UNIQUE KEY uq_settlement_provider_fingerprint (provider_code, file_fingerprint),
  KEY idx_settlement_batches_date (business_date, provider_code),
  CONSTRAINT fk_settlement_batch_terminal FOREIGN KEY (terminal_id) REFERENCES pos_payment_terminals(id) ON DELETE RESTRICT,
  CONSTRAINT fk_settlement_batch_importer FOREIGN KEY (imported_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_settlement_batch_status CHECK (status IN ('imported','matching','matched','review_required','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_settlement_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  provider_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_amount BIGINT NOT NULL,
  internal_amount BIGINT NULL,
  match_status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'unmatched',
  difference_amount BIGINT NOT NULL DEFAULT 0,
  resolution_actor_id BIGINT UNSIGNED NULL,
  resolution_reason VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_settlement_item_batch_payment (batch_id, payment_id),
  UNIQUE KEY uq_settlement_item_batch_reference (batch_id, provider_reference),
  KEY idx_settlement_items_match (match_status, batch_id),
  KEY idx_settlement_items_provider_ref (provider_reference),
  CONSTRAINT fk_settlement_items_batch FOREIGN KEY (batch_id) REFERENCES pos_settlement_batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_settlement_items_payment FOREIGN KEY (payment_id) REFERENCES pos_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_settlement_items_resolver FOREIGN KEY (resolution_actor_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_settlement_item_status CHECK (match_status IN ('unmatched','matched','amount_mismatch','duplicate','ignored','resolved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_registers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_registers_public_id (public_id),
  UNIQUE KEY uq_registers_code (code),
  CONSTRAINT chk_registers_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_cash_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  register_id BIGINT UNSIGNED NOT NULL,
  opened_by BIGINT UNSIGNED NULL,
  opening_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expected_cash_amount BIGINT NOT NULL DEFAULT 0,
  counted_cash_amount BIGINT UNSIGNED NULL,
  difference_amount BIGINT NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
  opened_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  closed_at DATETIME(3) NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  active_register_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status IN ('open','requires_review') THEN register_id ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cash_sessions_public_id (public_id),
  UNIQUE KEY uq_cash_sessions_one_active (active_register_id),
  KEY idx_cash_sessions_register_time (register_id, opened_at, id),
  CONSTRAINT fk_cash_sessions_register FOREIGN KEY (register_id) REFERENCES pos_registers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cash_sessions_opener FOREIGN KEY (opened_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_cash_sessions_reviewer FOREIGN KEY (reviewed_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_cash_session_status CHECK (status IN ('open','requires_review','closed','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_categories_name (name),
  CONSTRAINT chk_expense_categories_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  amount BIGINT UNSIGNED NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IRT',
  payment_method VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  cash_session_id BIGINT UNSIGNED NULL,
  description VARCHAR(1000) NULL,
  occurred_at DATETIME(3) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_expenses_public_id (public_id),
  KEY idx_expenses_occurred (occurred_at, status),
  KEY idx_expenses_category_time (category_id, occurred_at),
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES pos_expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_cash_session FOREIGN KEY (cash_session_id) REFERENCES pos_cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_expenses_payment_method CHECK (payment_method IN ('cash','bank_terminal','online','other')),
  CONSTRAINT chk_expenses_status CHECK (status IN ('draft','posted','voided')),
  CONSTRAINT chk_expenses_currency CHECK (currency_code = 'IRT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_cash_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  movement_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  refund_id BIGINT UNSIGNED NULL,
  expense_id BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cash_movements_public_id (public_id),
  UNIQUE KEY uq_cash_movement_payment (payment_id),
  UNIQUE KEY uq_cash_movement_refund (refund_id),
  UNIQUE KEY uq_cash_movement_expense (expense_id),
  UNIQUE KEY uq_cash_movement_session_idempotency (session_id, idempotency_key),
  KEY idx_cash_movements_session_time (session_id, created_at, id),
  CONSTRAINT fk_cash_movements_session FOREIGN KEY (session_id) REFERENCES pos_cash_sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cash_movements_payment FOREIGN KEY (payment_id) REFERENCES pos_payments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cash_movements_refund FOREIGN KEY (refund_id) REFERENCES pos_refunds(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cash_movements_expense FOREIGN KEY (expense_id) REFERENCES pos_expenses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cash_movements_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_cash_movement_type CHECK (movement_type IN ('sale_in','refund_out','expense_out','manual_in','manual_out','opening_float','adjustment')),
  CONSTRAINT chk_cash_movement_single_source CHECK ((payment_id IS NOT NULL) + (refund_id IS NOT NULL) + (expense_id IS NOT NULL) <= 1),
  CONSTRAINT chk_cash_movement_manual_reason CHECK (movement_type NOT IN ('manual_in','manual_out','adjustment') OR reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(80) NOT NULL,
  dimension VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 3,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_code (code),
  CONSTRAINT chk_units_decimal_places CHECK (decimal_places <= 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(160) NOT NULL,
  base_unit_id BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  negative_stock_policy VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'block',
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_items_public_id (public_id),
  UNIQUE KEY uq_stock_items_code (code),
  KEY idx_stock_items_active (is_active, name),
  CONSTRAINT fk_stock_items_base_unit FOREIGN KEY (base_unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_items_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_stock_items_negative_policy CHECK (negative_stock_policy IN ('block'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  location_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'storage',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_locations_public_id (public_id),
  UNIQUE KEY uq_stock_locations_code (code),
  CONSTRAINT chk_stock_location_type CHECK (location_type IN ('storage','kitchen','bar','freezer','other')),
  CONSTRAINT chk_stock_location_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_unit_conversions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_unit_id BIGINT UNSIGNED NOT NULL,
  to_unit_id BIGINT UNSIGNED NOT NULL,
  factor DECIMAL(18,8) NOT NULL,
  effective_from DATETIME(3) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_unit_conversions_pair_effective (from_unit_id, to_unit_id, effective_from),
  CONSTRAINT fk_unit_conversions_from FOREIGN KEY (from_unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_unit_conversions_to FOREIGN KEY (to_unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_unit_conversions_actor FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_unit_conversion_factor CHECK (factor > 0),
  CONSTRAINT chk_unit_conversion_different_units CHECK (from_unit_id <> to_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(180) NOT NULL,
  phone VARCHAR(32) NULL,
  notes VARCHAR(1000) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_public_id (public_id),
  KEY idx_suppliers_active_name (is_active, name),
  CONSTRAINT chk_suppliers_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_no VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  destination_location_id BIGINT UNSIGNED NULL,
  supplier_id BIGINT UNSIGNED NULL,
  source_order_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  reason VARCHAR(500) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  posted_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_documents_public_id (public_id),
  UNIQUE KEY uq_stock_documents_number (document_no),
  KEY idx_stock_documents_location_time (location_id, created_at, id),
  KEY idx_stock_documents_type_status (document_type, status, created_at),
  CONSTRAINT fk_stock_documents_location FOREIGN KEY (location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_documents_destination FOREIGN KEY (destination_location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_documents_supplier FOREIGN KEY (supplier_id) REFERENCES pos_suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_documents_order FOREIGN KEY (source_order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_documents_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_stock_document_type CHECK (document_type IN ('purchase_receipt','consumption','transfer','adjustment_in','adjustment_out','waste','return')),
  CONSTRAINT chk_stock_document_status CHECK (status IN ('draft','posted','voided')),
  CONSTRAINT chk_stock_document_transfer CHECK (document_type <> 'transfer' OR (destination_location_id IS NOT NULL AND destination_location_id <> location_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_document_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(18,6) NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  conversion_factor_snapshot DECIMAL(18,8) NOT NULL,
  base_quantity DECIMAL(18,6) NOT NULL,
  unit_cost_snapshot DECIMAL(24,8) NOT NULL DEFAULT 0,
  total_cost_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_stock_document_lines_document (document_id, id),
  KEY idx_stock_document_lines_item (stock_item_id, document_id),
  CONSTRAINT fk_stock_document_lines_document FOREIGN KEY (document_id) REFERENCES pos_stock_documents(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_document_lines_item FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_document_lines_unit FOREIGN KEY (unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_document_line_qty CHECK (quantity > 0 AND base_quantity > 0 AND conversion_factor_snapshot > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  document_line_id BIGINT UNSIGNED NULL,
  movement_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quantity_delta DECIMAL(18,6) NOT NULL,
  base_unit_id BIGINT UNSIGNED NOT NULL,
  unit_cost_snapshot DECIMAL(24,8) NOT NULL DEFAULT 0,
  total_cost_amount BIGINT NOT NULL DEFAULT 0,
  source_event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_ledger_source_event (source_event_key),
  KEY idx_stock_ledger_item_location_time (stock_item_id, location_id, created_at, id),
  KEY idx_stock_ledger_document_line (document_line_id),
  CONSTRAINT fk_stock_ledger_item FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_location FOREIGN KEY (location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_document_line FOREIGN KEY (document_line_id) REFERENCES pos_stock_document_lines(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_ledger_unit FOREIGN KEY (base_unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_ledger_delta CHECK (quantity_delta <> 0),
  CONSTRAINT chk_stock_ledger_type CHECK (movement_type IN ('purchase_in','consumption_out','transfer_in','transfer_out','adjustment_in','adjustment_out','waste_out','return_in','reversal'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_balances (
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  quantity_on_hand DECIMAL(18,6) NOT NULL DEFAULT 0,
  quantity_reserved DECIMAL(18,6) NOT NULL DEFAULT 0,
  average_unit_cost DECIMAL(24,8) NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (stock_item_id, location_id),
  CONSTRAINT fk_stock_balances_item FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_balances_location FOREIGN KEY (location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_balances_nonnegative CHECK (quantity_on_hand >= 0 AND quantity_reserved >= 0 AND quantity_reserved <= quantity_on_hand),
  CONSTRAINT chk_stock_balances_cost CHECK (average_unit_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  expires_at DATETIME(3) NULL,
  source_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_reservations_public_id (public_id),
  UNIQUE KEY uq_stock_reservations_order_key (order_id, idempotency_key),
  KEY idx_stock_reservations_status_expiry (status, expires_at),
  CONSTRAINT fk_stock_reservations_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_reservation_status CHECK (status IN ('active','consumed','released','expired')),
  CONSTRAINT chk_stock_reservation_source CHECK (source_type IN ('pos','storefront'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_stock_reservation_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  base_quantity DECIMAL(18,6) NOT NULL,
  consumed_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  released_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_reservation_line (reservation_id, stock_item_id, location_id),
  KEY idx_stock_reservation_lines_balance (stock_item_id, location_id),
  CONSTRAINT fk_stock_reservation_lines_reservation FOREIGN KEY (reservation_id) REFERENCES pos_stock_reservations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_reservation_lines_item FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_reservation_lines_location FOREIGN KEY (location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_stock_reservation_line_qty CHECK (base_quantity > 0 AND consumed_quantity >= 0 AND released_quantity >= 0 AND consumed_quantity + released_quantity <= base_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_recipes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  name VARCHAR(160) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_recipes_product_active (product_id, is_active),
  CONSTRAINT fk_recipes_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipes_variant FOREIGN KEY (variant_id) REFERENCES pos_product_variants(id) ON DELETE RESTRICT,
  CONSTRAINT chk_recipes_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_recipe_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id BIGINT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  effective_from DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_version_no (recipe_id, version_no),
  KEY idx_recipe_versions_status (recipe_id, status, effective_from),
  CONSTRAINT fk_recipe_versions_recipe FOREIGN KEY (recipe_id) REFERENCES pos_recipes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_versions_actor FOREIGN KEY (created_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_recipe_version_status CHECK (status IN ('draft','active','retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_order_items
  ADD CONSTRAINT fk_order_items_recipe_version FOREIGN KEY (recipe_version_id) REFERENCES pos_recipe_versions(id) ON DELETE RESTRICT;

CREATE TABLE pos_recipe_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_version_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(18,6) NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  conversion_factor_snapshot DECIMAL(18,8) NOT NULL,
  waste_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_lines_version_item_unit (recipe_version_id, stock_item_id, unit_id),
  CONSTRAINT fk_recipe_lines_version FOREIGN KEY (recipe_version_id) REFERENCES pos_recipe_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_lines_stock_item FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_lines_unit FOREIGN KEY (unit_id) REFERENCES pos_units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_recipe_line_values CHECK (quantity > 0 AND conversion_factor_snapshot > 0 AND waste_percent >= 0 AND waste_percent <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_order_item_recipe_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_item_id BIGINT UNSIGNED NOT NULL,
  recipe_version_id BIGINT UNSIGNED NOT NULL,
  stock_item_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  required_base_quantity DECIMAL(18,6) NOT NULL,
  consumed_base_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
  unit_cost_snapshot DECIMAL(24,8) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_order_item_recipe_lines_item (order_item_id, id),
  KEY idx_order_item_recipe_lines_stock (stock_item_id, location_id),
  CONSTRAINT fk_order_item_recipe_lines_order_item FOREIGN KEY (order_item_id) REFERENCES pos_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_recipe_lines_version FOREIGN KEY (recipe_version_id) REFERENCES pos_recipe_versions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_recipe_lines_stock FOREIGN KEY (stock_item_id) REFERENCES pos_stock_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_order_item_recipe_lines_location FOREIGN KEY (location_id) REFERENCES pos_stock_locations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_order_item_recipe_qty CHECK (required_base_quantity > 0 AND consumed_base_quantity >= 0 AND consumed_base_quantity <= required_base_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_customer_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  event_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  points_delta BIGINT NOT NULL DEFAULT 0,
  credit_delta BIGINT NOT NULL DEFAULT 0,
  visit_delta INT NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_ledger_event (event_key),
  KEY idx_customer_ledger_customer_time (customer_id, created_at, id),
  CONSTRAINT fk_customer_ledger_customer FOREIGN KEY (customer_id) REFERENCES pos_customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_ledger_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_discount_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  discount_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  discount_amount BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'applied',
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reversed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_discount_redemption_order (order_id),
  UNIQUE KEY uq_discount_redemption_idempotency (idempotency_key),
  KEY idx_discount_redemptions_code_customer (discount_code, customer_id, status),
  CONSTRAINT fk_discount_redemptions_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_discount_redemptions_customer FOREIGN KEY (customer_id) REFERENCES pos_customers(id) ON DELETE RESTRICT,
  CONSTRAINT chk_discount_redemption_status CHECK (status IN ('applied','reversed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
