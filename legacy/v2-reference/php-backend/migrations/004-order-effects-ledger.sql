-- Apply once to databases created before the reversible-effects ledger.
CREATE TABLE IF NOT EXISTS pos_order_effects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  effect_type ENUM('ingredient','customer') NOT NULL,
  resource_id VARCHAR(100) NOT NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  points_spent BIGINT NOT NULL DEFAULT 0,
  points_earned BIGINT NOT NULL DEFAULT 0,
  money_delta BIGINT NOT NULL DEFAULT 0,
  visits_delta INT NOT NULL DEFAULT 0,
  reversed_at DATETIME NULL,
  reversed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_order_effect (order_id, effect_type, resource_id),
  KEY idx_pos_order_effect_reversal (order_id, reversed_at),
  CONSTRAINT fk_pos_order_effect_order FOREIGN KEY (order_id) REFERENCES pos_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_order_effect_actor FOREIGN KEY (reversed_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
