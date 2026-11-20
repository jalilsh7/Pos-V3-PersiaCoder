<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class UserManagementController
{
    public function __construct(private UserManagementService $users) {}

    public function listBusinessUsers(Request $request): Response
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return Response::success(['users' => $this->users->listBusinessUsers($scope)]);
    }

    public function listBranchUsers(Request $request): Response
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return Response::success(['users' => $this->users->listBranchUsers($scope)]);
    }

    public function createBusinessUser(Request $request): Response
    {
        return $this->create($request, false);
    }

    public function createBranchUser(Request $request): Response
    {
        return $this->create($request, true);
    }

    private function create(Request $request, bool $branchScoped): Response
    {
        $principal = $request->attribute('principal');
        $scope = $request->attribute('business_scope');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }

        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $payload = $request->json();
        foreach (['username', 'display_name', 'role_code'] as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field])) {
                throw new HttpException(422, 'invalid_user_payload', 'Username, display_name and role_code are required strings.');
            }
        }

        $result = $branchScoped
            ? $this->users->createBranchUser(
                $principal,
                $scope,
                $payload['username'],
                $payload['display_name'],
                $payload['role_code'],
                $request->header('idempotency-key') ?? '',
                $request->requestId,
                (string) ($request->attribute('remote_ip') ?? ''),
            )
            : $this->users->createBusinessUser(
                $principal,
                $scope,
                $payload['username'],
                $payload['display_name'],
                $payload['role_code'],
                $request->header('idempotency-key') ?? '',
                $request->requestId,
                (string) ($request->attribute('remote_ip') ?? ''),
            );

        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
