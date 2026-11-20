<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Infrastructure\Database;

use PDO;

final readonly class PermissionRepository
{
    public function __construct(private PDO $connection) {}

    public function findActiveBusinessId(string $publicId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_businesses WHERE public_id = :public_id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['public_id' => $publicId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function findActiveBranchId(string $publicId, int $businessId): ?int
    {
        $statement = $this->connection->prepare(
            'SELECT id FROM pos_branches
             WHERE public_id = :public_id AND business_id = :business_id AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['public_id' => $publicId, 'business_id' => $businessId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function userHasPermission(
        int $userId,
        string $permissionCode,
        int $businessId,
        ?int $branchId,
    ): bool {
        $business = $this->connection->prepare(
            'SELECT 1
             FROM app_user_business_roles ubr
             INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
             INNER JOIN app_role_permissions rp ON rp.role_id = r.id
             INNER JOIN app_permissions p ON p.id = rp.permission_id
             WHERE ubr.user_id = :user_id
               AND ubr.business_id = :business_id
               AND p.code IN (:permission_code, :wildcard_code)
             LIMIT 1'
        );
        $business->execute([
            'user_id' => $userId,
            'business_id' => $businessId,
            'permission_code' => $permissionCode,
            'wildcard_code' => '*',
        ]);
        if ($business->fetchColumn() !== false) {
            return true;
        }

        if ($branchId === null) {
            return false;
        }

        $branch = $this->connection->prepare(
            'SELECT 1
             FROM app_user_branch_roles ubr
             INNER JOIN pos_branches b ON b.id = ubr.branch_id AND b.business_id = :branch_business_id
             INNER JOIN app_roles r ON r.id = ubr.role_id AND r.is_active = 1
             INNER JOIN app_role_permissions rp ON rp.role_id = r.id
             INNER JOIN app_permissions p ON p.id = rp.permission_id
             WHERE ubr.user_id = :branch_user_id
               AND ubr.branch_id = :branch_id
               AND p.code IN (:branch_permission_code, :branch_wildcard_code)
             LIMIT 1'
        );
        $branch->execute([
            'branch_business_id' => $businessId,
            'branch_user_id' => $userId,
            'branch_id' => $branchId,
            'branch_permission_code' => $permissionCode,
            'branch_wildcard_code' => '*',
        ]);

        return $branch->fetchColumn() !== false;
    }
}
