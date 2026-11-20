-- Tenant-scoped National Payamak service-template IDs and ordered POS variable mapping.
CREATE TABLE IF NOT EXISTS pos_sms_service_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  trigger_key VARCHAR(64) NOT NULL,
  provider_code VARCHAR(64) NOT NULL DEFAULT 'national_payamak_shared_number',
  provider_body_id BIGINT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  variable_keys_json JSON NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sms_service_template_scope (business_id, branch_id, trigger_key),
  KEY idx_sms_service_template_enabled (business_id, branch_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sms_service_template_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  trigger_key VARCHAR(64) NOT NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  action_code VARCHAR(32) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_sms_template_audit_scope (business_id, branch_id, trigger_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_permissions (code, description) VALUES ('communications.manage','Manage service SMS template mappings');
INSERT IGNORE INTO app_role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM app_roles r INNER JOIN app_permissions p ON p.code='communications.manage' WHERE r.code IN ('owner','manager');
