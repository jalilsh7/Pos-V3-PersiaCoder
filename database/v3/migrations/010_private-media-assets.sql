CREATE TABLE pos_media_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  storage_key VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  image_width SMALLINT UNSIGNED NOT NULL,
  image_height SMALLINT UNSIGNED NOT NULL,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_public_id (public_id),
  UNIQUE KEY uq_media_storage_key (storage_key),
  UNIQUE KEY uq_media_business_id_id (business_id, id),
  KEY idx_media_business_created (business_id, created_at, id),
  KEY idx_media_business_hash (business_id, sha256),
  CONSTRAINT fk_media_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_media_uploader FOREIGN KEY (uploaded_by) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_media_mime CHECK (mime_type IN ('image/jpeg','image/png','image/webp')),
  CONSTRAINT chk_media_dimensions CHECK (image_width > 0 AND image_height > 0),
  CONSTRAINT chk_media_size CHECK (byte_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_product_media (
  business_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (business_id, product_id),
  CONSTRAINT fk_product_media_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_product_media_product FOREIGN KEY (business_id, product_id)
    REFERENCES pos_products (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_product_media_asset FOREIGN KEY (business_id, media_id)
    REFERENCES pos_media_assets (business_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_category_media (
  business_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (business_id, category_id),
  CONSTRAINT fk_category_media_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_category_media_category FOREIGN KEY (business_id, category_id)
    REFERENCES pos_categories (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_category_media_asset FOREIGN KEY (business_id, media_id)
    REFERENCES pos_media_assets (business_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
