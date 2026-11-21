<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\InventoryDocumentRepository;
use PersiaCoder\PosV3\Inventory\InventoryDocumentService;
use PersiaCoder\PosV3\Inventory\InventoryPostingService;

$root = dirname(__DIR__);
require_once $root . '/database/v3/bin/bootstrap.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$pdo = PosV3DatabaseTools::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$uuid = static fn (): string => sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
);
$prefix = 'ci' . bin2hex(random_bytes(5));
$businessPublicId = $uuid();
$branchPublicId = $uuid();
$userPublicId = $uuid();
$stockItemPublicId = $uuid();
$sourceLocationPublicId = $uuid();
$destinationLocationPublicId = $uuid();
$supplierPublicId = $uuid();

$insert = $pdo->prepare(
    'INSERT INTO pos_businesses (public_id, code, name) VALUES (:public_id, :code, :name)'
);
$insert->execute(['public_id' => $businessPublicId, 'code' => $prefix, 'name' => 'CI Inventory Business']);
$businessId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO pos_branches (public_id, business_id, code, name) VALUES (:public_id, :business_id, :code, :name)'
);
$insert->execute([
    'public_id' => $branchPublicId, 'business_id' => $businessId,
    'code' => $prefix . '-b', 'name' => 'CI Inventory Branch',
]);
$branchId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO app_users (public_id, username, password_hash, display_name, is_active, must_change_password)
     VALUES (:public_id, :username, :password_hash, :display_name, 1, 0)'
);
$insert->execute([
    'public_id' => $userPublicId, 'username' => $prefix,
    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'display_name' => 'CI Inventory Actor',
]);
$userId = (int) $pdo->lastInsertId();

$unit = $pdo->query("SELECT id FROM pos_units WHERE code = 'kg'")->fetchColumn();
if ($unit === false) throw new RuntimeException('The kg base unit is missing after migrations.');

$insert = $pdo->prepare(
    'INSERT INTO pos_stock_items (business_id, public_id, code, name, base_unit_id, is_active, negative_stock_policy)
     VALUES (:business_id, :public_id, :code, :name, :base_unit_id, 1, \'block\')'
);
$insert->execute([
    'business_id' => $businessId, 'public_id' => $stockItemPublicId,
    'code' => $prefix, 'name' => 'CI Test Flour', 'base_unit_id' => (int) $unit,
]);
$stockItemId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO pos_stock_locations (business_id, branch_id, public_id, code, name, location_type, is_active)
     VALUES (:business_id, :branch_id, :public_id, :code, :name, \'storage\', 1)'
);
$insert->execute([
    'business_id' => $businessId, 'branch_id' => $branchId,
    'public_id' => $sourceLocationPublicId, 'code' => $prefix . '-src', 'name' => 'CI Source',
]);
$sourceLocationId = (int) $pdo->lastInsertId();
$insert->execute([
    'business_id' => $businessId, 'branch_id' => $branchId,
    'public_id' => $destinationLocationPublicId, 'code' => $prefix . '-dst', 'name' => 'CI Destination',
]);
$destinationLocationId = (int) $pdo->lastInsertId();

$insert = $pdo->prepare(
    'INSERT INTO pos_suppliers (business_id, public_id, name, is_active)
     VALUES (:business_id, :public_id, :name, 1)'
);
$insert->execute([
    'business_id' => $businessId, 'public_id' => $supplierPublicId, 'name' => 'CI Supplier',
]);

$scope = new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);
$actor = new AuthenticatedPrincipal(
    1, $userId, $userPublicId, $prefix, 'CI Inventory Actor', false,
    hash('sha256', str_repeat('c', 43)), new DateTimeImmutable('+1 hour'),
);
$appKey = str_repeat('ci-inventory-posting-key-', 2);
$documents = new InventoryDocumentService($pdo, $appKey);
$posting = new InventoryPostingService($pdo, $appKey);
$requestId = 'ci-inv-' . bin2hex(random_bytes(4));
$ip = '127.0.0.1';

