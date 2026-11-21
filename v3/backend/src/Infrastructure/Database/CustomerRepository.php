<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class CustomerRepository
{
    public function __construct(private PDO $connection) {}

    public function lockBusiness(int $businessId): void
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_businesses WHERE id = :business_id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['business_id' => $businessId]);
        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException('The customer business scope no longer exists.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findByPublicId(int $businessId, string $publicId, bool $forUpdate = false): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, name, phone_normalized, phone_display, notes, is_active, created_at, updated_at
             FROM pos_customers
             WHERE business_id = :business_id AND public_id = :public_id
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByPhoneForUpdate(int $businessId, string $phone, ?int $excludeId = null): ?array
    {
        $sql = 'SELECT id, public_id FROM pos_customers
                WHERE business_id = :business_id AND phone_normalized = :phone';
        if ($excludeId !== null) $sql .= ' AND id <> :exclude_id';
        $sql .= ' ORDER BY id ASC LIMIT 1 FOR UPDATE';
        $statement = $this->connection->prepare($sql);
        $params = ['business_id' => $businessId, 'phone' => $phone];
        if ($excludeId !== null) $params['exclude_id'] = $excludeId;
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(
        int $businessId,
        string $publicId,
        string $name,
        ?string $phoneNormalized,
        ?string $phoneDisplay,
        ?string $notes,
    ): array {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_customers
                (business_id, public_id, name, phone_normalized, phone_display, notes, is_active)
             VALUES
                (:business_id, :public_id, :name, :phone_normalized, :phone_display, :notes, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'public_id' => $publicId,
            'name' => $name,
            'phone_normalized' => $phoneNormalized,
            'phone_display' => $phoneDisplay,
            'notes' => $notes,
        ]);
        $row = $this->findByPublicId($businessId, $publicId);
        if ($row === null) throw new \RuntimeException('The customer could not be loaded after creation.');
        return $row;
    }

    public function update(
        int $businessId,
        int $customerId,
        string $name,
        ?string $phoneNormalized,
        ?string $phoneDisplay,
        ?string $notes,
        bool $isActive,
    ): void {
        $statement = $this->connection->prepare(
            'UPDATE pos_customers
             SET name = :name, phone_normalized = :phone_normalized,
                 phone_display = :phone_display, notes = :notes, is_active = :is_active
             WHERE business_id = :business_id AND id = :customer_id'
        );
        $statement->execute([
            'business_id' => $businessId,
            'customer_id' => $customerId,
            'name' => $name,
            'phone_normalized' => $phoneNormalized,
            'phone_display' => $phoneDisplay,
            'notes' => $notes,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    /** @return list<array<string,mixed>> */
    /**
     * @param array{updated_at:string,id:int}|null $cursor
     * @return array{customers:list<array<string,mixed>>,next_cursor_data:array{updated_at:string,id:int}|null}
     */
    public function list(int $businessId, string $search, string $status, int $limit, ?array $cursor = null): array
    {
        $where = ['c.business_id = :business_id'];
        $params = ['business_id' => $businessId];
        if ($status === 'active') $where[] = 'c.is_active = 1';
        if ($status === 'inactive') $where[] = 'c.is_active = 0';
        if ($search !== '') {
            $where[] = '(c.name LIKE :search_name OR c.phone_display LIKE :search_phone_display OR CONVERT(c.phone_normalized USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE :search_phone)';
            $needle = '%' . $search . '%';
            $params['search_name'] = $needle;
            $params['search_phone_display'] = $needle;
            $params['search_phone'] = $needle;
        }
        if ($cursor !== null) {
            $where[] = '(c.updated_at < :cursor_updated_at OR (c.updated_at = :cursor_updated_at AND c.id < :cursor_id))';
            $params['cursor_updated_at'] = $cursor['updated_at'];
            $params['cursor_id'] = $cursor['id'];
        }
        $sql = 'SELECT c.id AS cursor_id, c.public_id, c.name, c.phone_display, c.notes, c.is_active, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM pos_orders o
                     WHERE o.business_id = c.business_id AND o.customer_id = c.id) AS order_count,
                    (SELECT COALESCE(SUM(
                        COALESCE((SELECT SUM(p.amount) FROM pos_payments p
                                  WHERE p.business_id = o.business_id AND p.branch_id = o.branch_id
                                    AND p.order_id = o.id AND p.status IN (\'captured\',\'partially_refunded\',\'refunded\')), 0)
                        - COALESCE((SELECT SUM(r.amount) FROM pos_refunds r
                                    WHERE r.business_id = o.business_id AND r.branch_id = o.branch_id
                                      AND r.order_id = o.id AND r.status = \'succeeded\'), 0)
                    ), 0) FROM pos_orders o
                     WHERE o.business_id = c.business_id AND o.customer_id = c.id
                       AND o.order_status = \'completed\') AS total_spent,
                    (SELECT MAX(o.created_at) FROM pos_orders o
                     WHERE o.business_id = c.business_id AND o.customer_id = c.id) AS last_order_at
                FROM pos_customers c
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY c.updated_at DESC, c.id DESC
                LIMIT :fetch_limit';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, $key === 'cursor_id' || $key === 'business_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursorData = $hasMore && is_array($last) ? [
            'updated_at' => (string) $last['updated_at'],
            'id' => (int) $last['cursor_id'],
        ] : null;

        return [
            'customers' => array_map(self::mapCustomer(...), $rows),
            'next_cursor_data' => $nextCursorData,
        ];
    }

    public function detail(int $businessId, string $publicId): ?array
    {
        $customer = $this->findByPublicId($businessId, $publicId);
        if ($customer === null) return null;
        $customerId = (int) $customer['id'];

        $stats = $this->connection->prepare(
            'SELECT COUNT(*) AS order_count,
                    COALESCE(SUM(CASE WHEN order_status = \'completed\' THEN
                        COALESCE((SELECT SUM(p.amount) FROM pos_payments p
                                  WHERE p.business_id = pos_orders.business_id AND p.branch_id = pos_orders.branch_id
                                    AND p.order_id = pos_orders.id
                                    AND p.status IN (\'captured\',\'partially_refunded\',\'refunded\')), 0)
                        - COALESCE((SELECT SUM(r.amount) FROM pos_refunds r
                                    WHERE r.business_id = pos_orders.business_id AND r.branch_id = pos_orders.branch_id
                                      AND r.order_id = pos_orders.id AND r.status = \'succeeded\'), 0)
                      ELSE 0 END), 0) AS total_spent,
                    MAX(created_at) AS last_order_at
             FROM pos_orders
             WHERE business_id = :business_id AND customer_id = :customer_id'
        );
        $stats->execute(['business_id' => $businessId, 'customer_id' => $customerId]);
        $summary = $stats->fetch(PDO::FETCH_ASSOC) ?: [];

        $orders = $this->connection->prepare(
            'SELECT public_id, order_number, source_channel, order_status, payment_status,
                    total_amount, created_at
             FROM pos_orders
             WHERE business_id = :business_id AND customer_id = :customer_id
             ORDER BY created_at DESC, id DESC
             LIMIT 10'
        );
        $orders->execute(['business_id' => $businessId, 'customer_id' => $customerId]);

        // Loyalty is read from the append-only customer ledger. No points or credit
        // are inferred from orders until an explicit earning policy is configured.
        $loyaltySummary = $this->connection->prepare(
            'SELECT COALESCE(SUM(points_delta), 0) AS points_balance,
                    COALESCE(SUM(credit_delta), 0) AS credit_balance,
                    COALESCE(SUM(visit_delta), 0) AS visit_count
             FROM pos_customer_ledger
             WHERE customer_id = :customer_id'
        );
        $loyaltySummary->execute(['customer_id' => $customerId]);
        $loyalty = $loyaltySummary->fetch(PDO::FETCH_ASSOC) ?: [];

        $loyaltyEntries = $this->connection->prepare(
            'SELECT l.event_key, l.points_delta, l.credit_delta, l.visit_delta,
                    l.reason, l.created_at, o.public_id AS order_public_id,
                    o.order_number
             FROM pos_customer_ledger l
             LEFT JOIN pos_orders o
               ON o.id = l.order_id AND o.business_id = :business_id
             WHERE l.customer_id = :customer_id
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT 20'
        );
        $loyaltyEntries->execute(['business_id' => $businessId, 'customer_id' => $customerId]);

        return [
            'customer' => self::mapCustomer($customer),
            'stats' => [
                'order_count' => (int) ($summary['order_count'] ?? 0),
                'total_spent' => (int) ($summary['total_spent'] ?? 0),
                'last_order_at' => $summary['last_order_at'] === null ? null : (string) $summary['last_order_at'],
            ],
            'recent_orders' => array_map(static fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'order_number' => (int) $row['order_number'],
                'source_channel' => (string) $row['source_channel'],
                'order_status' => (string) $row['order_status'],
                'payment_status' => (string) $row['payment_status'],
                'total_amount' => (int) $row['total_amount'],
                'created_at' => (string) $row['created_at'],
            ], $orders->fetchAll(PDO::FETCH_ASSOC)),
            'loyalty' => [
                'points_balance' => (int) ($loyalty['points_balance'] ?? 0),
                'credit_balance' => (int) ($loyalty['credit_balance'] ?? 0),
                'visit_count' => (int) ($loyalty['visit_count'] ?? 0),
                'entries' => array_map(static fn (array $row): array => [
                    'event_key' => (string) $row['event_key'],
                    'points_delta' => (int) $row['points_delta'],
                    'credit_delta' => (int) $row['credit_delta'],
                    'visit_delta' => (int) $row['visit_delta'],
                    'reason' => $row['reason'] === null ? '' : (string) $row['reason'],
                    'order_id' => $row['order_public_id'] === null ? null : (string) $row['order_public_id'],
                    'order_number' => $row['order_number'] === null ? null : (int) $row['order_number'],
                    'created_at' => (string) $row['created_at'],
                ], $loyaltyEntries->fetchAll(PDO::FETCH_ASSOC)),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function mapCustomer(array $row): array
    {
        return [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'phone' => $row['phone_display'] === null ? '' : (string) $row['phone_display'],
            'notes' => $row['notes'] === null ? '' : (string) $row['notes'],
            'is_active' => (bool) $row['is_active'],
            'order_count' => (int) ($row['order_count'] ?? 0),
            'total_spent' => (int) ($row['total_spent'] ?? 0),
            'last_order_at' => isset($row['last_order_at']) && $row['last_order_at'] !== null ? (string) $row['last_order_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
