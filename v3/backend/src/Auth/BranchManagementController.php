<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class BranchManagementController
{
    public function __construct(private BranchManagementService $branches) {}

    public function list(Request $request): Response
    {
        return Response::success(['branches' => $this->branches->list($this->scope($request))]);
    }

    public function create(Request $request): Response
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $payload = $request->json();
        if (!is_string($payload['name'] ?? null) || !is_string($payload['code'] ?? null)) {
            throw new HttpException(422, 'invalid_branch_payload', 'Branch name and code are required strings.');
        }
        $timezone = $payload['timezone'] ?? null;
        if ($timezone !== null && !is_string($timezone)) {
            throw new HttpException(422, 'invalid_branch_payload', 'Timezone must be a string.');
        }

        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        $result = $this->branches->create(
            $principal,
            $this->scope($request),
            $payload['name'],
            $payload['code'],
            $timezone,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    private function scope(Request $request): BusinessScope
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return $scope;
    }
}
