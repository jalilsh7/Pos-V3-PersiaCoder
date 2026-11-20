<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Inventory;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\SupplierRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class SupplierService
{
    private SupplierRepository $suppliers;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->suppliers = new SupplierRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function list(BusinessScope $scope, bool $includeInactive = true): array
    {
        return $this->suppliers->listForBusiness($scope->businessId, $includeInactive);
    }

    public function create(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        [$name, $phone, $notes] = $this->normalize($payload);
        $publicId = self::uuidV4();
        $after = ['name' => $name, 'phone' => $phone, 'notes' => $notes, 'is_active' => true];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.supplier.create', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $publicId, $name, $phone, $notes, $after, $requestId, $ip): array {
                $this->suppliers->create($scope->businessId, $publicId, $name, $phone, $notes);
                $this->audit->record($actor->userId, 'inventory.supplier.created', 'supplier', $publicId,
                    null, $after, null, $requestId, $ip, $scope->businessId);
                return ['status' => 201, 'body' => [
                    'data' => ['supplier' => ['id' => $publicId] + $after], 'meta' => (object) [],
                ]];
            },
        );
    }

    public function update(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $publicId, array $payload,
        string $key, string $requestId, string $ip,
    ): array {
        if (!self::isUuid($publicId)) throw new HttpException(422, 'supplier_id_invalid', 'The supplier identifier is invalid.');
        $current = $this->suppliers->find($scope->businessId, $publicId);
        if ($current === null) throw new HttpException(404, 'supplier_not_found', 'The supplier was not found.');
        [$name, $phone, $notes] = $this->normalize($payload, $current);
        $active = $payload['is_active'] ?? (bool) $current['is_active'];
        if (!is_bool($active)) throw new HttpException(422, 'supplier_active_invalid', 'The supplier active flag is invalid.');
        $before = ['name' => (string) $current['name'], 'phone' => $current['phone'],
            'notes' => $current['notes'], 'is_active' => (bool) $current['is_active']];
        $after = ['name' => $name, 'phone' => $phone, 'notes' => $notes, 'is_active' => $active];
        $fingerprint = hash('sha256', json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute(
            $actor->userId, $scope, 'inventory.supplier.update', $key, $fingerprint,
            function (PDO $db) use ($scope, $actor, $publicId, $current, $name, $phone, $notes, $active, $before, $after, $requestId, $ip): array {
                $this->suppliers->update($scope->businessId, (int) $current['id'], $name, $phone, $notes, $active);
                $this->audit->record($actor->userId, 'inventory.supplier.updated', 'supplier', $publicId,
                    $before, $after, null, $requestId, $ip, $scope->businessId);
                return ['status' => 200, 'body' => [
                    'data' => ['supplier' => ['id' => $publicId] + $after], 'meta' => (object) [],
                ]];
            },
        );
    }

    private function normalize(array $payload, ?array $current = null): array
    {
        $name = trim((string) ($payload['name'] ?? $current['name'] ?? ''));
        $phoneValue = array_key_exists('phone', $payload) ? $payload['phone'] : ($current['phone'] ?? null);
        $notesValue = array_key_exists('notes', $payload) ? $payload['notes'] : ($current['notes'] ?? null);
        if ($name === '' || self::unicodeLength($name) > 180) {
            throw new HttpException(422, 'supplier_name_invalid', 'Supplier name is required and must not exceed 180 characters.');
        }
        if ($phoneValue !== null && !is_string($phoneValue)) throw new HttpException(422, 'supplier_phone_invalid', 'Supplier phone must be text.');
        $phone = $phoneValue === null ? null : trim($phoneValue);
        if ($phone === '') $phone = null;
        if ($phone !== null && self::unicodeLength($phone) > 32) throw new HttpException(422, 'supplier_phone_invalid', 'Supplier phone must not exceed 32 characters.');
        if ($notesValue !== null && !is_string($notesValue)) throw new HttpException(422, 'supplier_notes_invalid', 'Supplier notes must be text.');
        $notes = $notesValue === null ? null : trim($notesValue);
        if ($notes === '') $notes = null;
        if ($notes !== null && self::unicodeLength($notes) > 1000) throw new HttpException(422, 'supplier_notes_invalid', 'Supplier notes must not exceed 1000 characters.');
        return [$name, $phone, $notes];
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