$createDocument = static function (array $payload, string $suffix) use (
    $documents, $actor, $scope, $requestId, $ip, $prefix, $assert,
): string {
    $result = $documents->create($actor, $scope, $payload, $prefix . '-doc-' . $suffix . '-key', $requestId, $ip);
    $assert($result['status'] === 201, 'Draft document creation failed.');
    return (string) $result['body']['data']['stock_document']['id'];
};
$addLine = static function (string $documentId, string $quantity, ?string $totalCost, string $suffix) use (
    $documents, $actor, $scope, $stockItemPublicId, $requestId, $ip, $prefix, $assert,
): string {
    $payload = ['stock_item_id' => $stockItemPublicId, 'unit_code' => 'kg', 'quantity' => $quantity];
    if ($totalCost !== null) $payload['total_cost_amount'] = $totalCost;
    $result = $documents->addLine(
        $actor, $scope, $documentId, $payload,
        $prefix . '-line-' . $suffix . '-key', $requestId, $ip,
    );
    $assert($result['status'] === 201, 'Draft document line creation failed.');
    return (string) $result['body']['data']['stock_document_line']['id'];
};
$postDocument = static function (string $documentId, string $suffix) use (
    $posting, $actor, $scope, $requestId, $ip, $prefix,
): array {
    return $posting->post(
        $actor, $scope, $documentId, $prefix . '-post-' . $suffix . '-key', $requestId, $ip,
    );
};
$balance = static function (int $locationId) use ($pdo, $businessId, $branchId, $stockItemId): array {
    $s = $pdo->prepare(
        'SELECT quantity_on_hand, quantity_reserved, average_unit_cost
         FROM pos_stock_balances
         WHERE business_id = :business_id AND branch_id = :branch_id
           AND stock_item_id = :stock_item_id AND location_id = :location_id'
    );
    $s->execute([
        'business_id' => $businessId, 'branch_id' => $branchId,
        'stock_item_id' => $stockItemId, 'location_id' => $locationId,
    ]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Expected stock balance row is missing.');
    return $row;
};
$ledgerCount = static function () use ($pdo, $businessId, $branchId): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM pos_stock_ledger WHERE business_id = :business_id AND branch_id = :branch_id');
    $s->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
    return (int) $s->fetchColumn();
};

$receiptOne = $createDocument([
    'document_type' => 'purchase_receipt', 'location_id' => $sourceLocationPublicId,
    'supplier_id' => $supplierPublicId,
], 'receipt-one');
$receiptOneLine = $addLine($receiptOne, '2', '3000', 'receipt-one');
$postOne = $postDocument($receiptOne, 'receipt-one');
$assert($postOne['body']['data']['stock_document']['status'] === 'posted', 'First purchase receipt did not post.');
$firstBalance = $balance($sourceLocationId);
$assert($firstBalance['quantity_on_hand'] === '2.000000', 'First receipt quantity is incorrect.');
$assert($firstBalance['average_unit_cost'] === '1500.00000000', 'First receipt weighted cost is incorrect.');

$receiptTwo = $createDocument([
    'document_type' => 'purchase_receipt', 'location_id' => $sourceLocationPublicId,
    'supplier_id' => $supplierPublicId,
], 'receipt-two');
$receiptTwoLine = $addLine($receiptTwo, '2', '5000', 'receipt-two');
$postTwo = $postDocument($receiptTwo, 'receipt-two');
$assert($postTwo['body']['data']['stock_document']['status'] === 'posted', 'Second purchase receipt did not post.');
$secondBalance = $balance($sourceLocationId);
$assert($secondBalance['quantity_on_hand'] === '4.000000', 'Second receipt quantity is incorrect.');
$assert($secondBalance['average_unit_cost'] === '2000.00000000', 'Moving weighted average cost is incorrect.');

