<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Security;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScopeResolver;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class PermissionMiddleware
{
    public function __construct(
        private PermissionService $permissions,
        private BusinessScopeResolver $scopeResolver,
        private string $permissionCode,
        private bool $requireBranch = true,
        private bool $allowBranchContext = false,
    ) {}

    /** @param callable(Request): Response $next */
    public function __invoke(Request $request, array $parameters, callable $next): Response
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        if ($principal->mustChangePassword) {
            throw new HttpException(403, 'password_change_required', 'Change the initial password before using business operations.');
        }

        $scope = $this->scopeResolver->resolve($request, $this->requireBranch, $this->allowBranchContext);
        if (!$this->permissions->allows($principal, $this->permissionCode, $scope)) {
            throw new HttpException(403, 'permission_denied', 'You do not have permission to perform this action.');
        }

        return $next($request->withAttribute('business_scope', $scope));
    }
}
