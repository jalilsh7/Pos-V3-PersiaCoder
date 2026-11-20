<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class CashRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<array<string,mixed>> */
    public function listRegisters(int $businessId, int $branchId): array
    {
        $statement = $this->connection->prepare(
            "SELECT r.public_id, r.code, r.name, r.is_active,
                    s.public_id AS session_public_id, s.status AS session_status,
                    s.opening_amount, s.expected_cash_amount, s.opened_at
             FROM pos_registers r
             LEFT JOIN pos_cash_sessions s
               ON s.register_id = r.id
              AND s.business_id = r.business_id
              AND s.branch_id = r.branch_id
              AND s.status IN ('open','requires_review')
             WHERE r.business_id = :business_id AND r.branch_id = :branch_id
             ORDER BY r.name ASC"
        );
        $statement->execute(['business_id' => $businessId, 'branch_id' => $branchId]);
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['public_id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'is_active' => (bool) $row['is_active'],
            'active_session' => $row['session_public_id'] === null ? null : [
                'id' => (string) $row['session_public_id'],
                'status' => (string) $row['session_status'],
                'opening_amount' => (int) $row['opening_amount'],
                'expected_cash_amount' => (int) $row['expected_cash_amount'],
                'opened_at' => (string) $row['opened_at'],
            ],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function createRegister(int $businessId, int $branchId, string $publicId, string $code, string $name): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_registers (business_id, branch_id, public_id, code, name, is_active)
             VALUES (:business_id, :branch_id, :public_id, :code, :name, 1)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'code' => $code,
            'name' => $name,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findRegisterForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, code, name, is_active
             FROM pos_registers
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function hasActiveSession(int $businessId, int $branchId, int $registerId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM pos_cash_sessions
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND register_id = :register_id AND status IN ('open','requires_review')
             LIMIT 1"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'register_id' => $registerId,
        ]);
        return $statement->fetchColumn() !== false;
    }

    public function createSession(
        int $businessId,
        int $branchId,
        int $registerId,
        string $publicId,
        int $openedBy,
        int $openingAmount,
    ): int {
        $statement = $this->connection->prepare(
            "INSERT INTO pos_cash_sessions
                (business_id, branch_id, public_id, register_id, opened_by, opening_amount,
                 expected_cash_amount, status, opened_at)
             VALUES
                (:business_id, :branch_id, :public_id, :register_id, :opened_by,
                 :opening_amount, :expected_cash_amount, 'open', UTC_TIMESTAMP(3))"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'register_id' => $registerId,
            'opened_by' => $openedBy,
            'opening_amount' => $openingAmount,
            'expected_cash_amount' => $openingAmount,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createMovement(
        int $businessId,
        int $branchId,
        int $sessionId,
        string $publicId,
        string $movementType,
        int $amount,
        ?int $paymentId,
        ?int $refundId,
        ?int $expenseId,
        ?string $reason,
        int $actorUserId,
        string $idempotencyHash,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_cash_movements
                (business_id, branch_id, public_id, session_id, movement_type, amount,
                 payment_id, refund_id, expense_id, reason, actor_user_id, idempotency_key)
             VALUES
                (:business_id, :branch_id, :public_id, :session_id, :movement_type, :amount,
                 :payment_id, :refund_id, :expense_id, :reason, :actor_user_id, :idempotency_key)'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
            'session_id' => $sessionId,
            'movement_type' => $movementType,
            'amount' => $amount,
            'payment_id' => $paymentId,
            'refund_id' => $refundId,
            'expense_id' => $expenseId,
            'reason' => $reason,
            'actor_user_id' => $actorUserId,
            'idempotency_key' => $idempotencyHash,
        ]);
    }

    public function decreaseExpectedCash(int $businessId, int $branchId, int $sessionId, int $amount): void
    {
        $statement = $this->connection->prepare(
            'UPDATE pos_cash_sessions
             SET expected_cash_amount = expected_cash_amount - :amount
             WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id
               AND status = :status AND expected_cash_amount >= :minimum_amount'
        );
        $statement->execute([
            'amount' => $amount,
            'id' => $sessionId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'status' => 'open',
            'minimum_amount' => $amount,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The cash session does not have enough expected cash or is no longer open.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findOpenSessionForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT id, public_id, register_id, expected_cash_amount
             FROM pos_cash_sessions
             WHERE business_id = :business_id AND branch_id = :branch_id
               AND public_id = :public_id AND status = 'open'
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function increaseExpectedCash(int $businessId, int $branchId, int $sessionId, int $amount): void
    {
        $statement = $this->connection->prepare(
            'UPDATE pos_cash_sessions
             SET expected_cash_amount = expected_cash_amount + :amount
             WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id AND status = :status'
        );
        $statement->execute([
            'amount' => $amount,
            'id' => $sessionId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'status' => 'open',
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The cash session is no longer open.');
        }
    }

    /** @return array<string,mixed>|null */
    public function findSessionForUpdate(int $businessId, int $branchId, string $publicId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, register_id, status, opening_amount,
                    expected_cash_amount, counted_cash_amount, difference_amount
             FROM pos_cash_sessions
             WHERE business_id = :business_id AND branch_id = :branch_id AND public_id = :public_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'public_id' => $publicId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function closeSession(
        int $businessId,
        int $branchId,
        int $sessionId,
        int $countedAmount,
        int $difference,
    ): string {
        $status = $difference === 0 ? 'closed' : 'requires_review';
        $statement = $this->connection->prepare(
            'UPDATE pos_cash_sessions
             SET counted_cash_amount = :counted_amount,
                 difference_amount = :difference_amount,
                 status = :status,
                 closed_at = UTC_TIMESTAMP(3)
             WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id AND status = :open_status'
        );
        $statement->execute([
            'counted_amount' => $countedAmount,
            'difference_amount' => $difference,
            'status' => $status,
            'id' => $sessionId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'open_status' => 'open',
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The cash session could not be closed.');
        }
        return $status;
    }

    public function reviewSession(int $businessId, int $branchId, int $sessionId, int $reviewedBy): void
    {
        $statement = $this->connection->prepare(
            "UPDATE pos_cash_sessions
             SET status = 'closed', reviewed_by = :reviewed_by
             WHERE id = :id AND business_id = :business_id AND branch_id = :branch_id
               AND status = 'requires_review'"
        );
        $statement->execute([
            'reviewed_by' => $reviewedBy,
            'id' => $sessionId,
            'business_id' => $businessId,
            'branch_id' => $branchId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('The cash session could not be reviewed.');
        }
    }
}
