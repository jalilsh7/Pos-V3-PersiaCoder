-- Immutable, encrypted outbox for National Payamak approved shared-number templates.
CREATE TABLE IF NOT EXISTS pos_sms_service_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  trigger_key VARCHAR(64) NOT NULL,
  provider_code VARCHAR(64) NOT NULL,
  provider_body_id BIGINT UNSIGNED NOT NULL,
  recipient_ciphertext VARBINARY(128) NOT NULL,
  recipient_nonce BINARY(12) NOT NULL,
  recipient_tag BINARY(16) NOT NULL,
  recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  variables_ciphertext MEDIUMBLOB NOT NULL,
  variables_nonce BINARY(12) NOT NULL,
  variables_tag BINARY(16) NOT NULL,
  variables_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('queued','processing','accepted','delivered','failed','rejected','unknown') NOT NULL DEFAULT 'queued',
  provider_message_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(3) NULL,
  failure_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_service_outbox_business_id (business_id, id),
  UNIQUE KEY uq_sms_service_outbox_idempotency (business_id, idempotency_key),
  UNIQUE KEY uq_sms_service_outbox_provider_id (business_id, provider_code, provider_message_id),
  KEY idx_sms_service_outbox_queue (status, next_attempt_at, id),
  KEY idx_sms_service_outbox_order (business_id, branch_id, order_id),
  CONSTRAINT fk_sms_service_outbox_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_service_outbox_branch FOREIGN KEY (business_id, branch_id) REFERENCES pos_branches(business_id,id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_service_outbox_order FOREIGN KEY (business_id, branch_id, order_id)
    REFERENCES pos_orders(business_id, branch_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sms_service_delivery_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  attempt_number SMALLINT UNSIGNED NOT NULL,
  outcome ENUM('accepted','rejected','unknown','retryable_failure') NOT NULL,
  provider_code VARCHAR(64) NOT NULL,
  provider_message_id VARCHAR(24) NULL,
  error_code VARCHAR(64) NULL,
  attempted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_sms_service_attempt_message (business_id, message_id, id),
  CONSTRAINT fk_sms_service_attempt_message FOREIGN KEY (business_id, message_id)
    REFERENCES pos_sms_service_outbox(business_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
