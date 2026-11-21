-- Link order-driven stock movements to their originating order.
-- The composite foreign key prevents cross-business or cross-branch references.

ALTER TABLE pos_stock_ledger
  ADD COLUMN source_order_id BIGINT UNSIGNED NULL AFTER document_line_id,
  ADD KEY idx_stock_ledger_source_order (business_id, branch_id, source_order_id, id),
  ADD CONSTRAINT fk_v3_stock_ledger_source_order_scope
    FOREIGN KEY (business_id, branch_id, source_order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT;
