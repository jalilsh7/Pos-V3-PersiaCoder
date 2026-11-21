<?php
declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderService.php');
$lifecycle = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderInventoryLifecycleService.php');
$schema = file_get_contents(__DIR__ . '/../database/v3/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/v3/migrations/011_scope-inventory-and-recipes.sql');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($service, 'new OrderInventoryLifecycleService($connection)'), 'Order lifecycle service is not wired into OrderService.');
$assert(str_contains($service, '$this->inventoryLifecycle->apply('), 'Order status transition does not invoke stock lifecycle.');
$assert(strpos($service, '$this->inventoryLifecycle->apply(') < strpos($service, '$this->orders->updateOrderStatus('), 'Stock lifecycle must execute before the order status update in the same transaction.');

$assert(str_contains($lifecycle, 'if ($targetStatus === \'accepted\')'), 'Acceptance reservation hook is missing.');
$assert(str_contains($lifecycle, 'elseif ($targetStatus === \'completed\')'), 'Completion consumption hook is missing.');
$assert(str_contains($lifecycle, "['cancelled', 'rejected']"), 'Cancellation/rejection release hook is missing.');
$assert(str_contains($lifecycle, 'variant_scope_id = :variant_scope'), 'Variant recipe lookup is missing.');
$assert(str_contains($lifecycle, 'return ($variantId === null ? null : $find($variantId)) ?? $find(null)'), 'Product-level recipe fallback is missing.');
$assert(str_contains($lifecycle, 'SET quantity_reserved = quantity_reserved +'), 'Reservation balance increment is missing.');
$assert(str_contains($lifecycle, 'quantity_on_hand - quantity_reserved >='), 'Reservation must enforce available stock.');
$assert(str_contains($lifecycle, 'SET quantity_on_hand=quantity_on_hand-'), 'Consumption must decrement on-hand stock.');
$assert(str_contains($lifecycle, 'SET quantity_reserved=quantity_reserved-'), 'Consumption/release must decrement reserved stock.');
$assert(str_contains($lifecycle, "'consumption_out'"), 'Consumption ledger movement is missing.');
$assert(str_contains($lifecycle, 'source_event_key'), 'Consumption ledger idempotency key is missing.');
$assert(str_contains($lifecycle, 'recipe_version_id = :version_id'), 'Accepted order item must pin its recipe version.');
$assert(str_contains($lifecycle, 'pos_order_item_recipe_lines'), 'Order item recipe snapshot is missing.');
$assert(str_contains($lifecycle, 'FOR UPDATE'), 'Inventory lifecycle must lock mutable rows.');
$assert(str_contains($lifecycle, 'DECIMAL(18,6)'), 'Inventory arithmetic must use database decimal precision.');
$assert(str_contains($schema, 'CREATE TABLE pos_stock_reservations'), 'Reservation schema is missing.');
$assert(str_contains($schema, 'CREATE TABLE pos_order_item_recipe_lines'), 'Order recipe snapshot schema is missing.');
$assert(str_contains($migration, 'ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id'), 'Inventory branch/business scope migration is missing.');

fwrite(STDOUT, "V3 order-to-recipe inventory lifecycle contract checks passed.\n");
