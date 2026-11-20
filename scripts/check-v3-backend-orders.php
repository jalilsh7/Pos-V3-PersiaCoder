<?php
declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../v3/backend/src/Infrastructure/Database/OrderRepository.php');
$service = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderService.php');
$controller = file_get_contents(__DIR__ . '/../v3/backend/src/Orders/OrderController.php');
$client = file_get_contents(__DIR__ . '/../v3/frontend/src/shared/api/client.ts');
$workspace = file_get_contents(__DIR__ . '/../v3/frontend/src/features/orders/OrderCenter.tsx');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};

$assert(str_contains($repository, 'o.business_id = :business_id AND o.branch_id = :branch_id'), 'Order pagination lost business/branch SQL scope.');
$assert(str_contains($repository, 'o.order_number < :before_order_number'), 'Order pagination cursor predicate is missing.');
$assert(str_contains($repository, 'ORDER BY o.order_number DESC LIMIT :fetch_limit'), 'Order pagination must use a stable descending branch sequence.');
$assert(str_contains($repository, '$limit + 1'), 'Order pagination must fetch one extra row to calculate has_more.');
$assert(str_contains($repository, "'has_more' => \$hasMore"), 'Order pagination has_more metadata is missing.');
$assert(str_contains($repository, "'next_cursor' => \$hasMore"), 'Order pagination next cursor metadata is missing.');
$assert(str_contains($service, "'/^[1-9][0-9]{0,18}$/'"), 'Order cursor validation is missing.');
$assert(str_contains($service, 'FILTER_VALIDATE_INT') && str_contains($service, "'min_range' => 1"), 'Order cursor integer overflow validation is missing.');
$assert(str_contains($controller, "query('before')"), 'Order controller does not accept the before cursor.');
$assert(str_contains($repository, 'o.payment_status = :payment_status'), 'Order repository payment filter is missing.');
$assert(str_contains($repository, 'f.method = :fulfillment_method'), 'Order repository fulfillment filter is missing.');
$assert(str_contains($service, 'paymentStatuses()') && str_contains($service, 'fulfillmentMethods()'), 'Order filter allow-lists are missing.');
$assert(str_contains($service, "'partial'") && str_contains($service, "'partially_refunded'") && str_contains($service, "'pending'"), 'Order payment filter values must match the database CHECK constraint.');
$assert(str_contains($controller, "query('payment')") && str_contains($controller, "query('fulfillment')"), 'Order controller does not accept payment/fulfillment filters.');
$assert(str_contains($client, 'payment?: string;') && str_contains($client, 'fulfillment?: string;'), 'Frontend order API filter contract is incomplete.');
$assert(str_contains($workspace, 'payment: payment === "all"') && str_contains($workspace, 'fulfillment: fulfillment === "all"'), 'Order center does not send server-side payment/fulfillment filters.');

$assert(str_contains($client, 'before?: string;') && str_contains($client, 'has_more: boolean;'), 'Frontend order page contract is incomplete.');
$assert(str_contains($workspace, 'loadMoreOrders') && str_contains($workspace, 'نمایش سفارش‌های قدیمی‌تر'), 'Order center does not expose the next page action.');

$assert(str_contains($repository, 'findEffectiveTimezone') && str_contains($repository, 'COALESCE(NULLIF(branch.timezone'), 'Order date filtering must resolve branch timezone with business fallback.');
$assert(str_contains($repository, 'o.created_at >= :created_from_utc') && str_contains($repository, 'o.created_at < :created_to_utc'), 'Order repository date bounds must use inclusive start and exclusive end.');
$assert(str_contains($service, 'normalizeDateRange') && str_contains($service, 'order_date_range_too_large'), 'Order service date validation and maximum range are missing.');
$assert(str_contains($service, "new \\DateTimeZone('UTC')"), 'Order service must convert local calendar boundaries to UTC.');
$assert(str_contains($controller, "query('from_date')") && str_contains($controller, "query('to_date')"), 'Order controller date query contract is incomplete.');
$assert(str_contains($client, 'fromDate?: string;') && str_contains($client, 'toDate?: string;')
    && str_contains($client, 'query.set("from_date", filters.fromDate)')
    && str_contains($client, 'query.set("to_date", filters.toDate)'), 'Frontend order client date filters are incomplete.');
$assert(str_contains($workspace, 'type="date"') && str_contains($workspace, 'fromDate: fromDate || undefined')
    && str_contains($workspace, 'toDate: toDate || undefined'), 'Order center does not send date filters with each cursor page.');

$posWorkspace = file_get_contents(__DIR__ . '/../v3/frontend/src/features/pos/PosWorkspace.tsx');

