<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class InventoryPostingRepository
{
    public function __construct(private PDO $connection) {}

    public function lockBalance(
        int $businessId, int $branchId, int $stockItemId, int $locationId,
    ): array {
        $insert = $this->connection->prepare(
            'INSERT INTO pos_stock_balances
             (stock_item_id, business_id, branch_id, location_id, quantity_on_hand, quantity_reserved, average_unit_cost)
             VALUES (:stock_item_id, :business_id, :branch_id, :location_id, 0, 0, 0)
             ON DUPLICATE KEY UPDATE stock_item_id = VALUES(stock_item_id)'
        );
        $insert->execute([
            'stock_item_id' => $stockItemId, 'business_id' => $businessId,
            'branch_id' => $branchId, 'location_id' => $locationId,
        ]);
        $select = $this->connection->prepare(
            'SELECT quantity_on_hand, quantity_reserved, average_unit_cost
             FROM pos_stock_balances
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND stock_item_id = :stock_item_id AND location_id = :location_id
             LIMIT 1 FOR UPDATE'
        );
        $select->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'stock_item_id' => $stockItemId, 'location_id' => $locationId,
        ]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('The scoped stock balance row could not be locked.');
        }
        return [
            'quantity_on_hand' => (string) $row['quantity_on_hand'],
            'quantity_reserved' => (string) $row['quantity_reserved'],
            'average_unit_cost' => (string) $row['average_unit_cost'],
        ];
    }

    public function stockItemForPosting(int $businessId, int $stockItemId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT base_unit_id, is_active FROM pos_stock_items
             WHERE business_id = :business_id AND id = :stock_item_id
             LIMIT 1 LOCK IN SHARE MODE'
        );
        $s->execute(['business_id' => $businessId, 'stock_item_id' => $stockItemId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function locationIsActive(int $businessId, int $branchId, int $locationId): bool
    {
        $s = $this->connection->prepare(
            'SELECT is_active FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id AND id = :location_id
             LIMIT 1 LOCK IN SHARE MODE'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'location_id' => $locationId,
        ]);
        return (int) $s->fetchColumn() === 1;
    }

    public function unitCostFromTotal(string $totalCostAmount, string $baseQuantity): string
    {
        $s = $this->connection->prepare(
            'SELECT CAST(ROUND(CAST(:total_cost AS DECIMAL(20,0)) /
                       CAST(:base_quantity AS DECIMAL(18,6)), 8) AS DECIMAL(24,8))'
        );
        $s->execute(['total_cost' => $totalCostAmount, 'base_quantity' => $baseQuantity]);
        return (string) $s->fetchColumn();
    }

    public function costForQuantity(string $baseQuantity, string $unitCost): string
    {
        $s = $this->connection->prepare(
            'SELECT CAST(ROUND(CAST(:base_quantity AS DECIMAL(18,6)) *
                       CAST(:unit_cost AS DECIMAL(24,8)), 0) AS DECIMAL(20,0))'
        );
        $s->execute(['base_quantity' => $baseQuantity, 'unit_cost' => $unitCost]);
        $amount = $s->fetchColumn();
        if ($amount === false) throw new \RuntimeException('Unable to calculate stock movement cost.');
        return (string) $amount;
    }

    public function weightedAverageFromTotalCost(
        string $oldQuantity, string $oldAverageCost, string $incomingQuantity, string $incomingTotalCost,
    ): string {
        $s = $this->connection->prepare(
            'SELECT CAST(ROUND(
                ((CAST(:old_quantity_value AS DECIMAL(18,6)) * CAST(:old_average AS DECIMAL(24,8)))
                  + CAST(:incoming_total_cost AS DECIMAL(20,0)))
                / (CAST(:old_quantity_denominator AS DECIMAL(18,6)) + CAST(:incoming_quantity AS DECIMAL(18,6))),
                8
             ) AS DECIMAL(24,8))'
        );
        $s->execute([
            'old_quantity_value' => $oldQuantity, 'old_average' => $oldAverageCost,
            'incoming_total_cost' => $incomingTotalCost, 'old_quantity_denominator' => $oldQuantity,
            'incoming_quantity' => $incomingQuantity,
        ]);
        return (string) $s->fetchColumn();
    }

    public function weightedAverageFromUnitCost(
        string $oldQuantity, string $oldAverageCost, string $incomingQuantity, string $incomingUnitCost,
    ): string {
        $s = $this->connection->prepare(
            'SELECT CAST(ROUND(
                ((CAST(:old_quantity_value AS DECIMAL(18,6)) * CAST(:old_average AS DECIMAL(24,8)))
                  + (CAST(:incoming_quantity_value AS DECIMAL(18,6)) * CAST(:incoming_unit_cost AS DECIMAL(24,8))))
                / (CAST(:old_quantity_denominator AS DECIMAL(18,6)) + CAST(:incoming_quantity_denominator AS DECIMAL(18,6))),
                8
             ) AS DECIMAL(24,8))'
        );
        $s->execute([
            'old_quantity_value' => $oldQuantity, 'old_average' => $oldAverageCost,
            'incoming_quantity_value' => $incomingQuantity, 'incoming_unit_cost' => $incomingUnitCost,
            'old_quantity_denominator' => $oldQuantity, 'incoming_quantity_denominator' => $incomingQuantity,
        ]);
        return (string) $s->fetchColumn();
    }

    public function receiveBalance(
        int $businessId, int $branchId, int $stockItemId, int $locationId,
        string $baseQuantity, string $newAverageCost,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_balances
             SET quantity_on_hand = quantity_on_hand + CAST(:quantity_delta AS DECIMAL(18,6)),
                 average_unit_cost = CAST(:average_cost AS DECIMAL(24,8))
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND stock_item_id = :stock_item_id AND location_id = :location_id'
        );
        $s->execute([
            'quantity_delta' => $baseQuantity, 'average_cost' => $newAverageCost,
            'business_id' => $businessId, 'branch_id' => $branchId,
            'stock_item_id' => $stockItemId, 'location_id' => $locationId,
        ]);
        if ($s->rowCount() !== 1) throw new \RuntimeException('The scoped stock balance could not be increased.');
    }

    public function issueBalance(
        int $businessId, int $branchId, int $stockItemId, int $locationId, string $baseQuantity,
    ): bool {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_balances
             SET quantity_on_hand = quantity_on_hand - CAST(:quantity_update AS DECIMAL(18,6)),
                 average_unit_cost = CASE
                   WHEN quantity_on_hand - CAST(:quantity_cost AS DECIMAL(18,6)) = 0 THEN 0
                   ELSE average_unit_cost
                 END
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND stock_item_id = :stock_item_id AND location_id = :location_id
               AND quantity_on_hand - quantity_reserved >= CAST(:quantity_available AS DECIMAL(18,6))'
        );
        $s->execute([
            'quantity_update' => $baseQuantity, 'quantity_cost' => $baseQuantity,
            'quantity_available' => $baseQuantity, 'business_id' => $businessId,
            'branch_id' => $branchId, 'stock_item_id' => $stockItemId, 'location_id' => $locationId,
        ]);
        return $s->rowCount() === 1;
    }

    public function appendLedger(
        int $businessId, int $branchId, int $stockItemId, int $locationId,
        int $documentLineId, string $movementType, string $quantityDelta,
        int $baseUnitId, string $unitCost, string $signedTotalCost, string $sourceEventKey,
    ): void {
        $s = $this->connection->prepare(
            'INSERT INTO pos_stock_ledger
             (business_id, branch_id, stock_item_id, location_id, document_line_id,
              movement_type, quantity_delta, base_unit_id, unit_cost_snapshot,
              total_cost_amount, source_event_key)
             VALUES
             (:business_id, :branch_id, :stock_item_id, :location_id, :document_line_id,
              :movement_type, :quantity_delta, :base_unit_id, :unit_cost_snapshot,
              :total_cost_amount, :source_event_key)'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'stock_item_id' => $stockItemId, 'location_id' => $locationId,
            'document_line_id' => $documentLineId, 'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta, 'base_unit_id' => $baseUnitId,
            'unit_cost_snapshot' => $unitCost, 'total_cost_amount' => $signedTotalCost,
            'source_event_key' => $sourceEventKey,
        ]);
    }
}
