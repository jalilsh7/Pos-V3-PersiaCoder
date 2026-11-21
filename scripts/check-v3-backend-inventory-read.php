<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Infrastructure\Database\InventoryRepository;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'PersiaCoder\\PosV3\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

final class InventoryReadTestStatement extends PDOStatement
{
    public array $bindings = [];
    public function __construct(public string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[(string) $param] = ['value' => $value, 'type' => $type];
        return true;
    }
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
}

final class InventoryReadTestPdo extends PDO
{
    public array $statements = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = new InventoryReadTestStatement($query);
        $this->statements[] = $statement;
        return $statement;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$db = new InventoryReadTestPdo();
$repository = new InventoryRepository($db);
$repository->listBalances(17, 29, null, null, 100);
$balance = $db->statements[0];
$assert(str_contains($balance->sql, 'b.business_id = :business_id AND b.branch_id = :branch_id'), 'Balance query must be scoped to business and branch.');
$assert(($balance->bindings[':business_id']['value'] ?? null) === 17, 'Balance business scope binding mismatch.');
$assert(($balance->bindings[':branch_id']['value'] ?? null) === 29, 'Balance branch scope binding mismatch.');
$assert(str_contains($balance->sql, 'b.quantity_on_hand - b.quantity_reserved'), 'Available stock must exclude reserved stock.');
$assert(str_contains($balance->sql, 'sl.branch_id = b.branch_id'), 'Balance location join must enforce branch scope.');

$repository->listLedger(17, 29, 41, 53, 900, 26);
$ledger = $db->statements[1];
$assert(str_contains($ledger->sql, 'l.business_id = :business_id AND l.branch_id = :branch_id'), 'Ledger query must be scoped to business and branch.');
$assert(str_contains($ledger->sql, 'l.id < :before_id'), 'Ledger cursor must use a stable descending identifier.');
$assert(str_contains($ledger->sql, 'dl.branch_id = l.branch_id'), 'Ledger document-line join must enforce branch scope.');
$assert(str_contains($ledger->sql, 'd.branch_id = l.branch_id'), 'Ledger document join must enforce branch scope.');
$assert(($ledger->bindings[':stock_item_id']['value'] ?? null) === 41, 'Ledger stock item filter binding mismatch.');
$assert(($ledger->bindings[':location_id']['value'] ?? null) === 53, 'Ledger location filter binding mismatch.');
$assert(($ledger->bindings[':before_id']['value'] ?? null) === 900, 'Ledger cursor binding mismatch.');
$assert(($ledger->bindings[':limit']['value'] ?? null) === 26, 'Ledger limit binding mismatch.');

$controller = file_get_contents($root . '/v3/backend/src/Inventory/InventoryController.php');
$service = file_get_contents($root . '/v3/backend/src/Inventory/InventoryService.php');
$client = file_get_contents($root . '/v3/frontend/src/shared/api/client.ts');
$workspace = file_get_contents($root . '/v3/frontend/src/features/inventory/InventoryWorkspace.tsx');
$assert(str_contains($controller, "'entries' => $result['items'], 'next_cursor' => $result['next_cursor']"), 'Inventory ledger cursor must be included in response data for API clients.');
$assert(str_contains($service, "'next_cursor' => $nextCursor"), 'Inventory service must return the next cursor for paged ledger reads.');
$assert(str_contains($client, 'beforeId?: string') && str_contains($client, 'query.set("before_id", filters.beforeId)') && str_contains($client, 'nextCursor: data.next_cursor'), 'Inventory frontend API client must support cursor pagination.');
$assert(str_contains($workspace, 'ledgerCursor') && str_contains($workspace, 'loadMoreLedger') && str_contains($workspace, 'بارگذاری گردش‌های قدیمی‌تر'), 'Inventory workspace must expose older ledger pages.');
$assert(!str_contains($client, 'ledger_id: number;'), 'Internal ledger row IDs must not be exposed in the public frontend model.');

echo "V3 inventory read scope and ledger pagination checks passed.\n";
