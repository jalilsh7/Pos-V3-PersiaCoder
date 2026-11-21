<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/CustomerRepository.php');
$service = file_get_contents($root . '/v3/backend/src/Customers/CustomerService.php');
$client = file_get_contents($root . '/v3/frontend/src/shared/api/client.ts');
$workspace = file_get_contents($root . '/v3/frontend/src/features/customers/CustomersWorkspace.tsx');

foreach (['repository' => $repository, 'service' => $service, 'client' => $client, 'workspace' => $workspace] as $name => $source) {
    if (!is_string($source) || $source === '') {
        fwrite(STDERR, "Unable to read {$name} source.\n");
        exit(1);
    }
}

$checks = [
    'loyalty balance is aggregated from append-only ledger' =>
        str_contains($repository, 'SUM(points_delta)') && str_contains($repository, 'SUM(credit_delta)') && str_contains($repository, 'SUM(visit_delta)'),
    'loyalty ledger is scoped through the selected customer and business' =>
        str_contains($repository, 'WHERE l.customer_id = :customer_id') && str_contains($repository, 'o.id = l.order_id AND o.business_id = :business_id'),
    'ledger history is bounded and deterministic' =>
        str_contains($repository, 'ORDER BY l.created_at DESC, l.id DESC') && str_contains($repository, 'LIMIT 20'),
    'no automatic points accrual is inferred from completed orders' =>
        str_contains($repository, 'No points or credit') && !str_contains($service, 'points_delta'),
    'frontend contract includes loyalty summary and history' =>
        str_contains($client, 'points_balance: number') && str_contains($client, 'entries: {'),
    'customer profile renders loyalty balances and events' =>
        str_contains($workspace, 'detail.loyalty.points_balance') && str_contains($workspace, 'detail.loyalty.entries.map'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "Customer loyalty contract failed:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}
echo "Customer loyalty contract checks passed (" . count($checks) . ").\n";
