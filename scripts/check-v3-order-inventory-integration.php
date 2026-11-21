<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Orders\OrderInventoryLifecycleService;

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
    '%08x-%04x-%04x-%04x-%012x',
    random_int(0, 0xffffffff), random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffffffffffff),
);
    $prefix = 'ci-order-stock-' . bin2hex(random_bytes(4));
$businessPublicId = $uuid();
$branchPublicId = $uuid();

$s = $pdo->prepare('INSERT INTO pos_businesses (public_id,code,name) VALUES (:public_id,:code,:name)');
$s->execute(['public_id'=>$businessPublicId,'code'=>$prefix,'name'=>'CI Order Inventory']);
$businessId = (int)$pdo->lastInsertId();
$s = $pdo->prepare('INSERT INTO pos_branches (public_id,business_id,code,name) VALUES (:public_id,:business_id,:code,:name)');
$s->execute(['public_id'=>$branchPublicId,'business_id'=>$businessId,'code'=>$prefix.'-b','name'=>'CI Branch']);
$branchId = (int)$pdo->lastInsertId();
$scope = new BusinessScope($businessId,$businessPublicId,$branchId,$branchPublicId);

$unitId = (int)$pdo->query("SELECT id FROM pos_units WHERE code='kg'")->fetchColumn();
$stockPublicId = $uuid();
$s = $pdo->prepare("INSERT INTO pos_stock_items (business_id,public_id,code,name,base_unit_id,is_active,negative_stock_policy)
 VALUES (:business,:public,:code,'CI Flour',:unit,1,'block')");
$s->execute(['business'=>$businessId,'public'=>$stockPublicId,'code'=>$prefix,'unit'=>$unitId]);
$stockId = (int)$pdo->lastInsertId();

$locationIds = [];
foreach ([['storage','Storage'],['kitchen','Kitchen']] as [$type,$name]) {
    $s = $pdo->prepare('INSERT INTO pos_stock_locations (business_id,branch_id,public_id,code,name,location_type,is_active)
      VALUES (:business,:branch,:public,:code,:name,:type,1)');
    $s->execute(['business'=>$businessId,'branch'=>$branchId,'public'=>$uuid(),'code'=>$prefix.'-'.$type,'name'=>$name,'type'=>$type]);
    $locationIds[$type] = (int)$pdo->lastInsertId();
}
$balance = $pdo->prepare('INSERT INTO pos_stock_balances
 (stock_item_id,business_id,branch_id,location_id,quantity_on_hand,quantity_reserved,average_unit_cost)
 VALUES (:item,:business,:branch,:location,:on_hand,0,:cost)');
$balance->execute(['item'=>$stockId,'business'=>$businessId,'branch'=>$branchId,'location'=>$locationIds['kitchen'],'on_hand'=>'0.400000','cost'=>'2000.00000000']);
$balance->execute(['item'=>$stockId,'business'=>$businessId,'branch'=>$branchId,'location'=>$locationIds['storage'],'on_hand'=>'5.000000','cost'=>'2500.00000000']);

$productPublicId = $uuid();
$s = $pdo->prepare("INSERT INTO pos_products (business_id,public_id,name,product_type,base_price_amount,tax_rate_bps,is_active)
 VALUES (:business,:public,'CI Recipe Product','prepared',10000,0,1)");
$s->execute(['business'=>$businessId,'public'=>$productPublicId]);
$productId = (int)$pdo->lastInsertId();

$variantInsert = $pdo->prepare('INSERT INTO pos_product_variants (business_id,public_id,product_id,name,is_active) VALUES (:business,:public,:product,:name,1)');
$variantInsert->execute(['business'=>$businessId,'public'=>$uuid(),'product'=>$productId,'name'=>'CI Fallback Variant']);
$fallbackVariantId = (int)$pdo->lastInsertId();
$variantInsert->execute(['business'=>$businessId,'public'=>$uuid(),'product'=>$productId,'name'=>'CI Dedicated Variant']);
$dedicatedVariantId = (int)$pdo->lastInsertId();

$recipePublicId = $uuid();
$s = $pdo->prepare('INSERT INTO pos_recipes (business_id,public_id,product_id,variant_id,name,is_active)
 VALUES (:business,:public,:product,NULL,\'CI Product Recipe\',1)');
$s->execute(['business'=>$businessId,'public'=>$recipePublicId,'product'=>$productId]);
$recipeId = (int)$pdo->lastInsertId();
$versionPublicId = $uuid();
$s = $pdo->prepare("INSERT INTO pos_recipe_versions (business_id,public_id,recipe_id,version_no,status,effective_from)
 VALUES (:business,:public,:recipe,1,'active',UTC_TIMESTAMP(3))");
$s->execute(['business'=>$businessId,'public'=>$versionPublicId,'recipe'=>$recipeId]);
$versionId = (int)$pdo->lastInsertId();
$s = $pdo->prepare('INSERT INTO pos_recipe_lines (business_id,recipe_version_id,stock_item_id,quantity,unit_id,conversion_factor_snapshot,waste_percent)
 VALUES (:business,:version,:item,0.500000,:unit,1.00000000,0)');
$s->execute(['business'=>$businessId,'version'=>$versionId,'item'=>$stockId,'unit'=>$unitId]);

$variantRecipePublicId = $uuid();
$s = $pdo->prepare('INSERT INTO pos_recipes (business_id,public_id,product_id,variant_id,name,is_active)
 VALUES (:business,:public,:product,:variant,\'CI Variant Recipe\',1)');
$s->execute(['business'=>$businessId,'public'=>$variantRecipePublicId,'product'=>$productId,'variant'=>$dedicatedVariantId]);
$variantRecipeId = (int)$pdo->lastInsertId();
$variantVersionPublicId = $uuid();
$s = $pdo->prepare("INSERT INTO pos_recipe_versions (business_id,public_id,recipe_id,version_no,status,effective_from)
 VALUES (:business,:public,:recipe,1,'active',UTC_TIMESTAMP(3))");
$s->execute(['business'=>$businessId,'public'=>$variantVersionPublicId,'recipe'=>$variantRecipeId]);
$variantVersionId = (int)$pdo->lastInsertId();
$s = $pdo->prepare('INSERT INTO pos_recipe_lines (business_id,recipe_version_id,stock_item_id,quantity,unit_id,conversion_factor_snapshot,waste_percent)
 VALUES (:business,:version,:item,0.250000,:unit,1.00000000,0)');
$s->execute(['business'=>$businessId,'version'=>$variantVersionId,'item'=>$stockId,'unit'=>$unitId]);

$createOrder = static function (string $suffix, ?int $variantId = null) use ($pdo,$businessId,$branchId,$productId,$prefix,$uuid): array {
    $s = $pdo->prepare("INSERT INTO pos_orders (business_id,branch_id,public_id,order_number,source_channel,order_status,payment_status,total_amount)
      VALUES (:business,:branch,:public,:number,'pos','draft','unpaid',10000)");
    $s->execute(['business'=>$businessId,'branch'=>$branchId,'public'=>$uuid(),'number'=>random_int(100000,900000)]);
    $orderId = (int)$pdo->lastInsertId();
    $s = $pdo->prepare("INSERT INTO pos_order_items (business_id,branch_id,order_id,product_id,variant_id,product_name_snapshot,quantity,unit_price_amount,line_total_amount)
      VALUES (:business,:branch,:order,:product,:variant,'CI Recipe Product',1.000,10000,10000)");
    $s->execute(['business'=>$businessId,'branch'=>$branchId,'order'=>$orderId,'product'=>$productId,'variant'=>$variantId]);
    return [$orderId,(int)$pdo->lastInsertId()];
};
$service = new OrderInventoryLifecycleService($pdo);
$readBalance = static function (int $locationId) use ($pdo,$businessId,$branchId,$stockId): array {
    $s=$pdo->prepare('SELECT quantity_on_hand,quantity_reserved FROM pos_stock_balances
      WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location');
    $s->execute(['business'=>$businessId,'branch'=>$branchId,'item'=>$stockId,'location'=>$locationId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Stock balance missing.');
};
[$orderId,$orderItemId] = $createOrder('consume',$fallbackVariantId);
$pdo->beginTransaction();
$service->apply($scope,$orderId,'accepted','ci-reserve-'.$prefix);
$pdo->commit();
$pdo->beginTransaction();
$service->apply($scope,$orderId,'accepted','ci-reserve-'.$prefix);
$pdo->commit();
$assert($readBalance($locationIds['kitchen'])['quantity_reserved']==='0.400000','Kitchen priority allocation was not reserved.');
$assert($readBalance($locationIds['storage'])['quantity_reserved']==='0.100000','Storage fallback allocation was not reserved.');
$s=$pdo->prepare('SELECT recipe_version_id FROM pos_order_items WHERE id=:id');
$s->execute(['id'=>$orderItemId]);
$assert((int)$s->fetchColumn()===$versionId,'Active product recipe version was not pinned to the order item.');
$s=$pdo->prepare('SELECT COUNT(*) FROM pos_order_item_recipe_lines WHERE business_id=:business AND branch_id=:branch AND order_item_id=:item');
$s->execute(['business'=>$businessId,'branch'=>$branchId,'item'=>$orderItemId]);
$assert((int)$s->fetchColumn()===2,'Recipe snapshot was not split across both stock locations.');

$pdo->beginTransaction();
$service->apply($scope,$orderId,'completed','ci-complete-'.$prefix);
$pdo->commit();
$pdo->beginTransaction();
$service->apply($scope,$orderId,'completed','ci-complete-replay-'.$prefix);
$pdo->commit();
$assert($readBalance($locationIds['kitchen'])['quantity_on_hand']==='0.000000','Kitchen on-hand quantity was not consumed.');
$assert($readBalance($locationIds['storage'])['quantity_reserved']==='0.000000','Storage reservation was not cleared on consumption.');
$s=$pdo->prepare("SELECT COUNT(*) FROM pos_stock_ledger WHERE business_id=:business AND branch_id=:branch AND movement_type='consumption_out'");
$s->execute(['business'=>$businessId,'branch'=>$branchId]);
$assert((int)$s->fetchColumn()===2,'Consumption ledger entries were not written for both locations.');
$s=$pdo->prepare('SELECT COUNT(*) FROM pos_stock_ledger WHERE business_id=:business AND branch_id=:branch AND source_order_id=:order');
$s->execute(['business'=>$businessId,'branch'=>$branchId,'order'=>$orderId]);
$assert((int)$s->fetchColumn()===2,'Order-driven consumption ledger entries must retain their originating order reference.');
$s=$pdo->prepare("SELECT CAST(SUM(total_cost_amount) AS CHAR) FROM pos_stock_ledger WHERE business_id=:business AND branch_id=:branch AND movement_type='consumption_out'");
$s->execute(['business'=>$businessId,'branch'=>$branchId]);
$assert((string)$s->fetchColumn()==='-1050','Consumption valuation did not use reservation-time unit cost snapshots.');

[$releaseOrderId] = $createOrder('release');
$pdo->beginTransaction();
$service->apply($scope,$releaseOrderId,'accepted','ci-release-reserve-'.$prefix);
$pdo->commit();
$pdo->beginTransaction();
$service->apply($scope,$releaseOrderId,'cancelled','ci-release-cancel-'.$prefix);
$pdo->commit();
$pdo->beginTransaction();
$service->apply($scope,$releaseOrderId,'cancelled','ci-release-cancel-replay-'.$prefix);
$pdo->commit();
$assert($readBalance($locationIds['storage'])['quantity_reserved']==='0.000000','Cancellation did not release the active reservation.');

[$variantOrderId,$variantOrderItemId] = $createOrder('variant',$dedicatedVariantId);
$pdo->beginTransaction();
$service->apply($scope,$variantOrderId,'accepted','ci-variant-reserve-'.$prefix);
$pdo->commit();
$s=$pdo->prepare('SELECT recipe_version_id FROM pos_order_items WHERE id=:id');
$s->execute(['id'=>$variantOrderItemId]);
$assert((int)$s->fetchColumn()===$variantVersionId,'Variant-specific recipe did not take precedence over the product recipe.');
$pdo->beginTransaction();
$service->apply($scope,$variantOrderId,'cancelled','ci-variant-release-'.$prefix);
$pdo->commit();

[$missingReservationOrderId,$missingReservationItemId] = $createOrder('missing-reservation');
$pdo->prepare('UPDATE pos_order_items SET recipe_version_id=:version WHERE id=:item')
    ->execute(['version'=>$versionId,'item'=>$missingReservationItemId]);
$pdo->beginTransaction();
try {
    $service->apply($scope,$missingReservationOrderId,'completed','ci-missing-reservation-'.$prefix);
    throw new RuntimeException('Recipe-tracked order completed without a stock reservation.');
} catch (HttpException $error) {
    $pdo->rollBack();
    $assert($error->errorCode === 'stock_reservation_missing', 'Unexpected missing-reservation error.');
}

[$shortOrderId] = $createOrder('shortage');
$pdo->prepare('UPDATE pos_stock_balances SET quantity_on_hand=0.100000 WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location')
    ->execute(['business'=>$businessId,'branch'=>$branchId,'item'=>$stockId,'location'=>$locationIds['storage']]);
$pdo->beginTransaction();
try {
    $service->apply($scope,$shortOrderId,'accepted','ci-shortage-'.$prefix);
    throw new RuntimeException('Insufficient stock was incorrectly accepted.');
} catch (HttpException $error) {
    $pdo->rollBack();
    $assert($error->status === 409, 'Unexpected shortage exception.');
}
$s=$pdo->prepare('SELECT COUNT(*) FROM pos_stock_reservations WHERE business_id=:business AND branch_id=:branch AND order_id=:order');
$s->execute(['business'=>$businessId,'branch'=>$branchId,'order'=>$shortOrderId]);
$assert((int)$s->fetchColumn()===0,'Failed reservation transaction left a reservation record behind.');

fwrite(STDOUT,"V3 order inventory reserve/consume/release/shortage integration checks passed.\n");
