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
$client = $read('v3/frontend/src/shared/api/client.ts');
$workspace = $read('v3/frontend/src/features/inventory/InventoryWorkspace.tsx');
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
$assert(str_contains($postingService, "'return_in'") && str_contains($postingService, 'findPostedReturnSourceByIdForUpdate'), 'Source-linked return posting must use historical source valuation.');
$assert(str_contains($postingService, 'canAddReturnQuantity') && str_contains($postingService, 'stock_return_quantity_exceeded'), 'Return posting must recheck cumulative quantity while holding the source lock.');
$assert(str_contains($service, 'source_document_id') && str_contains($service, 'source_document_line_id'), 'Return drafts and lines must retain exact source references.');
$assert(str_contains($service, 'findReturnSourceLineForUpdate') && str_contains($service, 'canAddReturnQuantity'), 'Return line creation must validate source scope and remaining quantity.');
$assert(str_contains($repository, 'recipient_phone_normalized') === false, 'Inventory repository must not depend on CRM consent fields.');
$assert(str_contains($repository, 'source_document_line_id') && str_contains($repository, 'returnedBaseQuantity'), 'Inventory repository must support source-linked return accounting.');
$assert(str_contains($repository, 'findPostedReturnSourceForUpdate') && str_contains($repository, 'findReturnSourceLineByIdForUpdate'), 'Return source and source lines must be lockable within scope.');
$assert(str_contains($repository, 'canAddReturnQuantity') && str_contains($repository, 'SUM(return_line.base_quantity)'), 'Cumulative posted return quantities must be calculated in SQL decimal arithmetic.');
$assert(str_contains($repository, 'returnable_quantity') && str_contains($workspace, 'returnable_quantity'), 'The UI must show remaining returnable quantity and disable exhausted lines.');
$assert(str_contains($repository, 'source_document_public_id') && str_contains($repository, 'source_document_line_id'), 'Document and line details must expose immutable source references.');
$assert(str_contains($service, 'stock_return_line_inherited'), 'Return lines must not permit manual item, unit or valuation edits.');
$assert(str_contains($client, 'source_document_line_id: string') && str_contains($client, '| "return"'), 'Frontend API contract must support source-linked return documents and lines.');
$assert(str_contains($workspace, 'returnSourceDocumentId') && str_contains($workspace, 'returnSourceLineId'), 'Inventory UI must select a posted source document and exact source line.');
$assert(str_contains($workspace, 'documentType === "return"'), 'Inventory UI must provide the return workflow.');
$assert(str_contains($postingRepository, "'return_in'") === false, 'Return movement type is written through the shared append-only ledger method.');
$createStart = strpos($service, 'public function create(');
$createEnd = strpos($service, 'public function addLine(', $createStart === false ? 0 : $createStart);
$createMethod = $createStart === false || $createEnd === false ? '' : substr($service, $createStart, $createEnd - $createStart);
$assert(str_contains($createMethod, "'return'") && str_contains($createMethod, 'findPostedReturnSourceForUpdate'), 'Return draft creation must require a posted source document.');

$sourceLinkMigration = $read('database/v3/migrations/021_stock-document-source-links.sql');
$assert(str_contains($sourceLinkMigration, 'ADD COLUMN source_document_id BIGINT UNSIGNED NULL'), 'Migration 021 must add an optional source-document reference without guessing historical links.');
$assert(str_contains($sourceLinkMigration, 'ADD COLUMN source_document_line_id BIGINT UNSIGNED NULL'), 'Migration 021 must add an optional source-line reference.');
$assert(str_contains($sourceLinkMigration, 'FOREIGN KEY (business_id, branch_id, source_document_id)'), 'A source document reference must be constrained to the same business and branch.');
$assert(str_contains($sourceLinkMigration, 'FOREIGN KEY (business_id, branch_id, source_document_line_id)'), 'A source line reference must be constrained to the same business and branch.');
$assert(str_contains($sourceLinkMigration, 'ON DELETE RESTRICT'), 'Source links must prevent deletion of referenced historical documents and lines.');
$assert(str_contains($sourceLinkMigration, 'NULL AFTER source_order_id'), 'Historical stock documents must remain unlinked instead of receiving guessed source references.');
$integration = $read('scripts/check-v3-inventory-posting-integration.php');
$assert(str_contains($integration, 'return-partial-one') && str_contains($integration, 'return_in'), 'Database integration must cover partial return valuation.');
$assert(str_contains($integration, 'return-race-two') && str_contains($integration, 'overReturnBlocked'), 'Database integration must reject cumulative over-return across drafts.');
$assert(str_contains($integration, 'return-parallel-one') && str_contains($integration, 'pcntl_fork') && str_contains($integration, 'returnBlockedCount'), 'Database integration must exercise a true parallel source-return posting race.');
$assert(str_contains($frontController, 'inventory/documents/{documentPublicId}/lines'), 'Return line API must use the protected inventory document line route.');

$assert(str_contains($service, 'total_cost_amount'), 'Purchase receipt draft lines must capture total TOMAN cost.');
$assert(str_contains($postingRepository, 'FOR UPDATE'), 'Posting must lock balance rows.');
$issueStart = strpos($postingRepository, 'function issueBalance(');
$issueEnd = strpos($postingRepository, 'public function appendLedger(', $issueStart === false ? 0 : $issueStart);
$issueMethod = $issueStart === false || $issueEnd === false ? '' : substr($postingRepository, $issueStart, $issueEnd - $issueStart);
$assert(str_contains($issueMethod, 'quantity_on_hand - quantity_reserved >= CAST(:quantity_available AS DECIMAL(18,6))'), 'Outgoing stock posting must preserve reserved quantities and only issue available stock.');
$assert(!str_contains($issueMethod, 'quantity_reserved = quantity_reserved -'), 'Manual stock posting must not release order reservations.');
$assert(str_contains($postingService, 'reserved quantities cannot be transferred') && str_contains($postingService, 'reserved quantities cannot be consumed or written off'), 'Transfer and outgoing adjustment/waste/consumption must reject stock reserved by active orders.');
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

$assert(str_contains($postingService, 'findPostedReturnSourceByIdForUpdate')
    && str_contains($postingService, 'canAddReturnQuantity')
    && str_contains($postingService, "'0', '1'"),
    'Posting a return must re-lock its posted source and re-check the complete draft return quantity before stock is received.');
$assert(str_contains($service, 'findPostedReturnSourceByIdForUpdate')
    && str_contains($service, 'canAddReturnQuantity')
    && str_contains($service, 'sourceLinePublicId'),
    'Adding a return line must lock the posted source and enforce the remaining quantity for the exact source line.');
$assert(str_contains($service, 'stock_return_line_inherited')
    && str_contains($service, 'Only draft document lines can be updated.'),
    'Return line valuation and source identity must remain immutable after creation.');

echo "V3 inventory draft document contract checks passed.\n";
