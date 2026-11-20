<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class InventoryRepository
{
    public function __construct(private PDO $connection) {}

    public function listUnits(): array
    {
        $s = $this->connection->query(
            'SELECT code, name, dimension, decimal_places FROM pos_units ORDER BY dimension, name, code'
        );
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'dimension' => (string) $row['dimension'],
            'decimal_places' => (int) $row['decimal_places'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findUnit(string $code): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, code, name, dimension, decimal_places FROM pos_units WHERE code = :code LIMIT 1'
        );
        $s->execute(['code' => $code]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listStockItems(int $businessId, ?string $search, int $limit): array
    {
        $sql = 'SELECT si.public_id, si.code, si.name, si.is_active, si.negative_stock_policy,
                       u.code AS base_unit_code, u.name AS base_unit_name, u.dimension AS base_unit_dimension
                FROM pos_stock_items si
                INNER JOIN pos_units u ON u.id = si.base_unit_id
                WHERE si.business_id = :business_id';
        $params = ['business_id' => $businessId];
        if ($search !== null && $search !== '') {
            $sql .= ' AND (si.name LIKE :search_name OR si.code LIKE :search_code)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_code'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY si.name, si.id LIMIT :limit';
        $s = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'business_id') continue;
            $s->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $s->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        $s->bindValue(':limit', $limit, PDO::PARAM_INT);
        $s->execute();
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'base_unit' => [
                'code' => (string) $row['base_unit_code'],
                'name' => (string) $row['base_unit_name'],
                'dimension' => (string) $row['base_unit_dimension'],
            ],
            'negative_stock_policy' => (string) $row['negative_stock_policy'],
            'is_active' => (bool) $row['is_active'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findStockItem(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT si.id, si.public_id, si.code, si.name, si.base_unit_id, si.is_active,
                    si.negative_stock_policy, u.code AS base_unit_code, u.name AS base_unit_name
             FROM pos_stock_items si
             INNER JOIN pos_units u ON u.id = si.base_unit_id
             WHERE si.business_id = :business_id AND si.public_id = :public_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function stockItemCodeExists(int $businessId, string $code, int $exceptId = 0): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_stock_items
             WHERE business_id = :business_id AND code = :code AND id <> :except_id LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'code' => $code, 'except_id' => $exceptId]);
        return $s->fetchColumn() !== false;
    }

    public function createStockItem(int $businessId, string $publicId, string $code, string $name, int $baseUnitId): int
    {
        $s = $this->connection->prepare(
            "INSERT INTO pos_stock_items (business_id, public_id, code, name, base_unit_id, is_active, negative_stock_policy)
             VALUES (:business_id, :public_id, :code, :name, :base_unit_id, 1, 'block')"
        );
        $s->execute([
            'business_id' => $businessId, 'public_id' => $publicId, 'code' => $code,
            'name' => $name, 'base_unit_id' => $baseUnitId,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function updateStockItem(int $businessId, int $id, string $code, string $name, bool $active): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_items SET code = :code, name = :name, is_active = :is_active
             WHERE business_id = :business_id AND id = :id'
        );
        $s->execute([
            'code' => $code, 'name' => $name, 'is_active' => $active ? 1 : 0,
            'business_id' => $businessId, 'id' => $id,
        ]);
    }

    public function stockItemHasActiveRecipe(int $businessId, int $stockItemId): bool
    {
        $s = $this->connection->prepare(
            "SELECT 1
             FROM pos_recipe_lines rl
             INNER JOIN pos_recipe_versions rv ON rv.id = rl.recipe_version_id AND rv.business_id = rl.business_id
             INNER JOIN pos_recipes r ON r.id = rv.recipe_id AND r.business_id = rv.business_id
             WHERE rl.business_id = :business_id AND rl.stock_item_id = :stock_item_id
               AND rv.status = 'active' AND r.is_active = 1 LIMIT 1"
        );
        $s->execute(['business_id' => $businessId, 'stock_item_id' => $stockItemId]);
        return $s->fetchColumn() !== false;
    }

    public function stockItemHasBalance(int $businessId, int $stockItemId): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_stock_balances
             WHERE business_id = :business_id AND stock_item_id = :stock_item_id
               AND (quantity_on_hand <> 0 OR quantity_reserved <> 0) LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'stock_item_id' => $stockItemId]);
        return $s->fetchColumn() !== false;
    }

    public function listLocations(int $businessId, int $branchId): array
    {
        $s = $this->connection->prepare(
            'SELECT public_id, code, name, location_type, is_active
             FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id
             ORDER BY name, id'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'location_type' => (string) $row['location_type'],
            'is_active' => (bool) $row['is_active'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findLocation(int $businessId, int $branchId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, public_id, code, name, location_type, is_active
             FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function locationCodeExists(int $businessId, int $branchId, string $code, int $exceptId = 0): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND code = :code AND id <> :except_id LIMIT 1'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'code' => $code, 'except_id' => $exceptId,
        ]);
        return $s->fetchColumn() !== false;
    }

    public function createLocation(int $businessId, int $branchId, string $publicId, string $code, string $name, string $type): int
    {
        $s = $this->connection->prepare(
            'INSERT INTO pos_stock_locations (business_id, branch_id, public_id, code, name, location_type, is_active)
             VALUES (:business_id, :branch_id, :public_id, :code, :name, :type, 1)'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId,
            'code' => $code, 'name' => $name, 'type' => $type,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function updateLocation(int $businessId, int $branchId, int $id, string $code, string $name, string $type, bool $active): void
    {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_locations
             SET code = :code, name = :name, location_type = :type, is_active = :is_active
             WHERE business_id = :business_id AND branch_id = :branch_id AND id = :id'
        );
        $s->execute([
            'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => $active ? 1 : 0,
            'business_id' => $businessId, 'branch_id' => $branchId, 'id' => $id,
        ]);
    }

    public function locationHasStock(int $businessId, int $branchId, int $locationId): bool
    {
        $s = $this->connection->prepare(
            'SELECT 1 FROM pos_stock_balances
             WHERE business_id = :business_id AND branch_id = :branch_id AND location_id = :location_id
               AND (quantity_on_hand <> 0 OR quantity_reserved <> 0) LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'location_id' => $locationId]);
        return $s->fetchColumn() !== false;
    }

    /** @return list<array<string,mixed>> */
    public function listBalances(
        int $businessId, int $branchId, ?int $stockItemId, ?int $locationId, int $limit,
    ): array {
        $sql = 'SELECT si.public_id AS stock_item_id, si.code AS stock_item_code,
                       si.name AS stock_item_name, u.code AS base_unit_code,
                       sl.public_id AS location_id, sl.code AS location_code,
                       sl.name AS location_name, b.quantity_on_hand,
                       b.quantity_reserved, (b.quantity_on_hand - b.quantity_reserved) AS quantity_available,
                       b.updated_at
                FROM pos_stock_balances b
                INNER JOIN pos_stock_items si
                  ON si.id = b.stock_item_id AND si.business_id = b.business_id
                INNER JOIN pos_stock_locations sl
                  ON sl.id = b.location_id AND sl.business_id = b.business_id AND sl.branch_id = b.branch_id
                INNER JOIN pos_units u ON u.id = si.base_unit_id
                WHERE b.business_id = :business_id AND b.branch_id = :branch_id';
        $params = ['business_id' => $businessId, 'branch_id' => $branchId];
        if ($stockItemId !== null) {
            $sql .= ' AND b.stock_item_id = :stock_item_id';
            $params['stock_item_id'] = $stockItemId;
        }
        if ($locationId !== null) {
            $sql .= ' AND b.location_id = :location_id';
            $params['location_id'] = $locationId;
        }
        $sql .= ' ORDER BY si.name ASC, sl.name ASC, b.stock_item_id ASC LIMIT :limit';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'stock_item_id' => (string) $row['stock_item_id'],
            'stock_item_code' => (string) $row['stock_item_code'],
            'stock_item_name' => (string) $row['stock_item_name'],
            'base_unit_code' => (string) $row['base_unit_code'],
            'location_id' => (string) $row['location_id'],
            'location_code' => (string) $row['location_code'],
            'location_name' => (string) $row['location_name'],
            'quantity_on_hand' => (string) $row['quantity_on_hand'],
            'quantity_reserved' => (string) $row['quantity_reserved'],
            'quantity_available' => (string) $row['quantity_available'],
            'updated_at' => (string) $row['updated_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array<string,mixed>> */
    public function listLedger(
        int $businessId, int $branchId, ?int $stockItemId, ?int $locationId,
        ?int $beforeId, int $limit,
    ): array {
        $sql = 'SELECT l.id AS ledger_id, l.movement_type, l.quantity_delta,
                       u.code AS base_unit_code, si.public_id AS stock_item_id,
                       si.code AS stock_item_code, si.name AS stock_item_name,
                       sl.public_id AS location_id, sl.code AS location_code,
                       sl.name AS location_name, d.public_id AS document_id,
                       d.document_no, d.document_type, dl.note, l.created_at
                FROM pos_stock_ledger l
                INNER JOIN pos_stock_items si
                  ON si.id = l.stock_item_id AND si.business_id = l.business_id
                INNER JOIN pos_stock_locations sl
                  ON sl.id = l.location_id AND sl.business_id = l.business_id AND sl.branch_id = l.branch_id
                INNER JOIN pos_units u ON u.id = l.base_unit_id
                LEFT JOIN pos_stock_document_lines dl
                  ON dl.id = l.document_line_id AND dl.business_id = l.business_id AND dl.branch_id = l.branch_id
                LEFT JOIN pos_stock_documents d
                  ON d.id = dl.document_id AND d.business_id = l.business_id AND d.branch_id = l.branch_id
                WHERE l.business_id = :business_id AND l.branch_id = :branch_id';
        $params = ['business_id' => $businessId, 'branch_id' => $branchId];
        if ($stockItemId !== null) {
            $sql .= ' AND l.stock_item_id = :stock_item_id';
            $params['stock_item_id'] = $stockItemId;
        }
        if ($locationId !== null) {
            $sql .= ' AND l.location_id = :location_id';
            $params['location_id'] = $locationId;
        }
        if ($beforeId !== null) {
            $sql .= ' AND l.id < :before_id';
            $params['before_id'] = $beforeId;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT :limit';
        $statement = $this->connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'ledger_id' => (int) $row['ledger_id'],
            'movement_type' => (string) $row['movement_type'],
            'quantity_delta' => (string) $row['quantity_delta'],
            'base_unit_code' => (string) $row['base_unit_code'],
            'stock_item_id' => (string) $row['stock_item_id'],
            'stock_item_code' => (string) $row['stock_item_code'],
            'stock_item_name' => (string) $row['stock_item_name'],
            'location_id' => (string) $row['location_id'],
            'location_code' => (string) $row['location_code'],
            'location_name' => (string) $row['location_name'],
            'document_id' => $row['document_id'] === null ? null : (string) $row['document_id'],
            'document_no' => $row['document_no'] === null ? null : (string) $row['document_no'],
            'document_type' => $row['document_type'] === null ? null : (string) $row['document_type'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

}
