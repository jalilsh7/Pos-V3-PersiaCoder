<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class UserRepository
{
    public function __construct(private PDO $connection) {}

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, public_id, username, password_hash, display_name,
                    is_active, must_change_password, session_version
             FROM app_users
             WHERE username = :username
             LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $userId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, password_hash, is_active
             FROM app_users
             WHERE id = :user_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($user) && (int) $user['is_active'] === 1 ? $user : null;
    }

    public function listBusinessUsers(int $businessId): array
    {
        $statement = $this->connection->prepare(
            'SELECT u.public_id, u.username, u.display_name, u.is_active,
                    u.must_change_password, u.last_login_at,
                    (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',')
                     FROM app_user_business_roles ubr
                     INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
                     WHERE ubr.user_id = u.id AND ubr.business_id = :business_roles_business_id) AS business_role_codes,
                    (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',')
                     FROM app_user_branch_roles ubr
                     INNER JOIN pos_branches b ON b.id = ubr.branch_id
                     INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
                     WHERE ubr.user_id = u.id AND b.business_id = :branch_roles_business_id) AS branch_role_codes
             FROM app_users u
             WHERE EXISTS (
                 SELECT 1 FROM app_user_business_roles ubr
                 WHERE ubr.user_id = u.id AND ubr.business_id = :business_id
             ) OR EXISTS (
                 SELECT 1
                 FROM app_user_branch_roles ubr
                 INNER JOIN pos_branches b ON b.id = ubr.branch_id
                 WHERE ubr.user_id = u.id AND b.business_id = :branch_business_id
             ) OR EXISTS (
                 SELECT 1
                 FROM app_user_roles ur
                 INNER JOIN app_roles r ON r.id = ur.role_id AND r.is_active = 1
                 WHERE ur.user_id = u.id AND r.code = \'owner\'
             )
             ORDER BY u.display_name ASC, u.username ASC'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_business_id' => $businessId,
            'business_roles_business_id' => $businessId,
            'branch_roles_business_id' => $businessId,
        ]);

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'username' => (string) $row['username'],
                'display_name' => (string) $row['display_name'],
                'is_active' => (int) $row['is_active'] === 1,
                'must_change_password' => (int) $row['must_change_password'] === 1,
                'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                'business_roles' => is_string($row['business_role_codes']) && $row['business_role_codes'] !== '' ? explode(',', $row['business_role_codes']) : [],
                'branch_roles' => is_string($row['branch_role_codes']) && $row['branch_role_codes'] !== '' ? explode(',', $row['branch_role_codes']) : [],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function listBranchUsers(int $businessId, int $branchId): array
    {
        $statement = $this->connection->prepare(
            'SELECT u.public_id, u.username, u.display_name, u.is_active,
                    u.must_change_password, u.last_login_at,
                    (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',')
                     FROM app_user_business_roles ubr
                     INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
                     WHERE ubr.user_id = u.id AND ubr.business_id = :business_roles_business_id) AS business_role_codes,
                    (SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',')
                     FROM app_user_branch_roles ubr
                     INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
                     WHERE ubr.user_id = u.id AND ubr.branch_id = :branch_roles_branch_id) AS branch_role_codes
             FROM app_users u
             WHERE EXISTS (
                 SELECT 1 FROM app_user_business_roles ubr
                 WHERE ubr.user_id = u.id AND ubr.business_id = :business_id
             ) OR EXISTS (
                 SELECT 1 FROM app_user_branch_roles ubr
                 WHERE ubr.user_id = u.id AND ubr.branch_id = :branch_id
             ) OR EXISTS (
                 SELECT 1
                 FROM app_user_roles ur
                 INNER JOIN app_roles r ON r.id = ur.role_id AND r.is_active = 1
                 WHERE ur.user_id = u.id AND r.code = \'owner\'
             )
             ORDER BY u.display_name ASC, u.username ASC'
        );
        $statement->execute([
            'business_id' => $businessId,
            'branch_id' => $branchId,
            'business_roles_business_id' => $businessId,
            'branch_roles_branch_id' => $branchId,
        ]);

        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['public_id'],
                'username' => (string) $row['username'],
                'display_name' => (string) $row['display_name'],
                'is_active' => (int) $row['is_active'] === 1,
                'must_change_password' => (int) $row['must_change_password'] === 1,
                'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
                'business_roles' => is_string($row['business_role_codes']) && $row['business_role_codes'] !== '' ? explode(',', $row['business_role_codes']) : [],
                'branch_roles' => is_string($row['branch_role_codes']) && $row['branch_role_codes'] !== '' ? explode(',', $row['branch_role_codes']) : [],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function usernameExists(string $username): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM app_users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);
        return $statement->fetchColumn() !== false;
    }

    public function createUser(
        string $publicId,
        string $username,
        string $passwordHash,
        string $displayName,
    ): int {
        $statement = $this->connection->prepare(
            'INSERT INTO app_users
                (public_id, username, password_hash, display_name, is_active, must_change_password)
             VALUES
                (:public_id, :username, :password_hash, :display_name, 1, 1)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'username' => $username,
            'password_hash' => $passwordHash,
            'display_name' => $displayName,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_users SET password_hash = :password_hash WHERE id = :user_id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'user_id' => $userId]);
    }

    public function changePasswordAndRevokeSessions(int $userId, string $passwordHash): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_users
             SET password_hash = :password_hash,
                 must_change_password = 0,
                 session_version = session_version + 1
             WHERE id = :user_id AND is_active = 1'
        );
        $statement->execute(['password_hash' => $passwordHash, 'user_id' => $userId]);
    }

    public function touchLastLogin(int $userId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_users SET last_login_at = UTC_TIMESTAMP(3) WHERE id = :user_id AND is_active = 1'
        );
        $statement->execute(['user_id' => $userId]);
    }
}
