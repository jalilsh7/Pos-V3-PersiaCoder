<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/v3/backend/src/Finance/OrderPaymentService.php');
$controller = file_get_contents($root . '/v3/backend/src/Finance/OrderPaymentController.php');
$routes = file_get_contents($root . '/v3/backend/public/index.php');
$schema = file_get_contents($root . '/database/v3/schema.sql');
$orderService = file_get_contents($root . '/v3/backend/src/Orders/OrderService.php');

$checks = [
    'cash payment is protected by the idempotency transaction boundary' =>
        str_contains($service, '$this->idempotency->execute('),
    'order is selected with business/branch scope and row lock' =>
        str_contains($service, 'AND branch_id = :branch_id AND public_id = :public_id') &&
        str_contains($service, '$forUpdate ?') && str_contains($service, ' FOR UPDATE'),
    'payment amount is checked against server-computed outstanding balance' =>
        str_contains($service, '$amount > $due'),
    'payment ledger row is captured as cash only' =>
        str_contains($service, "'cash', 'cashier', 'pos'") &&
        str_contains($service, "'IRT', 'captured'"),
    'payment event is recorded' =>
        str_contains($service, 'INSERT INTO pos_payment_events') &&
        str_contains($service, "'cash_captured'"),
    'cash drawer movement references the payment' =>
        str_contains($service, "'sale_in'") &&
        str_contains($service, 'hash(\'sha256\', \'cash-sale:\' . $idempotencyKey)'),
    'order payment status is updated in the same operation' =>
        str_contains($service, 'UPDATE pos_orders') &&
        str_contains($service, "'partially_paid'"),
    'only open sessions are offered to POS' =>
        str_contains($service, "s.status = 'open'") &&
        str_contains($service, 'listOpenCashSessions'),
    'cash capture endpoint is protected by POS permission, CSRF and same-origin' =>
        str_contains($routes, "'/api/v1/orders/{orderPublicId}/payments/cash'") &&
        str_contains($routes, "\$makePermissionMiddleware('pos.sell', true), \$csrfMiddleware, \$originMiddleware"),
    'cash payment controller requires JSON and forwards idempotency key' =>
        str_contains($controller, 'Content-Type must be application/json.') &&
        str_contains($controller, "header('idempotency-key')"),
    'schema allows captured cash payment and sale-in cash movement' =>
        str_contains($schema, "'captured'") &&
        str_contains($schema, "'sale_in'"),
    'cash refund requires finance permission and explicit confirmation' =>
        str_contains($service, "'finance.manage'") &&
        str_contains($service, "'confirm'") &&
        str_contains($service, 'refund_reason_required'),
    'cash refund is recorded with refund ledger and drawer movement' =>
        str_contains($service, 'INSERT INTO pos_refunds') &&
        str_contains($service, "'refund_out'") &&
        str_contains($service, "'cash_refunded'"),
    'cash refund endpoint is protected by finance permission, CSRF and same-origin' =>
        str_contains($routes, '/payments/{paymentPublicId}/refunds/cash') &&
        str_contains($routes, "\$makePermissionMiddleware('finance.manage', true), \$csrfMiddleware, \$originMiddleware"),
    'partially refunded orders cannot bypass refund before cancellation' =>
        str_contains($orderService, "'paid', 'partially_paid', 'partially_refunded'"),
    'frontend partial-payment filter uses the backend canonical status' =>
        str_contains(file_get_contents($root . '/v3/frontend/src/features/orders/OrderCenter.tsx'), 'value: "partially_paid", label: "پرداخت بخشی"') &&
        !str_contains(file_get_contents($root . '/v3/frontend/src/features/orders/OrderCenter.tsx'), 'value: "partial", label: "پرداخت بخشی"'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . ' payment contract check(s) failed.' . PHP_EOL);
    exit(1);
}
echo count($checks) . ' payment contract checks passed.' . PHP_EOL;
