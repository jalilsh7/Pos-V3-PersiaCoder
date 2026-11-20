<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Inventory;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\InventoryDocumentRepository;
use PersiaCoder\PosV3\Infrastructure\Database\InventoryPostingRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class InventoryPostingService
{
    private InventoryDocumentRepository $documents;
    private InventoryPostingRepository $posting;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->documents = new InventoryDocumentRepository($connection);
        $this->posting = new InventoryPostingRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    /** @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function post(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $documentPublicId,
        string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::branch($scope);
        if (!self::isUuid($documentPublicId)) {
            throw new HttpException(422, 'stock_document_id_invalid', 'The stock document identifier is invalid.');
        }
        $fingerprint = hash('sha256', json_encode([$documentPublicId], JSON_THROW_ON_ERROR));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-document.post', $key, $fingerprint,
            function (PDO $db) use ($actor, $scope, $branchId, $documentPublicId, $requestId, $ip): array {
                $document = $this->documents->findDraftForUpdate(
                    $scope->businessId, $branchId, $documentPublicId,
                );
                if ($document === null) {
                    throw new HttpException(404, 'stock_document_not_found', 'The stock document was not found.');
                }
                if ((string) $document['status'] !== 'draft') {
                    throw new HttpException(409, 'stock_document_not_draft', 'Only draft stock documents can be posted.');
                }

                $type = (string) $document['document_type'];
                if ($type === 'purchase_receipt') {
                    $supplierId = (int) ($document['supplier_id'] ?? 0);
                    if ($supplierId < 1 || !$this->documents->supplierIsActive($scope->businessId, $supplierId)) {
                        throw new HttpException(409, 'stock_document_supplier_inactive', 'The purchase receipt supplier is no longer active in this business.');
                    }
                }
                if ($type === 'return') {
                    throw new HttpException(409, 'stock_document_type_not_postable', 'Return posting requires a source-linked return valuation policy and is not enabled yet.');
                }
                $supportedTypes = [
                    'purchase_receipt', 'transfer', 'adjustment_in',
                    'adjustment_out', 'consumption', 'waste',
                ];
                if (!in_array($type, $supportedTypes, true)) {
                    throw new HttpException(409, 'stock_document_type_not_postable', 'This stock document type is not enabled for posting.');
                }

                $lines = $this->documents->listLinesForPostingForUpdate(
                    $scope->businessId, $branchId, (int) $document['id'],
                );
                if ($lines === []) {
                    throw new HttpException(409, 'stock_document_empty', 'A stock document must contain at least one line before posting.');
                }

                $sourceLocationId = (int) $document['location_id'];
                $destinationLocationId = $type === 'transfer'
                    ? (int) ($document['destination_location_id'] ?? 0)
                    : 0;
                if ($sourceLocationId < 1 || ($type === 'transfer' && $destinationLocationId < 1)) {
                    throw new HttpException(409, 'stock_document_location_invalid', 'The source or destination location is invalid.');
                }
                if (!$this->posting->locationIsActive($scope->businessId, $branchId, $sourceLocationId)
                    || ($type === 'transfer' && !$this->posting->locationIsActive($scope->businessId, $branchId, $destinationLocationId))) {
                    throw new HttpException(409, 'stock_location_inactive', 'A source or destination stock location is inactive or outside the current branch.');
                }
                $baseUnitIds = [];
                foreach ($lines as $line) {
                    $itemId = (int) $line['stock_item_id'];
                    if (isset($baseUnitIds[$itemId])) continue;
                    $stockItem = $this->posting->stockItemForPosting($scope->businessId, $itemId);
                    if ($stockItem === null || (int) $stockItem['is_active'] !== 1) {
                        throw new HttpException(409, 'stock_item_inactive', 'A stock item in this document is inactive or outside the current business.');
                    }
                    $baseUnitIds[$itemId] = (int) $stockItem['base_unit_id'];
                }

                // Lock all affected item/location balances in a stable order to reduce deadlocks.
                $pairs = [];
                foreach ($lines as $line) {
                    $itemId = (int) $line['stock_item_id'];
                    $pairs[$itemId . ':' . $sourceLocationId] = [$itemId, $sourceLocationId];
                    if ($type === 'transfer') {
                        $pairs[$itemId . ':' . $destinationLocationId] = [$itemId, $destinationLocationId];
                    }
                }
                $pairs = array_values($pairs);
                usort($pairs, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
                foreach ($pairs as [$itemId, $locationId]) {
                    $this->posting->lockBalance($scope->businessId, $branchId, $itemId, $locationId);
                }

                $outgoingTypes = [
                    'adjustment_out' => 'adjustment_out',
                    'consumption' => 'consumption_out',
                    'waste' => 'waste_out',
                ];
                foreach ($lines as $line) {
                    $lineId = (int) $line['id'];
                    $linePublicId = (string) $line['public_id'];
                    $itemId = (int) $line['stock_item_id'];
                    $baseUnitId = $baseUnitIds[$itemId];
                    $quantity = (string) $line['base_quantity'];
                    $sourceBalance = $this->posting->lockBalance(
                        $scope->businessId, $branchId, $itemId, $sourceLocationId,
                    );

                    if (in_array($type, ['purchase_receipt', 'adjustment_in'], true)) {
                        $totalCost = (string) $line['total_cost_amount'];
                        $unitCost = (string) $line['unit_cost_snapshot'];
                        $averageCost = $this->posting->weightedAverageFromTotalCost(
                            $sourceBalance['quantity_on_hand'],
                            $sourceBalance['average_unit_cost'],
                            $quantity,
                            $totalCost,
                        );
                        $this->posting->receiveBalance(
                            $scope->businessId, $branchId, $itemId, $sourceLocationId,
                            $quantity, $averageCost,
                        );
                        $movementType = $type === 'purchase_receipt' ? 'purchase_in' : 'adjustment_in';
                        $this->posting->appendLedger(
                            $scope->businessId, $branchId, $itemId, $sourceLocationId,
                            $lineId, $movementType, $quantity, $baseUnitId, $unitCost,
                            $totalCost, self::eventKey($documentPublicId, $linePublicId, $movementType),
                        );
                        $this->documents->updateLineValuation(
                            $scope->businessId, $branchId, (int) $document['id'], $lineId,
                            $unitCost, $totalCost,
                        );
                        continue;
                    }

                    if ($type === 'transfer') {
                        $unitCost = $sourceBalance['average_unit_cost'];
                        $cost = self::requireLedgerAmount($this->posting->costForQuantity($quantity, $unitCost));
                        if (!$this->posting->issueBalance(
                            $scope->businessId, $branchId, $itemId, $sourceLocationId, $quantity,
                        )) {
                            throw new HttpException(409, 'stock_insufficient', 'Available stock is insufficient; reserved quantities cannot be transferred.');
                        }
                        $destinationBalance = $this->posting->lockBalance(
                            $scope->businessId, $branchId, $itemId, $destinationLocationId,
                        );
                        $destinationAverage = $this->posting->weightedAverageFromUnitCost(
                            $destinationBalance['quantity_on_hand'],
                            $destinationBalance['average_unit_cost'],
                            $quantity,
                            $unitCost,
                        );
                        $this->posting->receiveBalance(
                            $scope->businessId, $branchId, $itemId, $destinationLocationId,
                            $quantity, $destinationAverage,
                        );
                        $this->posting->appendLedger(
                            $scope->businessId, $branchId, $itemId, $sourceLocationId,
                            $lineId, 'transfer_out', '-' . $quantity, $baseUnitId,
                            $unitCost, '-' . $cost,
                            self::eventKey($documentPublicId, $linePublicId, 'transfer_out'),
                        );
                        $this->posting->appendLedger(
                            $scope->businessId, $branchId, $itemId, $destinationLocationId,
                            $lineId, 'transfer_in', $quantity, $baseUnitId,
                            $unitCost, $cost,
                            self::eventKey($documentPublicId, $linePublicId, 'transfer_in'),
                        );
                        $this->documents->updateLineValuation(
                            $scope->businessId, $branchId, (int) $document['id'], $lineId,
                            $unitCost, $cost,
                        );
                        continue;
                    }

                    $unitCost = $sourceBalance['average_unit_cost'];
                    $cost = $this->posting->costForQuantity($quantity, $unitCost);
                    if (!$this->posting->issueBalance(
                        $scope->businessId, $branchId, $itemId, $sourceLocationId, $quantity,
                    )) {
                        throw new HttpException(409, 'stock_insufficient', 'Available stock is insufficient; reserved quantities cannot be consumed or written off.');
                    }
                    $movementType = $outgoingTypes[$type];
                    $this->posting->appendLedger(
                        $scope->businessId, $branchId, $itemId, $sourceLocationId,
                        $lineId, $movementType, '-' . $quantity, $baseUnitId,
                        $unitCost, '-' . $cost,
                        self::eventKey($documentPublicId, $linePublicId, $movementType),
                    );
                    $this->documents->updateLineValuation(
                        $scope->businessId, $branchId, (int) $document['id'], $lineId,
                        $unitCost, $cost,
                    );
                }

                $this->documents->markPosted($scope->businessId, $branchId, (int) $document['id']);
                $this->audit->record(
                    $actor->userId, 'inventory.stock_document.posted', 'stock_document',
                    $documentPublicId,
                    ['status' => 'draft', 'document_type' => $type],
                    ['status' => 'posted', 'document_type' => $type, 'line_count' => count($lines)],
                    null, $requestId, $ip, $scope->businessId, $branchId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['stock_document' => [
                        'id' => $documentPublicId,
                        'document_no' => (string) $document['document_no'],
                        'document_type' => $type,
                        'status' => 'posted',
                        'line_count' => count($lines),
                    ]],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    private static function requireLedgerAmount(string $amount): string
    {
        $maximum = '9223372036854775807';
        if (!preg_match('/^(?:0|[1-9][0-9]{0,19})$/', $amount)
            || strlen($amount) > strlen($maximum)
            || (strlen($amount) === strlen($maximum) && strcmp($amount, $maximum) > 0)) {
            throw new HttpException(409, 'stock_cost_amount_overflow', 'The calculated stock movement cost exceeds the supported TOMAN range.');
        }
        return $amount;
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
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    private static function eventKey(string $documentPublicId, string $linePublicId, string $movementType): string
    {
        return 'stockdoc:' . $documentPublicId . ':' . $linePublicId . ':' . $movementType;
    }
}
