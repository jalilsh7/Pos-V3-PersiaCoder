<?php
declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderService.php');
$lifecycle = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderInventoryLifecycleService.php');
$schema = file_get_contents(__DIR__ . '/../database/v3/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/v3/migrations/011_scope-inventory-and-recipes.sql');
$orderScopeMigration = file_get_contents(__DIR__ . '/../database/v3/migrations/008_scope-orders-and-service-areas.sql');

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
$assert(str_contains($lifecycle, \"'storefront' : 'pos'\"), 'Reservation source type must reflect the originating sales channel.');
$assert(str_contains($lifecycle, 'LIMIT 1 FOR UPDATE'), 'Active recipe selection must be locked against concurrent activation changes.');
$assert(str_contains($lifecycle, 'FOR UPDATE'), 'Inventory lifecycle must lock mutable rows.');
$assert(str_contains($lifecycle, 'ORDER BY id LOCK IN SHARE MODE') && str_contains($lifecycle, '$lockLocations'), 'Inventory location and balance locks must use a stable primary-key order.');
$assert(str_contains($lifecycle, 'usort($allocationRows') && str_contains($lifecycle, "=== 'kitchen'"), 'Stable lock ordering must not change kitchen-first allocation priority.');
$assert(str_contains($lifecycle, 'DECIMAL(18,6)'), 'Inventory arithmetic must use database decimal precision.');
$assert(str_contains($schema, 'CREATE TABLE pos_stock_reservations'), 'Reservation schema is missing.');
$assert(str_contains($schema, 'CREATE TABLE pos_order_item_recipe_lines'), 'Order recipe snapshot schema is missing.');
$assert(str_contains($orderScopeMigration, 'ALTER TABLE pos_order_item_recipe_lines') && str_contains($orderScopeMigration, 'ADD COLUMN business_id BIGINT UNSIGNED NOT NULL AFTER id') && str_contains($orderScopeMigration, 'ADD COLUMN branch_id BIGINT UNSIGNED NOT NULL AFTER business_id'), 'Order recipe snapshot business/branch scope migration is missing.');
$assert(str_contains($migration, 'fk_v3_order_item_recipe_location_scope'), 'Order recipe snapshot location scope constraint is missing.');
$assert(str_contains($migration, 'ADD COLUMN unit_cost_snapshot DECIMAL(24,8) NOT NULL DEFAULT 0 AFTER base_quantity'), 'Reservation-time cost snapshot migration is missing.');
$assert(str_contains($lifecycle, 'unit_cost_snapshot'), 'Reservation-time cost snapshot is not used by the order lifecycle.');
$assert(str_contains($lifecycle, "'unit_cost'=>(string)$line['unit_cost_snapshot']"), 'Consumption valuation must use the reservation-time unit cost snapshot.');
$assert(!str_contains($lifecycle, "'unit_cost'=>(string)$line['average_unit_cost']"), 'Consumption must not read an unavailable or mutable average_unit_cost field from reservation lines.');
$assert(str_contains($lifecycle, 'branch_context_required'), 'Stock lifecycle must reject missing branch context.');
$assert(str_contains($lifecycle, 'takeRemaining'), 'Snapshot allocation must preserve exact quantities without rounding loss.');

fwrite(STDOUT, "V3 order-to-recipe inventory lifecycle contract checks passed.\n");
