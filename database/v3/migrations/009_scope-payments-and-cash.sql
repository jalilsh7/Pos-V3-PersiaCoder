ALTER TABLE pos_payment_terminals
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD COLUMN default_branch_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN branch_id ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_payment_terminals_business_branch_id (business_id, branch_id, id),
  ADD UNIQUE KEY uq_payment_terminals_branch_default (business_id, branch_id, default_branch_id),
  ADD KEY idx_payment_terminals_branch_enabled (business_id, branch_id, enabled, is_default),
  ADD CONSTRAINT fk_payment_terminals_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payment_terminals_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_terminal_transactions
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_terminal_tx_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_terminal_tx_branch_status_time (business_id, branch_id, status, requested_at, id),
  ADD CONSTRAINT fk_terminal_tx_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_terminal_tx_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_terminal_tx_terminal_scope FOREIGN KEY (business_id, branch_id, terminal_id)
    REFERENCES pos_payment_terminals (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_terminal_tx_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_payments
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_payments_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_payments_branch_status_time (business_id, branch_id, status, created_at, id),
  ADD CONSTRAINT fk_payments_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payments_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payments_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payments_terminal_tx_scope FOREIGN KEY (business_id, branch_id, terminal_transaction_id)
    REFERENCES pos_terminal_transactions (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_payment_events
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_payment_events_branch_time (business_id, branch_id, payment_id, created_at, id),
  ADD CONSTRAINT fk_payment_events_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payment_events_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_payment_events_payment_scope FOREIGN KEY (business_id, branch_id, payment_id)
    REFERENCES pos_payments (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_refunds
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_refunds_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_refunds_branch_status (business_id, branch_id, status, created_at, id),
  ADD CONSTRAINT fk_refunds_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_refunds_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_refunds_payment_scope FOREIGN KEY (business_id, branch_id, payment_id)
    REFERENCES pos_payments (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_refunds_order_scope FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_registers
  DROP INDEX uq_registers_code,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_registers_business_branch_id (business_id, branch_id, id),
  ADD UNIQUE KEY uq_registers_branch_code (business_id, branch_id, code),
  ADD KEY idx_registers_branch_active (business_id, branch_id, is_active, name),
  ADD CONSTRAINT fk_registers_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_registers_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_cash_sessions
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_cash_sessions_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_cash_sessions_branch_status (business_id, branch_id, status, opened_at, id),
  ADD CONSTRAINT fk_cash_sessions_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_sessions_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_sessions_register_scope FOREIGN KEY (business_id, branch_id, register_id)
    REFERENCES pos_registers (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_expense_categories
  DROP INDEX uq_expense_categories_name,
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD UNIQUE KEY uq_expense_categories_business_id (business_id, id),
  ADD UNIQUE KEY uq_expense_categories_business_name (business_id, name),
  ADD CONSTRAINT fk_expense_categories_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT;

ALTER TABLE pos_expenses
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD UNIQUE KEY uq_expenses_business_branch_id (business_id, branch_id, id),
  ADD KEY idx_expenses_branch_occurred (business_id, branch_id, occurred_at, status, id),
  ADD CONSTRAINT fk_expenses_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_expenses_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_expenses_category_business FOREIGN KEY (business_id, category_id)
    REFERENCES pos_expense_categories (business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_expenses_cash_session_scope FOREIGN KEY (business_id, branch_id, cash_session_id)
    REFERENCES pos_cash_sessions (business_id, branch_id, id) ON DELETE RESTRICT;

ALTER TABLE pos_cash_movements
  ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id,
  ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id,
  ADD KEY idx_cash_movements_branch_time (business_id, branch_id, session_id, created_at, id),
  ADD CONSTRAINT fk_cash_movements_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_movements_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_movements_session_scope FOREIGN KEY (business_id, branch_id, session_id)
    REFERENCES pos_cash_sessions (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_movements_payment_scope FOREIGN KEY (business_id, branch_id, payment_id)
    REFERENCES pos_payments (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_movements_refund_scope FOREIGN KEY (business_id, branch_id, refund_id)
    REFERENCES pos_refunds (business_id, branch_id, id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_cash_movements_expense_scope FOREIGN KEY (business_id, branch_id, expense_id)
    REFERENCES pos_expenses (business_id, branch_id, id) ON DELETE RESTRICT;
