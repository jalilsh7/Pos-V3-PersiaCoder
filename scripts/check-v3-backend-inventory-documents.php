<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) throw new RuntimeException('Unable to read ' . $path);
    return $content;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$repository = $read('v3/backend/src/Infrastructure/Database/InventoryDocumentRepository.php');
$service = $read('v3/backend/src/Inventory/InventoryDocumentService.php');
$postingService = $read('v3/backend/src/Inventory/InventoryPostingService.php');
$postingRepository = $read('v3/backend/src/Infrastructure/Database/InventoryPostingRepository.php');
$controller = $read('v3/backend/src/Inventory/InventoryController.php');
$frontController = $read('v3/backend/public/index.php');
$lineMigration = $read('database/v3/migrations/012_stock-document-line-public-ids.sql');

$assert(str_contains($repository, 'WHERE business_id = :business_id AND branch_id = :branch_id'), 'Document lookup must enforce business and branch scope.');
$assert(str_contains($repository, 'WHERE d.business_id = :business_id AND d.branch_id = :branch_id'), 'Document list/detail must enforce business and branch scope.');
$assert(str_contains($repository, 'FOR UPDATE'), 'Document numbering must lock the branch sequence row.');
$assert(str_contains($repository, 'findDraftForUpdate'), 'Draft line writes must lock the scoped document row.');
$assert(str_contains($repository, 'conversion_factor_snapshot'), 'Document lines must persist a conversion factor snapshot.');
$assert(str_contains($repository, 'base_quantity'), 'Document lines must persist converted base quantity.');
$assert(str_contains($repository, 'findLineForUpdate'), 'Draft line edits must lock a scoped line row.');
$assert(str_contains($repository, 'l.public_id AS line_id'), 'Line responses must expose a stable public identifier.');
$assert(str_contains($lineMigration, 'ADD COLUMN public_id'), 'Migration 012 must add stable public line identifiers.');
$assert(str_contains($lineMigration, 'SET public_id = UUID()'), 'Migration 012 must backfill existing document lines.');
$assert(str_contains($lineMigration, 'uq_stock_document_lines_public_scope'), 'Migration 012 must enforce scoped line identifier uniqueness.');
$assert(str_contains($repository, "INSERT INTO pos_stock_documents"), 'Draft creation must persist a stock document header.');
$assert(str_contains($repository, "'draft'"), 'New stock documents must be created as drafts.');
$assert(str_contains($service, "inventory.stock-document.create"), 'Draft creation must use a dedicated idempotency scope.');
$assert(str_contains($service, 'public function addLine'), 'Draft document line creation must be implemented.');
$assert(str_contains($service, "inventory.stock-document.line.create"), 'Line creation must use a dedicated idempotency scope.');
$assert(str_contains($service, "inventory.stock-document.line.update"), 'Line updates must use a dedicated idempotency scope.');
$assert(str_contains($service, "inventory.stock-document.line.delete"), 'Line deletion must use a dedicated idempotency scope.');
$assert(str_contains($service, 'public function updateLine'), 'Draft line update must be implemented.');
$assert(str_contains($service, 'public function deleteLine'), 'Draft line deletion must be implemented.');
$assert(str_contains($postingService, 'public function post'), 'Transactional inventory posting service must be present.');
$assert(str_contains($postingService, 'inventory.stock-document.post'), 'Posting must be idempotent.');
$assert(str_contains($postingService, 'stock_document_not_draft'), 'Posting must reject non-draft documents.');
$assert(str_contains($postingService, 'stock_insufficient'), 'Posting must enforce available stock.');
$assert(str_contains($postingService, 'stock_item_inactive'), 'Posting must reject inactive stock items.');
$assert(str_contains($postingService, 'stock_location_inactive'), 'Posting must reject inactive locations.');
$assert(str_contains($postingService, 'weightedAverageFromTotalCost'), 'Purchase receipts must use moving weighted average valuation.');
$assert(str_contains($postingService, 'transfer_out'), 'Transfers must create a paired outbound ledger event.');
$assert(str_contains($postingService, 'stock_document_type_not_postable'), 'Unsupported return valuation must be rejected explicitly.');
$createStart = strpos($service, 'public function create(');
$createEnd = strpos($service, 'public function addLine(', $createStart === false ? 0 : $createStart);
$createMethod = $createStart === false || $createEnd === false ? '' : substr($service, $createStart, $createEnd - $createStart);
$assert(str_contains($createMethod, "'waste']") && !str_contains($createMethod, "'return'"), 'New return documents must be blocked until source-linked return valuation is implemented; use audited inbound adjustment meanwhile.');
$assert(str_contains($service, 'total_cost_amount'), 'Purchase receipt draft lines must capture total TOMAN cost.');
$assert(str_contains($postingRepository, 'FOR UPDATE'), 'Posting must lock balance rows.');
$assert(str_contains($postingRepository, 'INSERT INTO pos_stock_ledger'), 'Posting must append ledger entries.');
$assert(!preg_match('/(?:UPDATE|DELETE FROM)\\s+pos_stock_ledger/i', $postingRepository), 'Posting repository must keep the stock ledger append-only.');
$assert(str_contains($postingRepository, 'quantity_reserved'), 'Posting must protect reserved quantities.');
$assert(str_contains($service, '$this->audit->record('), 'Draft creation must append an audit record.');
$assert(str_contains($service, "'transfer'"), 'Transfer documents must be supported.');
$assert(str_contains($service, "'purchase_receipt'"), 'Purchase receipt documents must be supported.');
$assert(str_contains($service, 'destination_location_id'), 'Transfer destination must be validated.');
$assert(str_contains($service, 'findSupplierId'), 'Supplier references must be resolved in business scope.');
$assert(!preg_match('/(?:UPDATE|INSERT INTO)\s+pos_stock_balances/i', $repository), 'Draft document operations must not mutate stock balances.');
$assert(!preg_match('/INSERT INTO\s+pos_stock_ledger/i', $repository), 'Draft document operations must not append stock ledger entries.');
$assert(str_contains($frontController, "'/api/v1/inventory/documents'"), 'Document routes must be registered.');
$inventoryWorkspace = $read('v3/frontend/src/features/inventory/InventoryWorkspace.tsx');
$assert(str_contains($inventoryWorkspace, 'getInventoryDocument(createdDocument.id)')
    && str_contains($inventoryWorkspace, 'recovered.status === "posted"')
    && str_contains($inventoryWorkspace, 'بدون ایجاد سند تکراری'), 'Partial inventory document submission must recover and reopen the existing document instead of inviting duplicate creation.');
