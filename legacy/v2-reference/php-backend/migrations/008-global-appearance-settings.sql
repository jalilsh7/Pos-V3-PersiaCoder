-- Add global appearance settings for all POS users.
CREATE TABLE IF NOT EXISTS app_appearance_settings (
  id TINYINT UNSIGNED NOT NULL,
  settings JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT chk_appearance_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_appearance_settings_actor FOREIGN KEY (updated_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
