<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Customers;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\CustomerRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class CustomerService
{
    private CustomerRepository $customers;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private PermissionService $permissions,
    ) {
        $this->customers = new CustomerRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function list(
        BusinessScope $scope,
        string $search,
        string $status,
        int $limit,
        ?string $cursor = null,
    ): array {
        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            throw new HttpException(422, 'customer_status_invalid', 'Customer status filter must be all, active or inactive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new HttpException(422, 'customer_limit_invalid', 'Customer limit must be between 1 and 100.');
        }
        $search = trim($search);
        if (self::unicodeLength($search) > 100) {
            throw new HttpException(422, 'customer_search_too_long', 'Customer search is too long.');
        }
        $result = $this->customers->list(
            $scope->businessId,
            $search,
            $status,
            $limit,
            self::decodeCursor($cursor),
        );
        return [
            'customers' => $result['customers'],
            'next_cursor' => self::encodeCursor($result['next_cursor_data']),
        ];
    }


    public function get(BusinessScope $scope, string $publicId): array
    {
        if (!self::isUuid($publicId)) {
            throw new HttpException(400, 'customer_id_invalid', 'The customer identifier is invalid.');
        }
        $detail = $this->customers->detail($scope->businessId, $publicId);
        if ($detail === null) {
            throw new HttpException(404, 'customer_not_found', 'The customer was not found in this business.');
        }
        return $detail;
    }

    public function create(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!$this->permissions->allows($actor, 'customers.manage', $scope)) {
            throw new HttpException(403, 'permission_denied', 'Customer management permission is required.');
        }
        $input = self::validatePayload($payload, null);
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'customers.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $input, $requestId, $ipAddress): array {
                $this->customers->lockBusiness($scope->businessId);
                if ($input['phone_normalized'] !== null
                    && $this->customers->findByPhoneForUpdate($scope->businessId, $input['phone_normalized']) !== null) {
                    throw new HttpException(409, 'customer_phone_exists', 'A customer with this phone number already exists.');
                }
                $publicId = self::uuidV4();
                $customer = $this->customers->create(
                    $scope->businessId,
                    $publicId,
                    $input['name'],
                    $input['phone_normalized'],
                    $input['phone_display'],
                    $input['notes'],
                );
                $createdDetail = $this->customers->detail($scope->businessId, $publicId);
                if ($createdDetail === null) {
                    throw new \RuntimeException('The created customer could not be reloaded.');
                }
                $this->audit->record(
                    $actor->userId,
                    'customer.created',
                    'customer',
                    $publicId,
                    null,
                    ['is_active' => true, 'has_phone' => $input['phone_normalized'] !== null],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return ['status' => 201, 'body' => ['data' => [
                    'customer' => $createdDetail['customer'],
                ], 'meta' => (object) []]];

            },
        );
    }

    public function update(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $publicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!$this->permissions->allows($actor, 'customers.manage', $scope)) {
            throw new HttpException(403, 'permission_denied', 'Customer management permission is required.');
        }
        if (!self::isUuid($publicId)) {
            throw new HttpException(400, 'customer_id_invalid', 'The customer identifier is invalid.');
        }

        $fingerprint = hash('sha256', json_encode([
            'customer_id' => $publicId,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'customers.update',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $publicId, $payload, $requestId, $ipAddress): array {
                $this->customers->lockBusiness($scope->businessId);
                $existing = $this->customers->findByPublicId($scope->businessId, $publicId, true);
                if ($existing === null) {
                    throw new HttpException(404, 'customer_not_found', 'The customer was not found in this business.');
                }
                $input = self::validatePayload($payload, $existing);
                if ($input['phone_normalized'] !== null
                    && $this->customers->findByPhoneForUpdate($scope->businessId, $input['phone_normalized'], (int) $existing['id']) !== null) {
                    throw new HttpException(409, 'customer_phone_exists', 'A customer with this phone number already exists.');
                }
                $this->customers->update(
                    $scope->businessId,
                    (int) $existing['id'],
                    $input['name'],
                    $input['phone_normalized'],
                    $input['phone_display'],
                    $input['notes'],
                    $input['is_active'],
                );
                $this->audit->record(
                    $actor->userId,
                    'customer.updated',
                    'customer',
                    $publicId,
                    ['is_active' => (bool) $existing['is_active'], 'has_phone' => $existing['phone_normalized'] !== null],
                    ['is_active' => $input['is_active'], 'has_phone' => $input['phone_normalized'] !== null],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                $detail = $this->customers->detail($scope->businessId, $publicId);
                return ['status' => 200, 'body' => ['data' => $detail, 'meta' => (object) []]];
            },
        );
    }

    /** @return array{name:string,phone_normalized:?string,phone_display:?string,notes:?string,is_active:bool} */
    private static function validatePayload(array $payload, ?array $existing): array
    {
        $allowed = $existing === null
            ? ['name', 'phone', 'notes']
            : ['name', 'phone', 'notes', 'is_active'];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new HttpException(422, 'customer_field_invalid', 'The customer payload contains an unsupported field.');
            }
        }
        $name = array_key_exists('name', $payload) ? $payload['name'] : ($existing['name'] ?? null);
        $phone = array_key_exists('phone', $payload) ? $payload['phone'] : ($existing['phone_display'] ?? '');
        $notes = array_key_exists('notes', $payload) ? $payload['notes'] : ($existing['notes'] ?? '');
        $isActive = array_key_exists('is_active', $payload) ? $payload['is_active'] : (bool) ($existing['is_active'] ?? true);

        if (!is_string($name) || trim($name) === '' || self::unicodeLength(trim($name)) > 160) {
            throw new HttpException(422, 'customer_name_invalid', 'Customer name is required and must not exceed 160 characters.');
        }
        if (!is_string($phone)) {
            throw new HttpException(422, 'customer_phone_invalid', 'Customer phone must be a string.');
        }
        if (!is_string($notes) || self::unicodeLength($notes) > 1000) {
            throw new HttpException(422, 'customer_notes_invalid', 'Customer notes must not exceed 1000 characters.');
        }
        if (!is_bool($isActive)) {
            throw new HttpException(422, 'customer_active_invalid', 'Customer active state must be a boolean.');
        }
        $phone = trim($phone);
        if (self::unicodeLength($phone) > 32) {
            throw new HttpException(422, 'customer_phone_invalid', 'Customer phone display must not exceed 32 characters.');
        }
        $normalized = $phone === '' ? null : self::normalizeIranMobile($phone);
        if ($phone !== '' && $normalized === null) {
            throw new HttpException(422, 'customer_phone_invalid', 'Enter a valid Iranian mobile number.');
        }

        return [
            'name' => trim($name),
            'phone_normalized' => $normalized,
            'phone_display' => $phone === '' ? null : $phone,
            'notes' => trim($notes) === '' ? null : trim($notes),
            'is_active' => $isActive,
        ];
    }

    private static function normalizeIranMobile(string $value): ?string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/[^0-9+]/', '', $value) ?? '';
        if (str_starts_with($digits, '+98')) $digits = '0' . substr($digits, 3);
        elseif (str_starts_with($digits, '0098')) $digits = '0' . substr($digits, 4);
        elseif (str_starts_with($digits, '98') && strlen($digits) === 12) $digits = '0' . substr($digits, 2);
        elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) $digits = '0' . $digits;
        return preg_match('/^09[0-9]{9}$/', $digits) ? $digits : null;
    }

    private static function unicodeLength(string $value): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        $length = preg_match_all('/./us', $value);
        return $length === false ? strlen($value) : $length;
    }

    /** @return array{updated_at:string,id:int}|null */
    private static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') return null;
        if (strlen($cursor) > 256 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new HttpException(400, 'customer_cursor_invalid', 'The customer pagination cursor is invalid.');
        }
        $padding = (4 - (strlen($cursor) % 4)) % 4;
        $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            throw new HttpException(400, 'customer_cursor_invalid', 'The customer pagination cursor is invalid.');
        }
        try {
            $data = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'customer_cursor_invalid', 'The customer pagination cursor is invalid.');
        }
        if (!is_array($data) || !isset($data['updated_at'], $data['id'])
            || !is_string($data['updated_at']) || !is_int($data['id']) || $data['id'] < 1
            || preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?$/D', $data['updated_at']) !== 1) {
            throw new HttpException(400, 'customer_cursor_invalid', 'The customer pagination cursor is invalid.');
        }
        return ['updated_at' => $data['updated_at'], 'id' => $data['id']];
    }

    /** @param array{updated_at:string,id:int}|null $cursor */
    private static function encodeCursor(?array $cursor): ?string
    {
        if ($cursor === null) return null;
        $json = json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
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
