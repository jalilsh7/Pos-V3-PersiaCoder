<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\SmsOutboxRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class SmsOutboxService
{
    private SmsOutboxRepository $outbox;
    private SmsOutboxCrypto $crypto;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private PermissionService $permissions,
    ) {
        $this->outbox = new SmsOutboxRepository($connection);
        $this->crypto = new SmsOutboxCrypto($applicationKey);
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    /**
     * Queues one transactional message. Marketing messages are deliberately unsupported.
     *
     * @return array{status:int,body:array<string,mixed>,replayed:bool}
     */
    public function enqueueTransactional(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $recipient,
        string $messageText,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
        ?string $customerPublicId = null,
    ): array {
        if (!$this->permissions->allows($actor, 'pos.sell', $scope)) {
            throw new HttpException(403, 'permission_denied', 'POS sale permission is required to queue transactional messages.');
        }
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw new HttpException(400, 'idempotency_key_invalid', 'A valid Idempotency-Key header is required.');
        }
        $mobile = LegacySmsParser::normalizeIranMobile($recipient);
        if ($mobile === null) {
            throw new HttpException(422, 'sms_recipient_invalid', 'Enter a valid Iranian mobile number.');
        }
        $messageText = trim($messageText);
        if ($messageText === '' || preg_match('//u', $messageText) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $messageText) === 1) {
            throw new HttpException(422, 'sms_message_invalid', 'The transactional SMS text is invalid.');
        }

        $customerId = null;
        if ($customerPublicId !== null) {
            if (!self::isUuid($customerPublicId)) {
                throw new HttpException(400, 'customer_id_invalid', 'The customer identifier is invalid.');
            }
            $customer = $this->outbox->findActiveCustomer($scope->businessId, $customerPublicId);
            if ($customer === null) {
                throw new HttpException(404, 'customer_not_found', 'The active customer or mobile number was not found.');
            }
            if (!hash_equals($customer['phone_normalized'], $mobile)) {
                throw new HttpException(409, 'customer_phone_mismatch', 'The SMS recipient must match the selected customer mobile number.');
            }
            $customerId = $customer['id'];
        }

        $recipientCipher = $this->crypto->encrypt($mobile);
        $messageCipher = $this->crypto->encrypt($messageText);
        $fingerprint = hash('sha256', json_encode([
            'recipient_hash' => $recipientCipher['hash'],
            'message_hash' => $messageCipher['hash'],
            'customer_public_id' => $customerPublicId,
            'message_type' => 'transactional',
            'provider_code' => 'national_payamak_smartsms',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'communications.sms.enqueue',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $actor, $scope, $customerId, $mobile, $recipientCipher, $messageCipher,
                $idempotencyKey, $requestId, $ipAddress
            ): array {
                $publicId = self::uuidV4();
                $messageId = $this->outbox->insert(
                    $scope->businessId,
                    $scope->branchId,
                    $publicId,
                    $customerId,
                    'transactional',
                    'national_payamak_smartsms',
                    $recipientCipher,
                    $messageCipher,
                    hash('sha256', $idempotencyKey),
                    $actor->userId,
                );
                $this->audit->record(
                    $actor->userId,
                    'sms.message_queued',
                    'sms_message',
                    $publicId,
                    null,
                    [
                        'status' => 'queued',
                        'message_type' => 'transactional',
                        'provider_code' => 'national_payamak_smartsms',
                        'recipient_hash' => $this->crypto->hashRecipient($mobile),
                        'has_customer' => $customerId !== null,
                    ],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $scope->branchId,
                );
                return [
                    'status' => 202,
                    'body' => ['data' => ['message' => [
                        'id' => $publicId,
                        'status' => 'queued',
                        'message_type' => 'transactional',
                    ]], 'meta' => ['outbox_id' => $messageId]],
                ];
            },
        );
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
