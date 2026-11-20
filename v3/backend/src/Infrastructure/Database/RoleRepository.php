<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class RoleRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<array{id:int,code:string,name:string,is_system:bool,permissions:list<string>}> */
    public function listActiveRoles(): array
    {
        $statement = $this->connection->query(
            'SELECT r.id, r.code, r.name, r.is_system, p.code AS permission_code
             FROM app_roles r
             LEFT JOIN app_role_permissions rp ON rp.role_id = r.id
             LEFT JOIN app_permissions p ON p.id = rp.permission_id
             WHERE r.is_active = 1
             ORDER BY r.is_system DESC, r.name ASC, p.code ASC'
        );

        $roles = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            if (!isset($roles[$id])) {
                $roles[$id] = [
                    'id' => $id,
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'is_system' => (int) $row['is_system'] === 1,
                    'permissions' => [],
                ];
            }
            if (is_string($row['permission_code'])) {
                $roles[$id]['permissions'][] = $row['permission_code'];
            }
        }

        return array_values($roles);
    }

    /** @return list<array{code:string,description:?string}> */
    public function listActivePermissions(): array
    {
        $statement = $this->connection->query(
            'SELECT code, description FROM app_permissions ORDER BY code ASC'
        );
        return array_map(
            static fn (array $row): array => [
                'code' => (string) $row['code'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /** @return array{id:int,code:string,name:string,permissions:list<string>}|null */
    public function findActiveRole(string $code): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT r.id, r.code, r.name, p.code AS permission_code
             FROM app_roles r
             LEFT JOIN app_role_permissions rp ON rp.role_id = r.id
             LEFT JOIN app_permissions p ON p.id = rp.permission_id
             WHERE r.code = :role_code AND r.is_active = 1
             ORDER BY p.code ASC'
        );
        $statement->execute(['role_code' => $code]);

        $role = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($role === null) {
                $role = [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'permissions' => [],
                ];
            }
            if (is_string($row['permission_code'])) {
                $role['permissions'][] = $row['permission_code'];
            }
        }
        return $role;
    }

    public function findActiveUserId(string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM app_users
             WHERE public_id = :public_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['public_id' => $publicId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function roleCodeExists(string $code): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM app_roles WHERE code = :role_code LIMIT 1'
        );
        $statement->execute(['role_code' => $code]);
        return $statement->fetchColumn() !== false;
    }

    public function isSystemRole(string $code): bool
    {
        $statement = $this->connection->prepare(
            'SELECT is_system FROM app_roles WHERE code = :role_code AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['role_code' => $code]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function findPermissionIdsByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = [];
        $parameters = [];
        foreach (array_values($codes) as $index => $code) {
            $placeholder = 'permission_' . $index;
            $placeholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $code;
        }
        $statement = $this->connection->prepare(
            'SELECT id, code FROM app_permissions WHERE code IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($parameters);
        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[(string) $row['code']] = (int) $row['id'];
        }
        return $ids;
    }

    public function createCustomRole(string $code, string $name): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO app_roles (code, name, is_system, is_active)
             VALUES (:code, :name, 0, 1)'
        );
        $statement->execute(['code' => $code, 'name' => $name]);
        return (int) $this->connection->lastInsertId();
    }

    public function updateCustomRoleName(int $roleId, string $name): void
    {
        $statement = $this->connection->prepare(
            'UPDATE app_roles SET name = :name
             WHERE id = :role_id AND is_system = 0 AND is_active = 1'
        );
        $statement->execute(['name' => $name, 'role_id' => $roleId]);
    }

    public function replaceRolePermissions(int $roleId, array $permissionIds): void
    {
        $delete = $this->connection->prepare('DELETE FROM app_role_permissions WHERE role_id = :role_id');
        $delete->execute(['role_id' => $roleId]);

        $insert = $this->connection->prepare(
            'INSERT INTO app_role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)'
        );
        foreach ($permissionIds as $permissionId) {
            $insert->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function assignBusinessRole(int $userId, int $businessId, int $roleId, int $assignedBy): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO app_user_business_roles (user_id, business_id, role_id, assigned_by)
             VALUES (:user_id, :business_id, :role_id, :assigned_by)'
        );
        $statement->execute([
            'user_id' => $userId,
            'business_id' => $businessId,
            'role_id' => $roleId,
            'assigned_by' => $assignedBy,
        ]);
    }

    public function assignBranchRole(int $userId, int $branchId, int $roleId, int $assignedBy): void
    {
        $statement = $this->connection->prepare(
            'INSERT IGNORE INTO app_user_branch_roles (user_id, branch_id, role_id, assigned_by)
             VALUES (:user_id, :branch_id, :role_id, :assigned_by)'
        );
        $statement->execute([
            'user_id' => $userId,
            'branch_id' => $branchId,
            'role_id' => $roleId,
            'assigned_by' => $assignedBy,
        ]);
    }

    public function removeBusinessRole(int $userId, int $businessId, int $roleId): bool
    {
        $statement = $this->connection->prepare(
            'DELETE FROM app_user_business_roles
             WHERE user_id = :user_id AND business_id = :business_id AND role_id = :role_id'
        );
        $statement->execute(['user_id' => $userId, 'business_id' => $businessId, 'role_id' => $roleId]);
        return $statement->rowCount() > 0;
    }

    public function removeBranchRole(int $userId, int $branchId, int $roleId): bool
    {
        $statement = $this->connection->prepare(
            'DELETE FROM app_user_branch_roles
             WHERE user_id = :user_id AND branch_id = :branch_id AND role_id = :role_id'
        );
        $statement->execute(['user_id' => $userId, 'branch_id' => $branchId, 'role_id' => $roleId]);
        return $statement->rowCount() > 0;
    }
}
