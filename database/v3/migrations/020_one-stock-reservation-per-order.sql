-- A recipe-tracked order may have exactly one stock reservation lifecycle.
-- Request idempotency keys remain recorded, but cannot create a second reservation
-- for the same order when a caller retries with a different key.
ALTER TABLE pos_stock_reservations
  DROP INDEX uq_stock_reservations_order_key,
  ADD UNIQUE KEY uq_stock_reservations_order_once (business_id, branch_id, order_id);