$otherBranchPublicId = $uuid();
$insertBranch = $pdo->prepare(
    'INSERT INTO pos_branches (public_id, business_id, code, name) VALUES (:public_id, :business_id, :code, :name)'
);
$insertBranch->execute([
    'public_id' => $otherBranchPublicId, 'business_id' => $businessId,
    'code' => $prefix . '-other-branch', 'name' => 'CI Other Branch',
]);
$otherBranchId = (int) $pdo->lastInsertId();
$otherBranchScope = new BusinessScope($businessId, $businessPublicId, $otherBranchId, $otherBranchPublicId);
$branchIsolationBlocked = false;
try {
    $documents->get($otherBranchScope, $receiptTwo);
} catch (HttpException $error) {
    $branchIsolationBlocked = str_contains($error->getMessage(), 'not found');
}
$assert($branchIsolationBlocked, 'Document detail leaked across branch scope.');

$otherBusinessPublicId = $uuid();
$insert = $pdo->prepare('INSERT INTO pos_businesses (public_id, code, name) VALUES (:public_id, :code, :name)');
$insert->execute([
    'public_id' => $otherBusinessPublicId, 'code' => $prefix . '-other-business',
    'name' => 'CI Other Business',
]);
$otherBusinessId = (int) $pdo->lastInsertId();
$otherBusinessBranchPublicId = $uuid();
$insertBranch->execute([
    'public_id' => $otherBusinessBranchPublicId, 'business_id' => $otherBusinessId,
    'code' => $prefix . '-other-business-branch', 'name' => 'CI Other Business Branch',
]);
$otherBusinessBranchId = (int) $pdo->lastInsertId();
$otherBusinessScope = new BusinessScope(
    $otherBusinessId, $otherBusinessPublicId, $otherBusinessBranchId, $otherBusinessBranchPublicId,
);
$tenantIsolationBlocked = false;
try {
    $documents->get($otherBusinessScope, $receiptTwo);
} catch (HttpException $error) {
    $tenantIsolationBlocked = str_contains($error->getMessage(), 'not found');
}
$assert($tenantIsolationBlocked, 'Document detail leaked across business scope.');

$ordinaryDetail = $documents->get($scope, $receiptTwo);
$assert(!array_key_exists('total_cost_amount', $ordinaryDetail['lines'][0]), 'General inventory document detail must not expose cost snapshots.');
$valuation = $documents->getValuation($scope, $receiptTwo);
$assert($valuation['currency'] === 'TOMAN', 'Valuation response currency is incorrect.');
$assert($valuation['lines'][0]['total_cost_amount'] === '5000', 'Protected valuation response did not return the line total cost.');
$assert($valuation['lines'][0]['unit_cost_snapshot'] === '2500.00000000', 'Protected valuation response did not return the base-unit cost snapshot.');

$replay = $posting->post(
    $actor, $scope, $receiptTwo, $prefix . '-post-receipt-two-key', $requestId, $ip,
);
$assert($replay['replayed'] === true, 'Posting retry did not replay the idempotent response.');
$assert($ledgerCount() === 2, 'Idempotent posting retry created duplicate ledger rows.');

$consumption = $createDocument([
    'document_type' => 'consumption', 'location_id' => $sourceLocationPublicId,
], 'consumption');
$addLine($consumption, '1', null, 'consumption');
$postDocument($consumption, 'consumption');
$afterConsumption = $balance($sourceLocationId);
$assert($afterConsumption['quantity_on_hand'] === '3.000000', 'Consumption did not reduce on-hand quantity.');
$assert($afterConsumption['average_unit_cost'] === '2000.00000000', 'Consumption unexpectedly changed weighted average cost.');

