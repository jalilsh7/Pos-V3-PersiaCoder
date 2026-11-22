-- Dedicated permission for managing marketing campaign drafts and audience snapshots.
INSERT IGNORE INTO app_permissions (code, description)
VALUES ('communications.sms.marketing.manage', 'Create and prepare SMS marketing campaigns');

INSERT IGNORE INTO app_role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM app_roles r
INNER JOIN app_permissions p ON p.code = 'communications.sms.marketing.manage'
WHERE r.code = 'manager';
