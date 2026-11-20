<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;
use RuntimeException;

final readonly class SmsOutboxRepository
{
    public function __construct(private PDO $connection) {}

    /** @return array{id:int,phone_normalized:string}|null */
    public function findActiveCustomer(int $businessId, string $customerPublicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, phone_normalized FROM pos_customers
             WHERE business_id = :business_id AND public_id = :public_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $customerPublicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && is_string($row['phone_normalized'])
            ? ['id' => (int) $row['id'], 'phone_normalized' => $row['phone_normalized']]
            : null;
    }

    public function findCustomerId(int $businessId, string $customerPublicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_customers
             WHERE business_id = :business_id AND public_id = :public_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId, 'public_id' => $customerPublicId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function insert(
        int $businessId,
        ?int $branchId,
        string $publicId,
        ?int $customerId,
        string $messageType,
        string $providerCode,
        array $recipient,
        array $message,
        string $idempotencyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_sms_outbox
                (business_id, branch_id, public_id, customer_id, message_type, provider_code,
                 recipient_ciphertext, recipient_nonce, recipient_tag, recipient_hash,
                 message_ciphertext, message_nonce, message_tag, message_hash,
                 status, idempotency_key, max_attempts, created_by)
             VALUES
                (:business_id, :branch_id, :public_id, :customer_id, :message_type, :provider_code,
                 :recipient_ciphertext, :recipient_nonce, :recipient_tag, :recipient_hash,
                 :message_ciphertext, :message_nonce, :message_tag, :message_hash,
                 \'queued\', :idempotency_key, 3, :created_by)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'customer_id' => $customerId,
            'message_type' => $messageType,
            'provider_code' => $providerCode,
            'recipient_ciphertext' => $recipient['ciphertext'],
            'recipient_nonce' => $recipient['nonce'],
            'recipient_tag' => $recipient['tag'],
            'recipient_hash' => $recipient['hash'],
            'message_ciphertext' => $message['ciphertext'],
            'message_nonce' => $message['nonce'],
            'message_tag' => $message['tag'],
            'message_hash' => $message['hash'],
            'idempotency_key' => $idempotencyHash,
            'created_by' => $createdBy,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function claimQueuedBatch(int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT id, business_id, branch_id, public_id, provider_code,
                        recipient_ciphertext, recipient_nonce, recipient_tag, recipient_hash,
                        message_ciphertext, message_nonce, message_tag, message_hash,
                        attempt_count, max_attempts
                 FROM pos_sms_outbox
                 WHERE status = \'queued\'
                   AND provider_code = \'national_payamak_smartsms\'
                   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP(3))
                   AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP(3))
                 ORDER BY id ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED'
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
                $this->connection->exec(
                    'UPDATE pos_sms_outbox SET status = \'processing\', updated_at = UTC_TIMESTAMP(3)
                     WHERE id IN (' . implode(',', $ids) . ') AND status = \'queued\''
                );
            }
            $this->connection->commit();
            return $rows;
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function recoverStaleProcessing(int $ageSeconds = 600): int
    {
        $ageSeconds = max(300, min(3600, $ageSeconds));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $ageSeconds);
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT id, business_id, branch_id, provider_code, attempt_count
                 FROM pos_sms_outbox
                 WHERE status = \'processing\' AND provider_code = \'national_payamak_smartsms\' AND updated_at <= :cutoff
                 ORDER BY id ASC
                 LIMIT 100
                 FOR UPDATE SKIP LOCKED'
            );
            $statement->execute(['cutoff' => $cutoff]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $attemptNumber = (int) $row['attempt_count'] + 1;
                $update = $this->connection->prepare(
                    'UPDATE pos_sms_outbox
                     SET status = \'unknown\', attempt_count = :attempt_count,
                         failure_code = \'sms_worker_stale_processing\',
                         failure_message = \'Worker stopped while delivery outcome was unresolved\',
                         next_attempt_at = NULL, updated_at = UTC_TIMESTAMP(3)
                     WHERE id = :id AND status = \'processing\''
                );
                $update->execute(['attempt_count' => $attemptNumber, 'id' => (int) $row['id']]);
                $this->insertAttempt(
                    $row,
                    $attemptNumber,
                    'send',
                    'unknown',
                    null,
                    null,
                    'sms_worker_stale_processing',
                );
            }
            $this->connection->commit();
            return count($rows);
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function releaseClaimed(int $messageId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE pos_sms_outbox SET status = \'queued\', updated_at = UTC_TIMESTAMP(3)
             WHERE id = :id AND status = \'processing\''
        );
        $statement->execute(['id' => $messageId]);
    }

    public function markAccepted(array $row, string $providerMessageId): void
    {
        $attemptNumber = (int) $row['attempt_count'] + 1;
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE pos_sms_outbox
                 SET status = \'accepted\', provider_message_id = :provider_id,
                     attempt_count = :attempt_count, last_attempt_at = UTC_TIMESTAMP(3),
                     accepted_at = UTC_TIMESTAMP(3), next_attempt_at = NULL,
                     failure_code = NULL, failure_message = NULL, updated_at = UTC_TIMESTAMP(3)
                 WHERE id = :id AND status = \'processing\''
            );
            $statement->execute([
                'provider_id' => $providerMessageId,
                'attempt_count' => $attemptNumber,
                'id' => (int) $row['id'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('sms_outbox_message_not_processing');
            }
            $this->insertAttempt($row, $attemptNumber, 'send', 'accepted', $providerMessageId, null, null);
            $this->connection->commit();
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function markUnknown(array $row, ?string $errorCode): void
    {
        $attemptNumber = (int) $row['attempt_count'] + 1;
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE pos_sms_outbox
                 SET status = \'unknown\', attempt_count = :attempt_count,
                     last_attempt_at = UTC_TIMESTAMP(3), next_attempt_at = NULL,
                     failure_code = :failure_code, failure_message = \'Provider outcome is unknown; automatic retry disabled\',
                     updated_at = UTC_TIMESTAMP(3)
                 WHERE id = :id AND status = \'processing\''
            );
            $statement->execute([
                'attempt_count' => $attemptNumber,
                'failure_code' => $errorCode,
                'id' => (int) $row['id'],
            ]);
            $this->insertAttempt($row, $attemptNumber, 'send', 'unknown', null, null, $errorCode);
            $this->connection->commit();
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function markRetryableFailure(array $row, ?string $errorCode, string $safeMessage, int $delaySeconds): void
    {
        $attemptNumber = (int) $row['attempt_count'] + 1;
        $retry = $attemptNumber < (int) $row['max_attempts'];
        $nextAttemptAt = $retry ? gmdate('Y-m-d H:i:s', time() + max(30, min(3600, $delaySeconds))) : null;
        $status = $retry ? 'queued' : 'failed';
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE pos_sms_outbox
                 SET status = :status, attempt_count = :attempt_count,
                     last_attempt_at = UTC_TIMESTAMP(3), next_attempt_at = :next_attempt_at,
                     failure_code = :failure_code, failure_message = :failure_message,
                     updated_at = UTC_TIMESTAMP(3)
                 WHERE id = :id AND status = \'processing\''
            );
            $statement->execute([
                'status' => $status,
                'attempt_count' => $attemptNumber,
                'next_attempt_at' => $nextAttemptAt,
                'failure_code' => $errorCode,
                'failure_message' => substr($safeMessage, 0, 255),
                'id' => (int) $row['id'],
            ]);
            $this->insertAttempt($row, $attemptNumber, 'send', 'retryable_failure', null, null, $errorCode);
            $this->connection->commit();
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function markRejected(array $row, ?string $errorCode, string $safeMessage): void
    {
        $attemptNumber = (int) $row['attempt_count'] + 1;
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE pos_sms_outbox
                 SET status = \'rejected\', attempt_count = :attempt_count,
                     last_attempt_at = UTC_TIMESTAMP(3), next_attempt_at = NULL,
                     failure_code = :failure_code, failure_message = :failure_message,
                     updated_at = UTC_TIMESTAMP(3)
                 WHERE id = :id AND status = \'processing\''
            );
            $statement->execute([
                'attempt_count' => $attemptNumber,
                'failure_code' => $errorCode,
                'failure_message' => mb_substr($safeMessage, 0, 255, 'UTF-8'),
                'id' => (int) $row['id'],
            ]);
            $this->insertAttempt($row, $attemptNumber, 'send', 'rejected', null, null, $errorCode);
            $this->connection->commit();
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function claimDeliveryBatch(int $limit = 100, int $cooldownSeconds = 300): array
    {
        $limit = max(1, min(100, $limit));
        $cooldownSeconds = max(60, min(3600, $cooldownSeconds));
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT id, business_id, branch_id, provider_code, provider_message_id, status, attempt_count
                 FROM pos_sms_outbox
                 WHERE status IN (\'accepted\', \'sent_to_carrier\')
                   AND provider_code = \'national_payamak_smartsms\'
                   AND provider_message_id IS NOT NULL
                   AND (last_delivery_check_at IS NULL OR last_delivery_check_at <= :cutoff)
                 ORDER BY COALESCE(last_delivery_check_at, \'1970-01-01 00:00:00\') ASC, id ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED'
            );
            $statement->bindValue(':cutoff', gmdate('Y-m-d H:i:s', time() - $cooldownSeconds));
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
                $this->connection->exec(
                    'UPDATE pos_sms_outbox SET last_delivery_check_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3)
                     WHERE id IN (' . implode(',', $ids) . ')'
                );
            }
            $this->connection->commit();
            return $rows;
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function applyDeliveryStatus(array $row, int $rawStatus, string $status, bool $terminal): void
    {
        $currentStatus = (string) $row['status'];
        $nextStatus = in_array($currentStatus, ['delivered', 'failed', 'rejected', 'cancelled'], true)
            ? $currentStatus
            : match ($status) {
                'delivered', 'failed', 'rejected', 'sent_to_carrier' => $status,
                default => $currentStatus,
            };
        $deliveredAt = $nextStatus === 'delivered' ? 'COALESCE(delivered_at, UTC_TIMESTAMP(3))' : 'delivered_at';
        $failureCode = in_array($nextStatus, ['failed', 'rejected'], true) ? 'provider_status_' . $rawStatus : null;

        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'UPDATE pos_sms_outbox SET status = :status,
                    delivered_at = ' . $deliveredAt . ',
                    failure_code = :failure_code,
                    failure_message = CASE WHEN :is_failure = 1 THEN \'Provider delivery report indicates failure\' ELSE NULL END,
                    updated_at = UTC_TIMESTAMP(3)
                 WHERE id = :id'
            );
            $statement->execute([
                'status' => $nextStatus,
                'failure_code' => $failureCode,
                'is_failure' => $failureCode === null ? 0 : 1,
                'id' => (int) $row['id'],
            ]);
            $this->insertAttempt(
                $row,
                (int) $row['attempt_count'],
                'delivery_check',
                'status_received',
                (string) $row['provider_message_id'],
                $rawStatus,
                null,
            );
            $this->connection->commit();
        } catch (\Throwable $error) {
            if ($this->connection->inTransaction()) $this->connection->rollBack();
            throw $error;
        }
    }

    public function recordDeliveryCheckFailure(array $row, ?string $errorCode): void
    {
        $this->insertAttempt(
            $row,
            (int) $row['attempt_count'],
            'delivery_check',
            'unknown',
            (string) $row['provider_message_id'],
            null,
            $errorCode,
        );
    }

    private function insertAttempt(
        array $row,
        int $attemptNumber,
        string $attemptType,
        string $outcome,
        ?string $providerMessageId,
        ?int $providerStatusCode,
        ?string $errorCode,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_sms_delivery_attempts
                (business_id, branch_id, message_id, attempt_number, attempt_type, outcome,
                 provider_code, provider_message_id, provider_status_code, error_code, safe_metadata, completed_at)
             VALUES
                (:business_id, :branch_id, :message_id, :attempt_number, :attempt_type, :outcome,
                 :provider_code, :provider_message_id, :provider_status_code, :error_code, :safe_metadata, UTC_TIMESTAMP(3))'
        );
        $statement->execute([
            'business_id' => (int) $row['business_id'],
            'branch_id' => $row['branch_id'] === null ? null : (int) $row['branch_id'],
            'message_id' => (int) $row['id'],
            'attempt_number' => $attemptNumber,
            'attempt_type' => $attemptType,
            'outcome' => $outcome,
            'provider_code' => (string) $row['provider_code'],
            'provider_message_id' => $providerMessageId,
            'provider_status_code' => $providerStatusCode,
            'error_code' => $errorCode,
            'safe_metadata' => json_encode(['phase' => $attemptType], JSON_THROW_ON_ERROR),
        ]);
    }
}
