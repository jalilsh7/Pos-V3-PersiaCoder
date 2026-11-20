-- Apply once to existing MySQL databases after the order-effects migration.
ALTER TABLE pos_orders
  ADD COLUMN discount_code VARCHAR(64) NULL AFTER customer_phone,
  ADD KEY idx_pos_orders_discount_customer (discount_code, customer_id, status);
