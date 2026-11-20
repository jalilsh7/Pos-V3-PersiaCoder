ALTER TABLE pos_branches
  ADD UNIQUE KEY uq_pos_branches_business_id_id (business_id, id);

ALTER TABLE app_audit_logs
  ADD COLUMN business_id BIGINT UNSIGNED NULL AFTER actor_user_id,
  ADD COLUMN branch_id BIGINT UNSIGNED NULL AFTER business_id,
  ADD KEY idx_audit_business_time (business_id, created_at, id),
  ADD KEY idx_audit_branch_time (branch_id, created_at, id),
  ADD CONSTRAINT fk_audit_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  ADD CONSTRAINT fk_audit_branch_business FOREIGN KEY (business_id, branch_id)
    REFERENCES pos_branches (business_id, id) ON DELETE RESTRICT;
