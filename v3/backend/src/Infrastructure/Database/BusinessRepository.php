<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class BusinessRepository
{
    public function __construct(private PDO $connection) {}

    public function isInstallationOwner(int $userId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1
             FROM app_user_roles ur
             INNER JOIN app_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.code = 'owner' AND r.is_active = 1
               AND EXISTS (
                   SELECT 1 FROM app_business_setup_state state
                   WHERE state.id = 1 AND state.initial_business_id IS NULL
               )
             LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    /** @return list<array{id:string,code:string,name:string,timezone:string,currency:string}> */
    public function listAccessibleBusinesses(int $userId): array
    {
        $statement = $this->connection->prepare(
            'SELECT DISTINCT b.public_id, b.code, b.name, b.timezone, b.currency
             FROM pos_businesses b
             WHERE b.is_active = 1
               AND (
                   EXISTS (
                       SELECT 1 FROM app_user_business_roles ubr
                       WHERE ubr.user_id = :business_user_id AND ubr.business_id = b.id
                   )
                   OR EXISTS (
                       SELECT 1
                       FROM app_user_branch_roles ubr
                       INNER JOIN pos_branches branch ON branch.id = ubr.branch_id
                       WHERE ubr.user_id = :branch_user_id AND branch.business_id = b.id
                   )
               )
             ORDER BY b.name ASC, b.code ASC'
        );
        $statement->execute([
            'business_user_id' => $userId,
            'branch_user_id' => $userId,
        ]);
        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'timezone' => (string) $row['timezone'],
                'currency' => (string) $row['currency'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function assignBusinessOwner(int $userId, int $businessId): void
    {
        $roleId = $this->connection->query(
            "SELECT id FROM app_roles WHERE code = 'owner' AND is_active = 1 LIMIT 1"
        )->fetchColumn();
        if ($roleId === false) {
            throw new \RuntimeException('The business owner role is missing.');
        }

        $statement = $this->connection->prepare(
            'INSERT INTO app_user_business_roles (user_id, business_id, role_id, assigned_by)
             VALUES (:user_id, :business_id, :role_id, :assigned_by)'
        );
        $statement->execute([
            'user_id' => $userId,
            'business_id' => $businessId,
            'role_id' => (int) $roleId,
            'assigned_by' => $userId,
        ]);
    }

    public function removeInstallationOwnerRole(int $userId): void
    {
        $statement = $this->connection->prepare(
            "DELETE ur FROM app_user_roles ur
             INNER JOIN app_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.code = 'owner'"
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function lockInitialBusinessId(): ?int
    {
        $statement = $this->connection->query(
            'SELECT initial_business_id FROM app_business_setup_state WHERE id = 1 FOR UPDATE'
        );
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new \RuntimeException('Business setup state is missing.');
        }
        return $value === null ? null : (int) $value;
    }

    public function findBusinessTimezone(int $businessId): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT timezone FROM pos_businesses WHERE id = :business_id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['business_id' => $businessId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function listBranches(int $businessId): array
    {
        $statement = $this->connection->prepare(
            'SELECT public_id, code, name, timezone, is_active
             FROM pos_branches
             WHERE business_id = :business_id
             ORDER BY name ASC, code ASC'
        );
        $statement->execute(['business_id' => $businessId]);
        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'timezone' => $row['timezone'] === null ? null : (string) $row['timezone'],
                'is_active' => (int) $row['is_active'] === 1,
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function createBusiness(
        string $publicId,
        string $code,
        string $name,
        string $timezone,
        string $currency,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_businesses (public_id, code, name, timezone, currency)
             VALUES (:public_id, :code, :name, :timezone, :currency)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'code' => $code,
            'name' => $name,
            'timezone' => $timezone,
            'currency' => $currency,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function createBranch(
        string $publicId,
        int $businessId,
        string $code,
        string $name,
        ?string $timezone,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO pos_branches (public_id, business_id, code, name, timezone)
             VALUES (:public_id, :business_id, :code, :name, :timezone)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'business_id' => $businessId,
            'code' => $code,
            'name' => $name,
            'timezone' => $timezone,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function markInitialBusinessCreated(int $businessId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_business_setup_state
             SET initial_business_id = :business_id, initialized_at = UTC_TIMESTAMP(3)
             WHERE id = 1 AND initial_business_id IS NULL'
        );
        $statement->execute(['business_id' => $businessId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Initial business setup state could not be finalized.');
        }
    }
}
