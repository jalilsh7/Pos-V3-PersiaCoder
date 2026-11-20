-- Read-only SMS outbox visibility for authorized business managers.
INSERT IGNORE INTO app_permissions (code, description) VALUES ('communications.read','View transactional SMS queue and delivery history');
INSERT IGNORE INTO app_role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM app_roles r INNER JOIN app_permissions p ON p.code='communications.read' WHERE r.code='manager';
