<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class DiningRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<array<string,mixed>> */
    public function listAreas(int $businessId, int $branchId): array
    {
        $statement = $this->connection->prepare(
            'SELECT a.public_id, a.name, a.sort_order, a.is_active, COUNT(t.id) AS table_count
             FROM pos_dining_areas a
             LEFT JOIN pos_dining_tables t
               ON t.area_id = a.id AND t.business_id = a.business_id AND t.branch_id = a.branch_id
             WHERE a.business_id = :business_id AND a.branch_id = :branch_id
             GROUP BY a.id, a.public_id, a.name, a.sort_order, a.is_active
             ORDER BY a.sort_order ASC, a.name ASC'
        );
        $statement->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'name' => (string) $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'is_active' => (bool) $row['is_active'],
            'table_count' => (int) $row['table_count'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function listTables(int $businessId, int $branchId): array
    {
        $statement = $this->connection->prepare(
            "SELECT t.public_id, t.table_code, t.capacity, t.is_active,
                    a.public_id AS area_public_id, a.name AS area_name,
                    s.public_id AS session_public_id, s.opened_at
             FROM pos_dining_tables t
             INNER JOIN pos_dining_areas a
               ON a.id = t.area_id AND a.business_id = t.business_id AND a.branch_id = t.branch_id
             LEFT JOIN pos_table_sessions s
               ON s.table_id = t.id AND s.business_id = t.business_id
              AND s.branch_id = t.branch_id AND s.status = 'open'
             WHERE t.business_id = :business_id AND t.branch_id = :branch_id
             ORDER BY a.sort_order ASC, a.name ASC, t.table_code ASC"
        );
        $statement->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'code' => (string) $row['table_code'],
            'capacity' => (int) $row['capacity'],
            'is_active' => (bool) $row['is_active'],
            'area' => [
                'id' => (string) $row['area_public_id'],
                'name' => (string) $row['area_name'],
            ],
            'status' => $row['session_public_id'] === null ? 'available' : 'occupied',
            'session_id' => $row['session_public_id'] === null ? null : (string) $row['session_public_id'],
            'opened_at' => $row['opened_at'] === null ? null : (string) $row['opened_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findAreaId(int $businessId, int $branchId, string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_dining_areas
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND public_id = :public_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function createArea(int $businessId, int $branchId, string $publicId, string $name, int $sortOrder): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_dining_areas (business_id, branch_id, public_id, name, sort_order, is_active)
             VALUES (:business_id, :branch_id, :public_id, :name, :sort_order, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'name' => $name,
            'sort_order' => $sortOrder,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findTableForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, table_code, capacity, is_active
             FROM pos_dining_tables
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

    public function createTable(
        int $businessId,
        int $branchId,
        int $areaId,
        string $publicId,
        string $code,
        int $capacity,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_dining_tables
                (business_id, branch_id, area_id, public_id, table_code, capacity, is_active)
             VALUES
                (:business_id, :branch_id, :area_id, :public_id, :table_code, :capacity, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'area_id' => $areaId,
            'public_id' => $publicId,
            'table_code' => $code,
            'capacity' => $capacity,
        ]);
    }

    public function createTableSession(
        int $businessId,
        int $branchId,
        int $tableId,
        string $publicId,
        int $openedBy,
    ): void {
        $statement = $this->connection->prepare(
            "INSERT INTO pos_table_sessions
                (business_id, branch_id, public_id, table_id, status, opened_by, opened_at)
             VALUES
                (:business_id, :branch_id, :public_id, :table_id, 'open', :opened_by, UTC_TIMESTAMP(3))"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'table_id' => $tableId,
            'opened_by' => $openedBy,
        ]);
    }

    public function hasOpenSession(int $businessId, int $branchId, int $tableId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM pos_table_sessions
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND table_id = :table_id AND status = 'open'
             LIMIT 1"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'table_id' => $tableId,
        ]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function findSessionForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, table_id, status, opened_at
             FROM pos_table_sessions
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

    public function hasUnfinishedOrdersForSession(int $businessId, int $branchId, int $sessionId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM pos_orders
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND table_session_id = :session_id
               AND (
                 order_status NOT IN ('completed','cancelled','rejected')
                 OR (order_status = 'completed' AND payment_status <> 'paid')
               )
             LIMIT 1"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'session_id' => $sessionId,
        ]);
        return $statement->fetchColumn() !== false;
    }

    public function closeTableSession(int $businessId, int $branchId, int $sessionId, int $closedBy): void
    {
        $statement = $this->connection->prepare(
            "UPDATE pos_table_sessions
             SET status = 'closed', closed_at = UTC_TIMESTAMP(3), closed_by = :closed_by
             WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id AND status = 'open'"
        );
        $statement->execute([
            'closed_by' => $closedBy,
            'id' => $sessionId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
        ]);
    }
}
