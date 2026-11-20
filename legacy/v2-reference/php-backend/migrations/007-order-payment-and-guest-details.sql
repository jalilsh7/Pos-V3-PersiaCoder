-- Preserve guest identity, payment method, and loyalty point totals for each order.
ALTER TABLE pos_orders
  ADD COLUMN guest_name VARCHAR(160) NULL AFTER customer_phone,
  ADD COLUMN guest_phone VARCHAR(30) NULL AFTER guest_name,
  ADD COLUMN payment_method ENUM('cash','card','online') NOT NULL DEFAULT 'cash' AFTER guest_phone,
  ADD COLUMN points_used BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_method,
  ADD COLUMN points_earned BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER points_used;