$transfer = $createDocument([
    'document_type' => 'transfer', 'location_id' => $sourceLocationPublicId,
    'destination_location_id' => $destinationLocationPublicId,
], 'transfer');
$transferLine = $addLine($transfer, '1', null, 'transfer');
$postDocument($transfer, 'transfer');
$sourceAfterTransfer = $balance($sourceLocationId);
$destinationAfterTransfer = $balance($destinationLocationId);
$assert($sourceAfterTransfer['quantity_on_hand'] === '2.000000', 'Transfer did not reduce source stock.');
$assert($destinationAfterTransfer['quantity_on_hand'] === '1.000000', 'Transfer did not increase destination stock.');
$assert($destinationAfterTransfer['average_unit_cost'] === '2000.00000000', 'Transfer did not preserve source weighted cost.');
$transferLedger = $pdo->prepare(
    'SELECT COUNT(*) AS row_count, SUM(quantity_delta) AS quantity_sum, SUM(total_cost_amount) AS value_sum
     FROM pos_stock_ledger
     WHERE document_line_id = (SELECT id FROM pos_stock_document_lines WHERE public_id = :line_public_id)'
);
$transferLedger->execute(['line_public_id' => $transferLine]);
$transferEvents = $transferLedger->fetch(PDO::FETCH_ASSOC);
$assert((int) $transferEvents['row_count'] === 2, 'Transfer must create exactly two ledger events.');
$assert(trim((string) $transferEvents['quantity_sum'], '0.') === '', 'Transfer ledger quantity deltas must net to zero.');
$assert((string) $transferEvents['value_sum'] === '0', 'Transfer ledger value deltas must net to zero.');

$adjustmentIn = $createDocument([
    'document_type' => 'adjustment_in', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI inbound adjustment valuation',
], 'adjustment-in');
$addLine($adjustmentIn, '1', '1000', 'adjustment-in');
$postDocument($adjustmentIn, 'adjustment-in');
$afterAdjustmentIn = $balance($sourceLocationId);
$assert($afterAdjustmentIn['quantity_on_hand'] === '3.000000', 'Inbound adjustment did not increase stock.');
$assert($afterAdjustmentIn['average_unit_cost'] === '1666.66666667', 'Inbound adjustment did not recompute weighted average.');

$adjustmentOut = $createDocument([
    'document_type' => 'adjustment_out', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI outbound adjustment valuation',
], 'adjustment-out');
$adjustmentOutLine = $addLine($adjustmentOut, '1', null, 'adjustment-out');
$postDocument($adjustmentOut, 'adjustment-out');
$afterAdjustmentOut = $balance($sourceLocationId);
$assert($afterAdjustmentOut['quantity_on_hand'] === '2.000000', 'Outbound adjustment did not reduce stock.');
$assert($afterAdjustmentOut['average_unit_cost'] === '1666.66666667', 'Outbound adjustment unexpectedly changed weighted average cost.');

$pdo->prepare(
    'UPDATE pos_stock_balances SET quantity_reserved = 1
     WHERE business_id = :business_id AND branch_id = :branch_id
       AND stock_item_id = :stock_item_id AND location_id = :location_id'
)->execute([
    'business_id' => $businessId, 'branch_id' => $branchId,
    'stock_item_id' => $stockItemId, 'location_id' => $sourceLocationId,
]);
$ledgerBeforeReservedCheck = $ledgerCount();
$reservedWaste = $createDocument([
    'document_type' => 'waste', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI reserved stock protection',
], 'reserved');
$addLine($reservedWaste, '1.5', null, 'reserved');
$reservedBlocked = false;
try {
    $postDocument($reservedWaste, 'reserved');
} catch (HttpException $error) {
    $reservedBlocked = str_contains($error->getMessage(), 'Available stock is insufficient');
}
$assert($reservedBlocked, 'Posting consumed stock reserved for another workflow.');
$assert($ledgerCount() === $ledgerBeforeReservedCheck, 'Reserved-stock rejection appended a ledger entry.');
$pdo->prepare(
    'UPDATE pos_stock_balances SET quantity_reserved = 0
     WHERE business_id = :business_id AND branch_id = :branch_id
       AND stock_item_id = :stock_item_id AND location_id = :location_id'
)->execute([
    'business_id' => $businessId, 'branch_id' => $branchId,
    'stock_item_id' => $stockItemId, 'location_id' => $sourceLocationId,
]);

