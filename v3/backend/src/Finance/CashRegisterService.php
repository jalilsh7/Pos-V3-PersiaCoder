<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Finance;

use PDO;
use PDOException;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\CashRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class CashRegisterService
{
    private const MAX_AMOUNT = 9000000000000000;

    private CashRepository $cash;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(PDO $connection, string $applicationKey)
    {
        $this->cash = new CashRepository($connection);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function list(BusinessScope $scope): array
    {
        return ['registers' => $this->cash->listRegisters($scope->businessId, (int) $scope->branchId)];
    }

    public function createRegister(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $rawCode = $payload['code'] ?? null;
        $code = is_string($rawCode) ? strtoupper(trim($rawCode)) : '';
        $rawName = $payload['name'] ?? null;
        $name = is_string($rawName) ? trim($rawName) : '';
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code)) {
            throw new HttpException(422, 'register_code_invalid', 'Register code must contain 2-40 uppercase letters, digits, hyphens or underscores.');
        }
        if ($name === '' || self::unicodeLength($name) > 120) {
            throw new HttpException(422, 'register_name_invalid', 'Register name is required and must not exceed 120 characters.');
        }

        $fingerprint = self::fingerprint(['code' => $code, 'name' => $name]);
        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'cash.registers.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $code, $name, $requestId, $ipAddress): array {
                $publicId = self::uuidV4();
                try {
                    $this->cash->createRegister($scope->businessId, (int) $scope->branchId, $publicId, $code, $name);
                } catch (PDOException $exception) {
                    if ((string) $exception->getCode() === '23000') {
                        throw new HttpException(409, 'register_code_exists', 'A cash register with this code already exists in this branch.');
                    }
                    throw $exception;
                }
                $this->audit->record(
                    $actor->userId,
                    'cash.register_created',
                    'cash_register',
                    $publicId,
                    null,
                    ['code' => $code, 'name' => $name],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['register' => ['id' => $publicId, 'code' => $code, 'name' => $name, 'is_active' => true]], 'meta' => (object) []],
                ];
            },
        );
    }

    public function openSession(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $registerPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($registerPublicId)) {
            throw new HttpException(400, 'register_id_invalid', 'The cash register identifier is invalid.');
        }
        $openingAmount = self::parseAmount($payload['opening_amount'] ?? 0, 'opening_amount');
        $fingerprint = self::fingerprint([
            'register_id' => $registerPublicId,
            'opening_amount' => $openingAmount,
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'cash.sessions.open',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $registerPublicId, $openingAmount, $idempotencyKey, $requestId, $ipAddress): array {
                $register = $this->cash->findRegisterForUpdate($scope->businessId, (int) $scope->branchId, $registerPublicId);
                if ($register === null || (int) $register['is_active'] !== 1) {
                    throw new HttpException(404, 'active_register_not_found', 'The active cash register was not found in this branch.');
                }
                if ($this->cash->hasActiveSession($scope->businessId, (int) $scope->branchId, (int) $register['id'])) {
                    throw new HttpException(409, 'register_session_already_active', 'This register already has an open or review-required session.');
                }

                $sessionPublicId = self::uuidV4();
                $sessionId = $this->cash->createSession(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $register['id'],
                    $sessionPublicId,
                    $actor->userId,
                    $openingAmount,
                );
                if ($openingAmount > 0) {
                    $this->cash->createMovement(
                        $scope->businessId,
                        (int) $scope->branchId,
                        $sessionId,
                        self::uuidV4(),
                        'opening_float',
                        $openingAmount,
                        null,
                        null,
                        null,
                        'Opening float',
                        $actor->userId,
                        hash('sha256', 'opening:' . $idempotencyKey),
                    );
                }
                $this->audit->record(
                    $actor->userId,
                    'cash.session_opened',
                    'cash_session',
                    $sessionPublicId,
                    null,
                    ['register_id' => $registerPublicId, 'opening_amount' => $openingAmount],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['session' => [
                        'id' => $sessionPublicId,
                        'register_id' => $registerPublicId,
                        'status' => 'open',
                        'opening_amount' => $openingAmount,
                        'expected_cash_amount' => $openingAmount,
                    ]], 'meta' => (object) []],
                ];
            },
        );
    }

    public function closeSession(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $sessionPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($sessionPublicId)) {
            throw new HttpException(400, 'cash_session_id_invalid', 'The cash session identifier is invalid.');
        }
        $countedAmount = self::parseAmount($payload['counted_cash_amount'] ?? null, 'counted_cash_amount');
        $fingerprint = self::fingerprint([
            'session_id' => $sessionPublicId,
            'counted_cash_amount' => $countedAmount,
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'cash.sessions.close',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $sessionPublicId, $countedAmount, $requestId, $ipAddress): array {
                $session = $this->cash->findSessionForUpdate($scope->businessId, (int) $scope->branchId, $sessionPublicId);
                if ($session === null) {
                    throw new HttpException(404, 'cash_session_not_found', 'The cash session was not found in this branch.');
                }
                if ((string) $session['status'] !== 'open') {
                    throw new HttpException(409, 'cash_session_not_open', 'Only an open cash session can be closed.');
                }
                $expected = (int) $session['expected_cash_amount'];
                $difference = $countedAmount - $expected;
                $status = $this->cash->closeSession(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $session['id'],
                    $countedAmount,
                    $difference,
                );
                $this->audit->record(
                    $actor->userId,
                    'cash.session_closed',
                    'cash_session',
                    $sessionPublicId,
                    ['status' => 'open', 'expected_cash_amount' => $expected],
                    ['status' => $status, 'counted_cash_amount' => $countedAmount, 'difference_amount' => $difference],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 200,
                    'body' => ['data' => [
                        'session_id' => $sessionPublicId,
                        'status' => $status,
                        'expected_cash_amount' => $expected,
                        'counted_cash_amount' => $countedAmount,
                        'difference_amount' => $difference,
                    ], 'meta' => (object) []],
                ];
            },
        );
    }

    public function createManualMovement(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $sessionPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($sessionPublicId)) {
            throw new HttpException(400, 'cash_session_id_invalid', 'The cash session identifier is invalid.');
        }
        $direction = $payload['direction'] ?? null;
        if (!in_array($direction, ['in', 'out'], true)) {
            throw new HttpException(422, 'cash_movement_direction_invalid', 'Movement direction must be in or out.');
        }
        $amount = self::parseAmount($payload['amount'] ?? null, 'movement_amount');
        if ($amount <= 0) {
            throw new HttpException(422, 'cash_movement_amount_invalid', 'Movement amount must be greater than zero.');
        }
        $reason = $payload['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '' || self::unicodeLength($reason) > 500) {
            throw new HttpException(422, 'cash_movement_reason_required', 'A reason of up to 500 characters is required.');
        }
        $reason = trim($reason);
        $movementType = $direction === 'in' ? 'manual_in' : 'manual_out';
        $fingerprint = self::fingerprint([
            'session_id' => $sessionPublicId,
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'cash.movements.manual',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $sessionPublicId, $direction, $movementType, $amount, $reason, $idempotencyKey, $requestId, $ipAddress): array {
                $session = $this->cash->findOpenSessionForUpdate($scope->businessId, (int) $scope->branchId, $sessionPublicId);
                if ($session === null) {
                    throw new HttpException(409, 'cash_session_not_open', 'Manual cash movements require an open cash session.');
                }
                $expectedBefore = (int) $session['expected_cash_amount'];
                if ($direction === 'out' && $expectedBefore < $amount) {
                    throw new HttpException(409, 'cash_balance_insufficient', 'The withdrawal exceeds the expected cash balance.');
                }

                if ($direction === 'in') {
                    $this->cash->increaseExpectedCash($scope->businessId, (int) $scope->branchId, (int) $session['id'], $amount);
                } else {
                    $this->cash->decreaseExpectedCash($scope->businessId, (int) $scope->branchId, (int) $session['id'], $amount);
                }
                $this->cash->createMovement(
                    $scope->businessId,
                    (int) $scope->branchId,
                    (int) $session['id'],
                    self::uuidV4(),
                    $movementType,
                    $amount,
                    null,
                    null,
                    null,
                    $reason,
                    $actor->userId,
                    hash('sha256', 'manual-cash:' . $idempotencyKey),
                );
                $expectedAfter = $direction === 'in' ? $expectedBefore + $amount : $expectedBefore - $amount;
                $this->audit->record(
                    $actor->userId,
                    'cash.manual_movement_created',
                    'cash_session',
                    $sessionPublicId,
                    ['expected_cash_amount' => $expectedBefore],
                    ['movement_type' => $movementType, 'amount' => $amount, 'expected_cash_amount' => $expectedAfter],
                    $reason,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => [
                        'session_id' => $sessionPublicId,
                        'movement_type' => $movementType,
                        'amount' => $amount,
                        'expected_cash_amount' => $expectedAfter,
                    ], 'meta' => (object) []],
                ];
            },
        );
    }

    public function reviewSession(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $sessionPublicId,
        array $payload,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if (!self::isUuid($sessionPublicId)) {
            throw new HttpException(400, 'cash_session_id_invalid', 'The cash session identifier is invalid.');
        }
        $note = $payload['review_note'] ?? null;
        if (!is_string($note) || trim($note) === '' || self::unicodeLength($note) > 500) {
            throw new HttpException(422, 'cash_review_note_required', 'A review note of up to 500 characters is required.');
        }
        $note = trim($note);
        $fingerprint = self::fingerprint(['session_id' => $sessionPublicId, 'review_note' => $note]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'cash.sessions.review',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use ($actor, $scope, $sessionPublicId, $note, $requestId, $ipAddress): array {
                $session = $this->cash->findSessionForUpdate($scope->businessId, (int) $scope->branchId, $sessionPublicId);
                if ($session === null) {
                    throw new HttpException(404, 'cash_session_not_found', 'The cash session was not found in this branch.');
                }
                if ((string) $session['status'] !== 'requires_review') {
                    throw new HttpException(409, 'cash_session_review_not_required', 'This cash session does not require review.');
                }
                $this->cash->reviewSession($scope->businessId, (int) $scope->branchId, (int) $session['id'], $actor->userId);
                $this->audit->record(
                    $actor->userId,
                    'cash.session_reviewed',
                    'cash_session',
                    $sessionPublicId,
                    ['status' => 'requires_review', 'difference_amount' => (int) $session['difference_amount']],
                    ['status' => 'closed'],
                    $note,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 200,
                    'body' => ['data' => ['session_id' => $sessionPublicId, 'status' => 'closed', 'reviewed' => true], 'meta' => (object) []],
                ];
            },
        );
    }

    private static function parseAmount(mixed $value, string $field): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,15})$/', $value))) {
            throw new HttpException(422, 'invalid_' . $field, 'The amount must be a non-negative integer in IRT.');
        }
        $amount = (int) $value;
        if ($amount < 0 || $amount > self::MAX_AMOUNT) {
            throw new HttpException(422, 'invalid_' . $field, 'The amount is outside the allowed range.');
        }
        return $amount;
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
