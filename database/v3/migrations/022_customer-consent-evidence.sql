-- Append-only evidence for marketing consent. Current state is derived from the latest event.
CREATE TABLE pos_customer_consent_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  channel VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  notice_version VARCHAR(80) NULL,
  evidence_ref VARCHAR(190) NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_consent_event_key (business_id, event_key),
  KEY idx_customer_consent_current (business_id, customer_id, purpose, channel, id),
  KEY idx_customer_consent_history (business_id, customer_id, created_at, id),
  CONSTRAINT fk_customer_consent_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_consent_customer FOREIGN KEY (business_id, customer_id)
    REFERENCES pos_customers(business_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_consent_actor FOREIGN KEY (actor_user_id) REFERENCES app_users(id) ON DELETE SET NULL,
  CONSTRAINT chk_customer_consent_purpose CHECK (purpose IN ('marketing')),
  CONSTRAINT chk_customer_consent_channel CHECK (channel IN ('sms')),
  CONSTRAINT chk_customer_consent_decision CHECK (decision IN ('granted','withdrawn')),
  CONSTRAINT chk_customer_consent_source CHECK (source IN ('checkout','pos','web','api','import')),
  CONSTRAINT chk_customer_consent_grant_evidence CHECK (
    decision <> 'granted' OR
    (notice_version IS NOT NULL AND CHAR_LENGTH(TRIM(notice_version)) > 0
     AND source <> 'import')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_permissions (code, description)
VALUES ('customers.consent.manage', 'Record and withdraw customer marketing consent');

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM app_roles r
INNER JOIN app_permissions p ON p.code = 'customers.consent.manage'
WHERE r.code = 'manager';