$returnFieldOverrideBlocked = false;
try {
    $documents->create(
        $actor, $scope,
        ['document_type' => 'return', 'source_document_id' => $adjustmentOut,
         'location_id' => $sourceLocationPublicId, 'reason' => 'CI reject location override'],
        $prefix . '-return-location-override-key', $requestId, $ip,
    );
} catch (HttpException $error) {
    $returnFieldOverrideBlocked = $error->status === 422 && $error->errorCode === 'stock_return_source_fields_locked';
}
$assert($returnFieldOverrideBlocked, 'Return document accepted a client-supplied location override.');

$invalidReturnSourceBlocked = false;
try {
    $documents->create(
        $actor, $scope,
        ['document_type' => 'return', 'source_document_id' => $receiptTwo, 'reason' => 'CI invalid return source'],
        $prefix . '-return-invalid-source-key', $requestId, $ip,
    );
} catch (HttpException $error) {
    $invalidReturnSourceBlocked = $error->status === 409 && $error->errorCode === 'stock_return_source_invalid';
}
$assert($invalidReturnSourceBlocked, 'A non-outbound source document was accepted for return.');

$returnDocument = $createDocument([
    'document_type' => 'return', 'source_document_id' => $adjustmentOut,
    'reason' => 'CI partial return from outbound adjustment',
], 'return-partial-one');
$returnLineOverrideBlocked = false;
try {
    $documents->addLine(
        $actor, $scope, $returnDocument,
        ['source_document_line_id' => $adjustmentOutLine, 'stock_item_id' => $stockItemPublicId,
         'unit_code' => 'kg', 'quantity' => '0.4'],
        $prefix . '-line-return-override-key', $requestId, $ip,
    );
} catch (HttpException $error) {
    $returnLineOverrideBlocked = $error->status === 422 && $error->errorCode === 'stock_return_cost_or_item_override_not_allowed';
}
$assert($returnLineOverrideBlocked, 'Return line accepted an item or unit override.');
$returnLineResult = $documents->addLine(
    $actor, $scope, $returnDocument,
    ['source_document_line_id' => $adjustmentOutLine, 'quantity' => '0.4'],
    $prefix . '-line-return-partial-one-key', $requestId, $ip,
);
$assert($returnLineResult['status'] === 201, 'Source-linked partial return line could not be created.');
$returnPost = $postDocument($returnDocument, 'return-partial-one');
$assert($returnPost['body']['data']['stock_document']['status'] === 'posted', 'Source-linked return did not post.');
$returnedBalance = $balance($sourceLocationId);
$assert($returnedBalance['quantity_on_hand'] === '2.400000', 'Partial return did not increase stock by the requested base quantity.');
$assert($returnedBalance['average_unit_cost'] === '1666.66666667', 'Return did not restore the source historical unit cost.');
$returnLedger = $pdo->prepare(
    "SELECT quantity_delta, unit_cost_snapshot, total_cost_amount
     FROM pos_stock_ledger WHERE document_line_id = (
       SELECT id FROM pos_stock_document_lines WHERE public_id = :line_public_id
     ) AND movement_type = 'return_in'"
);
$returnLedger->execute(['line_public_id' => $returnLineResult['body']['data']['stock_document_line']['id']]);
$returnLedgerRow = $returnLedger->fetch(PDO::FETCH_ASSOC);
$assert($returnLedgerRow !== false && $returnLedgerRow['quantity_delta'] === '0.400000'
    && $returnLedgerRow['unit_cost_snapshot'] === '1666.66666667'
    && $returnLedgerRow['total_cost_amount'] === '667',
    'Return ledger did not preserve source-line cost valuation.');

