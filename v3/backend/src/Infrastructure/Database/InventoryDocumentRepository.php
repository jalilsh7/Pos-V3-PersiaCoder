<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class InventoryDocumentRepository
{
    public function __construct(private PDO $connection) {}

    public function findLocationId(int $businessId, int $branchId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND public_id = :public_id AND is_active = 1 LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function findSupplierId(int $businessId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_suppliers
             WHERE business_id = :business_id AND public_id = :public_id AND is_active = 1 LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function supplierIsActive(int $businessId, int $supplierId): bool
    {
        $s = $this->connection->prepare(
            'SELECT is_active FROM pos_suppliers
             WHERE business_id = :business_id AND id = :supplier_id
             LIMIT 1 LOCK IN SHARE MODE'
        );
        $s->execute(['business_id' => $businessId, 'supplier_id' => $supplierId]);
        return (int) $s->fetchColumn() === 1;
    }

    public function nextDocumentNumber(int $businessId, int $branchId): string
    {
        $key = 'stockdoc:' . $businessId . ':' . $branchId;
        $insert = $this->connection->prepare(
            'INSERT IGNORE INTO pos_sequences (sequence_key, current_value) VALUES (:key, 0)'
        );
        $insert->execute(['key' => $key]);
        $select = $this->connection->prepare(
            'SELECT current_value FROM pos_sequences WHERE sequence_key = :key FOR UPDATE'
        );
        $select->execute(['key' => $key]);
        $next = (int) $select->fetchColumn() + 1;
        $update = $this->connection->prepare(
            'UPDATE pos_sequences SET current_value = :value WHERE sequence_key = :key'
        );
        $update->execute(['value' => $next, 'key' => $key]);
        return sprintf('STK-%d-%08d', $branchId, $next);
    }

    public function create(
        int $businessId, int $branchId, string $publicId, string $documentNo,
        string $type, int $locationId, ?int $destinationId, ?int $supplierId,
        ?string $reason, int $actorUserId, ?int $sourceDocumentId = null,
    ): void {
        $s = $this->connection->prepare(
            "INSERT INTO pos_stock_documents
             (business_id, branch_id, public_id, document_no, document_type, location_id,
              destination_location_id, supplier_id, source_document_id, status, reason, actor_user_id)
             VALUES
             (:business_id, :branch_id, :public_id, :document_no, :document_type, :location_id,
              :destination_location_id, :supplier_id, :source_document_id, 'draft', :reason, :actor_user_id)"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId,
            'document_no' => $documentNo, 'document_type' => $type, 'location_id' => $locationId,
            'destination_location_id' => $destinationId, 'supplier_id' => $supplierId, 'source_document_id' => $sourceDocumentId,
            'reason' => $reason, 'actor_user_id' => $actorUserId,
        ]);
    }

    public function list(int $businessId, int $branchId, ?string $type, ?string $status, int $limit): array
    {
        $sql = 'SELECT d.public_id, d.document_no, d.document_type, d.status, d.reason,
                       source_doc.public_id AS source_document_public_id, source_doc.document_no AS source_document_no,
                       src.public_id AS location_id, src.name AS location_name,
                       dst.public_id AS destination_location_id, dst.name AS destination_location_name,
                       sup.public_id AS supplier_public_id, sup.name AS supplier_name,
                       d.created_at, d.posted_at
                FROM pos_stock_documents d
                INNER JOIN pos_stock_locations src
                  ON src.id = d.location_id AND src.business_id = d.business_id AND src.branch_id = d.branch_id
                LEFT JOIN pos_stock_locations dst
                  ON dst.id = d.destination_location_id AND dst.business_id = d.business_id AND dst.branch_id = d.branch_id
                LEFT JOIN pos_suppliers sup
                  ON sup.id = d.supplier_id AND sup.business_id = d.business_id
                LEFT JOIN pos_stock_documents source_doc
                  ON source_doc.id = d.source_document_id AND source_doc.business_id = d.business_id AND source_doc.branch_id = d.branch_id
                WHERE d.business_id = :business_id AND d.branch_id = :branch_id';
        $params = ['business_id' => $businessId, 'branch_id' => $branchId];
        if ($type !== null) { $sql .= ' AND d.document_type = :type'; $params['type'] = $type; }
        if ($status !== null) { $sql .= ' AND d.status = :status'; $params['status'] = $status; }
        $sql .= ' ORDER BY d.id DESC LIMIT :limit';
        $s = $this->connection->prepare($sql);
        $s->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        $s->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        if ($type !== null) $s->bindValue(':type', $type, PDO::PARAM_STR);
        if ($status !== null) $s->bindValue(':status', $status, PDO::PARAM_STR);
        $s->bindValue(':limit', $limit, PDO::PARAM_INT);
        $s->execute();
        return array_map(static fn (array $r): array => [
            'id' => (string) $r['public_id'], 'document_no' => (string) $r['document_no'],
            'document_type' => (string) $r['document_type'], 'status' => (string) $r['status'],
            'reason' => $r['reason'] === null ? null : (string) $r['reason'],
            'source_document' => $r['source_document_public_id'] === null ? null : ['id' => (string) $r['source_document_public_id'], 'document_no' => (string) $r['source_document_no']],
            'location' => ['id' => (string) $r['location_id'], 'name' => (string) $r['location_name']],
            'destination' => $r['destination_location_id'] === null ? null : [
                'id' => (string) $r['destination_location_id'], 'name' => (string) $r['destination_location_name'],
            ],
            'supplier' => $r['supplier_public_id'] === null ? null : [
                'id' => (string) $r['supplier_public_id'], 'name' => (string) $r['supplier_name'],
            ],
            'created_at' => (string) $r['created_at'],
            'posted_at' => $r['posted_at'] === null ? null : (string) $r['posted_at'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $businessId, int $branchId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT d.id, d.public_id, d.document_no, d.document_type, d.status, d.reason,
                    d.supplier_id, d.source_document_id, source_doc.public_id AS source_document_public_id,
                    source_doc.document_no AS source_document_no, d.created_at, d.posted_at,
                    src.public_id AS location_id, src.name AS location_name,
                    dst.public_id AS destination_location_id, dst.name AS destination_location_name,
                    sup.public_id AS supplier_public_id, sup.name AS supplier_name
             FROM pos_stock_documents d
             INNER JOIN pos_stock_locations src
               ON src.id = d.location_id AND src.business_id = d.business_id AND src.branch_id = d.branch_id
             LEFT JOIN pos_stock_locations dst
               ON dst.id = d.destination_location_id AND dst.business_id = d.business_id AND dst.branch_id = d.branch_id
             LEFT JOIN pos_suppliers sup
               ON sup.id = d.supplier_id AND sup.business_id = d.business_id
             LEFT JOIN pos_stock_documents source_doc
               ON source_doc.id = d.source_document_id AND source_doc.business_id = d.business_id AND source_doc.branch_id = d.branch_id
             WHERE d.business_id = :business_id AND d.branch_id = :branch_id AND d.public_id = :public_id
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        return [
            'id' => (string) $r['public_id'], 'document_no' => (string) $r['document_no'],
            'document_type' => (string) $r['document_type'], 'status' => (string) $r['status'],
            'reason' => $r['reason'] === null ? null : (string) $r['reason'],
            'source_document' => $r['source_document_public_id'] === null ? null : ['id' => (string) $r['source_document_public_id'], 'document_no' => (string) $r['source_document_no']],
            'location' => ['id' => (string) $r['location_id'], 'name' => (string) $r['location_name']],
            'destination' => $r['destination_location_id'] === null ? null : [
                'id' => (string) $r['destination_location_id'], 'name' => (string) $r['destination_location_name'],
            ],
            'supplier' => $r['supplier_public_id'] === null ? null : [
                'id' => (string) $r['supplier_public_id'], 'name' => (string) $r['supplier_name'],
            ],
            'lines' => $this->listLines($businessId, $branchId, (int) $r['id']),
            'created_at' => (string) $r['created_at'],
            'posted_at' => $r['posted_at'] === null ? null : (string) $r['posted_at'],
        ];
    }

    public function findId(int $businessId, int $branchId, string $publicId): ?int
    {
        $s = $this->connection->prepare(
            'SELECT id FROM pos_stock_documents
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function listValuationLines(int $businessId, int $branchId, int $documentId): array
    {
        $s = $this->connection->prepare(
            'SELECT l.public_id AS line_id, l.source_document_line_id, si.public_id AS stock_item_id, si.name AS stock_item_name,
                    l.quantity, u.code AS unit_code, l.base_quantity,
                    l.unit_cost_snapshot, l.total_cost_amount
             FROM pos_stock_document_lines l
             INNER JOIN pos_stock_items si
               ON si.id = l.stock_item_id AND si.business_id = l.business_id
             INNER JOIN pos_units u ON u.id = l.unit_id
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :document_id
             ORDER BY l.id ASC'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId,
        ]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['line_id'],
            'stock_item_id' => (string) $row['stock_item_id'],
            'stock_item_name' => (string) $row['stock_item_name'],
            'quantity' => (string) $row['quantity'],
            'unit_code' => (string) $row['unit_code'],
            'base_quantity' => (string) $row['base_quantity'],
            'unit_cost_snapshot' => (string) $row['unit_cost_snapshot'],
            'total_cost_amount' => (string) $row['total_cost_amount'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findPostedReturnSourceForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            "SELECT d.id, d.public_id, d.document_no, d.document_type, d.location_id, d.status,
                    l.public_id AS location_public_id
             FROM pos_stock_documents d
             INNER JOIN pos_stock_locations l
               ON l.id = d.location_id AND l.business_id = d.business_id AND l.branch_id = d.branch_id
             WHERE d.business_id = :business_id AND d.branch_id = :branch_id
               AND d.public_id = :public_id AND d.status = 'posted'
               AND d.document_type IN ('adjustment_out','consumption','waste')
             LIMIT 1 FOR UPDATE"
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findPostedReturnSourceByIdForUpdate(int $businessId, int $branchId, int $sourceDocumentId): ?array
    {
        $s = $this->connection->prepare(
            "SELECT id, document_type, location_id, status
             FROM pos_stock_documents
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND id = :source_document_id AND status = 'posted'
               AND document_type IN ('adjustment_out','consumption','waste')
             LIMIT 1 FOR UPDATE"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'source_document_id' => $sourceDocumentId,
        ]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findReturnSourceLineByIdForUpdate(
        int $businessId, int $branchId, int $sourceDocumentId, int $sourceLineId,
    ): ?array {
        $s = $this->connection->prepare(
            "SELECT l.id, l.stock_item_id, l.base_quantity,
                    led.unit_cost_snapshot, led.total_cost_amount
             FROM pos_stock_document_lines l
             INNER JOIN pos_stock_ledger led ON led.document_line_id = l.id
               AND led.business_id = l.business_id AND led.branch_id = l.branch_id
               AND led.movement_type IN ('adjustment_out','consumption_out','waste_out')
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :source_document_id AND l.id = :source_line_id
             LIMIT 1 FOR UPDATE"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'source_document_id' => $sourceDocumentId, 'source_line_id' => $sourceLineId,
        ]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findReturnSourceLineForUpdate(
        int $businessId, int $branchId, int $sourceDocumentId, string $linePublicId,
    ): ?array {
        $s = $this->connection->prepare(
            "SELECT l.id, l.public_id, l.stock_item_id, l.quantity, l.unit_id,
                    l.conversion_factor_snapshot, l.base_quantity,
                    si.public_id AS stock_item_public_id, si.name AS stock_item_name,
                    u.code AS unit_code, u.name AS unit_name,
                    led.unit_cost_snapshot, led.total_cost_amount
             FROM pos_stock_document_lines l
             INNER JOIN pos_stock_items si ON si.id = l.stock_item_id AND si.business_id = l.business_id
             INNER JOIN pos_units u ON u.id = l.unit_id
             INNER JOIN pos_stock_ledger led ON led.document_line_id = l.id
               AND led.business_id = l.business_id AND led.branch_id = l.branch_id
               AND led.movement_type IN ('adjustment_out','consumption_out','waste_out')
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :source_document_id AND l.public_id = :line_public_id
             LIMIT 1 FOR UPDATE"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'source_document_id' => $sourceDocumentId, 'line_public_id' => $linePublicId,
        ]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function returnedBaseQuantity(int $businessId, int $branchId, int $sourceLineId): string
    {
        $s = $this->connection->prepare(
            "SELECT COALESCE(SUM(return_line.base_quantity), 0)
             FROM pos_stock_document_lines return_line
             INNER JOIN pos_stock_documents return_doc
               ON return_doc.id = return_line.document_id
              AND return_doc.business_id = return_line.business_id
              AND return_doc.branch_id = return_line.branch_id
             WHERE return_line.business_id = :business_id AND return_line.branch_id = :branch_id
               AND return_line.source_document_line_id = :source_line_id
               AND return_doc.document_type = 'return' AND return_doc.status = 'posted'"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'source_line_id' => $sourceLineId,
        ]);
        return (string) $s->fetchColumn();
    }

    public function canAddReturnQuantity(
        int $businessId, int $branchId, int $sourceLineId, int $returnDocumentId,
        string $quantity, string $conversionFactor,
    ): bool {
        $s = $this->connection->prepare(
            "SELECT (
                source_line.base_quantity
                - COALESCE((
                    SELECT SUM(return_line.base_quantity)
                    FROM pos_stock_document_lines return_line
                    INNER JOIN pos_stock_documents return_doc
                      ON return_doc.id = return_line.document_id
                     AND return_doc.business_id = return_line.business_id
                     AND return_doc.branch_id = return_line.branch_id
                    WHERE return_line.business_id = source_line.business_id
                      AND return_line.branch_id = source_line.branch_id
                      AND return_line.source_document_line_id = source_line.id
                      AND return_doc.document_type = 'return'
                      AND return_doc.status = 'posted'
                ), 0)
                - COALESCE((
                    SELECT SUM(draft_line.base_quantity)
                    FROM pos_stock_document_lines draft_line
                    WHERE draft_line.business_id = source_line.business_id
                      AND draft_line.branch_id = source_line.branch_id
                      AND draft_line.document_id = :return_document_id
                      AND draft_line.source_document_line_id = source_line.id
                ), 0)
                - (CAST(:quantity_value AS DECIMAL(18,6)) * CAST(:factor_value AS DECIMAL(18,8)))
             ) >= 0
             FROM pos_stock_document_lines source_line
             WHERE source_line.business_id = :business_id
               AND source_line.branch_id = :branch_id AND source_line.id = :source_line_id"
        );
        $s->execute([
            'return_document_id' => $returnDocumentId, 'quantity_value' => $quantity,
            'factor_value' => $conversionFactor, 'business_id' => $businessId,
            'branch_id' => $branchId, 'source_line_id' => $sourceLineId,
        ]);
        return (int) $s->fetchColumn() === 1;
    }

    public function draftReturnedBaseQuantity(
        int $businessId, int $branchId, int $returnDocumentId, int $sourceLineId,
    ): string {
        $s = $this->connection->prepare(
            'SELECT COALESCE(SUM(base_quantity), 0) FROM pos_stock_document_lines
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND document_id = :document_id AND source_document_line_id = :source_line_id'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'document_id' => $returnDocumentId, 'source_line_id' => $sourceLineId,
        ]);
        return (string) $s->fetchColumn();
    }

    public function findDraftForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            "SELECT id, public_id, document_no, document_type, status,
                    location_id, destination_location_id, supplier_id, source_document_id
             FROM pos_stock_documents
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1 FOR UPDATE"
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listLinesForPostingForUpdate(int $businessId, int $branchId, int $documentId): array
    {
        $s = $this->connection->prepare(
            'SELECT l.id, l.public_id, l.stock_item_id, l.source_document_line_id,
                    l.base_quantity, l.unit_cost_snapshot, l.total_cost_amount
             FROM pos_stock_document_lines l
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :document_id
             ORDER BY l.stock_item_id ASC, l.id ASC
             FOR UPDATE'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId,
        ]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateLineValuation(
        int $businessId, int $branchId, int $documentId, int $lineId,
        string $unitCostSnapshot, string $totalCostAmount,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_document_lines
             SET unit_cost_snapshot = :unit_cost_snapshot, total_cost_amount = :total_cost_amount
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND document_id = :document_id AND id = :line_id'
        );
        $s->execute([
            'unit_cost_snapshot' => $unitCostSnapshot, 'total_cost_amount' => $totalCostAmount,
            'business_id' => $businessId, 'branch_id' => $branchId,
            'document_id' => $documentId, 'line_id' => $lineId,
        ]);
    }

    public function markPosted(int $businessId, int $branchId, int $documentId): void
    {
        $s = $this->connection->prepare(
            "UPDATE pos_stock_documents
             SET status = 'posted', posted_at = UTC_TIMESTAMP(3)
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND id = :document_id AND status = 'draft'"
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId,
        ]);
        if ($s->rowCount() !== 1) {
            throw new \RuntimeException('The stock document state changed while posting.');
        }
    }

    public function findStockItemForLine(int $businessId, string $publicId): ?array
    {
        $s = $this->connection->prepare(
            'SELECT si.id, si.public_id, si.name, si.base_unit_id, bu.dimension AS base_dimension
             FROM pos_stock_items si
             INNER JOIN pos_units bu ON bu.id = si.base_unit_id
             WHERE si.business_id = :business_id AND si.public_id = :public_id AND si.is_active = 1
             LIMIT 1'
        );
        $s->execute(['business_id' => $businessId, 'public_id' => $publicId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findUnitForLine(string $unitCode): ?array
    {
        $s = $this->connection->prepare(
            'SELECT id, code, name, dimension FROM pos_units WHERE code = :code LIMIT 1'
        );
        $s->execute(['code' => $unitCode]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findConversionFactor(int $fromUnitId, int $toUnitId): ?string
    {
        if ($fromUnitId === $toUnitId) return '1.00000000';
        $s = $this->connection->prepare(
            'SELECT factor FROM pos_unit_conversions
             WHERE from_unit_id = :from_unit_id AND to_unit_id = :to_unit_id
               AND effective_from <= CURRENT_TIMESTAMP(3)
             ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $s->execute(['from_unit_id' => $fromUnitId, 'to_unit_id' => $toUnitId]);
        $factor = $s->fetchColumn();
        return $factor === false ? null : (string) $factor;
    }

    public function addLine(
        int $businessId, int $branchId, int $documentId, string $linePublicId, int $stockItemId,
        string $quantity, int $unitId, string $conversionFactor, string $totalCostAmount, ?string $note,
        ?int $sourceDocumentLineId = null,
    ): void {
        $s = $this->connection->prepare(
            'INSERT INTO pos_stock_document_lines
             (business_id, branch_id, document_id, public_id, source_document_line_id, stock_item_id, quantity, unit_id,
              conversion_factor_snapshot, base_quantity, unit_cost_snapshot, total_cost_amount, note)
             VALUES
             (:business_id, :branch_id, :document_id, :public_id, :source_document_line_id, :stock_item_id, :quantity_value, :unit_id,
              :factor_value, CAST(:quantity_calc AS DECIMAL(18,6)) * CAST(:factor_calc AS DECIMAL(18,8)),
              CASE WHEN CAST(:total_cost_calc AS DECIMAL(20,0)) = 0 THEN 0
                   ELSE ROUND(CAST(:total_cost_unit AS DECIMAL(20,0)) /
                     (CAST(:quantity_unit AS DECIMAL(18,6)) * CAST(:factor_unit AS DECIMAL(18,8))), 8) END,
              :total_cost_amount, :note)'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId,
            'public_id' => $linePublicId, 'source_document_line_id' => $sourceDocumentLineId, 'stock_item_id' => $stockItemId,
            'quantity_value' => $quantity, 'unit_id' => $unitId,
            'factor_value' => $conversionFactor, 'quantity_calc' => $quantity,
            'factor_calc' => $conversionFactor, 'total_cost_calc' => $totalCostAmount,
            'total_cost_unit' => $totalCostAmount, 'quantity_unit' => $quantity,
            'factor_unit' => $conversionFactor, 'total_cost_amount' => $totalCostAmount, 'note' => $note,
        ]);
    }

    public function findLineForUpdate(
        int $businessId, int $branchId, int $documentId, string $linePublicId,
    ): ?array {
        $s = $this->connection->prepare(
            'SELECT l.id, l.public_id, si.public_id AS stock_item_public_id,
                    l.quantity, u.code AS unit_code, l.conversion_factor_snapshot,
                    l.base_quantity, l.total_cost_amount, l.note
             FROM pos_stock_document_lines l
             INNER JOIN pos_stock_items si
               ON si.id = l.stock_item_id AND si.business_id = l.business_id
             INNER JOIN pos_units u ON u.id = l.unit_id
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :document_id AND l.public_id = :public_id
             LIMIT 1 FOR UPDATE'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'document_id' => $documentId, 'public_id' => $linePublicId,
        ]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateLine(
        int $businessId, int $branchId, int $documentId, int $lineId, int $stockItemId,
        string $quantity, int $unitId, string $conversionFactor, string $totalCostAmount, ?string $note,
    ): void {
        $s = $this->connection->prepare(
            'UPDATE pos_stock_document_lines
             SET stock_item_id = :stock_item_id, quantity = :quantity_value, unit_id = :unit_id,
                 conversion_factor_snapshot = :factor_value,
                 base_quantity = CAST(:quantity_calc AS DECIMAL(18,6)) * CAST(:factor_calc AS DECIMAL(18,8)),
                 unit_cost_snapshot = CASE WHEN CAST(:total_cost_calc AS DECIMAL(20,0)) = 0 THEN 0
                   ELSE ROUND(CAST(:total_cost_unit AS DECIMAL(20,0)) /
                     (CAST(:quantity_unit AS DECIMAL(18,6)) * CAST(:factor_unit AS DECIMAL(18,8))), 8) END,
                 total_cost_amount = :total_cost_amount,
                 note = :note
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND document_id = :document_id AND id = :line_id'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId,
            'line_id' => $lineId, 'stock_item_id' => $stockItemId, 'quantity_value' => $quantity,
            'unit_id' => $unitId, 'factor_value' => $conversionFactor,
            'quantity_calc' => $quantity, 'factor_calc' => $conversionFactor,
            'total_cost_calc' => $totalCostAmount, 'total_cost_unit' => $totalCostAmount,
            'quantity_unit' => $quantity, 'factor_unit' => $conversionFactor,
            'total_cost_amount' => $totalCostAmount, 'note' => $note,
        ]);
    }

    public function deleteLine(int $businessId, int $branchId, int $documentId, int $lineId): void
    {
        $s = $this->connection->prepare(
            'DELETE FROM pos_stock_document_lines
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND document_id = :document_id AND id = :line_id'
        );
        $s->execute([
            'business_id' => $businessId, 'branch_id' => $branchId,
            'document_id' => $documentId, 'line_id' => $lineId,
        ]);
    }

    private function listLines(int $businessId, int $branchId, int $documentId): array
    {
        $s = $this->connection->prepare(
            'SELECT l.public_id AS line_id, l.source_document_line_id, si.public_id AS stock_item_id, si.name AS stock_item_name,
                    l.quantity, u.code AS unit_code, u.name AS unit_name,
                    l.conversion_factor_snapshot, l.base_quantity, l.note
             FROM pos_stock_document_lines l
             INNER JOIN pos_stock_items si
               ON si.id = l.stock_item_id AND si.business_id = l.business_id
             INNER JOIN pos_units u ON u.id = l.unit_id
             WHERE l.business_id = :business_id AND l.branch_id = :branch_id
               AND l.document_id = :document_id
             ORDER BY l.id ASC'
        );
        $s->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'document_id' => $documentId]);
        return array_map(static fn (array $r): array => [
            'id' => (string) $r['line_id'],
            'source_document_line_id' => $r['source_document_line_id'] === null ? null : (string) $r['source_document_line_id'],
            'stock_item_id' => (string) $r['stock_item_id'],
            'stock_item_name' => (string) $r['stock_item_name'],
            'quantity' => (string) $r['quantity'],
            'unit_code' => (string) $r['unit_code'],
            'unit_name' => (string) $r['unit_name'],
            'conversion_factor_snapshot' => (string) $r['conversion_factor_snapshot'],
            'base_quantity' => (string) $r['base_quantity'],
            'note' => $r['note'] === null ? null : (string) $r['note'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

}
