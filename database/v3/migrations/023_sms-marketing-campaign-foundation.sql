-- Marketing campaigns are isolated from transactional SMS outboxes.
-- This migration only adds campaign/audience persistence; no dispatcher is enabled.
CREATE TABLE pos_sms_marketing_campaigns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title VARCHAR(160) NOT NULL,
  provider_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_body_id BIGINT UNSIGNED NOT NULL,
  variable_keys_json JSON NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
  scheduled_at DATETIME(3) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_marketing_campaign_public (business_id, public_id),
  UNIQUE KEY uq_sms_marketing_campaign_scope (business_id, id),
  KEY idx_sms_marketing_campaign_status (business_id, status, scheduled_at, id),
  CONSTRAINT fk_sms_marketing_campaign_business FOREIGN KEY (business_id)
    REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_marketing_campaign_actor FOREIGN KEY (created_by)
    REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sms_marketing_campaign_status
    CHECK (status IN ('draft','prepared','paused','cancelled','completed')),
  CONSTRAINT chk_sms_marketing_campaign_body CHECK (provider_body_id > 0),
  CONSTRAINT chk_sms_marketing_campaign_title CHECK (CHAR_LENGTH(TRIM(title)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pos_sms_marketing_campaign_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  consent_event_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'eligible',
  suppression_reason VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
  evaluated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_marketing_recipient_once (business_id, campaign_id, customer_id),
  KEY idx_sms_marketing_recipient_dispatch (business_id, campaign_id, status, id),
  KEY idx_sms_marketing_recipient_customer (business_id, customer_id, status),
  CONSTRAINT fk_sms_marketing_recipient_campaign FOREIGN KEY (business_id, campaign_id)
    REFERENCES pos_sms_marketing_campaigns(business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_marketing_recipient_customer FOREIGN KEY (business_id, customer_id)
    REFERENCES pos_customers(business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_marketing_recipient_consent FOREIGN KEY (consent_event_id)
    REFERENCES pos_customer_consent_events(id) ON DELETE RESTRICT,
  CONSTRAINT chk_sms_marketing_recipient_status
    CHECK (status IN ('eligible','suppressed','queued','sending','sent','failed','unknown','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
