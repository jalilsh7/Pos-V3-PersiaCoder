<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;

final readonly class BusinessScopeResolver
{
    public function __construct(private PermissionRepository $repository) {}

    public function resolve(Request $request, bool $requireBranch, bool $allowBranchContext = false): BusinessScope
    {
        $businessPublicId = trim($request->header('x-business-id') ?? '');
        if (!self::isUuid($businessPublicId)) {
            throw new HttpException(400, 'business_context_required', 'A valid X-Business-ID header is required.');
        }

        $businessId = $this->repository->findActiveBusinessId($businessPublicId);
        if ($businessId === null) {
            throw new HttpException(404, 'business_not_found', 'The requested business was not found.');
        }

        $branchPublicId = trim($request->header('x-branch-id') ?? '');
        if ($branchPublicId === '') {
            if ($requireBranch) {
                throw new HttpException(400, 'branch_context_required', 'A valid X-Branch-ID header is required.');
            }
            return new BusinessScope($businessId, $businessPublicId, null, null);
        }

        if (!$requireBranch && !$allowBranchContext) {
            throw new HttpException(400, 'branch_context_not_allowed', 'This operation requires a business-wide scope without a branch header.');
        }

        if (!self::isUuid($branchPublicId)) {
            throw new HttpException(400, 'branch_context_invalid', 'The X-Branch-ID header is invalid.');
        }

        $branchId = $this->repository->findActiveBranchId($branchPublicId, $businessId);
        if ($branchId === null) {
            throw new HttpException(404, 'branch_not_found', 'The requested branch was not found in this business.');
        }

        return new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