$assert(str_contains($controller, 'public function createDocument'), 'Document create handler must be present.');
$assert(str_contains($controller, 'public function createDocumentLine'), 'Document line handler must be present.');
$assert(str_contains($controller, 'public function updateDocumentLine'), 'Document line update handler must be present.');
$assert(str_contains($controller, 'public function deleteDocumentLine'), 'Document line delete handler must be present.');
$assert(str_contains($controller, 'public function postDocument'), 'Document posting handler must be present.');
$assert(str_contains($controller, 'public function documentValuation'), 'Valuation handler must be present.');
$assert(str_contains($frontController, '/api/v1/inventory/documents/{documentPublicId}/lines'), 'Document line route must be registered.');
$assert(str_contains($frontController, '/api/v1/inventory/documents/{documentPublicId}/lines/{linePublicId}'), 'Document line update/delete routes must be registered.');
$assert(str_contains($frontController, '/api/v1/inventory/documents/{documentPublicId}/post'), 'Document posting route must be registered.');
$assert(str_contains($frontController, 'InventoryPostingService;'), 'API bootstrap must import the posting service.');
$assert(str_contains($frontController, '/api/v1/inventory/documents/{documentPublicId}/valuation'), 'Protected valuation route must be registered.');
$assert(str_contains($frontController, "makePermissionMiddleware('inventory.manage', true)"), 'Document mutations must require branch-scoped inventory.manage permission.');
$assert(!preg_match('/(?:UPDATE|INSERT INTO)\\s+pos_stock_balances/i', $repository), 'Draft line CRUD must not mutate stock balances.');
$assert(!preg_match('/INSERT INTO\\s+pos_stock_ledger/i', $repository), 'Draft line CRUD must not append stock ledger entries.');

echo "V3 inventory draft document contract checks passed.\n";
