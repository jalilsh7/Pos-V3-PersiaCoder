<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Finance\OrderPaymentService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;

$root = dirname(__DIR__);
require_once $root . '/database/v3/bin/bootstrap.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\PosV3\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$pdo = PosV3DatabaseTools::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$uuid = static fn (): string => sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
);
$prefix = 'ci-pay-' . bin2hex(random_bytes(5));
$businessPublicId = $uuid();
$branchPublicId = $uuid();
$userPublicId = $uuid();
$registerPublicId = $uuid();
$sessionPublicId = $uuid();
$orderPublicId = $uuid();

$insert = $pdo->prepare(
    'INSERT INTO pos_businesses (public_id, code, name) VALUES (:public_id, :code, :name)'
);
$insert->execute([
    'public_id' => $businessPublicId,
    'code' => $prefix,
    'name' => 'CI Payment Business',
]);
$businessId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO pos_branches (public_id, business_id, code, name)
     VALUES (:public_id, :business_id, :code, :name)'
);
$insert->execute([
    'public_id' => $branchPublicId,
    'business_id' => $businessId,
    'code' => $prefix . '-branch',
    'name' => 'CI Payment Branch',
]);
$branchId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO app_users (public_id, username, password_hash, display_name, is_active, must_change_password)
     VALUES (:public_id, :username, :password_hash, :display_name, 1, 0)'
);
$insert->execute([
    'public_id' => $userPublicId,
    'username' => $prefix,
    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'display_name' => 'CI Payment Manager',
]);
$userId = (int) $pdo->lastInsertId();

$roleId = $pdo->query("SELECT id FROM app_roles WHERE code = 'manager' AND is_active = 1")->fetchColumn();
if ($roleId === false) throw new RuntimeException('The manager role is missing after migrations.');
$pdo->prepare(
    'INSERT INTO app_user_business_roles (user_id, business_id, role_id)
     VALUES (:user_id, :business_id, :role_id)'
)->execute(['user_id' => $userId, 'business_id' => $businessId, 'role_id' => (int) $roleId]);

$insert = $pdo->prepare(
    'INSERT INTO pos_registers (business_id, branch_id, public_id, code, name, is_active)
     VALUES (:business_id, :branch_id, :public_id, :code, :name, 1)'
);
$insert->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $registerPublicId,
    'code' => $prefix . '-register',
    'name' => 'CI Cash Register',
]);
$registerId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    "INSERT INTO pos_cash_sessions
        (business_id, branch_id, public_id, register_id, opened_by, opening_amount, expected_cash_amount, status)
     VALUES
        (:business_id, :branch_id, :public_id, :register_id, :opened_by, 0, 0, 'open')"
);
$insert->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $sessionPublicId,
    'register_id' => $registerId,
    'opened_by' => $userId,
]);
$sessionId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    "INSERT INTO pos_orders
        (business_id, branch_id, public_id, order_number, source_channel, order_status, payment_status,
         currency_code, subtotal_amount, discount_amount, tax_amount, delivery_fee_amount, total_amount, created_by)
     VALUES
        (:business_id, :branch_id, :public_id, :order_number, 'pos', 'draft', 'unpaid',
         'IRT', 10000, 0, 0, 0, 10000, :created_by)"
);
$insert->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $orderPublicId,
    'order_number' => random_int(100000, 999999999),
    'created_by' => $userId,
]);
$orderId = (int) $pdo->lastInsertId();

$scope = new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);
$actor = new AuthenticatedPrincipal(
    1, $userId, $userPublicId, $prefix, 'CI Payment Manager', false,
    hash('sha256', str_repeat('p', 43)), new DateTimeImmutable('+1 hour'),
);
$service = new OrderPaymentService(
    $pdo,
    str_repeat('ci-payment-integration-key-', 2),
    new PermissionService(new PermissionRepository($pdo)),
);
$requestId = $uuid();
$ip = '127.0.0.1';

$first = $service->captureCash(
    $actor, $scope, $orderPublicId,
    ['amount' => 4000, 'cash_session_id' => $sessionPublicId],
    'ci-cash-capture-0001', $requestId, $ip,
);
$assert($first['status'] === 201 && $first['body']['data']['order']['payment_status'] === 'partially_paid',
    'First partial cash capture did not set partially_paid.');
$assert($first['body']['data']['order']['due_amount'] === 6000, 'Partial capture outstanding balance is incorrect.');

$replay = $service->captureCash(
    $actor, $scope, $orderPublicId,
    ['amount' => 4000, 'cash_session_id' => $sessionPublicId],
    'ci-cash-capture-0001', $requestId, $ip,
);
$assert($replay['replayed'] === true, 'Repeated cash capture was not served from idempotency storage.');

$overpaymentBlocked = false;
try {
    $service->captureCash(
        $actor, $scope, $orderPublicId,
        ['amount' => 6001, 'cash_session_id' => $sessionPublicId],
        'ci-cash-capture-0002-over', $requestId, $ip,
    );
} catch (HttpException $error) {
    $overpaymentBlocked = $error->status === 422 && $error->errorCode === 'payment_exceeds_balance';
}
$assert($overpaymentBlocked, 'Cash overpayment was not rejected using the server-side balance.');

