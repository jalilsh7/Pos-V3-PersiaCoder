<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/v3/bin/bootstrap.php';

$pdo = PosV3DatabaseTools::connect();
$state = $pdo->query('SELECT setup_completed, installed_at FROM app_install_state WHERE id = 1')->fetch();
if (!$state || (int) $state['setup_completed'] !== 1 || empty($state['installed_at'])) {
    throw new RuntimeException('Installer did not finalize app_install_state.');
}

$baseline = $pdo->query("SELECT checksum FROM app_schema_migrations WHERE version = '000_baseline'")->fetchColumn();
$expected = hash_file('sha256', __DIR__ . '/../database/v3/schema.sql');
if (!is_string($baseline) || !hash_equals($expected, $baseline)) {
    throw new RuntimeException('Installer did not record the exact baseline checksum.');
}

$user = $pdo->prepare(
    "SELECT u.password_hash, u.must_change_password, r.code
     FROM app_users u
     INNER JOIN app_user_roles ur ON ur.user_id = u.id
     INNER JOIN app_roles r ON r.id = ur.role_id
     WHERE u.username = ?"
);
$user->execute([PosV3DatabaseTools::env('POS_V3_ADMIN_USERNAME')]);
$admin = $user->fetch();
if (!$admin || $admin['code'] !== 'owner' || (int) $admin['must_change_password'] !== 1) {
    throw new RuntimeException('Initial owner account or forced password-change flag is missing.');
}
if (!password_verify(PosV3DatabaseTools::env('POS_V3_ADMIN_PASSWORD'), $admin['password_hash'])) {
    throw new RuntimeException('Initial owner password was not securely hashed or cannot be verified.');
}

echo "V3 installer validation passed: baseline checksum, install state and initial owner account verified.\n";
