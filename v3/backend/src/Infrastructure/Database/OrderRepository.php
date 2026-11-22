<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class OrderRepository
{
    public function __construct(private PDO $connection) {}

    public function findCustomerId(int $businessId, string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_customers
             WHERE business_id = :business_id AND public_id = :public_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findOpenTableSessionId(int $businessId, int $branchId, string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            "SELECT ts.id
             FROM pos_table_sessions ts
             INNER JOIN pos_dining_tables dt
               ON dt.id = ts.table_id
              AND dt.business_id = ts.business_id
              AND dt.branch_id = ts.branch_id
             WHERE ts.business_id = :business_id AND ts.branch_id = :branch_id
               AND ts.public_id = :public_id AND ts.status = 'open'
               AND dt.is_active = 1
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @return array<string,mixed>|null */
    public function findProductForUpdate(int $businessId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, name, sku, product_type, base_price_amount, tax_rate_bps
             FROM pos_products
             WHERE business_id = :business_id AND public_id = :public_id
               AND is_active = 1 AND archived_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listActiveVariantsForUpdate(int $businessId, int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, name, sku, price_delta_amount
             FROM pos_product_variants
             WHERE business_id = :business_id AND product_id = :product_id AND is_active = 1
             ORDER BY name ASC, id ASC FOR UPDATE'
        );
        $statement->execute(['business_id' => $businessId, 'product_id' => $productId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function listProductOptionGroupsForUpdate(int $businessId, int $productId): array
    {
        $statement = $this->connection->prepare(
            'SELECT g.id, g.public_id, g.name, g.selection_type, g.min_select, g.max_select,
                    pog.is_required, g.is_required_default
             FROM pos_product_option_groups pog
             INNER JOIN pos_option_groups g
               ON g.id = pog.option_group_id AND g.business_id = pog.business_id
             WHERE pog.business_id = :business_id
               AND pog.product_id = :product_id
               AND g.is_active = 1
             ORDER BY pog.sort_order ASC, g.name ASC
             FOR UPDATE'
        );
        $statement->execute(['business_id' => $businessId, 'product_id' => $productId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function findOptionForUpdate(int $businessId, int $groupId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, group_id, name, price_delta_amount
             FROM pos_options
             WHERE business_id = :business_id AND group_id = :group_id
               AND public_id = :public_id AND is_active = 1
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'business_id' => $businessId,
            'group_id' => $groupId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function nextOrderNumber(int $businessId, int $branchId): int
    {
        $sequenceKey = 'order:' . $businessId . ':' . $branchId;
        $insert = $this->connection->prepare(
            'INSERT IGNORE INTO pos_sequences (sequence_key, current_value) VALUES (:sequence_key, 0)'
        );
        $insert->execute(['sequence_key' => $sequenceKey]);

        $update = $this->connection->prepare(
            'UPDATE pos_sequences
             SET current_value = LAST_INSERT_ID(current_value + 1)
             WHERE sequence_key = :sequence_key'
        );
        $update->execute(['sequence_key' => $sequenceKey]);
        return (int) $this->connection->lastInsertId();
    }

    public function createOrder(
        int $businessId,
        int $branchId,
        string $publicId,
        int $orderNumber,
        string $sourceChannel,
        ?int $customerId,
        ?int $tableSessionId,
        int $subtotal,
        int $tax,
        int $total,
        int $actorUserId,
    ): int {
        $statement = $this->connection->prepare(
            "INSERT INTO pos_orders
                (business_id, branch_id, public_id, order_number, source_channel, order_status,
                 payment_status, customer_id, table_session_id, currency_code, subtotal_amount,
                 discount_amount, tax_amount, delivery_fee_amount, total_amount, created_by)
             VALUES
                (:business_id, :branch_id, :public_id, :order_number, :source_channel, 'draft',
                 'unpaid', :customer_id, :table_session_id, 'IRT', :subtotal_amount,
                 0, :tax_amount, 0, :total_amount, :created_by)"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'order_number' => $orderNumber,
            'source_channel' => $sourceChannel,
            'customer_id' => $customerId,
            'table_session_id' => $tableSessionId,
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'created_by' => $actorUserId,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createFulfillment(
        int $businessId,
        int $branchId,
        int $orderId,
        string $method,
        ?string $recipientName,
        ?string $recipientPhone,
        ?string $addressJson,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_order_fulfillments
                (business_id, branch_id, order_id, method, recipient_name_snapshot,
                 recipient_phone_snapshot, address_snapshot, delivery_fee_amount)
             VALUES
                (:business_id, :branch_id, :order_id, :method, :recipient_name,
                 :recipient_phone, :address_snapshot, 0)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'method' => $method,
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'address_snapshot' => $addressJson,
        ]);
    }

    public function createOrderItem(
        int $businessId,
        int $branchId,
        int $orderId,
        int $productId,
        ?int $variantId,
        string $productName,
        ?string $variantName,
        ?string $sku,
        string $quantity,
        int $unitPrice,
        int $taxRateBps,
        int $taxAmount,
        int $lineTotal,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_order_items
                (business_id, branch_id, order_id, product_id, variant_id, product_name_snapshot,
                 variant_name_snapshot, sku_snapshot, quantity, unit_price_amount, discount_amount,
                 tax_rate_bps, tax_amount, line_total_amount)
             VALUES
                (:business_id, :branch_id, :order_id, :product_id, :variant_id, :product_name_snapshot,
                 :variant_name_snapshot, :sku_snapshot, :quantity, :unit_price_amount, 0,
                 :tax_rate_bps, :tax_amount, :line_total_amount)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'product_name_snapshot' => $productName,
            'variant_name_snapshot' => $variantName,
            'sku_snapshot' => $sku,
            'quantity' => $quantity,
            'unit_price_amount' => $unitPrice,
            'tax_rate_bps' => $taxRateBps,
            'tax_amount' => $taxAmount,
            'line_total_amount' => $lineTotal,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createOrderItemOption(
        int $businessId,
        int $branchId,
        int $orderItemId,
        int $groupId,
        int $optionId,
        string $groupName,
        string $optionName,
        int $priceDelta,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_order_item_options
                (business_id, branch_id, order_item_id, option_group_id, option_id,
                 group_name_snapshot, option_name_snapshot, price_delta_amount, quantity)
             VALUES
                (:business_id, :branch_id, :order_item_id, :option_group_id, :option_id,
                 :group_name_snapshot, :option_name_snapshot, :price_delta_amount, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_item_id' => $orderItemId,
            'option_group_id' => $groupId,
            'option_id' => $optionId,
            'group_name_snapshot' => $groupName,
            'option_name_snapshot' => $optionName,
            'price_delta_amount' => $priceDelta,
        ]);
    }

    public function createStatusEvent(
        int $businessId,
        int $branchId,
        int $orderId,
        ?string $fromStatus,
        string $toStatus,
        ?string $reasonCode,
        ?string $reasonText,
        int $actorUserId,
        string $sourceChannel,
        ?string $idempotencyHash,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_order_status_events
                (business_id, branch_id, order_id, from_status, to_status, reason_code,
                 reason_text, actor_user_id, source_channel, idempotency_key)
             VALUES
                (:business_id, :branch_id, :order_id, :from_status, :to_status, :reason_code,
                 :reason_text, :actor_user_id, :source_channel, :idempotency_key)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText,
            'actor_user_id' => $actorUserId,
            'source_channel' => $sourceChannel,
            'idempotency_key' => $idempotencyHash,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findOrder(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT o.id, o.public_id, o.order_number, o.source_channel, o.order_status,
                    o.payment_status, o.currency_code, o.subtotal_amount, o.discount_amount,
                    o.tax_amount, o.delivery_fee_amount, o.total_amount, o.created_at, o.accepted_at,
                    c.public_id AS customer_public_id, c.name AS customer_name,
                    ts.public_id AS table_session_public_id,
                    f.method AS fulfillment_method, f.recipient_name_snapshot,
                    f.recipient_phone_snapshot, f.address_snapshot, f.delivery_fee_amount AS fulfillment_fee
             FROM pos_orders o
             LEFT JOIN pos_customers c ON c.id = o.customer_id AND c.business_id = o.business_id
             LEFT JOIN pos_table_sessions ts ON ts.id = o.table_session_id
                AND ts.business_id = o.business_id AND ts.branch_id = o.branch_id
             LEFT JOIN pos_order_fulfillments f ON f.order_id = o.id
                AND f.business_id = o.business_id AND f.branch_id = o.branch_id
             WHERE o.business_id = :business_id AND o.branch_id = :branch_id AND o.public_id = :public_id
             LIMIT 1'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listOrderItemsWithOptions(int $businessId, int $branchId, int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT oi.id, oi.product_name_snapshot, oi.variant_name_snapshot, oi.sku_snapshot,
                    oi.quantity, oi.unit_price_amount, oi.discount_amount, oi.tax_rate_bps,
                    oi.tax_amount, oi.line_total_amount,
                    oio.group_name_snapshot, oio.option_name_snapshot,
                    oio.price_delta_amount, oio.quantity AS option_quantity
             FROM pos_order_items oi
             LEFT JOIN pos_order_item_options oio
               ON oio.order_item_id = oi.id
              AND oio.business_id = oi.business_id
              AND oio.branch_id = oi.branch_id
             WHERE oi.business_id = :business_id
               AND oi.branch_id = :branch_id
               AND oi.order_id = :order_id
             ORDER BY oi.id ASC, oio.id ASC'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
        ]);

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemId = (int) $row['id'];
            if (!isset($items[$itemId])) {
                $items[$itemId] = [
                    'name' => (string) $row['product_name_snapshot'],
                    'variant_name' => $row['variant_name_snapshot'] === null ? null : (string) $row['variant_name_snapshot'],
                    'sku' => $row['sku_snapshot'] === null ? null : (string) $row['sku_snapshot'],
                    'quantity' => (string) $row['quantity'],
                    'unit_price_amount' => (int) $row['unit_price_amount'],
                    'discount_amount' => (int) $row['discount_amount'],
                    'tax_rate_bps' => (int) $row['tax_rate_bps'],
                    'tax_amount' => (int) $row['tax_amount'],
                    'line_total_amount' => (int) $row['line_total_amount'],
                    'options' => [],
                ];
            }
            if (is_string($row['option_name_snapshot'])) {
                $items[$itemId]['options'][] = [
                    'group_name' => (string) $row['group_name_snapshot'],
                    'name' => (string) $row['option_name_snapshot'],
                    'price_delta_amount' => (int) $row['price_delta_amount'],
                    'quantity' => (string) $row['option_quantity'],
                ];
            }
        }
        return array_values($items);
    }

    /** @return list<array<string,mixed>> */
    public function listStatusEvents(int $businessId, int $branchId, int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT e.from_status, e.to_status, e.reason_code, e.reason_text, e.source_channel,
                    e.created_at, u.public_id AS actor_public_id, u.display_name AS actor_name
             FROM pos_order_status_events e
             LEFT JOIN app_users u ON u.id = e.actor_user_id
             WHERE e.business_id = :business_id
               AND e.branch_id = :branch_id
               AND e.order_id = :order_id
             ORDER BY e.id ASC'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
        ]);
        return array_map(static fn (array $row): array => [
            'from_status' => $row['from_status'] === null ? null : (string) $row['from_status'],
            'to_status' => (string) $row['to_status'],
            'reason_code' => $row['reason_code'] === null ? null : (string) $row['reason_code'],
            'reason_text' => $row['reason_text'] === null ? null : (string) $row['reason_text'],
            'source_channel' => (string) $row['source_channel'],
            'created_at' => (string) $row['created_at'],
            'actor' => $row['actor_public_id'] === null ? null : [
                'id' => (string) $row['actor_public_id'],
                'name' => $row['actor_name'] === null ? null : (string) $row['actor_name'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function listOrders(
        int $businessId,
        int $branchId,
        ?string $status,
        ?string $channel,
        int $limit,
        ?int $beforeOrderNumber = null,
        ?string $paymentStatus = null,
        ?string $fulfillmentMethod = null,
        ?string $createdFromUtc = null,
        ?string $createdToUtc = null,
    ): array {
        $sql = 'SELECT o.public_id, o.order_number, o.source_channel, o.order_status, o.payment_status,
                       o.subtotal_amount, o.tax_amount, o.total_amount, o.currency_code,
                       o.created_at, f.method AS fulfillment_method,
                       c.public_id AS customer_public_id, c.name AS customer_name
                FROM pos_orders o
                LEFT JOIN pos_order_fulfillments f ON f.order_id = o.id AND f.business_id = o.business_id AND f.branch_id = o.branch_id
                LEFT JOIN pos_customers c ON c.id = o.customer_id AND c.business_id = o.business_id
                WHERE o.business_id = :business_id AND o.branch_id = :branch_id';
        if ($beforeOrderNumber !== null) {
            $sql .= ' AND o.order_number < :before_order_number';
        }
        if ($status !== null) {
            $sql .= ' AND o.order_status = :order_status';
        }
        if ($channel !== null) {
            $sql .= ' AND o.source_channel = :source_channel';
        }
        if ($paymentStatus !== null) {
            $sql .= ' AND o.payment_status = :payment_status';
        }
        if ($fulfillmentMethod !== null) {
            $sql .= ' AND f.method = :fulfillment_method';
        }
        if ($createdFromUtc !== null) {
            $sql .= ' AND o.created_at >= :created_from_utc';
        }
        if ($createdToUtc !== null) {
            $sql .= ' AND o.created_at < :created_to_utc';
        }
        $sql .= ' ORDER BY o.order_number DESC LIMIT :fetch_limit';
        $statement = $this->connection->prepare($sql);
        $statement->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        $statement->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        if ($beforeOrderNumber !== null) {
            $statement->bindValue(':before_order_number', $beforeOrderNumber, PDO::PARAM_INT);
        }
        if ($status !== null) {
            $statement->bindValue(':order_status', $status, PDO::PARAM_STR);
        }
        if ($channel !== null) {
            $statement->bindValue(':source_channel', $channel, PDO::PARAM_STR);
        }
        if ($paymentStatus !== null) {
            $statement->bindValue(':payment_status', $paymentStatus, PDO::PARAM_STR);
        }
        if ($fulfillmentMethod !== null) {
            $statement->bindValue(':fulfillment_method', $fulfillmentMethod, PDO::PARAM_STR);
        }
        if ($createdFromUtc !== null) {
            $statement->bindValue(':created_from_utc', $createdFromUtc, PDO::PARAM_STR);
        }
        if ($createdToUtc !== null) {
            $statement->bindValue(':created_to_utc', $createdToUtc, PDO::PARAM_STR);
        }
        $statement->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $orders = array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'order_number' => (int) $row['order_number'],
            'source_channel' => (string) $row['source_channel'],
            'order_status' => (string) $row['order_status'],
            'payment_status' => (string) $row['payment_status'],
            'subtotal_amount' => (int) $row['subtotal_amount'],
            'tax_amount' => (int) $row['tax_amount'],
            'total_amount' => (int) $row['total_amount'],
            'currency_code' => (string) $row['currency_code'],
            'created_at' => (string) $row['created_at'],
            'fulfillment_method' => $row['fulfillment_method'] === null ? null : (string) $row['fulfillment_method'],
            'customer' => $row['customer_public_id'] === null ? null : [
                'id' => (string) $row['customer_public_id'],
                'name' => (string) $row['customer_name'],
            ],
        ], $rows);

        return [
            'orders' => $orders,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $orders !== []
                ? (string) $orders[array_key_last($orders)]['order_number']
                : null,
        ];
    }

    public function findEffectiveTimezone(int $businessId, int $branchId): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT COALESCE(NULLIF(branch.timezone, \'\'), business.timezone)
             FROM pos_branches branch
             INNER JOIN pos_businesses business ON business.id = branch.business_id
             WHERE branch.business_id = :business_id AND branch.id = :branch_id
               AND branch.is_active = 1 AND business.is_active = 1
             LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
        $timezone = $statement->fetchColumn();
        return $timezone === false ? null : (string) $timezone;
    }

    /** @return array<string,mixed>|null */
    public function findOrderForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, order_number, source_channel, order_status, payment_status,
                    customer_id, total_amount, created_at
             FROM pos_orders
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function updateFulfillmentStatus(int $businessId, int $branchId, int $orderId, string $status): void
    {
        $statement = $this->connection->prepare(
            'UPDATE pos_order_fulfillments
             SET status = :status,
                 completed_at = CASE
                     WHEN :completed_status = 1 THEN COALESCE(completed_at, UTC_TIMESTAMP(3))
                     ELSE completed_at
                 END
             WHERE business_id = :business_id
               AND branch_id = :branch_id
               AND order_id = :order_id'
        );
        $statement->execute([
            'status' => $status,
            'completed_status' => $status === 'completed' ? 1 : 0,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
        ]);
    }

    public function updateOrderStatus(int $businessId, int $branchId, int $orderId, string $status): void
    {
        $statement = $this->connection->prepare(
            'UPDATE pos_orders
             SET order_status = :order_status,
                 accepted_at = CASE
                     WHEN :accepted_status = 1 THEN COALESCE(accepted_at, UTC_TIMESTAMP(3))
                     ELSE accepted_at
                 END
             WHERE id = :order_id AND business_id = :business_id AND branch_id = :branch_id'
        );
        $statement->execute([
            'order_status' => $status,
            'accepted_status' => $status === 'accepted' ? 1 : 0,
            'order_id' => $orderId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
        ]);
    }
}