$assert(str_contains($client, 'getPosCatalogProducts') && str_contains($client, '/catalog/products?'), 'POS catalog list client is missing.');
$assert(str_contains($client, 'getPosCatalogProductDetails') && str_contains($client, '`/catalog/products/${encodeURIComponent(productId)}`'), 'POS product configuration detail client is missing.');
$assert(str_contains($client, 'createPosOrder') && str_contains($client, 'Idempotency-Key'), 'POS order creation client or idempotency header is missing.');
$assert(str_contains($client, 'CreatePosOrderPayload') && str_contains($client, 'variant_id: string | null'), 'POS order payload contract is incomplete.');
$assert(str_contains($posWorkspace, 'getPosCatalogProducts()') && str_contains($posWorkspace, 'getPosCatalogProductDetails(summary.id)'), 'Cashier workspace is not loading the real catalog.');
$assert(str_contains($posWorkspace, 'createPosOrder(payload, idempotencyAttempt.current.key)'), 'Cashier workspace is not submitting orders with a stable idempotency key.');
$assert(str_contains($posWorkspace, 'product_id: line.product.id') && str_contains($posWorkspace, 'options: line.selectedOptions.map'), 'Cashier order payload does not use public product and option IDs.');
$assert(str_contains($posWorkspace, 'delivery_address: { street: deliveryAddress.trim() }'), 'POS delivery fulfillment address is not mapped to the API contract.');
$assert(!str_contains($posWorkspace, 'sampleProducts'), 'Cashier workspace still contains hard-coded sample products.');
$assert(str_contains($posWorkspace, 'محاسبه در سرور') && str_contains($posWorkspace, 'وضعیت «پیش‌نویس»'), 'Cashier UI must communicate server-authoritative pricing and draft/unpaid creation.');

$catalogWorkspace = file_get_contents(__DIR__ . '/../v3/frontend/src/features/catalog/CatalogWorkspace.tsx');
$assert(str_contains($client, 'getCatalogCategories') && str_contains($client, 'getCatalogProducts'), 'Catalog management read API client is incomplete.');
$assert(str_contains($client, 'createCatalogCategory') && str_contains($client, 'createCatalogProduct'), 'Catalog create API client is incomplete.');
$assert(str_contains($client, 'updateCatalogProduct') && str_contains($client, 'archiveCatalogProduct'), 'Catalog update/archive API client is incomplete.');
$assert(str_contains($client, 'path.startsWith("/catalog/")'), 'Catalog mutations are missing automatic idempotency-key retention.');
$assert(str_contains($catalogWorkspace, 'fetchCatalogSnapshot()') && str_contains($catalogWorkspace, 'getPosCatalogProductDetails'), 'Catalog workspace is not loading real server catalog data.');
$assert(str_contains($catalogWorkspace, 'createCatalogProduct') && str_contains($catalogWorkspace, 'createCatalogCategory'), 'Catalog workspace is not creating server-side products/categories.');
$assert(str_contains($catalogWorkspace, 'updateCatalogProduct') && str_contains($catalogWorkspace, 'archiveCatalogProduct'), 'Catalog workspace is not persisting product status and archive actions.');
$assert(!str_contains($catalogWorkspace, 'seedProducts'), 'Catalog workspace still contains hard-coded sample products.');

$orderCenter = file_get_contents(__DIR__ . '/../v3/frontend/src/features/orders/OrderCenter.tsx');
$kitchenBoard = file_get_contents(__DIR__ . '/../v3/frontend/src/features/kitchen/KitchenBoard.tsx');
$assert(str_contains($client, 'transitionOrder') && str_contains($client, '/status'), 'Order status transition API client is missing.');
$assert(str_contains($client, 'transitionKitchenOrder') && str_contains($client, '/kitchen-status'), 'Kitchen status transition API client is missing.');
$assert(str_contains($client, 'path.startsWith("/orders/")'), 'Order status mutations are missing automatic idempotency-key retention.');
$assert(str_contains($orderCenter, 'transitionOrder(selectedOrder.id, toStatus, reasonText)') && str_contains($orderCenter, 'پذیرش سفارش'), 'Order center does not expose backend acceptance/completion actions.');
$assert(str_contains($kitchenBoard, 'getOrders({ status') && str_contains($kitchenBoard, 'transitionKitchenOrder(order.id, nextStatus)'), 'Kitchen board is not using the live order queue and kitchen transition API.');
$assert(!str_contains($kitchenBoard, 'initialTickets'), 'Kitchen board still contains hard-coded sample tickets.');
$assert(str_contains($orderCenter, 'setShowCancelForm(true)') && str_contains($orderCenter, 'دلیل لغو سفارش (اجباری)'), 'Order center cancellation flow is missing the mandatory reason prompt.');
$assert(str_contains($orderCenter, 'changeOrderStatus("cancelled", cancelReason.trim())') && str_contains($client, 'reason_text: reasonText'), 'Order cancellation does not send its reason to the backend.');
$assert(str_contains($orderCenter, '!["paid", "partially_paid"].includes(selectedOrder.payment_status)'), 'Order center should not offer direct cancellation for paid/partially paid orders.');

fwrite(STDOUT, "V3 order pagination, timezone filters and POS order submission contract checks passed.\n");
