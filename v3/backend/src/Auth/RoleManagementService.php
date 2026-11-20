<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PDO;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\RoleRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class RoleManagementService
{
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(
        PDO $connection,
        string $applicationKey,
        private RoleRepository $roles,
        private PermissionService $permissions,
    ) {
        $this->idempotency = new IdempotencyService($connection, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($connection));
    }

    public function listRoles(): array
    {
        return $this->roles->listActiveRoles();
    }

    public function listPermissions(): array
    {
        return $this->roles->listActivePermissions();
    }

    public function createCustomRole(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $code,
        string $name,
        array $permissionCodes,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $code = strtolower(trim($code));
        $name = trim($name);
        $permissionCodes = $this->validatePermissionCodes($permissionCodes);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code) || $code === 'owner') {
            throw new HttpException(422, 'role_code_invalid', 'The custom role code is invalid.');
        }
        if ($name === '' || self::unicodeLength($name) > 100) {
            throw new HttpException(422, 'role_name_invalid', 'Role name is required and must not exceed 100 characters.');
        }

        $permissionIds = $this->authorizePermissionSet($actor, $scope, $permissionCodes);
        $fingerprint = self::fingerprint(['code' => $code, 'name' => $name, 'permissions' => $permissionCodes]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'roles.create',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $code,
                $name,
                $permissionIds,
                $permissionCodes,
                $actor,
                $scope,
                $requestId,
                $ipAddress,
            ): array {
                if ($this->roles->roleCodeExists($code)) {
                    throw new HttpException(409, 'role_code_exists', 'A role with this code already exists.');
                }
                $roleId = $this->roles->createCustomRole($code, $name);
                $this->roles->replaceRolePermissions($roleId, $permissionIds);
                $this->audit->record(
                    $actor->userId,
                    'role.created',
                    'role',
                    $code,
                    null,
                    ['name' => $name, 'permissions' => $permissionCodes, 'business_id' => $scope->businessPublicId],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    null,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['created' => true, 'code' => $code], 'meta' => (object) []],
                ];
            },
        );
    }

    public function updateCustomRole(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        string $code,
        string $name,
        array $permissionCodes,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $code = strtolower(trim($code));
        $name = trim($name);
        $permissionCodes = $this->validatePermissionCodes($permissionCodes);
        $role = $this->roles->findActiveRole($code);
        if ($role === null) {
            throw new HttpException(404, 'role_not_found', 'The role was not found.');
        }
        if ($role['code'] === 'owner' || $this->roles->isSystemRole($code)) {
            throw new HttpException(403, 'system_role_immutable', 'System roles cannot be modified.');
        }
        if ($name === '' || self::unicodeLength($name) > 100) {
            throw new HttpException(422, 'role_name_invalid', 'Role name is required and must not exceed 100 characters.');
        }

        $permissionIds = $this->authorizePermissionSet($actor, $scope, $permissionCodes);
        $fingerprint = self::fingerprint(['code' => $code, 'name' => $name, 'permissions' => $permissionCodes]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            'roles.update',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $role,
                $name,
                $permissionIds,
                $permissionCodes,
                $actor,
                $scope,
                $requestId,
                $ipAddress,
            ): array {
                $this->roles->updateCustomRoleName($role['id'], $name);
                $this->roles->replaceRolePermissions($role['id'], $permissionIds);
                $this->audit->record(
                    $actor->userId,
                    'role.updated',
                    'role',
                    $role['code'],
                    ['name' => $role['name'], 'permissions' => $role['permissions']],
                    ['name' => $name, 'permissions' => $permissionCodes, 'business_id' => $scope->businessPublicId],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    null,
                );
                return [
                    'status' => 200,
                    'body' => ['data' => ['updated' => true, 'code' => $role['code']], 'meta' => (object) []],
                ];
            },
        );
    }

    public function assignBusinessRole(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        return $this->assign($actor, $targetUserPublicId, $roleCode, $scope, false, $idempotencyKey, $requestId, $ipAddress);
    }

    public function assignBranchRole(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if ($scope->branchId === null) {
            throw new HttpException(400, 'branch_context_required', 'A branch scope is required.');
        }
        return $this->assign($actor, $targetUserPublicId, $roleCode, $scope, true, $idempotencyKey, $requestId, $ipAddress);
    }

    public function removeBusinessRole(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        return $this->remove($actor, $targetUserPublicId, $roleCode, $scope, false, $idempotencyKey, $requestId, $ipAddress);
    }

    public function removeBranchRole(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        if ($scope->branchId === null) {
            throw new HttpException(400, 'branch_context_required', 'A branch scope is required.');
        }
        return $this->remove($actor, $targetUserPublicId, $roleCode, $scope, true, $idempotencyKey, $requestId, $ipAddress);
    }

    private function assign(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        bool $branchScoped,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $targetId = $this->resolveTarget($targetUserPublicId);
        $role = $this->resolveRole($roleCode);
        if ($role['code'] === 'owner' || in_array('*', $role['permissions'], true)) {
            throw new HttpException(403, 'protected_role_assignment', 'The installation owner role cannot be assigned through scoped role management.');
        }
        $this->authorizePermissionSet($actor, $scope, $role['permissions']);
        $fingerprint = self::fingerprint([
            'target_user_id' => $targetUserPublicId,
            'role_code' => $role['code'],
            'scope' => $branchScoped ? 'branch' : 'business',
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            $branchScoped ? 'roles.assign.branch' : 'roles.assign.business',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $targetId,
                $targetUserPublicId,
                $role,
                $actor,
                $scope,
                $branchScoped,
                $requestId,
                $ipAddress,
            ): array {
                if ($branchScoped) {
                    $this->roles->assignBranchRole($targetId, (int) $scope->branchId, $role['id'], $actor->userId);
                } else {
                    $this->roles->assignBusinessRole($targetId, $scope->businessId, $role['id'], $actor->userId);
                }
                $this->audit->record(
                    $actor->userId,
                    $branchScoped ? 'role.branch_assignment_created' : 'role.business_assignment_created',
                    'user',
                    $targetUserPublicId,
                    null,
                    [
                        'role_code' => $role['code'],
                        'business_id' => $scope->businessPublicId,
                        'branch_id' => $branchScoped ? $scope->branchPublicId : null,
                    ],
                    null,
                    $requestId,
                    $ipAddress,
                    $scope->businessId,
                    $branchScoped ? $scope->branchId : null,
                );
                return [
                    'status' => 201,
                    'body' => ['data' => ['assigned' => true], 'meta' => (object) []],
                ];
            },
        );
    }

    private function remove(
        AuthenticatedPrincipal $actor,
        string $targetUserPublicId,
        string $roleCode,
        BusinessScope $scope,
        bool $branchScoped,
        string $idempotencyKey,
        string $requestId,
        string $ipAddress,
    ): array {
        $targetId = $this->resolveTarget($targetUserPublicId);
        $role = $this->resolveRole($roleCode);
        $fingerprint = self::fingerprint([
            'target_user_id' => $targetUserPublicId,
            'role_code' => $role['code'],
            'scope' => $branchScoped ? 'branch' : 'business',
            'action' => 'remove',
        ]);

        return $this->idempotency->execute(
            $actor->userId,
            $scope,
            $branchScoped ? 'roles.remove.branch' : 'roles.remove.business',
            $idempotencyKey,
            $fingerprint,
            function (PDO $connection) use (
                $actor,
                $targetId,
                $targetUserPublicId,
                $role,
                $scope,
                $branchScoped,
                $requestId,
                $ipAddress,
            ): array {
                $removed = $branchScoped
                    ? $this->roles->removeBranchRole($targetId, (int) $scope->branchId, $role['id'])
                    : $this->roles->removeBusinessRole($targetId, $scope->businessId, $role['id']);
                if ($removed) {
                    $this->audit->record(
                        $actor->userId,
                        $branchScoped ? 'role.branch_assignment_removed' : 'role.business_assignment_removed',
                        'user',
                        $targetUserPublicId,
                        [
                            'role_code' => $role['code'],
                            'business_id' => $scope->businessPublicId,
                            'branch_id' => $branchScoped ? $scope->branchPublicId : null,
                        ],
                        null,
                        null,
                        $requestId,
                        $ipAddress,
                        $scope->businessId,
                        $branchScoped ? $scope->branchId : null,
                    );
                }
                return [
                    'status' => 200,
                    'body' => ['data' => ['removed' => $removed], 'meta' => (object) []],
                ];
            },
        );
    }

    /** @return list<int> */
    private function authorizePermissionSet(
        AuthenticatedPrincipal $actor,
        BusinessScope $scope,
        array $permissionCodes,
    ): array {
        if ($permissionCodes === [] || count($permissionCodes) > 64) {
            throw new HttpException(422, 'permission_set_invalid', 'A role must contain between 1 and 64 permissions.');
        }
        $idsByCode = $this->roles->findPermissionIdsByCodes($permissionCodes);
        if (count($idsByCode) !== count($permissionCodes)) {
            throw new HttpException(422, 'permission_unknown', 'The permission set contains an unknown permission.');
        }
        foreach ($permissionCodes as $permissionCode) {
            if ($permissionCode === '*' || !$this->permissions->allows($actor, $permissionCode, $scope)) {
                throw new HttpException(403, 'permission_delegation_denied', 'You cannot grant a permission you do not hold.');
            }
        }
        return array_values($idsByCode);
    }

    /** @return list<string> */
    private function validatePermissionCodes(array $permissionCodes): array
    {
        if (!array_is_list($permissionCodes)) {
            throw new HttpException(422, 'permission_set_invalid', 'Permissions must be a list of permission codes.');
        }
        $normalized = [];
        foreach ($permissionCodes as $permissionCode) {
            if (!is_string($permissionCode) || !preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $permissionCode)) {
                throw new HttpException(422, 'permission_code_invalid', 'A permission code is invalid.');
            }
            $normalized[] = $permissionCode;
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new HttpException(422, 'permission_set_duplicate', 'A permission code may only appear once.');
        }
        return $normalized;
    }

    private function resolveTarget(string $publicId): int
    {
        if (!self::isUuid($publicId)) {
            throw new HttpException(400, 'user_id_invalid', 'The target user identifier is invalid.');
        }
        $userId = $this->roles->findActiveUserId($publicId);
        if ($userId === null) {
            throw new HttpException(404, 'user_not_found', 'The target user was not found.');
        }
        return $userId;
    }

    /** @return array{id:int,code:string,name:string,permissions:list<string>} */
    private function resolveRole(string $code): array
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code)) {
            throw new HttpException(400, 'role_code_invalid', 'The role code is invalid.');
        }
        $role = $this->roles->findActiveRole($code);
        if ($role === null) {
            throw new HttpException(404, 'role_not_found', 'The role was not found.');
        }
        return $role;
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
}
