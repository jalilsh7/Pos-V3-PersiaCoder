<?php
declare(strict_types=1);

/**
 * Database-level regression checks for constraints used by POS checkout.
 * Runs only against the disposable CI database and rolls back all fixtures.
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
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);

function expect_constraint_violation(callable $operation, string $label): void {
    try {
        $operation();
    } catch (PDOException $error) {
        if ((string)$error->getCode() === '23000') return;
        throw $error;
    }
    throw new RuntimeException("Expected database constraint violation: {$label}");
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $user = $pdo->prepare(
        "INSERT INTO app_users (username, password_hash, display_name, role, is_active, must_change_password)
         VALUES (?, ?, 'CI regression', 'manager', 1, 0)"
    );
    $user->execute(['ci_' . $suffix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $actorId = (int)$pdo->lastInsertId();

    $order = $pdo->prepare(
        "INSERT INTO pos_orders (order_number, status, idempotency_key, request_hash, items, totals, created_by)
         VALUES (?, 'new', ?, ?, JSON_ARRAY(), JSON_OBJECT(), ?)"
    );
    $idempotencyKey = sprintf(
        '%s-%s-%s-%s-%s',
        substr($suffix, 0, 8),
        substr($suffix, 0, 4),
        substr($suffix, 0, 4),
        substr($suffix, 0, 4),
        str_pad(substr($suffix, 0, 12), 12, '0')
    );
    $order->execute([900000000 + random_int(1, 999999), $idempotencyKey, hash('sha256', $suffix), $actorId]);
    $orderId = (int)$pdo->lastInsertId();

    expect_constraint_violation(
        fn() => $order->execute([900000000 + random_int(1, 999999), $idempotencyKey, hash('sha256', 'different'), $actorId]),
        'idempotency key must be unique'
    );

    $effect = $pdo->prepare(
        "INSERT INTO pos_order_effects (order_id, effect_type, resource_id, quantity)
         VALUES (?, 'ingredient', 'ci_fixture', 1)"
    );
    $effect->execute([$orderId]);
    expect_constraint_violation(
        fn() => $effect->execute([$orderId]),
        'one effect per order/type/resource'
    );

    $redemption = $pdo->prepare(
        "INSERT INTO pos_discount_redemptions (order_id, discount_id, code)
         VALUES (?, 'ci_discount', 'CI-TEST')"
    );
    $redemption->execute([$orderId]);
    expect_constraint_violation(
        fn() => $redemption->execute([$orderId]),
        'one discount redemption per order'
    );

    $pdo->rollBack();
    echo "MySQL transaction regression test passed: idempotency, effect ledger, and redemption uniqueness constraints verified; fixtures rolled back.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
