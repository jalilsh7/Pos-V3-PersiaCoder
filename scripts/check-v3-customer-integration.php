<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Customers\CustomerService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;

$root = dirname(__DIR__);
require_once $root . '/database/v3/bin/bootstrap.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = "PersiaCoder\\PosV3\\";
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
$prefix = 'ci-customer-' . bin2hex(random_bytes(4));
$businessPublicId = $uuid();
$branchPublicId = $uuid();
$userPublicId = $uuid();

$insert = $pdo->prepare('INSERT INTO pos_businesses (public_id, code, name) VALUES (:public_id, :code, :name)');
$insert->execute(['public_id' => $businessPublicId, 'code' => $prefix, 'name' => 'CI Customer Business']);
$businessId = (int) $pdo->lastInsertId();
$insert = $pdo->prepare(
    'INSERT INTO pos_branches (public_id, business_id, code, name)
     VALUES (:public_id, :business_id, :code, :name)'
);
$insert->execute([
    'public_id' => $branchPublicId, 'business_id' => $businessId,
    'code' => $prefix . '-branch', 'name' => 'CI Customer Branch',
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
    'display_name' => 'CI Customer Manager',
]);
$userId = (int) $pdo->lastInsertId();
$roleId = $pdo->query("SELECT id FROM app_roles WHERE code = 'manager' AND is_active = 1")->fetchColumn();
if ($roleId === false) throw new RuntimeException('The manager role is missing after migrations.');
$pdo->prepare(
    'INSERT INTO app_user_business_roles (user_id, business_id, role_id)
     VALUES (:user_id, :business_id, :role_id)'
)->execute(['user_id' => $userId, 'business_id' => $businessId, 'role_id' => (int) $roleId]);

$scope = new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);
$actor = new AuthenticatedPrincipal(
    1, $userId, $userPublicId, $prefix, 'CI Customer Manager', false,
    hash('sha256', str_repeat('u', 43)), new DateTimeImmutable('+1 hour'),
);
$service = new CustomerService(
    $pdo,
    str_repeat('ci-customer-integration-key-', 2),
    new PermissionService(new PermissionRepository($pdo)),
);
$requestId = $uuid();
$ip = '127.0.0.1';

$created = $service->create(
    $actor, $scope,
    ['name' => 'مشتری آزمایشی', 'phone' => '۰۹۱۲ ۳۴۵ ۶۷۸۹', 'notes' => 'یادداشت آزمایشی'],
    'ci-customer-create-0001', $requestId, $ip,
);
$customerId = (string) $created['body']['data']['customer']['id'];
$assert($created['status'] === 201 && $created['body']['data']['customer']['phone'] === '۰۹۱۲ ۳۴۵ ۶۷۸۹',
    'Customer creation did not return the saved profile.');
$replay = $service->create(
    $actor, $scope,
    ['name' => 'مشتری آزمایشی', 'phone' => '۰۹۱۲ ۳۴۵ ۶۷۸۹', 'notes' => 'یادداشت آزمایشی'],
    'ci-customer-create-0001', $requestId, $ip,
);
$assert($replay['replayed'] === true, 'Customer create retry was not idempotent.');

$duplicateBlocked = false;
try {
    $service->create(
        $actor, $scope,
        ['name' => 'مشتری تکراری', 'phone' => '+989123456789'],
        'ci-customer-create-0002', $requestId, $ip,
    );
} catch (HttpException $error) {
    $duplicateBlocked = $error->status === 409 && $error->errorCode === 'customer_phone_exists';
}
$assert($duplicateBlocked, 'Duplicate normalized Iranian mobile was not rejected.');

$invalidPhoneBlocked = false;
try {
    $service->create(
        $actor, $scope,
        ['name' => 'شماره نامعتبر', 'phone' => '12345'],
        'ci-customer-create-0003', $requestId, $ip,
    );
} catch (HttpException $error) {
    $invalidPhoneBlocked = $error->status === 422 && $error->errorCode === 'customer_phone_invalid';
}
$assert($invalidPhoneBlocked, 'Invalid customer phone was not rejected.');

$customerRow = $pdo->prepare(
    'SELECT id, phone_normalized FROM pos_customers WHERE business_id = :business_id AND public_id = :public_id'
);
$customerRow->execute(['business_id' => $businessId, 'public_id' => $customerId]);
$customer = $customerRow->fetch(PDO::FETCH_ASSOC);
$assert(is_array($customer) && $customer['phone_normalized'] === '09123456789',
    'Iranian mobile was not normalized to the canonical local form.');