$returnDraftOne = $createDocument([
    'document_type' => 'return', 'source_document_id' => $adjustmentOut,
    'reason' => 'CI competing partial return one',
], 'return-race-one');
$returnDraftTwo = $createDocument([
    'document_type' => 'return', 'source_document_id' => $adjustmentOut,
    'reason' => 'CI competing partial return two',
], 'return-race-two');
foreach ([[$returnDraftOne, 'return-race-one'], [$returnDraftTwo, 'return-race-two']] as [$returnDraft, $suffix]) {
    $documents->addLine(
        $actor, $scope, $returnDraft,
        ['source_document_line_id' => $adjustmentOutLine, 'quantity' => '0.4'],
        $prefix . '-line-' . $suffix . '-key', $requestId, $ip,
    );
}
$postDocument($returnDraftOne, 'return-race-one');
$ledgerBeforeOverReturn = $ledgerCount();
$overReturnBlocked = false;
try {
    $postDocument($returnDraftTwo, 'return-race-two');
} catch (HttpException $error) {
    $overReturnBlocked = str_contains($error->getMessage(), 'exceed the original outbound quantity');
}
$assert($overReturnBlocked, 'Concurrent draft returns were allowed to exceed the original source quantity.');
$assert($ledgerCount() === $ledgerBeforeOverReturn, 'Rejected over-return appended a ledger event.');
$assert($balance($sourceLocationId)['quantity_on_hand'] === '2.800000', 'Rejected over-return changed the stock balance.');

$ledgerBeforeRollback = $ledgerCount();
$sourceBeforeRollback = $balance($sourceLocationId);
$waste = $createDocument([
    'document_type' => 'waste', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI atomic rollback check',
], 'rollback');
$addLine($waste, '1', null, 'rollback-one');
$addLine($waste, '99', null, 'rollback-two');
$failed = false;
try {
    $postDocument($waste, 'rollback');
} catch (HttpException $error) {
    $failed = str_contains($error->getMessage(), 'Available stock is insufficient');
}
$assert($failed, 'Insufficient stock posting did not return the expected conflict.');
$assert($ledgerCount() === $ledgerBeforeRollback, 'Failed multi-line posting left partial ledger entries.');
$sourceAfterRollback = $balance($sourceLocationId);
$assert($sourceAfterRollback['quantity_on_hand'] === $sourceBeforeRollback['quantity_on_hand'], 'Failed multi-line posting did not roll back the balance.');
$status = $pdo->prepare(
    'SELECT status FROM pos_stock_documents WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id'
);
$status->execute(['business_id' => $businessId, 'branch_id' => $branchId, 'public_id' => $waste]);
$assert($status->fetchColumn() === 'draft', 'Failed posting changed the document status.');

if (!extension_loaded('pcntl')) {
    throw new RuntimeException('The pcntl extension is required for inventory posting concurrency checks.');
}
$raceDocumentOne = $createDocument([
    'document_type' => 'waste', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI concurrent posting race',
], 'race-one');
$addLine($raceDocumentOne, '2', null, 'race-one');
$raceDocumentTwo = $createDocument([
    'document_type' => 'waste', 'location_id' => $sourceLocationPublicId,
    'reason' => 'CI concurrent posting race',
], 'race-two');
$addLine($raceDocumentTwo, '2', null, 'race-two');
$ledgerBeforeRace = $ledgerCount();

// Do not fork with a live PDO connection: a child destructor could send COM_QUIT
// over the inherited socket and invalidate the parent's connection.
unset(
    $createDocument, $addLine, $postDocument, $documents, $posting,
    $balance, $ledgerCount, $insert, $insertBranch, $transferLedger, $status, $pdo,
);
gc_collect_cycles();

