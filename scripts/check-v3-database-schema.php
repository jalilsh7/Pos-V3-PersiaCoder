<?php
declare(strict_types=1);

/**
 * Runtime validation for the isolated V3 baseline.
 * Required environment: MYSQL_TEST_HOST, MYSQL_TEST_PORT, MYSQL_TEST_DATABASE,
 * MYSQL_TEST_USERNAME, MYSQL_TEST_PASSWORD.
 */

$required = ['MYSQL_TEST_HOST', 'MYSQL_TEST_DATABASE', 'MYSQL_TEST_USERNAME'];
foreach ($required as $key) {
    if (getenv($key) === false || getenv($key) === '') {
        fwrite(STDERR, "Missing environment variable: {$key}\n");
        exit(2);
    }
}

$host = (string) getenv('MYSQL_TEST_HOST');
$port = (string) (getenv('MYSQL_TEST_PORT') ?: '3306');
$database = (string) getenv('MYSQL_TEST_DATABASE');
$username = (string) getenv('MYSQL_TEST_USERNAME');
$password = (string) (getenv('MYSQL_TEST_PASSWORD') ?: '');

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectDatabaseFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException($message);
}

$tableCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
)->fetchColumn();
assertTrue($tableCount === 61, "Expected 61 V3 baseline tables, found {$tableCount}");

$foreignKeys = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
)->fetchColumn();
assertTrue($foreignKeys >= 40, "Expected at least 40 foreign keys, found {$foreignKeys}");

$indexes = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND index_name <> 'PRIMARY'"
)->fetchColumn();
assertTrue($indexes >= 80, "Expected at least 80 secondary indexes, found {$indexes}");

$checks = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK'"
)->fetchColumn();
assertTrue($checks >= 10, "Expected enforced CHECK constraints, found {$checks}");

$generatedColumns = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND extra LIKE '%GENERATED%'"
)->fetchColumn();
assertTrue($generatedColumns >= 2, "Expected generated columns for active-session uniqueness");

$pdo->beginTransaction();
try {
    expectDatabaseFailure(
        static fn () => $pdo->exec("INSERT INTO app_install_state (id, setup_completed) VALUES (2, 0)"),
        'CHECK constraint did not reject invalid install-state singleton id'
    );

    expectDatabaseFailure(
        static fn () => $pdo->exec("INSERT INTO app_user_roles (user_id, role_id) VALUES (999999999, 999999999)"),
        'Foreign key did not reject missing user/role references'
    );

    $pdo->exec(
        "INSERT INTO app_users (public_id, username, password_hash, display_name)
         VALUES ('00000000-0000-4000-8000-000000000001', 'v3-ci-unique', 'not-a-real-login-hash', 'CI')"
    );
    expectDatabaseFailure(
        static fn () => $pdo->exec(
            "INSERT INTO app_users (public_id, username, password_hash, display_name)
             VALUES ('00000000-0000-4000-8000-000000000002', 'v3-ci-unique', 'not-a-real-login-hash', 'CI duplicate')"
        ),
        'Unique username constraint did not reject a duplicate'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "V3 schema validation passed: {$tableCount} tables, {$foreignKeys} foreign keys, {$indexes} secondary indexes, {$checks} CHECK constraints, {$generatedColumns} generated columns.\n";
