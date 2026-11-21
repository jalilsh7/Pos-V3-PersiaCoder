<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Orders;

use PDO;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;

/**
 * Keeps recipe snapshots and stock reservation/consumption in the order transaction.
 * All quantities stay in DECIMAL SQL; PHP never performs floating-point stock arithmetic.
 */
final readonly class OrderInventoryLifecycleService
{
    public function __construct(private PDO $db) {}

    public function apply(
        BusinessScope $scope,
        int $orderId,
        string $targetStatus,
        string $idempotencyKey,
    ): void {
        if ($scope->branchId === null || $scope->branchId < 1) {
            throw new HttpException(400, 'branch_context_required', 'برای عملیات رزرو و مصرف موجودی، انتخاب شعبه الزامی است.');
        }
        $businessId = $scope->businessId;
        $branchId = $scope->branchId;
        if ($targetStatus === 'accepted') {
            $this->reserve($businessId, $branchId, $orderId, $idempotencyKey);
        } elseif ($targetStatus === 'completed') {
            $this->consume($businessId, $branchId, $orderId);
        } elseif (in_array($targetStatus, ['cancelled', 'rejected'], true)) {
            $this->release($businessId, $branchId, $orderId);
        }
    }

    private function reserve(int $businessId, int $branchId, int $orderId, string $key): void
    {
        $existing = $this->db->prepare(
            "SELECT id, status FROM pos_stock_reservations
             WHERE business_id = :business_id AND branch_id = :branch_id AND order_id = :order_id
               AND idempotency_key = :idempotency_key LIMIT 1 FOR UPDATE"
        );
        $existing->execute(['business_id'=>$businessId,'branch_id'=>$branchId,'order_id'=>$orderId,'idempotency_key'=>$key]);
        $found = $existing->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            if ((string)$found['status'] === 'active') return;
            throw new HttpException(409, 'stock_reservation_already_finalized', 'The stock reservation for this order is already finalized.');
        }

        $items = $this->db->prepare(
            'SELECT oi.id AS order_item_id, oi.product_id, oi.variant_id, oi.quantity
             FROM pos_order_items oi
             WHERE oi.business_id = :business_id AND oi.branch_id = :branch_id AND oi.order_id = :order_id
             ORDER BY oi.id FOR UPDATE'
        );
        $items->execute(['business_id'=>$businessId,'branch_id'=>$branchId,'order_id'=>$orderId]);
        $orderItems = $items->fetchAll(PDO::FETCH_ASSOC);
        $requirements = [];

        foreach ($orderItems as $item) {
            $recipe = $this->activeRecipe($businessId, (int)$item['product_id'], $item['variant_id'] === null ? null : (int)$item['variant_id']);
            if ($recipe === null) continue;
            $pinRecipe = $this->db->prepare(
                'UPDATE pos_order_items SET recipe_version_id = :version_id
                 WHERE business_id = :business_id AND branch_id = :branch_id AND id = :order_item_id'
            );
            $pinRecipe->execute([
                'version_id'=>(int)$recipe['version_id'],'business_id'=>$businessId,
                'branch_id'=>$branchId,'order_item_id'=>(int)$item['order_item_id'],
            ]);
            $lines = $this->db->prepare(
                'SELECT rl.stock_item_id, si.base_unit_id,
                        CAST(ROUND(CAST(rl.quantity AS DECIMAL(18,6))
                          * CAST(rl.conversion_factor_snapshot AS DECIMAL(18,8))
                          * (1 + CAST(rl.waste_percent AS DECIMAL(7,4)) / 100)
                          * CAST(:order_quantity AS DECIMAL(12,3)), 6) AS DECIMAL(18,6)) AS required_qty
                 FROM pos_recipe_lines rl
                 INNER JOIN pos_stock_items si ON si.id = rl.stock_item_id AND si.business_id = rl.business_id
                 WHERE rl.business_id = :business_id AND rl.recipe_version_id = :version_id
                 ORDER BY rl.stock_item_id, rl.id'
            );
            $lines->execute(['order_quantity'=>(string)$item['quantity'],'business_id'=>$businessId,'version_id'=>(int)$recipe['version_id']]);
            foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $stockId = (int)$line['stock_item_id'];
                $requirements[$stockId] ??= ['base_unit_id'=>(int)$line['base_unit_id'],'qty'=>'0','items'=>[]];
                $sum = $this->db->prepare('SELECT CAST(CAST(:a AS DECIMAL(18,6)) + CAST(:b AS DECIMAL(18,6)) AS DECIMAL(18,6))');
                $sum->execute(['a'=>$requirements[$stockId]['qty'],'b'=>(string)$line['required_qty']]);
                $requirements[$stockId]['qty'] = (string)$sum->fetchColumn();
                $requirements[$stockId]['items'][] = [
                    'order_item_id'=>(int)$item['order_item_id'],
                    'version_id'=>(int)$recipe['version_id'],
                    'quantity'=>(string)$line['required_qty'],
                    'allocated'=>'0',
                ];
            }
        }
        if ($requirements === []) return;

        $channelQuery = $this->db->prepare(
            'SELECT source_channel FROM pos_orders
             WHERE business_id=:business_id AND branch_id=:branch_id AND id=:order_id LIMIT 1'
        );
        $channelQuery->execute(['business_id'=>$businessId,'branch_id'=>$branchId,'order_id'=>$orderId]);
        $sourceType = (string)$channelQuery->fetchColumn() === 'storefront' ? 'storefront' : 'pos';
        $reservation = $this->db->prepare(
            "INSERT INTO pos_stock_reservations
             (business_id, branch_id, public_id, order_id, status, source_type, idempotency_key)
             VALUES (:business_id, :branch_id, :public_id, :order_id, 'active', :source_type, :idempotency_key)"
        );
        $reservation->execute([
            'business_id'=>$businessId,'branch_id'=>$branchId,'public_id'=>self::uuid(),
            'order_id'=>$orderId,'source_type'=>$sourceType,'idempotency_key'=>$key,
        ]);
        $reservationId = (int)$this->db->lastInsertId();

        $locationQuery = $this->db->prepare(
            "SELECT id FROM pos_stock_locations
             WHERE business_id = :business_id AND branch_id = :branch_id AND is_active = 1
               AND location_type IN ('kitchen','storage')
             ORDER BY CASE location_type WHEN 'kitchen' THEN 0 ELSE 1 END, id"
        );
        $locationQuery->execute(['business_id'=>$businessId,'branch_id'=>$branchId]);
        $locations = array_map('intval', $locationQuery->fetchAll(PDO::FETCH_COLUMN));
        if ($locations === []) {
            throw new HttpException(409, 'stock_location_required', 'برای رزرو مواد اولیه، ابتدا یک انبار فعال از نوع آشپزخانه یا انبار تعریف کنید.');
        }

        ksort($requirements);
        foreach ($requirements as $stockId => $requirement) {
            $itemCheck = $this->db->prepare(
                'SELECT is_active FROM pos_stock_items WHERE business_id = :business_id AND id = :id LIMIT 1'
            );
            $itemCheck->execute(['business_id'=>$businessId,'id'=>$stockId]);
            if ((int)$itemCheck->fetchColumn() !== 1) {
                throw new HttpException(409, 'recipe_stock_item_inactive', 'یکی از مواد اولیه رسپی غیرفعال شده است.');
            }

            $allocated = '0';
            foreach ($locations as $locationId) {
                $balanceInsert = $this->db->prepare(
                    'INSERT INTO pos_stock_balances
                     (stock_item_id,business_id,branch_id,location_id,quantity_on_hand,quantity_reserved,average_unit_cost)
                     VALUES (:item,:business,:branch,:location,0,0,0)
                     ON DUPLICATE KEY UPDATE stock_item_id = VALUES(stock_item_id)'
                );
                $balanceInsert->execute(['item'=>$stockId,'business'=>$businessId,'branch'=>$branchId,'location'=>$locationId]);
                $balance = $this->db->prepare(
                    'SELECT quantity_on_hand,quantity_reserved,average_unit_cost
                     FROM pos_stock_balances WHERE business_id=:business AND branch_id=:branch
                       AND stock_item_id=:item AND location_id=:location FOR UPDATE'
                );
                $balance->execute(['business'=>$businessId,'branch'=>$branchId,'item'=>$stockId,'location'=>$locationId]);
                $row = $balance->fetch(PDO::FETCH_ASSOC);
                $available = $this->decimalSubtract((string)$row['quantity_on_hand'], (string)$row['quantity_reserved']);
                $remaining = $this->decimalSubtract($requirement['qty'], $allocated);
                $take = $this->decimalMin($available, $remaining);
                if ($take === '0.000000') continue;

                $update = $this->db->prepare(
                    'UPDATE pos_stock_balances SET quantity_reserved = quantity_reserved + CAST(:qty AS DECIMAL(18,6))
                     WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location
                       AND quantity_on_hand - quantity_reserved >= CAST(:qty_check AS DECIMAL(18,6))'
                );
                $update->execute(['qty'=>$take,'qty_check'=>$take,'business'=>$businessId,'branch'=>$branchId,'item'=>$stockId,'location'=>$locationId]);
                if ($update->rowCount() !== 1) throw new HttpException(409, 'stock_insufficient', 'موجودی یکی از مواد اولیه برای پذیرش سفارش کافی نیست.');

                $lineInsert = $this->db->prepare(
                    'INSERT INTO pos_stock_reservation_lines
                     (business_id,branch_id,reservation_id,stock_item_id,location_id,base_quantity,unit_cost_snapshot)
                     VALUES (:business,:branch,:reservation,:item,:location,:qty,:unit_cost)'
                );
                $lineInsert->execute(['business'=>$businessId,'branch'=>$branchId,'reservation'=>$reservationId,'item'=>$stockId,'location'=>$locationId,'qty'=>$take,'unit_cost'=>(string)$row['average_unit_cost']]);
                $snapshot = $this->db->prepare(
                    'INSERT INTO pos_order_item_recipe_lines
                     (business_id,branch_id,order_item_id,recipe_version_id,stock_item_id,location_id,
                      required_base_quantity,consumed_base_quantity,unit_cost_snapshot)
                     VALUES (:business,:branch,:order_item,:version,:item,:location,:qty,0,:cost)'
                );
                $takeRemaining = $take;
                foreach ($requirements[$stockId]['items'] as $sourceIndex => $source) {
                    if ($this->decimalCompare($takeRemaining, '0') <= 0) break;
                    $sourceRemaining = $this->decimalSubtract($source['quantity'], $source['allocated']);
                    $sourceAllocation = $this->decimalMin($sourceRemaining, $takeRemaining);
                    if ($this->decimalCompare($sourceAllocation, '0') <= 0) continue;
                    $snapshot->execute([
                        'business'=>$businessId,'branch'=>$branchId,'order_item'=>$source['order_item_id'],
                        'version'=>$source['version_id'],'item'=>$stockId,'location'=>$locationId,
                        'qty'=>$sourceAllocation,'cost'=>(string)$row['average_unit_cost'],
                    ]);
                    $requirements[$stockId]['items'][$sourceIndex]['allocated'] = $this->decimalAdd($source['allocated'], $sourceAllocation);
                    $takeRemaining = $this->decimalSubtract($takeRemaining, $sourceAllocation);
                }
                if ($this->decimalCompare($takeRemaining, '0') > 0) {
                    throw new HttpException(409, 'recipe_allocation_inconsistent', 'تقسیم مواد اولیه بین اقلام سفارش با خطا مواجه شد.');
                }
                $allocated = $this->decimalAdd($allocated, $take);
                if ($this->decimalCompare($allocated, $requirement['qty']) >= 0) break;
            }
            if ($this->decimalCompare($allocated, $requirement['qty']) < 0) {
                throw new HttpException(409, 'stock_insufficient', 'موجودی مواد اولیه کافی نیست؛ سفارش پذیرفته نشد.');
            }
        }
    }

    private function consume(int $businessId, int $branchId, int $orderId): void
    {
        $q = $this->db->prepare(
            "SELECT r.id, r.status FROM pos_stock_reservations r
             WHERE r.business_id=:business AND r.branch_id=:branch AND r.order_id=:order
             ORDER BY r.id DESC LIMIT 1 FOR UPDATE"
        );
        $q->execute(['business'=>$businessId,'branch'=>$branchId,'order'=>$orderId]);
        $reservation = $q->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) return;
        if ((string)$reservation['status'] === 'consumed') return;
        if ((string)$reservation['status'] !== 'active') {
            throw new HttpException(409, 'stock_reservation_not_active', 'رزرو موجودی سفارش فعال نیست و امکان مصرف آن وجود ندارد.');
        }
        $lines = $this->db->prepare(
            'SELECT rl.*, si.base_unit_id
             FROM pos_stock_reservation_lines rl
             INNER JOIN pos_stock_items si ON si.id=rl.stock_item_id AND si.business_id=rl.business_id
             WHERE rl.business_id=:business AND rl.branch_id=:branch AND rl.reservation_id=:reservation
             ORDER BY rl.stock_item_id,rl.location_id FOR UPDATE'
        );
        $lines->execute(['business'=>$businessId,'branch'=>$branchId,'reservation'=>(int)$reservation['id']]);
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $qty = (string)$line['base_quantity'];
            $update = $this->db->prepare(
                'UPDATE pos_stock_balances SET quantity_on_hand=quantity_on_hand-CAST(:qty AS DECIMAL(18,6)),
                   quantity_reserved=quantity_reserved-CAST(:reserved AS DECIMAL(18,6))
                 WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location
                   AND quantity_on_hand>=CAST(:on_hand AS DECIMAL(18,6))
                   AND quantity_reserved>=CAST(:reserved_check AS DECIMAL(18,6))'
            );
            $update->execute(['qty'=>$qty,'reserved'=>$qty,'business'=>$businessId,'branch'=>$branchId,'item'=>(int)$line['stock_item_id'],'location'=>(int)$line['location_id'],'on_hand'=>$qty,'reserved_check'=>$qty]);
            if ($update->rowCount() !== 1) throw new HttpException(409, 'stock_balance_inconsistent', 'موجودی رزروشده با مانده انبار سازگار نیست.');
            $cost = $this->db->prepare('SELECT CAST(ROUND(CAST(:qty AS DECIMAL(18,6))*CAST(:unit_cost AS DECIMAL(24,8)),0) AS DECIMAL(20,0))');
            $cost->execute(['qty'=>$qty,'unit_cost'=>(string)$line['average_unit_cost']]);
            $totalCost = (string)$cost->fetchColumn();
            $eventKey = 'order-consume:' . $orderId . ':' . (int)$line['stock_item_id'] . ':' . (int)$line['location_id'];
            $ledger = $this->db->prepare(
                "INSERT INTO pos_stock_ledger
                 (business_id,branch_id,stock_item_id,location_id,document_line_id,movement_type,quantity_delta,
                  base_unit_id,unit_cost_snapshot,total_cost_amount,source_event_key)
                 VALUES (:business,:branch,:item,:location,NULL,'consumption_out',:delta,:unit,:cost,:total,:event)"
            );
            $ledger->execute([
                'business'=>$businessId,'branch'=>$branchId,'item'=>(int)$line['stock_item_id'],'location'=>(int)$line['location_id'],
                'delta'=>'-'.$qty,'unit'=>(int)$line['base_unit_id'],'cost'=>(string)$line['unit_cost_snapshot'],
                'total'=>'-'.$totalCost,'event'=>$eventKey,
            ]);
            $lineUpdate = $this->db->prepare(
                'UPDATE pos_stock_reservation_lines SET consumed_quantity=base_quantity
                 WHERE business_id=:business AND branch_id=:branch AND id=:id'
            );
            $lineUpdate->execute(['business'=>$businessId,'branch'=>$branchId,'id'=>(int)$line['id']]);
            $snapshot = $this->db->prepare(
                'UPDATE pos_order_item_recipe_lines SET consumed_base_quantity=required_base_quantity,
                   unit_cost_snapshot=:cost
                 WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location
                   AND order_item_id IN (SELECT id FROM pos_order_items WHERE business_id=:business2 AND branch_id=:branch2 AND order_id=:order)'
            );
            $snapshot->execute(['cost'=>(string)$line['unit_cost_snapshot'],'business'=>$businessId,'branch'=>$branchId,'item'=>(int)$line['stock_item_id'],'location'=>(int)$line['location_id'],'business2'=>$businessId,'branch2'=>$branchId,'order'=>$orderId]);
        }
        $this->db->prepare("UPDATE pos_stock_reservations SET status='consumed' WHERE business_id=:business AND branch_id=:branch AND id=:id")
            ->execute(['business'=>$businessId,'branch'=>$branchId,'id'=>(int)$reservation['id']]);
    }

    private function release(int $businessId, int $branchId, int $orderId): void
    {
        $q = $this->db->prepare(
            "SELECT id,status FROM pos_stock_reservations WHERE business_id=:business AND branch_id=:branch
             AND order_id=:order ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $q->execute(['business'=>$businessId,'branch'=>$branchId,'order'=>$orderId]);
        $reservation = $q->fetch(PDO::FETCH_ASSOC);
        if (!$reservation || (string)$reservation['status'] !== 'active') return;
        $lines = $this->db->prepare(
            'SELECT id,stock_item_id,location_id,base_quantity FROM pos_stock_reservation_lines
             WHERE business_id=:business AND branch_id=:branch AND reservation_id=:reservation
             ORDER BY stock_item_id,location_id FOR UPDATE'
        );
        $lines->execute(['business'=>$businessId,'branch'=>$branchId,'reservation'=>(int)$reservation['id']]);
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $update = $this->db->prepare(
                'UPDATE pos_stock_balances SET quantity_reserved=quantity_reserved-CAST(:qty AS DECIMAL(18,6))
                 WHERE business_id=:business AND branch_id=:branch AND stock_item_id=:item AND location_id=:location
                   AND quantity_reserved>=CAST(:check_qty AS DECIMAL(18,6))'
            );
            $update->execute(['qty'=>(string)$line['base_quantity'],'check_qty'=>(string)$line['base_quantity'],'business'=>$businessId,'branch'=>$branchId,'item'=>(int)$line['stock_item_id'],'location'=>(int)$line['location_id']]);
            if ($update->rowCount() !== 1) throw new HttpException(409, 'stock_reservation_inconsistent', 'رزرو موجودی قابل آزادسازی نیست؛ عملیات لغو متوقف شد.');
            $this->db->prepare('UPDATE pos_stock_reservation_lines SET released_quantity=base_quantity WHERE business_id=:business AND branch_id=:branch AND id=:id')
                ->execute(['business'=>$businessId,'branch'=>$branchId,'id'=>(int)$line['id']]);
        }
        $this->db->prepare("UPDATE pos_stock_reservations SET status='released' WHERE business_id=:business AND branch_id=:branch AND id=:id")
            ->execute(['business'=>$businessId,'branch'=>$branchId,'id'=>(int)$reservation['id']]);
    }

    private function activeRecipe(int $businessId, int $productId, ?int $variantId): ?array
    {
        $find = function (?int $variant) use ($businessId,$productId): ?array {
            $s = $this->db->prepare(
                "SELECT r.id,rv.id AS version_id FROM pos_recipes r
                 INNER JOIN pos_recipe_versions rv ON rv.recipe_id=r.id AND rv.business_id=r.business_id
                 WHERE r.business_id=:business AND r.product_id=:product AND r.variant_scope_id=:variant_scope
                   AND r.is_active=1 AND rv.status='active'
                 ORDER BY rv.effective_from DESC,rv.id DESC LIMIT 1 FOR UPDATE"
            );
            $s->execute(['business'=>$businessId,'product'=>$productId,'variant_scope'=>$variant ?? 0]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        };
        return ($variantId === null ? null : $find($variantId)) ?? $find(null);
    }

    private function decimalAdd(string $a,string $b): string { return $this->decimalOp($a,$b,'+'); }
    private function decimalSubtract(string $a,string $b): string { return $this->decimalOp($a,$b,'-'); }
    private function decimalOp(string $a,string $b,string $op): string {
        $s=$this->db->prepare("SELECT CAST(CAST(:a AS DECIMAL(18,6)) {$op} CAST(:b AS DECIMAL(18,6)) AS DECIMAL(18,6))");
        $s->execute(['a'=>$a,'b'=>$b]); return (string)$s->fetchColumn();
    }
    private function decimalCompare(string $a,string $b): int {
        $s=$this->db->prepare('SELECT CASE WHEN CAST(:a AS DECIMAL(18,6)) < CAST(:b AS DECIMAL(18,6)) THEN -1 WHEN CAST(:a2 AS DECIMAL(18,6)) > CAST(:b2 AS DECIMAL(18,6)) THEN 1 ELSE 0 END');
        $s->execute(['a'=>$a,'b'=>$b,'a2'=>$a,'b2'=>$b]); return (int)$s->fetchColumn();
    }
    private function decimalMin(string $a,string $b): string {
        $s=$this->db->prepare('SELECT CAST(LEAST(CAST(:a AS DECIMAL(18,6)),CAST(:b AS DECIMAL(18,6))) AS DECIMAL(18,6))');
        $s->execute(['a'=>$a,'b'=>$b]); return (string)$s->fetchColumn();
    }
    private static function uuid(): string {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
