CREATE TABLE pos_businesses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(160) NOT NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'IRT',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_businesses_public_id (public_id),
  UNIQUE KEY uq_pos_businesses_code (code),
  CONSTRAINT chk_pos_businesses_active CHECK (is_active IN (0,1)),
  CONSTRAINT chk_pos_businesses_currency CHECK (currency = 'IRT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_business_setup_state (
  id TINYINT UNSIGNED NOT NULL,
  initial_business_id BIGINT UNSIGNED NULL,
  initialized_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_business_setup_state_business (initial_business_id),
  CONSTRAINT fk_app_business_setup_state_business FOREIGN KEY (initial_business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_app_business_setup_state_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_business_setup_state (id, initial_business_id, initialized_at) VALUES (1, NULL, NULL);

CREATE TABLE pos_branches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(160) NOT NULL,
  timezone VARCHAR(64) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_branches_public_id (public_id),
  UNIQUE KEY uq_pos_branches_business_code (business_id, code),
  KEY idx_pos_branches_business_active (business_id, is_active),
  CONSTRAINT fk_pos_branches_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_pos_branches_active CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_user_business_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  business_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, business_id, role_id),
  KEY idx_user_business_roles_business (business_id, role_id, user_id),
  CONSTRAINT fk_user_business_roles_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_business_roles_business FOREIGN KEY (business_id) REFERENCES pos_businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_business_roles_role FOREIGN KEY (role_id) REFERENCES app_roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_business_roles_assigner FOREIGN KEY (assigned_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_user_branch_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, branch_id, role_id),
  KEY idx_user_branch_roles_branch (branch_id, role_id, user_id),
  CONSTRAINT fk_user_branch_roles_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_branch_roles_branch FOREIGN KEY (branch_id) REFERENCES pos_branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_branch_roles_role FOREIGN KEY (role_id) REFERENCES app_roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_branch_roles_assigner FOREIGN KEY (assigned_by) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_permissions (code, description) VALUES
('business.read', 'View business profile and settings'),
('business.update', 'Update business profile and settings'),
('branches.read', 'View branches'),
('branches.manage', 'Create and update branches'),
('users.read', 'View users and assignments'),
('users.manage', 'Create users and manage access assignments'),
('roles.read', 'View roles and permissions'),
('roles.manage', 'Manage roles and permission grants'),
('catalog.read', 'View products and categories'),
('catalog.manage', 'Manage products, categories and options'),
('orders.read', 'View orders'),
('orders.create', 'Create orders'),
('orders.update', 'Update order workflow'),
('orders.cancel', 'Cancel orders'),
('pos.sell', 'Operate point of sale'),
('kitchen.read', 'View kitchen work queue'),
('kitchen.update', 'Update preparation item status'),
('inventory.read', 'View stock and inventory documents'),
('inventory.manage', 'Manage stock, recipes and inventory documents'),
('customers.read', 'View customer profiles'),
('customers.manage', 'Manage customer profiles and loyalty'),
('finance.read', 'View financial records and reports'),
('finance.manage', 'Manage cash sessions and financial records'),
('payments.create', 'Create and confirm payment records'),
('payments.refund', 'Create refunds'),
('register.read', 'View cash register sessions'),
('register.manage', 'Manage cash register sessions and movements'),
('reports.read', 'View operational and financial reports'),
('storefront.manage', 'Manage online menu and storefront settings'),
('terminals.manage', 'Manage payment terminals and integrations'),
('audit.read', 'View audit log'),
('settings.manage', 'Manage application settings'),
('*', 'Wildcard permission reserved for the installation owner');

INSERT IGNORE INTO app_roles (code, name, is_system, is_active) VALUES
('manager', 'مدیر', 1, 1),
('cashier', 'صندوقدار', 1, 1),
('kitchen', 'آشپزخانه', 1, 1),
('inventory', 'انباردار', 1, 1),
('accountant', 'حسابدار', 1, 1),
('waiter', 'پرسنل سالن', 1, 1);

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM app_roles r
CROSS JOIN app_permissions p
WHERE r.code = 'owner';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM app_roles r
INNER JOIN app_permissions p ON p.code IN (
  'business.read','business.update','branches.read','branches.manage',
  'users.read','users.manage','roles.read','catalog.read','catalog.manage',
  'orders.read','orders.create','orders.update','orders.cancel','pos.sell',
  'kitchen.read','kitchen.update','inventory.read','inventory.manage',
  'customers.read','customers.manage','finance.read','finance.manage',
  'payments.create','payments.refund','register.read','register.manage',
  'reports.read','storefront.manage','terminals.manage','audit.read','settings.manage'
)
WHERE r.code = 'manager';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM app_roles r
INNER JOIN app_permissions p ON p.code IN (
  'business.read','branches.read','catalog.read','orders.read','orders.create',
  'orders.update','pos.sell','payments.create','register.read','register.manage','customers.read'
)
WHERE r.code = 'cashier';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM app_roles r
INNER JOIN app_permissions p ON p.code IN ('orders.read','kitchen.read','kitchen.update')
WHERE r.code = 'kitchen';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM app_roles r
INNER JOIN app_permissions p ON p.code IN ('business.read','branches.read','catalog.read','inventory.read','inventory.manage')
WHERE r.code = 'inventory';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM app_roles r
INNER JOIN app_permissions p ON p.code IN (
  'business.read','branches.read','orders.read','finance.read','finance.manage',
  'payments.create','payments.refund','register.read','register.manage','reports.read','audit.read'
)
WHERE r.code = 'accountant';

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM app_roles r
INNER JOIN app_permissions p ON p.code IN ('business.read','branches.read','catalog.read','orders.read','orders.create','orders.update')
WHERE r.code = 'waiter';
