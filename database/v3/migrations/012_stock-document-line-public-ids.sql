-- Stable public identifiers for editable draft stock document lines.
-- Backfill existing rows before enforcing NOT NULL and scoped uniqueness.
ALTER TABLE pos_stock_document_lines
  ADD COLUMN public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER id;

UPDATE pos_stock_document_lines
SET public_id = UUID()
WHERE public_id IS NULL;

ALTER TABLE pos_stock_document_lines
  MODIFY public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ADD UNIQUE KEY uq_stock_document_lines_public_scope (business_id, branch_id, document_id, public_id);
