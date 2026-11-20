<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Dining;

use PDO;
use PDOException;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\DiningRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class DiningService
{
    private DiningRepository $dining;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->dining = new DiningRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function list(BusinessScope $scope): array
    {
        return [
            'areas' => $this->dining->listAreas($scope->businessId, (int) $scope->branchId),
            'tables' => $this->dining->listTables($scope->businessId, (int) $scope->branchId),
        ];
    }

    public function createArea(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $rawName = $payload['name'] ?? null;
        $name = is_string($rawName) ? trim($rawName) : '';
        $sortOrder = $payload['sort_order'] ?? 0;
        if ($name === '' || self::unicodeLength($name) > 80) {
            throw new HttpException(422, 'dining_area_name_invalid', 'Area name is required and must not exceed 80 characters.');
        }
        if (!is_int($sortOrder) || $sortOrder < 0 || $sortOrder > 10000) {
            throw new HttpException(422, 'dining_area_sort_order_invalid', 'Area sort order must be an integer between 0 and 10000.');
        }

        $fingerprint = self::fingerprint(['name' => $name, 'sort_order' => $sortOrder]);
        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'dining.areas.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $name, $sortOrder, $requestId, $ipAddress): array {
                $publicId = self::uuidV4();
                $this->dining->createArea($scope->businessId, (int) $scope->branchId, $publicId, $name, $sortOrder);
                $this->audit->record(
                    $actor->userId,
                    'dining.area_created',
                    'dining_area',
                    $publicId,
                    null,
                    ['name' => $name, 'sort_order' => $sortOrder],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['area' => ['id' => $publicId, 'name' => $name, 'sort_order' => $sortOrder, 'is_active' => true]], 'meta' => (object) []],
                ];
            },
        );
    }

    public function createTable(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $areaPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($areaPublicId)) {
            throw new HttpException(400, 'invalid_dining_area_id', 'The dining area identifier is invalid.');
        }
        $rawCode = $payload['code'] ?? null;
        $code = is_string($rawCode) ? strtoupper(trim($rawCode)) : '';
        $capacity = $payload['capacity'] ?? 2;
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,19}$/', $code)) {
            throw new HttpException(422, 'dining_table_code_invalid', 'Table code must contain 1-20 letters, digits, hyphens or underscores.');
        }
        if (!is_int($capacity) || $capacity < 1 || $capacity > 50) {
            throw new HttpException(422, 'dining_table_capacity_invalid', 'Table capacity must be an integer between 1 and 50.');
        }

        $fingerprint = self::fingerprint([
            'area_id' => $areaPublicId,
            'code' => $code,
            'capacity' => $capacity,
        ]);
        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'dining.tables.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $areaPublicId, $code, $capacity, $requestId, $ipAddress): array {
                $areaId = $this->dining->findAreaId($scope->businessId, (int) $scope->branchId, $areaPublicId);
                if ($areaId === null) {
                    throw new HttpException(404, 'dining_area_not_found', 'The active dining area was not found in this branch.');
                }
                $publicId = self::uuidV4();
                try {
                    $this->dining->createTable(
                        $scope->businessId,
                        (int) $scope->branchId,
                        $areaId,
                        $publicId,
                        $code,
                        $capacity,
                    );
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        throw new HttpException(409, 'dining_table_code_exists', 'A table with this code already exists in the selected area.');
                    }
                    throw $exception;
                }
                $this->audit->record(
                    $actor->userId,
                    'dining.table_created',
                    'dining_table',
                    $publicId,
                    null,
                    ['area_id' => $areaPublicId, 'code' => $code, 'capacity' => $capacity],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['table' => ['id' => $publicId, 'area_id' => $areaPublicId, 'code' => $code, 'capacity' => $capacity, 'is_active' => true, 'status' => 'available']], 'meta' => (object) []],
                ];
            },
        );
    }

    public function openSession(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $tablePublicId,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($tablePublicId)) {
            throw new HttpException(400, 'invalid_dining_table_id', 'The dining table identifier is invalid.');
        }
        $fingerprint = self::fingerprint(['table_id' => $tablePublicId]);
        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'dining.sessions.open',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $tablePublicId, $requestId, $ipAddress): array {
                $table = $this->dining->findTableForUpdate($scope->businessId, (int) $scope->branchId, $tablePublicId);
                if ($table === null || (int) $table['is_active'] !== 1) {
                    throw new HttpException(404, 'active_dining_table_not_found', 'The active dining table was not found in this branch.');
                }
                if ($this->dining->hasOpenSession($scope->businessId, (int) $scope->branchId, (int) $table['id'])) {
                    throw new HttpException(409, 'dining_table_already_occupied', 'This table already has an open session.');
                }
                $sessionPublicId = self::uuidV4();
                try {
                    $this->dining->createTableSession(
                        $scope->businessId,
                        (int) $scope->branchId,
                        (int) $table['id'],
                        $sessionPublicId,
                        $actor->userId,
                    );
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        throw new HttpException(409, 'dining_table_already_occupied', 'This table already has an open session.');
                    }
                    throw $exception;
                }
                $this->audit->record(
                    $actor->userId,
                    'dining.table_session_opened',
                    'table_session',
                    $sessionPublicId,
                    null,
                    ['table_id' => $tablePublicId, 'table_code' => $table['table_code']],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['session' => ['id' => $sessionPublicId, 'table_id' => $tablePublicId, 'status' => 'open']], 'meta' => (object) []],
                ];
            },
        );
    }

    public function closeSession(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $sessionPublicId,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($sessionPublicId)) {
            throw new HttpException(400, 'invalid_table_session_id', 'The table session identifier is invalid.');
        }
        $fingerprint = self::fingerprint(['session_id' => $sessionPublicId, 'action' => 'close']);
        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'dining.sessions.close',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $sessionPublicId, $requestId, $ipAddress): array {
                $session = $this->dining->findSessionForUpdate($scope->businessId, (int) $scope->branchId, $sessionPublicId);
                if ($session === null) {
                    throw new HttpException(404, 'table_session_not_found', 'The table session was not found in this branch.');
                }
                if ((string) $session['status'] !== 'open') {
                    throw new HttpException(409, 'table_session_already_closed', 'The table session is already closed.');
                }
                if ($this->dining->hasUnfinishedOrdersForSession($scope->businessId, (int) $scope->branchId, (int) $session['id'])) {
                    throw new HttpException(409, 'table_session_has_open_orders', 'Complete or cancel all active orders before closing this table session.');
                }
                $this->dining->closeTableSession(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $session['id'],
                    $actor->userId,
                );
                $this->audit->record(
                    $actor->userId,
                    'dining.table_session_closed',
                    'table_session',
                    $sessionPublicId,
                    ['status' => 'open'],
                    ['status' => 'closed'],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 200,
                    'body' => ['data' => ['session_id' => $sessionPublicId, 'status' => 'closed'], 'meta' => (object) []],
                ];
            },
        );
    }

    private static function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
