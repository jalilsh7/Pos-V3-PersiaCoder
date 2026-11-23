<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Orders;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\OrderRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;
use PersiaCoder\PosV3\Communications\Sms\SmsServiceTemplateOutbox;

final readonly class OrderService
{
    private const MAX_AMOUNT = 9000000000000000;
    private const MAX_QUANTITY_MILLI = 1000000;

    private OrderRepository $orders;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;
    private SmsServiceTemplateOutbox $smsTemplates;
    private OrderInventoryLifecycleService $inventoryLifecycle;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private PermissionService $permissions,
    ) {
        $this->orders = new OrderRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
        $this->smsTemplates = new SmsServiceTemplateOutbox($connection, $applicationKey);
        $this->inventoryLifecycle = new OrderInventoryLifecycleService($connection);
    }

    public function list(
        BusinessScope $scope,
        ?string $status,
        ?string $channel,
        int $limit,
        ?string $beforeCursor = null,
        ?string $paymentStatus = null,
        ?string $fulfillmentMethod = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        if ($scope->branchId === null || $scope->branchId < 1) {
            throw new HttpException(400, 'branch_context_required', 'A branch context is required to list orders.');
        }
        if ($status !== null && !in_array($status, self::statuses(), true)) {
            throw new HttpException(422, 'invalid_order_status_filter', 'The order status filter is invalid.');
        }
        if ($channel !== null && !in_array($channel, self::channels(), true)) {
            throw new HttpException(422, 'invalid_order_channel_filter', 'The order channel filter is invalid.');
        }
        if ($paymentStatus !== null && !in_array($paymentStatus, self::paymentStatuses(), true)) {
            throw new HttpException(422, 'invalid_order_payment_filter', 'The order payment filter is invalid.');
        }
        if ($fulfillmentMethod !== null && !in_array($fulfillmentMethod, self::fulfillmentMethods(), true)) {
            throw new HttpException(422, 'invalid_order_fulfillment_filter', 'The order fulfillment filter is invalid.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new HttpException(422, 'invalid_order_limit', 'Limit must be between 1 and 100.');
        }
        $beforeOrderNumber = null;
        if ($beforeCursor !== null) {
            if (!preg_match('/^[1-9][0-9]{0,18}$/', $beforeCursor)) {
                throw new HttpException(422, 'invalid_order_cursor', 'The order pagination cursor is invalid.');
            }
            $validatedCursor = filter_var(
                $beforeCursor,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if ($validatedCursor === false) {
                throw new HttpException(422, 'invalid_order_cursor', 'The order pagination cursor is outside the supported range.');
            }
            $beforeOrderNumber = $validatedCursor;
        }
        [$createdFromUtc, $createdToUtc] = $this->normalizeDateRange(
            $scope, $fromDate, $toDate,
        );
        return $this->orders->listOrders(
            $scope->businessId,
            $scope->branchId,
            $status,
            $channel,
            $limit,
            $beforeOrderNumber,
            $paymentStatus,
            $fulfillmentMethod,
            $createdFromUtc,
            $createdToUtc,
        );
    }

    /** @return array{?string,?string} UTC DATETIME bounds; the upper bound is exclusive. */
    private function normalizeDateRange(
        BusinessScope $scope, ?string $fromDate, ?string $toDate,
    ): array {
        if ($fromDate === null && $toDate === null) {
            return [null, null];
        }
        $timezoneName = $this->orders->findEffectiveTimezone(
            $scope->businessId, (int) $scope->branchId,
        );
        if ($timezoneName === null) {
            throw new HttpException(409, 'order_timezone_unavailable', 'The business or branch timezone is unavailable.');
        }
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Throwable) {
            throw new HttpException(409, 'order_timezone_invalid', 'The configured business or branch timezone is invalid.');
        }

        $from = $fromDate === null ? null : self::parseLocalDate($fromDate, $timezone);
        $to = $toDate === null ? null : self::parseLocalDate($toDate, $timezone);
        if ($from !== null && $to !== null) {
            if ($from > $to) {
                throw new HttpException(422, 'invalid_order_date_range', 'The start date must not be after the end date.');
            }
            $inclusiveDays = $from->diff($to)->days + 1;
            if ($inclusiveDays > 366) {
                throw new HttpException(422, 'order_date_range_too_large', 'The order date range must not exceed 366 local calendar days.');
            }
        }

        $utc = new \DateTimeZone('UTC');
        return [
            $from?->setTimezone($utc)->format('Y-m-d H:i:s.u'),
            $to?->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s.u'),
        ];
    }

    private static function parseLocalDate(string $value, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new HttpException(422, 'invalid_order_date_filter', 'Order dates must use YYYY-MM-DD format.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new HttpException(422, 'invalid_order_date_filter', 'The order date is not a valid calendar date.');
        }
        return $date;
    }

    public function get(BusinessScope $scope, string $orderPublicId): array
    {
        if (!self::isUuid($orderPublicId)) {
            throw new HttpException(400, 'invalid_order_id', 'The order identifier is invalid.');
        }
        $order = $this->orders->findOrder($scope->businessId, (int) $scope->branchId, $orderPublicId);
        if ($order === null) {
            throw new HttpException(404, 'order_not_found', 'The order was not found in this branch.');
        }

        $address = null;
        if (is_string($order['address_snapshot']) && $order['address_snapshot'] !== '') {
            $decoded = json_decode($order['address_snapshot'], true);
            $address = is_array($decoded) ? $decoded : null;
        }

        return [
            'order' => [
                'id' => (string) $order['public_id'],
                'order_number' => (int) $order['order_number'],
                'source_channel' => (string) $order['source_channel'],
                'order_status' => (string) $order['order_status'],
                'payment_status' => (string) $order['payment_status'],
                'currency_code' => (string) $order['currency_code'],
                'subtotal_amount' => (int) $order['subtotal_amount'],
                'discount_amount' => (int) $order['discount_amount'],
                'tax_amount' => (int) $order['tax_amount'],
                'delivery_fee_amount' => (int) $order['delivery_fee_amount'],
                'total_amount' => (int) $order['total_amount'],
                'created_at' => (string) $order['created_at'],
                'accepted_at' => $order['accepted_at'] === null ? null : (string) $order['accepted_at'],
                'customer' => $order['customer_public_id'] === null ? null : [
                    'id' => (string) $order['customer_public_id'],
                    'name' => (string) $order['customer_name'],
                ],
                'table_session_id' => $order['table_session_public_id'] === null ? null : (string) $order['table_session_public_id'],
                'fulfillment' => $order['fulfillment_method'] === null ? null : [
                    'method' => (string) $order['fulfillment_method'],
                    'status' => (string) $order['fulfillment_status'],
                    'recipient_name' => $order['recipient_name_snapshot'] === null ? null : (string) $order['recipient_name_snapshot'],
                    'recipient_phone' => $order['recipient_phone_snapshot'] === null ? null : (string) $order['recipient_phone_snapshot'],
                    'address' => $address,
                    'delivery_fee_amount' => (int) $order['fulfillment_fee'],
                ],
            ],
            'items' => $this->orders->listOrderItemsWithOptions(
                $scope->businessId,
                (int) $scope->branchId,
                (int) $order['id'],
            ),
            'status_history' => $this->orders->listStatusEvents(
                $scope->businessId,
                (int) $scope->branchId,
                (int) $order['id'],
            ),
        ];
    }

    public function create(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $sourceChannel = $payload['source_channel'] ?? 'pos';
        if (!is_string($sourceChannel) || !in_array($sourceChannel, self::channels(), true)) {
            throw new HttpException(422, 'invalid_order_channel', 'The order source channel is invalid.');
        }
        if ($sourceChannel === 'pos' && !$this->permissions->allows($actor, 'pos.sell', $scope)) {
            throw new HttpException(403, 'permission_denied', 'POS sale permission is required for this channel.');
        }

        $customerPublicId = $payload['customer_id'] ?? null;
        $tableSessionPublicId = $payload['table_session_id'] ?? null;
        if ($customerPublicId !== null && (!is_string($customerPublicId) || !self::isUuid($customerPublicId))) {
            throw new HttpException(422, 'invalid_customer_id', 'Customer identifier is invalid.');
        }
        if ($tableSessionPublicId !== null && (!is_string($tableSessionPublicId) || !self::isUuid($tableSessionPublicId))) {
            throw new HttpException(422, 'invalid_table_session_id', 'Table session identifier is invalid.');
        }
        if ($sourceChannel === 'table' && $tableSessionPublicId === null) {
            throw new HttpException(422, 'table_session_required', 'A table session is required for table orders.');
        }
        if ($sourceChannel !== 'table' && $tableSessionPublicId !== null) {
            throw new HttpException(422, 'table_session_channel_mismatch', 'A table session can only be used for table orders.');
        }

        [$fulfillmentMethod, $recipientName, $recipientPhone, $addressJson] = $this->normalizeFulfillment($payload, $sourceChannel);
        $items = $this->normalizeItems($payload['items'] ?? null);
        $fingerprint = self::fingerprint([
            'source_channel' => $sourceChannel,
            'customer_id' => $customerPublicId,
            'table_session_id' => $tableSessionPublicId,
            'fulfillment_method' => $fulfillmentMethod,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'address_snapshot' => $addressJson,
            'items' => $items,
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'orders.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $actor,
                $scope,
                $sourceChannel,
                $customerPublicId,
                $tableSessionPublicId,
                $fulfillmentMethod,
                $recipientName,
                $recipientPhone,
                $addressJson,
                $items,
                $requestId,
                $ipAddress,
            ): array {
                $customerId = $customerPublicId === null
                    ? null
                    : $this->orders->findCustomerId($scope->businessId, $customerPublicId);
                if ($customerPublicId !== null && $customerId === null) {
                    throw new HttpException(404, 'customer_not_found', 'The customer was not found in this business.');
                }

                $tableSessionId = $tableSessionPublicId === null
                    ? null
                    : $this->orders->findOpenTableSessionId($scope->businessId, (int) $scope->branchId, $tableSessionPublicId);
                if ($tableSessionPublicId !== null && $tableSessionId === null) {
                    throw new HttpException(404, 'open_table_session_not_found', 'The open table session was not found in this branch.');
                }

                $orderLines = [];
                $subtotal = 0;
                $taxTotal = 0;

                foreach ($items as $item) {
                    $product = $this->orders->findProductForUpdate($scope->businessId, $item['product_id']);
                    if ($product === null) {
                        throw new HttpException(422, 'product_unavailable', 'One of the selected products is unavailable.');
                    }

                    $variants = $this->orders->listActiveVariantsForUpdate($scope->businessId, (int) $product['id']);
                    $variant = null;
                    if ($variants !== []) {
                        if ($item['variant_id'] === null) {
                            throw new HttpException(422, 'product_variant_required', 'Select a variant for this product.');
                        }
                        foreach ($variants as $candidate) {
                            if (hash_equals((string) $candidate['public_id'], $item['variant_id'])) {
                                $variant = $candidate;
                                break;
                            }
                        }
                        if ($variant === null) {
                            throw new HttpException(422, 'invalid_product_variant', 'The selected variant is not available for this product.');
                        }
                    } elseif ($item['variant_id'] !== null) {
                        throw new HttpException(422, 'unexpected_product_variant', 'This product does not have selectable variants.');
                    }

                    $groups = $this->orders->listProductOptionGroupsForUpdate($scope->businessId, (int) $product['id']);
                    $selectedOptions = $this->resolveOptions($scope->businessId, $groups, $item['options']);

                    $baseUnitPrice = (int) $product['base_price_amount'];
                    if ($variant !== null) {
                        $baseUnitPrice = self::safeAdd($baseUnitPrice, (int) $variant['price_delta_amount']);
                    }
                    if ($baseUnitPrice < 0 || $baseUnitPrice > self::MAX_AMOUNT) {
                        throw new HttpException(422, 'invalid_calculated_price', 'The product and variant price is outside the allowed range.');
                    }
                    $effectiveUnitPrice = $baseUnitPrice;
                    foreach ($selectedOptions as $selectedOption) {
                        $effectiveUnitPrice = self::safeAdd($effectiveUnitPrice, (int) $selectedOption['price_delta_amount']);
                    }
                    if ($effectiveUnitPrice < 0 || $effectiveUnitPrice > self::MAX_AMOUNT) {
                        throw new HttpException(422, 'invalid_calculated_price', 'The calculated item price is outside the allowed range.');
                    }

                    $lineSubtotal = self::amountForQuantity($effectiveUnitPrice, $item['quantity_milli']);
                    $taxRate = (int) $product['tax_rate_bps'];
                    if ($taxRate < 0 || $taxRate > 10000) {
                        throw new HttpException(422, 'invalid_product_tax', 'The product tax rate is invalid.');
                    }
                    $lineTax = self::calculateTax($lineSubtotal, $taxRate);
                    $lineTotal = self::safeAdd($lineSubtotal, $lineTax);
                    $subtotal = self::safeAdd($subtotal, $lineSubtotal);
                    $taxTotal = self::safeAdd($taxTotal, $lineTax);

                    $orderLines[] = [
                        'product' => $product,
                        'variant' => $variant,
                        'quantity' => $item['quantity'],
                        'unit_price' => $baseUnitPrice,
                        'tax_rate' => $taxRate,
                        'tax_amount' => $lineTax,
                        'line_total' => $lineTotal,
                        'line_subtotal' => $lineSubtotal,
                        'options' => $selectedOptions,
                    ];
                }

                $total = self::safeAdd($subtotal, $taxTotal);
                $orderPublicId = self::uuidV4();
                $orderNumber = $this->orders->nextOrderNumber($scope->businessId, (int) $scope->branchId);
                $orderId = $this->orders->createOrder(
                    $scope->businessId,
                    (int) $scope->branchId,
                    $orderPublicId,
                    $orderNumber,
                    $sourceChannel,
                    $customerId,
                    $tableSessionId,
                    $subtotal,
                    $taxTotal,
                    $total,
                    $actor->userId,
                );
                if ($fulfillmentMethod !== null) {
                    $this->orders->createFulfillment(
                        $scope->businessId,
                        (int) $scope->branchId,
                        $orderId,
                        $fulfillmentMethod,
                        $recipientName,
                        $recipientPhone,
                        $addressJson,
                    );
                }

                foreach ($orderLines as $line) {
                    $product = $line['product'];
                    $variant = $line['variant'];
                    $sku = $variant !== null && is_string($variant['sku'] ?? null)
                        ? (string) $variant['sku']
                        : ($product['sku'] === null ? null : (string) $product['sku']);
                    $orderItemId = $this->orders->createOrderItem(
                        $scope->businessId,
                        (int) $scope->branchId,
                        $orderId,
                        (int) $product['id'],
                        $variant === null ? null : (int) $variant['id'],
                        (string) $product['name'],
                        $variant === null ? null : (string) $variant['name'],
                        $sku,
                        $line['quantity'],
                        $line['unit_price'],
                        $line['tax_rate'],
                        $line['tax_amount'],
                        $line['line_total'],
                    );
                    foreach ($line['options'] as $option) {
                        $this->orders->createOrderItemOption(
                            $scope->businessId,
                            (int) $scope->branchId,
                            $orderItemId,
                            (int) $option['group_id'],
                            (int) $option['id'],
                            (string) $option['group_name'],
                            (string) $option['name'],
                            (int) $option['price_delta_amount'],
                        );
                    }
                }

                $idempotencyHash = hash('sha256', $requestId . ':' . $orderPublicId);
                $this->orders->createStatusEvent(
                    $scope->businessId,
                    (int) $scope->branchId,
                    $orderId,
                    null,
                    'draft',
                    'created',
                    null,
                    $actor->userId,
                    $sourceChannel,
                    $idempotencyHash,
                );
                $this->queueServiceSms($scope, 'order.created', [
                    'order_id' => $orderId,
                    'customer_id' => $customerId,
                    'recipient_phone' => $recipientPhone,
                    'order_number' => $orderNumber,
                    'total_amount' => $total,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'order_status' => 'draft',
                ]);
                $this->audit->record(
                    $actor->userId,
                    'order.created',
                    'order',
                    $orderPublicId,
                    null,
                    [
                        'order_number' => $orderNumber,
                        'source_channel' => $sourceChannel,
                        'item_count' => count($orderLines),
                        'fulfillment_method' => $fulfillmentMethod,
                        'subtotal_amount' => $subtotal,
                        'tax_amount' => $taxTotal,
                        'total_amount' => $total,
                        'currency_code' => 'IRT',
                    ],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );

                return [
                    'status' => 201,
                    'body' => [
                        'data' => [
                            'order' => [
                                'id' => $orderPublicId,
                                'order_number' => $orderNumber,
                                'source_channel' => $sourceChannel,
                                'order_status' => 'draft',
                                'payment_status' => 'unpaid',
                                'subtotal_amount' => $subtotal,
                                'tax_amount' => $taxTotal,
                                'total_amount' => $total,
                                'currency_code' => 'IRT',
                                'fulfillment_method' => $fulfillmentMethod,
                                'item_count' => count($orderLines),
                            ],
                        ],
                        'meta' => (object) [],
                    ],
                ];
            },
        );
    }

    public function transition(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $orderPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
        bool $kitchenOnly = false,
    ): array {
        if (!self::isUuid($orderPublicId)) {
            throw new HttpException(400, 'invalid_order_id', 'The order identifier is invalid.');
        }
        $targetStatus = $payload['to_status'] ?? null;
        if (!is_string($targetStatus) || !in_array($targetStatus, self::statuses(), true)) {
            throw new HttpException(422, 'invalid_order_status', 'The target order status is invalid.');
        }
        $reasonCode = $payload['reason_code'] ?? null;
        $reasonText = $payload['reason_text'] ?? null;
        if ($reasonCode !== null && (!is_string($reasonCode) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $reasonCode))) {
            throw new HttpException(422, 'invalid_status_reason_code', 'The status reason code is invalid.');
        }
        if ($reasonText !== null && (!is_string($reasonText) || self::unicodeLength($reasonText) > 500)) {
            throw new HttpException(422, 'invalid_status_reason_text', 'The status reason text must not exceed 500 characters.');
        }
        if (in_array($targetStatus, ['cancelled', 'rejected'], true) && $reasonCode === null && trim((string) $reasonText) === '') {
            throw new HttpException(422, 'status_reason_required', 'A reason code or reason text is required when cancelling or rejecting an order.');
        }

        $fingerprint = self::fingerprint([
            'order_id' => $orderPublicId,
            'to_status' => $targetStatus,
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText,
            'kitchen_only' => $kitchenOnly,
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'orders.status.update',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $actor,
                $scope,
                $orderPublicId,
                $targetStatus,
                $reasonCode,
                $reasonText,
                $requestId,
                $ipAddress,
                $idempotencyKey,
                $kitchenOnly,
            ): array {
                if ($kitchenOnly && !in_array($targetStatus, ['preparing', 'ready'], true)) {
                    throw new HttpException(403, 'kitchen_transition_forbidden', 'Kitchen users may only move orders into preparing or ready.');
                }
                $order = $this->orders->findOrderForUpdate($scope->businessId, (int) $scope->branchId, $orderPublicId);
                if ($order === null) {
                    throw new HttpException(404, 'order_not_found', 'The order was not found in this branch.');
                }
                $currentStatus = (string) $order['order_status'];
                if (in_array($targetStatus, ['cancelled', 'rejected'], true)
                    && in_array((string) $order['payment_status'], ['paid', 'partially_paid', 'partially_refunded'], true)) {
                    throw new HttpException(409, 'refund_required_before_cancellation', 'A paid order must go through the refund workflow before it can be cancelled or rejected.');
                }
                $allowed = self::transitions()[$currentStatus] ?? [];
                if (!in_array($targetStatus, $allowed, true)) {
                    throw new HttpException(409, 'order_status_transition_invalid', 'The requested order status transition is not allowed.');
                }
                if (in_array($targetStatus, ['cancelled', 'rejected'], true)
                    && !$this->permissions->allows($actor, 'orders.cancel', $scope)) {
                    throw new HttpException(403, 'permission_denied', 'Order cancellation permission is required.');
                }
                if (in_array($targetStatus, ['preparing', 'ready'], true)
                    && !$this->permissions->allows($actor, 'kitchen.update', $scope)) {
                    throw new HttpException(403, 'permission_denied', 'Kitchen update permission is required for this transition.');
                }

                // Stock reservation/consumption/release shares the status transaction.
                $this->inventoryLifecycle->apply(
                    $scope,
                    (int) $order['id'],
                    $targetStatus,
                    hash('sha256', $idempotencyKey),
                );
                $this->orders->updateOrderStatus(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $order['id'],
                    $targetStatus,
                );

                // Keep the fulfillment lifecycle in the same transaction as the order state.
                $fulfillmentStatus = match ($targetStatus) {
                    'draft', 'awaiting_payment' => 'pending',
                    'accepted' => 'accepted',
                    'preparing' => 'preparing',
                    'ready' => 'ready',
                    'completed' => 'completed',
                    'cancelled', 'rejected' => 'cancelled',
                    default => null,
                };
                if ($fulfillmentStatus !== null) {
                    $this->orders->updateFulfillmentStatus(
                        $scope->businessId,
                        (int) $scope->branchId,
                        (int) $order['id'],
                        $fulfillmentStatus,
                    );
                }
                $this->orders->createStatusEvent(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $order['id'],
                    $currentStatus,
                    $targetStatus,
                    $reasonCode,
                    $reasonText,
                    $actor->userId,
                    (string) $order['source_channel'],
                    hash('sha256', $idempotencyKey),
                );
                $smsContext = [
                    'order_id' => (int) $order['id'],
                    'customer_id' => isset($order['customer_id']) ? (int) $order['customer_id'] : null,
                    'recipient_phone' => null,
                    'order_number' => (int) $order['order_number'],
                    'total_amount' => (int) $order['total_amount'],
                    'created_at' => (string) $order['created_at'],
                    'order_status' => $targetStatus,
                ];
                if (!in_array($targetStatus, ['ready', 'completed'], true)) {
                    $this->queueServiceSms($scope, 'order.status_changed', $smsContext);
                }
                if ($targetStatus === 'ready') {
                    $this->queueServiceSms($scope, 'order.ready', $smsContext);
                } elseif ($targetStatus === 'completed') {
                    $this->queueServiceSms($scope, 'order.completed', $smsContext);
                }
                $this->audit->record(
                    $actor->userId,
                    'order.status_changed',
                    'order',
                    $orderPublicId,
                    ['order_status' => $currentStatus],
                    ['order_status' => $targetStatus, 'order_number' => (int) $order['order_number']],
                    $reasonText,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );

                return [
                    'status' => 200,
                    'body' => [
                        'data' => [
                            'order_id' => $orderPublicId,
                            'order_number' => (int) $order['order_number'],
                            'from_status' => $currentStatus,
                            'to_status' => $targetStatus,
                            'payment_status' => (string) $order['payment_status'],
                        ],
                        'meta' => (object) [],
                    ],
                ];
            },
        );
    }

    public function dispatchDelivery(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $orderPublicId,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($orderPublicId)) {
            throw new HttpException(400, 'invalid_order_id', 'The order identifier is invalid.');
        }
        if (!$this->permissions->allows($actor, 'orders.update', $scope)) {
            throw new HttpException(403, 'permission_denied', 'Order update permission is required to dispatch a delivery.');
        }

        $fingerprint = self::fingerprint([
            'order_id' => $orderPublicId,
            'action' => 'delivery.dispatch',
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'orders.delivery.dispatch',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $orderPublicId, $requestId, $ipAddress): array {
                $order = $this->orders->findOrderForUpdate(
                    $scope->businessId,
                    (int) $scope->branchId,
                    $orderPublicId,
                );
                if ($order === null) {
                    throw new HttpException(404, 'order_not_found', 'The order was not found in this branch.');
                }
                $fulfillment = $this->orders->findFulfillmentForUpdate(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $order['id'],
                );
                if ($fulfillment === null || $fulfillment['method'] !== 'delivery') {
                    throw new HttpException(409, 'delivery_fulfillment_required', 'Only delivery orders can be dispatched.');
                }
                if ($order['order_status'] !== 'ready' || $fulfillment['status'] !== 'ready') {
                    if ($fulfillment['status'] === 'dispatched') {
                        throw new HttpException(409, 'delivery_already_dispatched', 'This delivery has already been dispatched.');
                    }
                    throw new HttpException(409, 'delivery_not_ready', 'The order and delivery must both be ready before dispatch.');
                }

                $this->orders->updateFulfillmentStatus(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $order['id'],
                    'dispatched',
                );
                $this->audit->record(
                    $actor->userId,
                    'order.delivery_dispatched',
                    'order',
                    $orderPublicId,
                    ['fulfillment_status' => 'ready'],
                    [
                        'fulfillment_status' => 'dispatched',
                        'order_number' => (int) $order['order_number'],
                        'order_status' => (string) $order['order_status'],
                    ],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );

                return [
                    'status' => 200,
                    'body' => [
                        'data' => [
                            'order_id' => $orderPublicId,
                            'order_number' => (int) $order['order_number'],
                            'order_status' => (string) $order['order_status'],
                            'from_fulfillment_status' => 'ready',
                            'to_fulfillment_status' => 'dispatched',
                        ],
                        'meta' => (object) [],
                    ],
                ];
            },
        );
    }

    public function transitionKitchen(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $orderPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        return $this->transition(
            $actor,
            $scope,
            $orderPublicId,
            $payload,
            $idempotencyKey,
            $requestId,
            $ipAddress,
            true,
        );
    }

    /** @return array{?string,?string,?string,?string} */
    private function normalizeFulfillment(array $payload, string $sourceChannel): array
    {
        $method = $payload['fulfillment_method'] ?? null;
        $expectedMethod = match ($sourceChannel) {
            'table' => 'dine_in',
            'takeaway' => 'takeaway',
            default => null,
        };
        if ($expectedMethod !== null) {
            if ($method !== null && $method !== $expectedMethod) {
                throw new HttpException(422, 'fulfillment_channel_mismatch', 'Fulfillment method does not match the order channel.');
            }
            $method = $expectedMethod;
        }
        if ($method !== null && (!is_string($method) || !in_array($method, ['dine_in', 'takeaway', 'delivery'], true))) {
            throw new HttpException(422, 'invalid_fulfillment_method', 'The fulfillment method is invalid.');
        }

        $recipientName = $payload['recipient_name'] ?? null;
        $recipientPhone = $payload['recipient_phone'] ?? null;
        $address = $payload['delivery_address'] ?? null;
        if ($method === 'delivery') {
            if (!is_string($recipientName) || trim($recipientName) === '' || self::unicodeLength($recipientName) > 160) {
                throw new HttpException(422, 'delivery_recipient_required', 'A delivery recipient name is required.');
            }
            if (!is_string($recipientPhone) || !preg_match('/^[0-9+(). -]{7,32}$/', $recipientPhone)) {
                throw new HttpException(422, 'delivery_phone_invalid', 'A valid delivery phone number is required.');
            }
            if (!is_array($address) || array_is_list($address) || $address === []) {
                throw new HttpException(422, 'delivery_address_required', 'A delivery address object is required.');
            }
            foreach ($address as $key => $value) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{1,39}$/', $key)
                    || (!is_string($value) && !is_int($value))
                    || (is_string($value) && self::unicodeLength($value) > 300)) {
                    throw new HttpException(422, 'delivery_address_invalid', 'The delivery address contains an invalid field.');
                }
            }
            $addressJson = json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($addressJson) > 4000) {
                throw new HttpException(422, 'delivery_address_too_large', 'The delivery address is too large.');
            }
            return [$method, trim($recipientName), trim($recipientPhone), $addressJson];
        }

        if ($recipientName !== null || $recipientPhone !== null || $address !== null) {
            throw new HttpException(422, 'unexpected_delivery_details', 'Recipient and address details are only valid for delivery fulfillment.');
        }
        return [$method, null, null, null];
    }

    /** @return list<array{product_id:string,variant_id:?string,quantity:string,quantity_milli:int,options:list<string>}> */
    private function normalizeItems(mixed $rawItems): array
    {
        if (!is_array($rawItems) || !array_is_list($rawItems) || $rawItems === [] || count($rawItems) > 100) {
            throw new HttpException(422, 'invalid_order_items', 'An order must contain between 1 and 100 items.');
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem) || !is_string($rawItem['product_id'] ?? null)
                || !self::isUuid($rawItem['product_id'])) {
                throw new HttpException(422, 'invalid_order_item', 'Each order item must include a valid product identifier.');
            }
            $variantId = $rawItem['variant_id'] ?? null;
            if ($variantId !== null && (!is_string($variantId) || !self::isUuid($variantId))) {
                throw new HttpException(422, 'invalid_order_variant', 'The variant identifier is invalid.');
            }
            [$quantity, $quantityMilli] = self::parseQuantity($rawItem['quantity'] ?? 1);
            $options = $rawItem['options'] ?? [];
            if (!is_array($options) || !array_is_list($options) || count($options) > 30) {
                throw new HttpException(422, 'invalid_order_options', 'Options must be a list of up to 30 option identifiers.');
            }
            $normalizedOptions = [];
            foreach ($options as $optionId) {
                if (!is_string($optionId) || !self::isUuid($optionId)) {
                    throw new HttpException(422, 'invalid_order_option', 'An option identifier is invalid.');
                }
                $normalizedOptions[] = $optionId;
            }
            if (count($normalizedOptions) !== count(array_unique($normalizedOptions))) {
                throw new HttpException(422, 'duplicate_order_option', 'An option may only be selected once per item.');
            }
            $items[] = [
                'product_id' => $rawItem['product_id'],
                'variant_id' => $variantId,
                'quantity' => $quantity,
                'quantity_milli' => $quantityMilli,
                'options' => $normalizedOptions,
            ];
        }
        return $items;
    }

    /** @return list<array<string,mixed>> */
    private function resolveOptions(int $businessId, array $groups, array $selectedPublicIds): array
    {
        $resolved = [];
        $unmatched = $selectedPublicIds;

        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            $groupSelected = [];
            foreach ($unmatched as $index => $optionPublicId) {
                $option = $this->orders->findOptionForUpdate($businessId, $groupId, $optionPublicId);
                if ($option !== null) {
                    $option['group_id'] = $groupId;
                    $option['group_name'] = (string) $group['name'];
                    $groupSelected[] = $option;
                    unset($unmatched[$index]);
                }
            }

            $count = count($groupSelected);
            $minimum = (int) $group['min_select'];
            $maximum = (int) $group['max_select'];
            if ((int) $group['is_required'] === 1 || (int) $group['is_required_default'] === 1) {
                $minimum = max(1, $minimum);
            }
            if ((string) $group['selection_type'] === 'single') {
                $maximum = min(1, $maximum);
            }
            if ($count < $minimum || $count > $maximum) {
                throw new HttpException(422, 'option_selection_out_of_range', 'The selected options do not satisfy the product option group rules.');
            }
            foreach ($groupSelected as $option) {
                $resolved[] = $option;
            }
        }

        if ($unmatched !== []) {
            throw new HttpException(422, 'option_not_available_for_product', 'One or more selected options are not available for this product.');
        }
        return $resolved;
    }

    /** @return array{string,int} */
    private static function parseQuantity(mixed $raw): array
    {
        if (!is_string($raw) && !is_int($raw)) {
            throw new HttpException(422, 'invalid_quantity', 'Quantity must be a decimal string or integer.');
        }
        $value = trim((string) $raw);
        if (!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.([0-9]{1,3}))?$/', $value)) {
            throw new HttpException(422, 'invalid_quantity', 'Quantity must have at most three decimal places and be no greater than 1000.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $milli = ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
        if ($milli < 1 || $milli > self::MAX_QUANTITY_MILLI) {
            throw new HttpException(422, 'invalid_quantity', 'Quantity must be greater than zero and no greater than 1000.');
        }
        return [sprintf('%d.%03d', intdiv($milli, 1000), $milli % 1000), $milli];
    }

    private static function amountForQuantity(int $unitPrice, int $quantityMilli): int
    {
        $wholeQuantity = intdiv($quantityMilli, 1000);
        $fraction = $quantityMilli % 1000;
        if ($unitPrice > 0 && $wholeQuantity > intdiv(self::MAX_AMOUNT, $unitPrice)) {
            throw new HttpException(422, 'order_amount_too_large', 'The calculated order line exceeds the allowed amount.');
        }
        $wholeAmount = $unitPrice * $wholeQuantity;
        $fractionProduct = $unitPrice * $fraction;
        $fractionAmount = intdiv($fractionProduct + 500, 1000);
        return self::safeAdd($wholeAmount, $fractionAmount);
    }

    private static function calculateTax(int $amount, int $rateBps): int
    {
        $whole = intdiv($amount, 10000);
        $remainder = $amount % 10000;
        return ($whole * $rateBps) + intdiv(($remainder * $rateBps) + 5000, 10000);
    }

    private static function safeAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > self::MAX_AMOUNT - $right) {
            throw new HttpException(422, 'order_amount_too_large', 'The calculated order amount exceeds the allowed limit.');
        }
        if ($right < 0 && $left < -self::MAX_AMOUNT - $right) {
            throw new HttpException(422, 'invalid_calculated_price', 'The calculated item price is outside the allowed range.');
        }
        return $left + $right;
    }

    /** @return list<string> */
    private function queueServiceSms(BusinessScope $scope, string $trigger, array $context): void
    {
        try {
            $this->smsTemplates->enqueueForOrderEvent($scope, $trigger, $context);
        } catch (\Throwable $exception) {
            // SMS is a side effect: never fail order creation or a valid status transition.
            error_log('POS_V3 sms_template_enqueue_failed trigger=' . $trigger
                . ' exception=' . $exception::class);
        }
    }

    private static function statuses(): array
    {
        return ['draft', 'awaiting_payment', 'accepted', 'preparing', 'ready', 'completed', 'cancelled', 'rejected'];
    }

    /** @return list<string> */
    private static function channels(): array
    {
        return ['pos', 'phone', 'manual', 'table', 'takeaway', 'storefront'];
    }

    /** @return list<string> */
    private static function paymentStatuses(): array
    {
        return ['unpaid', 'partially_paid', 'paid', 'refunded', 'partially_refunded', 'failed', 'pending'];
    }

    /** @return list<string> */
    private static function fulfillmentMethods(): array
    {
        return ['dine_in', 'takeaway', 'delivery'];
    }

    /** @return array<string,list<string>> */
    private static function transitions(): array
    {
        return [
            'draft' => ['awaiting_payment', 'accepted', 'cancelled', 'rejected'],
            'awaiting_payment' => ['accepted', 'cancelled', 'rejected'],
            'accepted' => ['preparing', 'ready', 'completed', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => [],
            'rejected' => [],
        ];
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
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
