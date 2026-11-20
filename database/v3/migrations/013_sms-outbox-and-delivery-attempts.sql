-- Encrypted transactional SMS outbox and delivery-attempt ledger.
-- Promotional messages are intentionally not represented until consent/opt-out controls are implemented.
CREATE TABLE pos_sms_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  message_type ENUM('transactional', 'verification') NOT NULL DEFAULT 'transactional',
  provider_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recipient_ciphertext VARBINARY(128) NOT NULL,
  recipient_nonce BINARY(12) NOT NULL,
  recipient_tag BINARY(16) NOT NULL,
  recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  message_ciphertext MEDIUMBLOB NOT NULL,
  message_nonce BINARY(12) NOT NULL,
  message_tag BINARY(16) NOT NULL,
  message_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM(
    'queued', 'processing', 'accepted', 'sent_to_carrier', 'delivered',
    'failed', 'rejected', 'unknown', 'cancelled'
  ) NOT NULL DEFAULT 'queued',
  provider_message_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  scheduled_at DATETIME(3) NULL,
  next_attempt_at DATETIME(3) NULL,
  last_attempt_at DATETIME(3) NULL,
  last_delivery_check_at DATETIME(3) NULL,
  accepted_at DATETIME(3) NULL,
  delivered_at DATETIME(3) NULL,
  failure_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  failure_message VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_outbox_business_id (business_id, id),
  UNIQUE KEY uq_sms_outbox_public_id (business_id, public_id),
  UNIQUE KEY uq_sms_outbox_idempotency (business_id, idempotency_key),
  UNIQUE KEY uq_sms_outbox_provider_id (business_id, provider_code, provider_message_id),
  KEY idx_sms_outbox_queue (status, next_attempt_at, scheduled_at, id),
  KEY idx_sms_outbox_delivery (status, last_delivery_check_at, id),
  KEY idx_sms_outbox_customer (business_id, customer_id, created_at),
  CONSTRAINT fk_sms_outbox_business FOREIGN KEY (business_id)
    REFERENCES pos_businesses (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_outbox_branch FOREIGN KEY (business_id, branch_id)
    REFERENCES pos_branches (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_outbox_customer FOREIGN KEY (business_id, customer_id)
    REFERENCES pos_customers (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_outbox_creator FOREIGN KEY (created_by)
    REFERENCES app_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_sms_delivery_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  attempt_number SMALLINT UNSIGNED NOT NULL,
  attempt_type ENUM('send', 'delivery_check') NOT NULL,
  outcome ENUM('accepted', 'rejected', 'unknown', 'retryable_failure', 'status_received') NOT NULL,
  provider_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_message_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
  provider_status_code INT NULL,
  error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  safe_metadata JSON NULL,
  attempted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_sms_attempt_message (business_id, message_id, id),
  KEY idx_sms_attempt_provider_id (business_id, provider_code, provider_message_id),
  CONSTRAINT fk_sms_attempt_business FOREIGN KEY (business_id)
    REFERENCES pos_businesses (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_attempt_branch FOREIGN KEY (business_id, branch_id)
    REFERENCES pos_branches (business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_attempt_message FOREIGN KEY (business_id, message_id)
    REFERENCES pos_sms_outbox (business_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
