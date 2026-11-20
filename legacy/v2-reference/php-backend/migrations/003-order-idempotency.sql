-- Run once on databases created from earlier schema versions.
-- Idempotency keys prevent duplicate orders when a cashier retries after a timeout.
ALTER TABLE pos_orders
  ADD COLUMN idempotency_key CHAR(36) NULL AFTER status,
  ADD COLUMN request_hash CHAR(64) NULL AFTER idempotency_key,
  ADD UNIQUE KEY uq_pos_order_idempotency (idempotency_key);
