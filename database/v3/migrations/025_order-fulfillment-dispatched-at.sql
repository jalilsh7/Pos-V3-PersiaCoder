-- Record the first system-confirmed dispatch timestamp for delivery fulfillment.
ALTER TABLE pos_order_fulfillments
  ADD COLUMN dispatched_at DATETIME(3) NULL AFTER scheduled_for;
