<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Finance;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\CashRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class OrderPaymentService
{
    private const MAX_AMOUNT = 9000000000000000;

    private IdempotencyService $idempotency;
    private AuditLogService $audit;
    private CashRepository $cash;

    public function __construct(
        private PDO $connection,
        string $applicationKey,
        private PermissionService $permissions,
    ) {
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
        $this->cash = new CashRepository($connection);
    }

    public function list(BusinessScope $scope, string $orderPublicId): array
    {
        $order = $this->findOrder($scope, $orderPublicId, false);
        if ($order === null) {
            throw new HttpException(404, 'order_not_found', 'The order was not found in this branch.');
        }

        $payments = $this->connection->prepare(
            "SELECT public_id, method, amount, currency_code, status, provider_reference, captured_at, created_at
             FROM pos_payments
             WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = :order_id
             ORDER BY id ASC"
        );
        $payments->execute([
            'business_id' => $scope->businessId,
            'branch_id' => $scope->branchId,
            'order_id' => (int) $order['id'],
        ]);
        $rows = array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'method' => (string) $row['method'],
            'amount' => (int) $row['amount'],
            'currency_code' => (string) $row['currency_code'],
            'status' => (string) $row['status'],
            'provider_reference' => $row['provider_reference'] === null ? null : (string) $row['provider_reference'],
            'captured_at' => $row['captured_at'] === null ? null : (string) $row['captured_at'],
            'created_at' => (string) $row['created_at'],
        ], $payments->fetchAll(PDO::FETCH_ASSOC));

        $totals = $this->paymentTotals($scope, (int) $order['id']);
        return [
            'order_id' => $orderPublicId,
            'order_number' => (int) $order['order_number'],
            'payment_status' => (string) $order['payment_status'],
            'total_amount' => (int) $order['total_amount'],
            'captured_amount' => $totals['captured'],
            'refunded_amount' => $totals['refunded'],
            'due_amount' => max(0, (int) $order['total_amount'] - $totals['captured'] + $totals['refunded']),
            'payments' => $rows,
        ];
    }

    public function listOpenCashSessions(BusinessScope $scope): array
    {
        if ($scope->branchId === null || $scope->branchId < 1) {
            throw new HttpException(400, 'branch_context_required', 'A branch context is required to list payment sessions.');
        }
        $statement = $this->connection->prepare(
            "SELECT s.public_id, r.name AS register_name, r.code AS register_code
             FROM pos_cash_sessions s
             INNER JOIN pos_registers r
               ON r.id = s.register_id
              AND r.business_id = s.business_id
              AND r.branch_id = s.branch_id
             WHERE s.business_id = :business_id AND s.branch_id = :branch_id
               AND s.status = 'open' AND r.is_active = 1
             ORDER BY r.name ASC, s.opened_at ASC"
        );
        $statement->execute([
            'business_id' => $scope->businessId,
            'branch_id' => $scope->branchId,
        ]);
        return ['sessions' => array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'register_name' => (string) $row['register_name'],
            'register_code' => (string) $row['register_code'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function captureCash(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $orderPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if ($scope->branchId === null || $scope->branchId < 1) {
            throw new HttpException(400, 'branch_context_required', 'A branch context is required to receive payment.');
        }
        if (!self::isUuid($orderPublicId)) {
            throw new HttpException(400, 'invalid_order_id', 'The order identifier is invalid.');
        }
        if (!$this->permissions->allows($actor, 'pos.sell', $scope)) {
            throw new HttpException(403, 'permission_denied', 'POS sale permission is required to receive payment.');
        }

        $amount = self::parseAmount($payload['amount'] ?? null);
        if ($amount < 1) {
            throw new HttpException(422, 'payment_amount_invalid', 'The received amount must be greater than zero.');
        }
        $sessionPublicId = $payload['cash_session_id'] ?? null;
        if (!is_string($sessionPublicId) || !self::isUuid($sessionPublicId)) {
            throw new HttpException(422, 'cash_session_required', 'Select an open cash session for this cash payment.');
        }

        $fingerprint = hash('sha256', json_encode([
            'order_id' => $orderPublicId,
            'method' => 'cash',
            'amount' => $amount,
            'cash_session_id' => $sessionPublicId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'orders.payments.cash',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $actor, $scope, $orderPublicId, $amount, $sessionPublicId,
                $idempotencyKey, $requestId, $ipAddress,
            ): array {
                $order = $this->findOrder($scope, $orderPublicId, true);
                if ($order === null) {
                    throw new HttpException(404, 'order_not_found', 'The order was not found in this branch.');
                }
                if ((string) $order['source_channel'] !== 'pos') {
                    throw new HttpException(409, 'cash_payment_channel_invalid', 'Cashier payments can only be recorded for POS orders.');
                }
                if (in_array((string) $order['order_status'], ['cancelled', 'rejected', 'completed'], true)) {
                    throw new HttpException(409, 'order_not_payable', 'This order can no longer receive a cashier payment.');
                }

                $session = $this->cash->findOpenSessionForUpdate(
                    $scope->businessId, (int) $scope->branchId, $sessionPublicId,
                );
                if ($session === null) {
                    throw new HttpException(409, 'cash_session_not_open', 'Cash payment requires an open cash session in this branch.');
                }

                $totals = $this->paymentTotals($scope, (int) $order['id']);
                $due = (int) $order['total_amount'] - $totals['captured'] + $totals['refunded'];
                if ($due < 1) {
                    throw new HttpException(409, 'order_already_paid', 'The order has no outstanding balance.');
                }
                if ($amount > $due) {
                    throw new HttpException(422, 'payment_exceeds_balance', 'The received amount cannot exceed the outstanding balance.', [
                        'due_amount' => $due,
                    ]);
                }

                $paymentPublicId = self::uuidV4();
                $paymentKey = hash('sha256', $idempotencyKey);
                $insert = $connection->prepare(
                    "INSERT INTO pos_payments
                        (business_id, branch_id, public_id, order_id, method, provider_code, source_type,
                         amount, currency_code, status, idempotency_key, captured_at, created_by)
                     VALUES
                        (:business_id, :branch_id, :public_id, :order_id, 'cash', 'cashier', 'pos',
                         :amount, 'IRT', 'captured', :idempotency_key, UTC_TIMESTAMP(3), :created_by)"
                );
                $insert->execute([
                    'business_id' => $scope->businessId,
                    'branch_id' => $scope->branchId,
                    'public_id' => $paymentPublicId,
                    'order_id' => (int) $order['id'],
                    'amount' => $amount,
                    'idempotency_key' => $paymentKey,
                    'created_by' => $actor->userId,
                ]);
                $paymentId = (int) $connection->lastInsertId();

                $event = $connection->prepare(
                    "INSERT INTO pos_payment_events
                        (business_id, branch_id, payment_id, event_type, from_status, to_status,
                         amount, actor_user_id, idempotency_key, safe_payload)
                     VALUES
                        (:business_id, :branch_id, :payment_id, 'cash_captured', NULL, 'captured',
                         :amount, :actor_user_id, :idempotency_key, :safe_payload)"
                );
                $event->execute([
                    'business_id' => $scope->businessId,
                    'branch_id' => $scope->branchId,
                    'payment_id' => $paymentId,
                    'amount' => $amount,
                    'actor_user_id' => $actor->userId,
                    'idempotency_key' => hash('sha256', 'cash-captured:' . $idempotencyKey),
                    'safe_payload' => json_encode(['cash_session_id' => $sessionPublicId], JSON_THROW_ON_ERROR),
                ]);

                $this->cash->increaseExpectedCash(
                    $scope->businessId, (int) $scope->branchId, (int) $session['id'], $amount,
                );
                $this->cash->createMovement(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $session['id'],
                    self::uuidV4(),
                    'sale_in',
                    $amount,
                    $paymentId,
                    null,
                    null,
                    null,
                    $actor->userId,
                    hash('sha256', 'cash-sale:' . $idempotencyKey),
                );

                $capturedAfter = $totals['captured'] + $amount;
                $netPaidAfter = $capturedAfter - $totals['refunded'];
                $paymentStatus = $netPaidAfter >= (int) $order['total_amount']
                    ? 'paid'
                    : ($netPaidAfter > 0 ? 'partially_paid' : 'unpaid');
                $update = $connection->prepare(
                    'UPDATE pos_orders
                     SET payment_status = :payment_status
                     WHERE id = :order_id AND business_id = :business_id AND branch_id = :branch_id'
                );
                $update->execute([
                    'payment_status' => $paymentStatus,
                    'order_id' => (int) $order['id'],
                    'business_id' => $scope->businessId,
                    'branch_id' => $scope->branchId,
                ]);

                $this->audit->record(
                    $actor->userId,
                    'order.cash_payment_captured',
                    'payment',
                    $paymentPublicId,
                    ['payment_status' => (string) $order['payment_status'], 'due_amount' => $due],
                    [
                        'payment_status' => $paymentStatus,
                        'amount' => $amount,
                        'order_number' => (int) $order['order_number'],
                        'cash_session_id' => $sessionPublicId,
                    ],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );

                return [
                    'status' => 201,
                    'body' => ['data' => [
                        'payment' => [
                            'id' => $paymentPublicId,
                            'method' => 'cash',
                            'amount' => $amount,
                            'status' => 'captured',
                            'captured_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                        ],
                        'order' => [
                            'id' => $orderPublicId,
                            'order_number' => (int) $order['order_number'],
                            'payment_status' => $paymentStatus,
                            'captured_amount' => $capturedAfter,
                            'due_amount' => max(0, (int) $order['total_amount'] - $capturedAfter + $totals['refunded']),
                        ],
                    ], 'meta' => (object) []],
                ];
            },
        );
    }

    /** @return array<string,mixed>|null */
    private function findOrder(BusinessScope $scope, string $orderPublicId, bool $forUpdate): ?array
    {
        $sql = 'SELECT id, public_id, order_number, source_channel, order_status, payment_status, total_amount
                FROM pos_orders
                WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
                LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $this->connection->prepare($sql);
        $statement->execute([
            'business_id' => $scope->businessId,
            'branch_id' => $scope->branchId,
            'public_id' => $orderPublicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{captured:int,refunded:int} */
    private function paymentTotals(BusinessScope $scope, int $orderId): array
    {
        $payments = $this->connection->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM pos_payments
             WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = :order_id
               AND status IN ('captured','partially_refunded','refunded')"
        );
        $payments->execute([
            'business_id' => $scope->businessId,
            'branch_id' => $scope->branchId,
            'order_id' => $orderId,
        ]);

        $refunds = $this->connection->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM pos_refunds
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND order_id = :order_id AND status = 'succeeded'"
        );
        $refunds->execute([
            'business_id' => $scope->businessId,
            'branch_id' => $scope->branchId,
            'order_id' => $orderId,
        ]);

        return [
            'captured' => (int) $payments->fetchColumn(),
            'refunded' => (int) $refunds->fetchColumn(),
        ];
    }

    private static function parseAmount(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^[1-9][0-9]{0,15}$/', $value))) {
            throw new HttpException(422, 'payment_amount_invalid', 'Amount must be a positive integer in IRT.');
        }
        $amount = (int) $value;
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new HttpException(422, 'payment_amount_invalid', 'Amount is outside the supported range.');
        }
        return $amount;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