$raceChildren = [];
foreach ([
    [$raceDocumentOne, 'race-one'],
    [$raceDocumentTwo, 'race-two'],
] as [$raceDocumentId, $raceSuffix]) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    if ($pair === false) throw new RuntimeException('Unable to create the concurrency test barrier.');
    $resultFile = tempnam(sys_get_temp_dir(), 'pos-v3-stock-race-');
    if ($resultFile === false) throw new RuntimeException('Unable to create concurrency result file.');
    $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('Unable to fork inventory posting worker.');
    if ($pid === 0) {
        fclose($pair[0]);
        fread($pair[1], 1);
        fclose($pair[1]);
        $pdo = null;
        try {
            $workerPdo = PosV3DatabaseTools::connect();
            $workerPosting = new InventoryPostingService($workerPdo, $appKey);
            $result = $workerPosting->post(
                $actor, $scope, $raceDocumentId,
                $prefix . '-post-' . $raceSuffix . '-key',
                'ci-race-' . bin2hex(random_bytes(4)), $ip,
            );
            file_put_contents($resultFile, json_encode(['status' => 'posted', 'replayed' => $result['replayed']], JSON_THROW_ON_ERROR));
        } catch (HttpException $error) {
            file_put_contents($resultFile, json_encode(['status' => 'http_error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            file_put_contents($resultFile, json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_THROW_ON_ERROR));
        }
        exit(0);
    }
    fclose($pair[1]);
    $raceChildren[] = ['pid' => $pid, 'socket' => $pair[0], 'file' => $resultFile];
}
foreach ($raceChildren as $child) {
    fwrite($child['socket'], 'G');
    fclose($child['socket']);
}
foreach ($raceChildren as $child) {
    pcntl_waitpid($child['pid'], $waitStatus);
    $assert(pcntl_wifexited($waitStatus) && pcntl_wexitstatus($waitStatus) === 0, 'Concurrent inventory posting worker exited unexpectedly.');
}
$raceResults = [];
foreach ($raceChildren as $child) {
    $raw = file_get_contents($child['file']);
    @unlink($child['file']);
    $result = is_string($raw) ? json_decode($raw, true) : null;
    $assert(is_array($result) && isset($result['status']), 'Concurrent inventory posting worker returned no result.');
    $raceResults[] = $result;
}
$postedCount = count(array_filter($raceResults, static fn (array $r): bool => $r['status'] === 'posted'));
$blockedCount = count(array_filter(
    $raceResults,
    static fn (array $r): bool => $r['status'] === 'http_error'
        && str_contains((string) ($r['message'] ?? ''), 'Available stock is insufficient'),
));
echo 'Concurrent posting race results: ' . json_encode($raceResults, JSON_UNESCAPED_UNICODE) . "\\n";
$assert($postedCount === 1 && $blockedCount === 1, 'Concurrent posting race must allow exactly one post and block the competing post: ' . json_encode($raceResults, JSON_UNESCAPED_UNICODE));

$pdo = PosV3DatabaseTools::connect();
$ledgerCount = static function () use ($pdo, $businessId, $branchId): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM pos_stock_ledger WHERE business_id = :business_id AND branch_id = :branch_id');
    $s->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
    return (int) $s->fetchColumn();
};
$balance = static function (int $locationId) use ($pdo, $businessId, $branchId, $stockItemId): array {
    $s = $pdo->prepare(
        'SELECT quantity_on_hand, quantity_reserved, average_unit_cost
         FROM pos_stock_balances
         WHERE business_id = :business_id AND branch_id = :branch_id
           AND stock_item_id = :stock_item_id AND location_id = :location_id'
    );
    $s->execute([
        'business_id' => $businessId, 'branch_id' => $branchId,
        'stock_item_id' => $stockItemId, 'location_id' => $locationId,
    ]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Expected stock balance row is missing after concurrency test.');
    return $row;
};
$assert($ledgerCount() === $ledgerBeforeRace + 1, 'Concurrent posting race appended an unexpected number of ledger entries.');
$raceBalance = $balance($sourceLocationId);
$assert($raceBalance['quantity_on_hand'] === '0.000000', 'Concurrent posting race produced an incorrect final quantity.');

echo "V3 inventory posting integration checks passed.\n";