$second = $service->captureCash(
    $actor, $scope, $orderPublicId,
    ['amount' => 6000, 'cash_session_id' => $sessionPublicId],
    'ci-cash-capture-0002', $requestId, $ip,
);
$assert($second['body']['data']['order']['payment_status'] === 'paid'
    && $second['body']['data']['order']['due_amount'] === 0,
    'Final cash capture did not settle the order.');

$summary = $service->list($scope, $orderPublicId);
$assert($summary['captured_amount'] === 10000 && $summary['due_amount'] === 0
    && count($summary['payments']) === 2 && count($summary['refunds']) === 0,
    'Payment summary did not reflect the two captured cash payments.');

$paymentOne = (string) $first['body']['data']['payment']['id'];
$paymentTwo = (string) $second['body']['data']['payment']['id'];
$refundOne = $service->refundCash(
    $actor, $scope, $orderPublicId, $paymentOne,
    ['amount' => 1500, 'cash_session_id' => $sessionPublicId, 'reason' => 'CI partial refund', 'confirm' => true],
    'ci-cash-refund-0001', $requestId, $ip,
);
$assert($refundOne['body']['data']['order']['payment_status'] === 'partially_refunded',
    'Partial refund did not set the order payment status to partially_refunded.');
$refundReplay = $service->refundCash(
    $actor, $scope, $orderPublicId, $paymentOne,
    ['amount' => 1500, 'cash_session_id' => $sessionPublicId, 'reason' => 'CI partial refund', 'confirm' => true],
    'ci-cash-refund-0001', $requestId, $ip,
);
$assert($refundReplay['replayed'] === true, 'Repeated cash refund was not served from idempotency storage.');

$service->refundCash(
    $actor, $scope, $orderPublicId, $paymentOne,
    ['amount' => 2500, 'cash_session_id' => $sessionPublicId, 'reason' => 'CI complete first payment refund', 'confirm' => true],
    'ci-cash-refund-0002', $requestId, $ip,
);
$lastRefund = $service->refundCash(
    $actor, $scope, $orderPublicId, $paymentTwo,
    ['amount' => 6000, 'cash_session_id' => $sessionPublicId, 'reason' => 'CI complete second payment refund', 'confirm' => true],
    'ci-cash-refund-0003', $requestId, $ip,
);
$assert($lastRefund['body']['data']['order']['payment_status'] === 'refunded'
    && $lastRefund['body']['data']['order']['due_amount'] === 0,
    'Full refund did not set refunded with zero collectible balance.');

$sessionQuery = $pdo->prepare(
    'SELECT expected_cash_amount FROM pos_cash_sessions
     WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id'
);
$sessionQuery->execute(['id' => $sessionId, 'business_id' => $businessId, 'branch_id' => $branchId]);
$assert((int) $sessionQuery->fetchColumn() === 0, 'Cash session expected amount did not reconcile after full refunds.');

$count = static function (PDO $pdo, string $sql, array $params): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};
$params = ['business_id' => $businessId, 'branch_id' => $branchId, 'order_id' => $orderId];
$assert($count($pdo, 'SELECT COUNT(*) FROM pos_payments WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = :order_id', $params) === 2,
    'Idempotent payment retry created a duplicate payment row.');
$assert($count($pdo, 'SELECT COUNT(*) FROM pos_refunds WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = :order_id AND status = \'succeeded\'', $params) === 3,
    'Idempotent refund retry created a duplicate refund row.');
$assert($count($pdo, 'SELECT COUNT(*) FROM pos_payment_events WHERE business_id = :business_id AND branch_id = :branch_id', ['business_id' => $businessId, 'branch_id' => $branchId]) === 5,
    'Payment event ledger does not contain exactly one event per capture/refund.');
$assert($count($pdo, 'SELECT COUNT(*) FROM pos_cash_movements WHERE business_id = :business_id AND branch_id = :branch_id', ['business_id' => $businessId, 'branch_id' => $branchId]) === 5,
    'Cash movements do not contain exactly one row per capture/refund.');

$finalSummary = $service->list($scope, $orderPublicId);
$assert($finalSummary['captured_amount'] === 10000 && $finalSummary['refunded_amount'] === 10000
    && $finalSummary['due_amount'] === 0 && $finalSummary['payment_status'] === 'refunded'
    && count($finalSummary['refunds']) === 3,
    'Final payment/refund summary is inconsistent.');

$fullyRefundedPaymentBlocked = false;
try {
    $service->captureCash(
        $actor, $scope, $orderPublicId,
        ['amount' => 10000, 'cash_session_id' => $sessionPublicId],
        'ci-cash-capture-after-refund', $requestId, $ip,
    );
} catch (HttpException $error) {
    $fullyRefundedPaymentBlocked = $error->status === 409 && $error->errorCode === 'order_fully_refunded';
}
$assert($fullyRefundedPaymentBlocked, 'A fully refunded order accepted a new cash capture.');

echo "V3 cash payment/refund MySQL integration checks passed." . PHP_EOL;
