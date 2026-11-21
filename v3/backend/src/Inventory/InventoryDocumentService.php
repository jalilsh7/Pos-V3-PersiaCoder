<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Inventory;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\InventoryDocumentRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class InventoryDocumentService
{
    private InventoryDocumentRepository $documents;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->documents = new InventoryDocumentRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function list(BusinessScope $scope, ?string $type, ?string $status, int $limit): array
    {
        $branchId = self::branch($scope);
        $types = ['purchase_receipt', 'consumption', 'transfer', 'adjustment_in', 'adjustment_out', 'waste', 'return'];
        if ($type !== null && !in_array($type, $types, true)) {
            throw new HttpException(422, 'stock_document_type_invalid', 'The stock document type is invalid.');
        }
        if ($status !== null && !in_array($status, ['draft', 'posted', 'voided'], true)) {
            throw new HttpException(422, 'stock_document_status_invalid', 'The stock document status is invalid.');
        }
        if ($limit < 1 || $limit > 100) throw new HttpException(422, 'inventory_limit_invalid', 'The page size must be between 1 and 100.');
        return $this->documents->list($scope->businessId, $branchId, $type, $status, $limit);
    }

    public function get(BusinessScope $scope, string $publicId): array
    {
        $branchId = self::branch($scope);
        if (!self::isUuid($publicId)) throw new HttpException(422, 'stock_document_id_invalid', 'The stock document identifier is invalid.');
        $document = $this->documents->find($scope->businessId, $branchId, $publicId);
        if ($document === null) throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
        return $document;
    }

    public function getValuation(BusinessScope $scope, string $publicId): array
    {
        $branchId = self::branch($scope);
        if (!self::isUuid($publicId)) {
            throw new HttpException(422, 'stock_document_id_invalid', 'The stock document identifier is invalid.');
        }
        $document = $this->documents->find($scope->businessId, $branchId, $publicId);
        if ($document === null) {
            throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
        }
        $documentId = $this->documents->findId($scope->businessId, $branchId, $publicId);
        if ($documentId === null) {
            throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
        }
        return [
            'document_id' => $publicId,
            'document_no' => (string) $document['document_no'],
            'status' => (string) $document['status'],
            'currency' => 'TOMAN',
            'lines' => $this->documents->listValuationLines($scope->businessId, $branchId, $documentId),
        ];
    }

    public function create(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::branch($scope);
        $type = $payload['document_type'] ?? null;
        $types = ['purchase_receipt', 'consumption', 'transfer', 'adjustment_in', 'adjustment_out', 'waste', 'return'];
        if (!is_string($type) || !in_array($type, $types, true)) {
            throw new HttpException(422, 'stock_document_type_invalid', 'The stock document type is invalid.');
        }
        $sourceDocumentPublicId = $payload['source_document_id'] ?? null;
        if ($type === 'return') {
            if (!is_string($sourceDocumentPublicId) || !self::isUuid($sourceDocumentPublicId)) {
                throw new HttpException(422, 'stock_return_source_required', 'A valid posted source document is required for a return.');
            }
        } elseif ($sourceDocumentPublicId !== null) {
            throw new HttpException(422, 'stock_return_source_not_allowed', 'Only return documents can reference a source document.');
        }
        $locationPublicId = $payload['location_id'] ?? null;
        if ($type !== 'return' && (!is_string($locationPublicId) || !self::isUuid($locationPublicId))) {
            throw new HttpException(422, 'stock_document_location_invalid', 'A valid location_id is required.');
        }
        $destinationPublicId = $payload['destination_location_id'] ?? null;
        if ($type === 'transfer') {
            if (!is_string($destinationPublicId) || !self::isUuid($destinationPublicId)
                || hash_equals($locationPublicId, $destinationPublicId)) {
                throw new HttpException(422, 'stock_document_destination_invalid', 'A transfer requires a different destination location.');
            }
        } elseif ($destinationPublicId !== null) {
            throw new HttpException(422, 'stock_document_destination_not_allowed', 'Only transfer documents can have a destination location.');
        }
        $supplierPublicId = $payload['supplier_id'] ?? null;
        if ($type === 'purchase_receipt' && (!is_string($supplierPublicId) || !self::isUuid($supplierPublicId))) {
            throw new HttpException(422, 'stock_document_supplier_required', 'A purchase receipt requires a supplier.');
        }
        if ($supplierPublicId !== null && (!is_string($supplierPublicId) || !self::isUuid($supplierPublicId))) {
            throw new HttpException(422, 'stock_document_supplier_invalid', 'The supplier identifier is invalid.');
        }
        $reasonValue = $payload['reason'] ?? '';
        if (!is_string($reasonValue)) throw new HttpException(422, 'stock_document_reason_invalid', 'The reason must be text.');
        $reason = trim($reasonValue);
        if (in_array($type, ['adjustment_in', 'adjustment_out', 'waste', 'return'], true) && $reason === '') {
            throw new HttpException(422, 'stock_document_reason_required', 'A reason is required for adjustments and waste.');
        }
        if (self::unicodeLength($reason) > 500) throw new HttpException(422, 'stock_document_reason_invalid', 'The reason must not exceed 500 characters.');
        $reason = $reason === '' ? null : $reason;

        $fingerprint = hash('sha256', json_encode(
            [$type, $locationPublicId, $destinationPublicId, $supplierPublicId, $sourceDocumentPublicId, $reason],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        $publicId = self::uuidV4();
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-document.create', $key, $fingerprint,
            function (PDO $db) use (
                $actor, $scope, $branchId, $type, $locationPublicId, $destinationPublicId,
                $supplierPublicId, $sourceDocumentPublicId, $reason, $publicId, $requestId, $ip,
            ): array {
                $sourceDocumentId = null;
                if ($type === 'return') {
                    $sourceDocument = $this->documents->findPostedReturnSourceForUpdate(
                        $scope->businessId, $branchId, (string) $sourceDocumentPublicId,
                    );
                    if ($sourceDocument === null) {
                        throw new HttpException(409, 'stock_return_source_invalid', 'The source must be a posted outbound document in this branch with supported historical valuation.');
                    }
                    $sourceDocumentId = (int) $sourceDocument['id'];
                    $locationId = (int) $sourceDocument['location_id'];
                } else {
                    $locationId = $this->documents->findLocationId($scope->businessId, $branchId, (string) $locationPublicId);
                    if ($locationId === null) throw new HttpException(404, 'stock_document_location_not_found', 'The active location was not found in this branch.');
                }
                $destinationId = null;
                if ($destinationPublicId !== null) {
                    $destinationId = $this->documents->findLocationId($scope->businessId, $branchId, $destinationPublicId);
                    if ($destinationId === null) throw new HttpException(404, 'stock_document_destination_not_found', 'The active destination was not found in this branch.');
                }
                $supplierId = null;
                if ($supplierPublicId !== null) {
                    $supplierId = $this->documents->findSupplierId($scope->businessId, $supplierPublicId);
                    if ($supplierId === null) throw new HttpException(404, 'stock_document_supplier_not_found', 'The active supplier was not found in this business.');
                }
                $documentNo = $this->documents->nextDocumentNumber($scope->businessId, $branchId);
                $this->documents->create(
                    $scope->businessId, $branchId, $publicId, $documentNo, $type,
                    $locationId, $destinationId, $supplierId, $reason, $actor->userId, $sourceDocumentId,
                );
                $after = [
                    'document_no' => $documentNo, 'document_type' => $type, 'status' => 'draft',
                    'location_id' => $type === 'return' ? (string) $sourceDocument['location_public_id'] : $locationPublicId, 'destination_location_id' => $destinationPublicId,
                    'supplier_id' => $supplierPublicId, 'source_document_id' => $sourceDocumentPublicId, 'reason' => $reason,
                ];
                $this->audit->record(
                    $actor->userId, 'inventory.stock_document.created', 'stock_document',
                    $publicId, null, $after, null, $requestId, $ip, $scope->businessId, $branchId,
                );
                return ['status' => 201, 'body' => [
                    'data' => ['stock_document' => ['id' => $publicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }


    public function addLine(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $documentPublicId,
        array $payload, string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::branch($scope);
        if (!self::isUuid($documentPublicId)) {
            throw new HttpException(422, 'stock_document_id_invalid', 'The stock document identifier is invalid.');
        }
        $stockItemPublicId = $payload['stock_item_id'] ?? null;
        $unitCode = $payload['unit_code'] ?? null;
        $sourceLinePublicId = $payload['source_document_line_id'] ?? null;
        if ($sourceLinePublicId !== null && (!is_string($sourceLinePublicId) || !self::isUuid($sourceLinePublicId))) {
            throw new HttpException(422, 'stock_return_source_line_invalid', 'A valid source_document_line_id is required.');
        }
        $quantity = $payload['quantity'] ?? null;
        if ($sourceLinePublicId === null && (!is_string($stockItemPublicId) || !self::isUuid($stockItemPublicId)
            || !is_string($unitCode) || !preg_match('/^[a-z0-9._-]{1,32}$/', $unitCode))) {
            throw new HttpException(422, 'stock_document_line_reference_invalid', 'A valid stock_item_id and unit_code are required.');
        }
        if (is_int($quantity)) $quantity = (string) $quantity;
        if (!is_string($quantity) || !preg_match('/^\\d{1,12}(?:\\.\\d{1,6})?$/', $quantity)
            || trim(str_replace('.', '', $quantity), '0') === '') {
            throw new HttpException(422, 'stock_document_line_quantity_invalid', 'Quantity must be a positive decimal with up to 6 decimal places.');
        }
        $noteValue = $payload['note'] ?? '';
        if (!is_string($noteValue)) throw new HttpException(422, 'stock_document_line_note_invalid', 'The line note must be text.');
        $note = trim($noteValue);
        if (self::unicodeLength($note) > 500) {
            throw new HttpException(422, 'stock_document_line_note_invalid', 'The line note must not exceed 500 characters.');
        }
        $note = $note === '' ? null : $note;
        $hasCostAmount = array_key_exists('total_cost_amount', $payload);
        $totalCostAmount = self::normalizeTotalCost($payload['total_cost_amount'] ?? '0');
        $linePublicId = self::uuidV4();
        $fingerprint = hash('sha256', json_encode(
            [$documentPublicId, $stockItemPublicId, $sourceLinePublicId, $quantity, $unitCode, $totalCostAmount, $note],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-document.line.create', $key, $fingerprint,
            function (PDO $db) use (
                $actor, $scope, $branchId, $documentPublicId, $stockItemPublicId, $sourceLinePublicId,
                $unitCode, $quantity, $note, $linePublicId, $hasCostAmount, $totalCostAmount, $requestId, $ip,
            ): array {
                $document = $this->documents->findDraftForUpdate($scope->businessId, $branchId, $documentPublicId);
                if ($document === null) {
                    throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
                }
                if ((string) $document['status'] !== 'draft') {
                    throw new HttpException(409, 'stock_document_not_draft', 'Lines can only be added to a draft stock document.');
                }
                $documentType = (string) $document['document_type'];
                $sourceDocumentLineId = null;
                if ($documentType === 'return') {
                    if ($sourceLinePublicId === null || (int) ($document['source_document_id'] ?? 0) < 1) {
                        throw new HttpException(422, 'stock_return_source_line_required', 'A return line must reference an exact source document line.');
                    }
                    if ($hasCostAmount || $totalCostAmount !== '0' || $stockItemPublicId !== null || $unitCode !== null) {
                        throw new HttpException(422, 'stock_return_cost_or_item_override_not_allowed', 'Return item, unit and cost are inherited from the source line and cannot be overridden.');
                    }
                    $sourceDocument = $this->documents->findPostedReturnSourceByIdForUpdate(
                        $scope->businessId, $branchId, (int) $document['source_document_id'],
                    );
                    if ($sourceDocument === null || (int) $sourceDocument['location_id'] !== (int) $document['location_id']) {
                        throw new HttpException(409, 'stock_return_source_invalid', 'The linked source document is no longer eligible for return.');
                    }
                    $sourceLine = $this->documents->findReturnSourceLineForUpdate(
                        $scope->businessId, $branchId, (int) $document['source_document_id'], $sourceLinePublicId,
                    );
                    if ($sourceLine === null) {
                        throw new HttpException(409, 'stock_return_source_line_invalid', 'The source line is not an eligible posted outbound line with historical valuation.');
                    }
                    if (!$this->documents->canAddReturnQuantity(
                        $scope->businessId, $branchId, (int) $sourceLine['id'], (int) $document['id'],
                        $quantity, (string) $sourceLine['conversion_factor_snapshot'],
                    )) {
                        throw new HttpException(409, 'stock_return_quantity_exceeded', 'The return quantity exceeds the remaining quantity of the source line.');
                    }
                    $item = [
                        'id' => (int) $sourceLine['stock_item_id'],
                        'public_id' => (string) $sourceLine['stock_item_public_id'],
                    ];
                    $unit = ['id' => (int) $sourceLine['unit_id'], 'code' => (string) $sourceLine['unit_code']];
                    $factor = (string) $sourceLine['conversion_factor_snapshot'];
                    $sourceDocumentLineId = (int) $sourceLine['id'];
                } else {
                    if ($sourceLinePublicId !== null) {
                        throw new HttpException(422, 'stock_return_source_line_not_allowed', 'Only return lines can reference a source document line.');
                    }
                    $costRequired = in_array($documentType, ['purchase_receipt', 'adjustment_in'], true);
                    if ($costRequired && !$hasCostAmount) {
                        throw new HttpException(422, 'stock_document_line_cost_required', 'This document type requires a total_cost_amount in TOMAN.');
                    }
                    if (!$costRequired && $totalCostAmount !== '0') {
                        throw new HttpException(422, 'stock_document_line_cost_not_allowed', 'Cost input is only accepted for purchase receipts and inbound adjustments.');
                    }
                    $item = $this->documents->findStockItemForLine($scope->businessId, (string) $stockItemPublicId);
                    if ($item === null) throw new HttpException(404, 'stock_item_not_found', 'The active stock item was not found in this business.');
                    $unit = $this->documents->findUnitForLine((string) $unitCode);
                    if ($unit === null) throw new HttpException(422, 'stock_document_line_unit_not_found', 'The selected unit does not exist.');
                    if ((string) $unit['dimension'] !== (string) $item['base_dimension']) {
                        throw new HttpException(422, 'stock_document_line_unit_dimension_mismatch', 'The selected unit does not match the stock item base unit dimension.');
                    }
                    $factor = $this->documents->findConversionFactor((int) $unit['id'], (int) $item['base_unit_id']);
                    if ($factor === null || trim(str_replace('.', '', $factor), '0') === '') {
                        throw new HttpException(422, 'stock_document_line_conversion_missing', 'No valid conversion exists from the selected unit to the stock item base unit.');
                    }
                }
                $this->documents->addLine(
                    $scope->businessId, $branchId, (int) $document['id'], $linePublicId, (int) $item['id'],
                    $quantity, (int) $unit['id'], $factor, $totalCostAmount, $note, $sourceDocumentLineId,
                );
                $after = [
                    'id' => $linePublicId,
                    'document_no' => (string) $document['document_no'],
                    'stock_item_id' => $item['public_id'], 'quantity' => $quantity,
                    'unit_code' => $unit['code'], 'source_document_line_id' => $sourceLinePublicId, 'conversion_factor_snapshot' => $factor,
                    'total_cost_amount' => $totalCostAmount, 'currency' => 'TOMAN',
                    'note' => $note,
                ];
                $this->audit->record(
                    $actor->userId, 'inventory.stock_document.line.created', 'stock_document',
                    $documentPublicId, null, $after, null, $requestId, $ip,
                    $scope->businessId, $branchId,
                );
                return ['status' => 201, 'body' => [
                    'data' => ['stock_document_line' => $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }


    public function updateLine(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $documentPublicId,
        string $linePublicId, array $payload, string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::branch($scope);
        if (!self::isUuid($documentPublicId) || !self::isUuid($linePublicId)) {
            throw new HttpException(422, 'stock_document_line_id_invalid', 'The document or line identifier is invalid.');
        }
        $stockItemPublicId = $payload['stock_item_id'] ?? null;
        $unitCode = $payload['unit_code'] ?? null;
        $quantity = $payload['quantity'] ?? null;
        if (!is_string($stockItemPublicId) || !self::isUuid($stockItemPublicId)
            || !is_string($unitCode) || !preg_match('/^[a-z0-9._-]{1,32}$/', $unitCode)) {
            throw new HttpException(422, 'stock_document_line_reference_invalid', 'A valid stock_item_id and unit_code are required.');
        }
        if (is_int($quantity)) $quantity = (string) $quantity;
        if (!is_string($quantity) || !preg_match('/^\\d{1,12}(?:\\.\\d{1,6})?$/', $quantity)
            || trim(str_replace('.', '', $quantity), '0') === '') {
            throw new HttpException(422, 'stock_document_line_quantity_invalid', 'Quantity must be a positive decimal with up to 6 decimal places.');
        }
        $noteValue = $payload['note'] ?? '';
        if (!is_string($noteValue)) throw new HttpException(422, 'stock_document_line_note_invalid', 'The line note must be text.');
        $note = trim($noteValue);
        if (self::unicodeLength($note) > 500) {
            throw new HttpException(422, 'stock_document_line_note_invalid', 'The line note must not exceed 500 characters.');
        }
        $note = $note === '' ? null : $note;
        $hasCostAmount = array_key_exists('total_cost_amount', $payload);
        $requestedCostAmount = $hasCostAmount ? self::normalizeTotalCost($payload['total_cost_amount']) : null;
        $fingerprint = hash('sha256', json_encode(
            [$documentPublicId, $linePublicId, $stockItemPublicId, $quantity, $unitCode, $requestedCostAmount, $note],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-document.line.update', $key, $fingerprint,
            function (PDO $db) use (
                $actor, $scope, $branchId, $documentPublicId, $linePublicId,
                $stockItemPublicId, $unitCode, $quantity, $note, $hasCostAmount, $requestedCostAmount, $requestId, $ip,
            ): array {
                $document = $this->documents->findDraftForUpdate($scope->businessId, $branchId, $documentPublicId);
                if ($document === null) throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
                if ((string) $document['status'] !== 'draft') {
                    throw new HttpException(409, 'stock_document_not_draft', 'Only draft document lines can be updated.');
                }
                if ((string) $document['document_type'] === 'return') {
                    throw new HttpException(409, 'stock_return_line_inherited', 'Return line item, unit, source and valuation are inherited from the source and cannot be edited; delete and recreate the draft line if needed.');
                }
                $line = $this->documents->findLineForUpdate(
                    $scope->businessId, $branchId, (int) $document['id'], $linePublicId,
                );
                if ($line === null) throw new HttpException(404, 'stock_document_line_not_found', 'The stock document line was not found.');
                $costRequired = in_array((string) $document['document_type'], ['purchase_receipt', 'adjustment_in'], true);
                $totalCostAmount = $hasCostAmount ? (string) $requestedCostAmount : (string) $line['total_cost_amount'];
                if (!$costRequired && $hasCostAmount && $totalCostAmount !== '0') {
                    throw new HttpException(422, 'stock_document_line_cost_not_allowed', 'Cost input is only accepted for purchase receipts and inbound adjustments.');
                }
                $item = $this->documents->findStockItemForLine($scope->businessId, $stockItemPublicId);
                if ($item === null) throw new HttpException(404, 'stock_item_not_found', 'The active stock item was not found in this business.');
                $unit = $this->documents->findUnitForLine($unitCode);
                if ($unit === null || (string) $unit['dimension'] !== (string) $item['base_dimension']) {
                    throw new HttpException(422, 'stock_document_line_unit_invalid', 'The selected unit is missing or has a different dimension.');
                }
                $factor = $this->documents->findConversionFactor((int) $unit['id'], (int) $item['base_unit_id']);
                if ($factor === null || trim(str_replace('.', '', $factor), '0') === '') {
                    throw new HttpException(422, 'stock_document_line_conversion_missing', 'No valid conversion exists from the selected unit to the stock item base unit.');
                }
                $before = [
                    'stock_item_id' => (string) $line['stock_item_public_id'],
                    'quantity' => (string) $line['quantity'], 'unit_code' => (string) $line['unit_code'],
                    'conversion_factor_snapshot' => (string) $line['conversion_factor_snapshot'],
                    'base_quantity' => (string) $line['base_quantity'],
                    'total_cost_amount' => (string) $line['total_cost_amount'],
                    'note' => $line['note'] === null ? null : (string) $line['note'],
                ];
                $this->documents->updateLine(
                    $scope->businessId, $branchId, (int) $document['id'], (int) $line['id'],
                    (int) $item['id'], $quantity, (int) $unit['id'], $factor, $totalCostAmount, $note,
                );
                $after = [
                    'id' => $linePublicId, 'stock_item_id' => $stockItemPublicId,
                    'quantity' => $quantity, 'unit_code' => $unitCode,
                    'conversion_factor_snapshot' => $factor, 'total_cost_amount' => $totalCostAmount,
                    'currency' => 'TOMAN', 'note' => $note,
                ];
                $this->audit->record(
                    $actor->userId, 'inventory.stock_document.line.updated', 'stock_document',
                    $documentPublicId, $before, $after, null, $requestId, $ip,
                    $scope->businessId, $branchId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['stock_document_line' => $after], 'meta' => (object) [],
                ]];
            },
        );
    }

    public function deleteLine(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $documentPublicId,
        string $linePublicId, string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::branch($scope);
        if (!self::isUuid($documentPublicId) || !self::isUuid($linePublicId)) {
            throw new HttpException(422, 'stock_document_line_id_invalid', 'The document or line identifier is invalid.');
        }
        $fingerprint = hash('sha256', json_encode(
            [$documentPublicId, $linePublicId], JSON_THROW_ON_ERROR,
        ));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-document.line.delete', $key, $fingerprint,
            function (PDO $db) use (
                $actor, $scope, $branchId, $documentPublicId, $linePublicId, $requestId, $ip,
            ): array {
                $document = $this->documents->findDraftForUpdate($scope->businessId, $branchId, $documentPublicId);
                if ($document === null) throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
                if ((string) $document['status'] !== 'draft') {
                    throw new HttpException(409, 'stock_document_not_draft', 'Only draft document lines can be deleted.');
                }
                $line = $this->documents->findLineForUpdate(
                    $scope->businessId, $branchId, (int) $document['id'], $linePublicId,
                );
                if ($line === null) throw new HttpException(404, 'stock_document_line_not_found', 'The stock document line was not found.');
                $before = [
                    'id' => $linePublicId, 'stock_item_id' => (string) $line['stock_item_public_id'],
                    'quantity' => (string) $line['quantity'], 'unit_code' => (string) $line['unit_code'],
                    'conversion_factor_snapshot' => (string) $line['conversion_factor_snapshot'],
                    'base_quantity' => (string) $line['base_quantity'],
                    'total_cost_amount' => (string) $line['total_cost_amount'],
                    'note' => $line['note'] === null ? null : (string) $line['note'],
                ];
                $this->documents->deleteLine(
                    $scope->businessId, $branchId, (int) $document['id'], (int) $line['id'],
                );
                $this->audit->record(
                    $actor->userId, 'inventory.stock_document.line.deleted', 'stock_document',
                    $documentPublicId, $before, null, null, $requestId, $ip,
                    $scope->businessId, $branchId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['deleted_line_id' => $linePublicId], 'meta' => (object) [],
                ]];
            },
        );
    }

    private static function normalizeTotalCost(mixed $value): string
    {
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $value)) {
            throw new HttpException(422, 'stock_document_line_cost_invalid', 'total_cost_amount must be a non-negative integer TOMAN amount.');
        }
        $maximum = '9223372036854775807';
        if (strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)) {
            throw new HttpException(422, 'stock_document_line_cost_invalid', 'total_cost_amount exceeds the supported range.');
        }
        return $value;
    }

    private static function branch(BusinessScope $scope): int
    {
        if ($scope->branchId === null || $scope->branchId < 1) {
            throw new HttpException(400, 'branch_context_required', 'A valid branch context is required.');
        }
        return $scope->branchId;
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

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }
}
