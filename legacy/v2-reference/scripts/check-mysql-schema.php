<?php
declare(strict_types=1);

/**
 * Structural smoke test against a freshly installed MySQL schema.
 * Credentials are supplied only by the CI environment, never committed.
 */
$host = getenv('MYSQL_TEST_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MYSQL_TEST_PORT') ?: 3306);
$database = getenv('MYSQL_TEST_DATABASE') ?: 'threefood_test';
$username = getenv('MYSQL_TEST_USERNAME') ?: 'root';
$password = getenv('MYSQL_TEST_PASSWORD') ?: '';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
    $username,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$requiredTables = [
    'app_users',
    'app_login_attempts',
    'app_audit_logs',
    'app_install_state',
    'pos_records',
    'pos_counters',
    'pos_orders',
    'pos_order_effects',
    'pos_discount_redemptions',
    'app_appearance_settings',
    'app_terminal_agents',
    'pos_payment_terminals',
    'pos_terminal_payments',
];
$tableQuery = $pdo->prepare(
    'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
);
foreach ($requiredTables as $table) {
    $tableQuery->execute([$table]);
    $row = $tableQuery->fetch();
    if (!$row) {
        fwrite(STDERR, "Missing required table: {$table}\n");
        exit(1);
    }
    if (strtoupper((string)$row['ENGINE']) !== 'INNODB') {
        fwrite(STDERR, "Table must use InnoDB: {$table}\n");
        exit(1);
    }
}

$requiredColumns = [
    'app_users' => ['session_version', 'password_hash', 'role'],
    'pos_orders' => ['idempotency_key', 'request_hash', 'guest_name', 'guest_phone', 'payment_method', 'points_used', 'points_earned', 'discount_code', 'items', 'totals'],
    'pos_order_effects' => ['order_id', 'effect_type', 'reversed_at'],
    'pos_discount_redemptions' => ['order_id', 'discount_id', 'customer_id', 'reversed_at'],
    'app_terminal_agents' => ['id', 'token_hash', 'enabled', 'last_seen_at'],
    'pos_payment_terminals' => ['id', 'agent_id', 'provider_code', 'driver_code', 'host', 'port', 'enabled'],
    'pos_terminal_payments' => ['id', 'idempotency_key', 'terminal_id', 'amount', 'status', 'trace_number', 'retrieval_reference_number'],
];
$columnQuery = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
);
foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $columnQuery->execute([$table, $column]);
        if ((int)$columnQuery->fetchColumn() !== 1) {
            fwrite(STDERR, "Missing required column: {$table}.{$column}\n");
            exit(1);
        }
    }
}

// Compare the complete ordered column list. A composite index produces one
// information_schema row per column, not one row per index.
$indexQuery = $pdo->prepare(
    'SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",") AS indexed_columns
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0
     GROUP BY INDEX_NAME'
);
$requiredIndexes = [
    ['pos_orders', 'uq_pos_order_idempotency', 'idempotency_key'],
    ['pos_order_effects', 'uq_pos_order_effect', 'order_id,effect_type,resource_id'],
    ['pos_discount_redemptions', 'uq_pos_discount_redemption_order', 'order_id'],
    ['app_terminal_agents', 'uq_terminal_agent_token_hash', 'token_hash'],
    ['pos_payment_terminals', 'uq_terminal_agent_local_key', 'agent_id,local_terminal_key'],
    ['pos_terminal_payments', 'uq_terminal_payment_idempotency', 'idempotency_key'],
];
foreach ($requiredIndexes as [$table, $index, $expectedColumns]) {
    $indexQuery->execute([$table, $index]);
    $actualColumns = $indexQuery->fetchColumn();
    if ($actualColumns !== $expectedColumns) {
        fwrite(STDERR, "Missing or invalid unique index: {$table}.{$index}; expected {$expectedColumns}\n");
        exit(1);
    }
}

$installState = (int)$pdo->query(
    'SELECT COUNT(*) FROM app_install_state WHERE id = 1 AND setup_completed = 0'
)->fetchColumn();
if ($installState !== 1) {
    fwrite(STDERR, "Fresh-install state row is missing or invalid\n");
    exit(1);
}

echo "MySQL schema smoke test passed: " . count($requiredTables) . " InnoDB tables, required columns, exact unique-index definitions, and install state verified.\n";
