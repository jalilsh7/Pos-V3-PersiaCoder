-- INV-RETURN-2026-001
-- Add immutable, tenant/branch-scoped source links for stock returns and corrections.
-- Existing records remain NULL; no historical document or ledger row is rewritten.

ALTER TABLE pos_stock_documents
  ADD COLUMN source_document_id BIGINT UNSIGNED NULL AFTER source_order_id,
  ADD KEY idx_stock_documents_source_document (business_id, branch_id, source_document_id),
  ADD CONSTRAINT fk_v3_stock_documents_source_scope
    FOREIGN KEY (business_id, branch_id, source_document_id)
    REFERENCES pos_stock_documents(business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_stock_document_lines
  ADD COLUMN source_document_line_id BIGINT UNSIGNED NULL AFTER document_id,
  ADD KEY idx_stock_document_lines_source_line (business_id, branch_id, source_document_line_id),
  ADD CONSTRAINT fk_v3_stock_document_lines_source_scope
    FOREIGN KEY (business_id, branch_id, source_document_line_id)
    REFERENCES pos_stock_document_lines(business_id, branch_id, id) ON DELETE RESTRICT;
