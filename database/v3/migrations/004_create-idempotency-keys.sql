CREATE TABLE app_idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  scope_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  endpoint_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lease_expires_at DATETIME(3) NOT NULL,
  response_status SMALLINT UNSIGNED NULL,
  response_ciphertext MEDIUMBLOB NULL,
  response_nonce BINARY(12) NULL,
  response_tag BINARY(16) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  expires_at DATETIME(3) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_scope_key (actor_user_id, business_id, scope_hash, endpoint_code, key_hash),
  KEY idx_idempotency_expiry (expires_at),
  CONSTRAINT fk_idempotency_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_idempotency_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_idempotency_branch FOREIGN KEY (branch_id) REFERENCES pos_branches(id) ON DELETE RESTRICT,
  CONSTRAINT chk_idempotency_response_state CHECK (
    (response_status IS NULL AND response_ciphertext IS NULL AND response_nonce IS NULL AND response_tag IS NULL AND completed_at IS NULL)
    OR
    (response_status BETWEEN 200 AND 299 AND response_ciphertext IS NOT NULL AND response_nonce IS NOT NULL AND response_tag IS NOT NULL AND completed_at IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
