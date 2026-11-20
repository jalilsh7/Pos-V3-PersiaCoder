<?php
declare(strict_types=1);

require_once __DIR__ . '/../database/v3/bin/bootstrap.php';
$pdo = PosV3DatabaseTools::connect();

$probe = $pdo->query("SHOW TABLES LIKE 'v3_ci_migration_probe'")->fetchColumn();
if (!$probe) {
    throw new RuntimeException('Migration probe table is missing.');
}

$count = (int) $pdo->query("SELECT COUNT(*) FROM app_schema_migrations WHERE version = '9999_ci-smoke'")->fetchColumn();
if ($count !== 1) {
    throw new RuntimeException("Expected exactly one migration record, found {$count}.");
}

echo "Migration idempotency check passed.\n";
