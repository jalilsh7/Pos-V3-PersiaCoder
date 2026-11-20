-- Apply once after 005-order-discount-code.sql.
CREATE TABLE IF NOT EXISTS pos_discount_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  discount_id VARCHAR(100) NOT NULL,
  code VARCHAR(64) NOT NULL,
  customer_id VARCHAR(100) NULL,
  redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reversed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_discount_redemption_order (order_id),
  KEY idx_pos_discount_redemption_global (discount_id, reversed_at, redeemed_at),
  KEY idx_pos_discount_redemption_customer (discount_id, customer_id),
  CONSTRAINT fk_pos_discount_redemption_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