$customerDbId = (int) $customer['id'];

$insertOrder = $pdo->prepare(
    "INSERT INTO pos_orders
        (business_id, branch_id, public_id, order_number, source_channel, order_status, payment_status,
         customer_id, currency_code, subtotal_amount, discount_amount, tax_amount, delivery_fee_amount, total_amount, created_by)
     VALUES
        (:business_id, :branch_id, :public_id, :order_number, 'pos', 'completed', 'partially_refunded',
         :customer_id, 'IRT', 10000, 0, 0, 0, 10000, :created_by)"
);
$insertOrder->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $uuid(),
    'order_number' => random_int(100000, 999999999),
    'customer_id' => $customerDbId,
    'created_by' => $userId,
]);
$orderId = (int) $pdo->lastInsertId();

$insertPayment = $pdo->prepare(
    "INSERT INTO pos_payments
        (business_id, branch_id, public_id, order_id, method, provider_code, source_type,
         amount, currency_code, status, idempotency_key, captured_at, created_by)
     VALUES
        (:business_id, :branch_id, :public_id, :order_id, 'cash', 'cashier', 'pos',
         10000, 'IRT', 'partially_refunded', :idempotency_key, UTC_TIMESTAMP(3), :created_by)"
);
$insertPayment->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $uuid(),
    'order_id' => $orderId,
    'idempotency_key' => hash('sha256', $prefix . '-payment'),
    'created_by' => $userId,
]);
$paymentId = (int) $pdo->lastInsertId();
$insertRefund = $pdo->prepare(
    "INSERT INTO pos_refunds
        (business_id, branch_id, public_id, payment_id, order_id, amount, method, status,
         reason, idempotency_key, requested_by, approved_by, completed_at)
     VALUES
        (:business_id, :branch_id, :public_id, :payment_id, :order_id, 2000, 'cash', 'succeeded',
         'CI partial refund', :idempotency_key, :requested_by, :approved_by, UTC_TIMESTAMP(3))"
);
$insertRefund->execute([
    'business_id' => $businessId,
    'branch_id' => $branchId,
    'public_id' => $uuid(),
    'payment_id' => $paymentId,
    'order_id' => $orderId,
    'idempotency_key' => hash('sha256', $prefix . '-refund'),
    'requested_by' => $userId,
    'approved_by' => $userId,
]);

$detail = $service->get($scope, $customerId);
$assert($detail['stats']['order_count'] === 1, 'Customer order count did not include the linked order.');
$assert($detail['stats']['total_spent'] === 8000, 'Customer spend did not subtract successful refunds from captured payments.');
$assert(count($detail['recent_orders']) === 1, 'Customer recent order history is missing.');

$updated = $service->update(
    $actor, $scope, $customerId,
    ['name' => 'مشتری به‌روزشده', 'notes' => 'یادداشت اصلاح‌شده', 'is_active' => false],
    'ci-customer-update-0001', $requestId, $ip,
);
$assert($updated['body']['data']['customer']['name'] === 'مشتری به‌روزشده'
    && $updated['body']['data']['customer']['is_active'] === false,
    'Customer profile update did not persist the requested fields.');

$list = $service->list($scope, 'به‌روزشده', 'inactive', 50);
$assert(count($list['customers']) === 1 && $list['customers'][0]['id'] === $customerId,
    'Customer search/status filter did not return the updated customer.');

$secondBusinessPublicId = $uuid();
$insert->execute(['public_id' => $secondBusinessPublicId, 'code' => $prefix . '-other', 'name' => 'Other CI Business']);
$secondBusinessId = (int) $pdo->lastInsertId();
$otherScope = new BusinessScope($secondBusinessId, $secondBusinessPublicId, null, null);
$scopeBlocked = false;
try {
    $service->get($otherScope, $customerId);
} catch (HttpException $error) {
    $scopeBlocked = $error->status === 404 && $error->errorCode === 'customer_not_found';
}
$assert($scopeBlocked, 'Customer detail leaked across business scope.');

echo "V3 customer CRUD, phone normalization, order spend and business scope integration checks passed." . PHP_EOL;
