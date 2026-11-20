<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class IdempotencyRepository
{
    public function __construct(private PDO $connection) {}

    /** @return array{id:int,created:bool,request_hash:string,response_status:?int,response_ciphertext:?string,response_nonce:?string,response_tag:?string} */
    public function createOrLock(
        int $actorUserId,
        int $businessId,
        ?int $branchId,
        string $scopeHash,
        string $endpointCode,
        string $keyHash,
        string $requestHash,
        string $expiresAt,
    ): array {
        $statement = $this->connection->prepare(
            'INSERT INTO app_idempotency_keys
                (actor_user_id, business_id, branch_id, scope_hash, endpoint_code, key_hash,
                 request_hash, lease_expires_at, expires_at)
             VALUES
                (:actor_user_id, :business_id, :branch_id, :scope_hash, :endpoint_code, :key_hash,
                 :request_hash, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE), :expires_at)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'scope_hash' => $scopeHash,
            'endpoint_code' => $endpointCode,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'expires_at' => $expiresAt,
        ]);
        $created = $statement->rowCount() === 1;
        $id = (int) $this->connection->lastInsertId();

        $row = $this->findForUpdate($id);
        if ($row === null) {
            throw new \RuntimeException('The idempotency record could not be locked.');
        }

        if (!$created && strtotime((string) $row['expires_at'] . ' UTC') <= time()) {
            $delete = $this->connection->prepare('DELETE FROM app_idempotency_keys WHERE id = :id');
            $delete->execute(['id' => $id]);
            return $this->createOrLock(
                $actorUserId,
                $businessId,
                $branchId,
                $scopeHash,
                $endpointCode,
                $keyHash,
                $requestHash,
                $expiresAt,
            );
        }

        if (!$created && $row['response_status'] === null
            && hash_equals((string) $row['request_hash'], $requestHash)
            && strtotime((string) $row['lease_expires_at'] . ' UTC') <= time()) {
            $lease = $this->connection->prepare(
                'UPDATE app_idempotency_keys
                 SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE)
                 WHERE id = :id AND response_status IS NULL
                   AND lease_expires_at <= UTC_TIMESTAMP(3)'
            );
            $lease->execute(['id' => $id]);
            $created = $lease->rowCount() === 1;
        }

        return [
            'id' => (int) $row['id'],
            'created' => $created,
            'request_hash' => (string) $row['request_hash'],
            'response_status' => $row['response_status'] === null ? null : (int) $row['response_status'],
            'response_ciphertext' => $row['response_ciphertext'] === null ? null : (string) $row['response_ciphertext'],
            'response_nonce' => $row['response_nonce'] === null ? null : (string) $row['response_nonce'],
            'response_tag' => $row['response_tag'] === null ? null : (string) $row['response_tag'],
        ];
    }

    public function complete(int $id, int $status, string $ciphertext, string $nonce, string $tag): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_idempotency_keys
             SET response_status = :response_status,
                 response_ciphertext = :response_ciphertext,
                 response_nonce = :response_nonce,
                 response_tag = :response_tag,
                 completed_at = UTC_TIMESTAMP(3)
             WHERE id = :id AND completed_at IS NULL'
        );
        $statement->execute([
            'response_status' => $status,
            'response_ciphertext' => $ciphertext,
            'response_nonce' => $nonce,
            'response_tag' => $tag,
            'id' => $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The idempotency result could not be finalized.');
        }
    }

    public function deletePending(int $id): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM app_idempotency_keys WHERE id = :id AND response_status IS NULL'
        );
        $statement->execute(['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    private function findForUpdate(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, request_hash, response_status, response_ciphertext, response_nonce,
                    response_tag, expires_at, lease_expires_at
             FROM app_idempotency_keys
             WHERE id = :id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
