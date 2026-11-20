<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Inventory;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\InventoryRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class InventoryService
{
    private InventoryRepository $inventory;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->inventory = new InventoryRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function listUnits(): array
    {
        return $this->inventory->listUnits();
    }

    public function listStockItems(BusinessScope $scope, ?string $search, int $limit): array
    {
        if ($search !== null && self::unicodeLength($search) > 100) {
            throw new HttpException(422, 'inventory_search_too_long', 'Search text must not exceed 100 characters.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The inventory page size must be between 1 and 100.');
        }
        return $this->inventory->listStockItems($scope->businessId, $search, $limit);
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function createStockItem(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $unitCode = $payload['base_unit_code'] ?? null;
        $negativePolicy = $payload['negative_stock_policy'] ?? 'block';
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)) {
            throw new HttpException(422, 'stock_item_code_invalid', 'Stock item code is invalid.');
        }
        if ($name === '' || self::unicodeLength($name) > 160) {
            throw new HttpException(422, 'stock_item_name_invalid', 'Stock item name is invalid.');
        }
        if (!is_string($unitCode) || !preg_match('/^[a-z0-9._-]{1,32}$/', $unitCode)) {
            throw new HttpException(422, 'stock_item_unit_invalid', 'A valid base_unit_code is required.');
        }
        if ($negativePolicy !== 'block') {
            throw new HttpException(422, 'negative_stock_policy_unsupported', 'Only the block negative-stock policy is supported.');
        }
        $unit = $this->inventory->findUnit($unitCode);
        if ($unit === null) {
            throw new HttpException(422, 'stock_item_unit_not_found', 'The selected base unit does not exist.');
        }
        $publicId = self::uuidV4();
        $after = [
            'code' => $code, 'name' => $name,
            'base_unit_code' => (string) $unit['code'],
            'negative_stock_policy' => 'block', 'is_active' => true,
        ];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-item.create', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $publicId, $code, $name, $unit, $after, $requestId, $ip): array {
                if ($this->inventory->stockItemCodeExists($scope->businessId, $code)) {
                    throw new HttpException(409, 'stock_item_code_exists', 'A stock item with this code already exists in this business.');
                }
                $this->inventory->createStockItem($scope->businessId, $publicId, $code, $name, (int) $unit['id']);
                $this->audit->record(
                    $actor->userId, 'inventory.stock_item.created', 'stock_item', $publicId,
                    null, $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 201, 'body' => [
                    'data' => ['stock_item' => ['id' => $publicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function updateStockItem(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $publicId, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($publicId)) {
            throw new HttpException(422, 'stock_item_id_invalid', 'The stock item identifier is invalid.');
        }
        $current = $this->inventory->findStockItem($scope->businessId, $publicId);
        if ($current === null) throw new HttpException(404, 'stock_item_not_found', 'The stock item was not found.');
        if (array_key_exists('base_unit_code', $payload) && $payload['base_unit_code'] !== $current['base_unit_code']) {
            throw new HttpException(409, 'stock_item_base_unit_immutable', 'The base unit cannot be changed after the stock item is created.');
        }
        $code = trim((string) ($payload['code'] ?? $current['code']));
        $name = trim((string) ($payload['name'] ?? $current['name']));
        $active = $payload['is_active'] ?? ((int) $current['is_active'] === 1);
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $code)
            || $name === '' || self::unicodeLength($name) > 160 || !is_bool($active)) {
            throw new HttpException(422, 'stock_item_fields_invalid', 'Stock item code, name or active flag is invalid.');
        }
        $itemId = (int) $current['id'];
        $before = [
            'code' => (string) $current['code'], 'name' => (string) $current['name'],
            'base_unit_code' => (string) $current['base_unit_code'], 'is_active' => (bool) $current['is_active'],
        ];
        $after = ['code' => $code, 'name' => $name, 'base_unit_code' => (string) $current['base_unit_code'], 'is_active' => $active];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-item.update', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $publicId, $itemId, $code, $name, $active, $before, $after, $requestId, $ip): array {
                if ($this->inventory->stockItemCodeExists($scope->businessId, $code, $itemId)) {
                    throw new HttpException(409, 'stock_item_code_exists', 'A stock item with this code already exists in this business.');
                }
                if (!$active && ($this->inventory->stockItemHasBalance($scope->businessId, $itemId)
                    || $this->inventory->stockItemHasActiveRecipe($scope->businessId, $itemId))) {
                    throw new HttpException(409, 'stock_item_in_use', 'A stock item with remaining stock or an active recipe cannot be disabled.');
                }
                $this->inventory->updateStockItem($scope->businessId, $itemId, $code, $name, $active);
                $this->audit->record(
                    $actor->userId, 'inventory.stock_item.updated', 'stock_item', $publicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['stock_item' => ['id' => $publicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    public function listLocations(BusinessScope $scope): array
    {
        $branchId = self::requireBranch($scope);
        return $this->inventory->listLocations($scope->businessId, $branchId);
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function createLocation(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::requireBranch($scope);
        $code = trim((string) ($payload['code'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $type = $payload['location_type'] ?? 'storage';
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)) {
            throw new HttpException(422, 'stock_location_code_invalid', 'Stock location code is invalid.');
        }
        if ($name === '' || self::unicodeLength($name) > 120) {
            throw new HttpException(422, 'stock_location_name_invalid', 'Stock location name is invalid.');
        }
        if (!is_string($type) || !in_array($type, ['storage', 'kitchen', 'bar', 'freezer', 'other'], true)) {
            throw new HttpException(422, 'stock_location_type_invalid', 'Stock location type is invalid.');
        }
        $publicId = self::uuidV4();
        $after = ['code' => $code, 'name' => $name, 'location_type' => $type, 'is_active' => true];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-location.create', $key, $fingerprint,
            function (PDO $db) use ($scope, $branchId, $actor, $publicId, $code, $name, $type, $after, $requestId, $ip): array {
                if ($this->inventory->locationCodeExists($scope->businessId, $branchId, $code)) {
                    throw new HttpException(409, 'stock_location_code_exists', 'A stock location with this code already exists in this branch.');
                }
                $this->inventory->createLocation($scope->businessId, $branchId, $publicId, $code, $name, $type);
                $this->audit->record(
                    $actor->userId, 'inventory.stock_location.created', 'stock_location', $publicId,
                    null, $after, null, $requestId, $ip, $scope->businessId, $branchId,
                );
                return ['status' => 201, 'body' => [
                    'data' => ['location' => ['id' => $publicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:array<string,mixed>,replayed:bool} */
    public function updateLocation(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $publicId, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        $branchId = self::requireBranch($scope);
        if (!self::isUuid($publicId)) {
            throw new HttpException(422, 'stock_location_id_invalid', 'The stock location identifier is invalid.');
        }
        $current = $this->inventory->findLocation($scope->businessId, $branchId, $publicId);
        if ($current === null) throw new HttpException(404, 'stock_location_not_found', 'The stock location was not found.');
        $code = trim((string) ($payload['code'] ?? $current['code']));
        $name = trim((string) ($payload['name'] ?? $current['name']));
        $type = $payload['location_type'] ?? $current['location_type'];
        $active = $payload['is_active'] ?? ((int) $current['is_active'] === 1);
        if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)
            || $name === '' || self::unicodeLength($name) > 120
            || !is_string($type) || !in_array($type, ['storage', 'kitchen', 'bar', 'freezer', 'other'], true)
            || !is_bool($active)) {
            throw new HttpException(422, 'stock_location_fields_invalid', 'Stock location fields are invalid.');
        }
        $locationId = (int) $current['id'];
        $before = [
            'code' => (string) $current['code'], 'name' => (string) $current['name'],
            'location_type' => (string) $current['location_type'], 'is_active' => (bool) $current['is_active'],
        ];
        $after = ['code' => $code, 'name' => $name, 'location_type' => $type, 'is_active' => $active];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.stock-location.update', $key, $fingerprint,
            function (PDO $db) use ($scope, $branchId, $actor, $publicId, $locationId, $code, $name, $type, $active, $before, $after, $requestId, $ip): array {
                if ($this->inventory->locationCodeExists($scope->businessId, $branchId, $code, $locationId)) {
                    throw new HttpException(409, 'stock_location_code_exists', 'A stock location with this code already exists in this branch.');
                }
                if (!$active && $this->inventory->locationHasStock($scope->businessId, $branchId, $locationId)) {
                    throw new HttpException(409, 'stock_location_has_stock', 'A location with on-hand or reserved stock cannot be disabled.');
                }
                $this->inventory->updateLocation($scope->businessId, $branchId, $locationId, $code, $name, $type, $active);
                $this->audit->record(
                    $actor->userId, 'inventory.stock_location.updated', 'stock_location', $publicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId, $branchId,
                );
                return ['status' => 200, 'body' => [
                    'data' => ['location' => ['id' => $publicId] + $after],
                    'meta' => (object) [],
                ]];
            },
        );
    }


    /** @return list<array<string,mixed>> */
    public function listBalances(
        BusinessScope $scope, ?string $stockItemPublicId, ?string $locationPublicId, int $limit,
    ): array {
        $branchId = self::requireBranch($scope);
        if ($limit < 1 || $limit > 200) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The inventory page size must be between 1 and 200.');
        }
        $stockItemId = null;
        if ($stockItemPublicId !== null) {
            if (!self::isUuid($stockItemPublicId)) {
                throw new HttpException(422, 'stock_item_id_invalid', 'The stock item identifier is invalid.');
            }
            $item = $this->inventory->findStockItem($scope->businessId, $stockItemPublicId);
            if ($item === null) {
                throw new HttpException(404, 'stock_item_not_found', 'The stock item was not found.');
            }
            $stockItemId = (int) $item['id'];
        }
        $locationId = null;
        if ($locationPublicId !== null) {
            if (!self::isUuid($locationPublicId)) {
                throw new HttpException(422, 'stock_location_id_invalid', 'The stock location identifier is invalid.');
            }
            $location = $this->inventory->findLocation($scope->businessId, $branchId, $locationPublicId);
            if ($location === null) {
                throw new HttpException(404, 'stock_location_not_found', 'The stock location was not found in this branch.');
            }
            $locationId = (int) $location['id'];
        }
        return $this->inventory->listBalances(
            $scope->businessId, $branchId, $stockItemId, $locationId, $limit,
        );
    }

    /** @return array{items:list<array<string,mixed>>,next_cursor:?string} */
    public function listLedger(
        BusinessScope $scope, ?string $stockItemPublicId, ?string $locationPublicId,
        ?int $beforeId, int $limit,
    ): array {
        $branchId = self::requireBranch($scope);
        if ($limit < 1 || $limit > 100) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The ledger page size must be between 1 and 100.');
        }
        if ($beforeId !== null && $beforeId < 1) {
            throw new HttpException(422, 'inventory_cursor_invalid', 'The ledger cursor is invalid.');
        }
        $stockItemId = null;
        if ($stockItemPublicId !== null) {
            if (!self::isUuid($stockItemPublicId)) {
                throw new HttpException(422, 'stock_item_id_invalid', 'The stock item identifier is invalid.');
            }
            $item = $this->inventory->findStockItem($scope->businessId, $stockItemPublicId);
            if ($item === null) {
                throw new HttpException(404, 'stock_item_not_found', 'The stock item was not found.');
            }
            $stockItemId = (int) $item['id'];
        }
        $locationId = null;
        if ($locationPublicId !== null) {
            if (!self::isUuid($locationPublicId)) {
                throw new HttpException(422, 'stock_location_id_invalid', 'The stock location identifier is invalid.');
            }
            $location = $this->inventory->findLocation($scope->businessId, $branchId, $locationPublicId);
            if ($location === null) {
                throw new HttpException(404, 'stock_location_not_found', 'The stock location was not found in this branch.');
            }
            $locationId = (int) $location['id'];
        }
        $rows = $this->inventory->listLedger(
            $scope->businessId, $branchId, $stockItemId, $locationId, $beforeId, $limit + 1,
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $nextCursor = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = (string) $last['ledger_id'];
        }
        foreach ($rows as &$row) {
            unset($row['ledger_id']);
        }
        unset($row);
        return ['items' => $rows, 'next_cursor' => $nextCursor];
    }

    private static function requireBranch(BusinessScope $scope): int
    {
        if ($scope->branchId === null) {
            throw new HttpException(400, 'branch_context_required', 'A branch context is required for stock locations.');
        }
        return $scope->branchId;
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        return $count === false ? PHP_INT_MAX : $count;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
