<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class RoleManagementController
{
    public function __construct(private RoleManagementService $roles) {}

    public function list(Request $request): Response
    {
        return Response::success(['roles' => $this->roles->listRoles()]);
    }

    public function permissions(Request $request): Response
    {
        return Response::success(['permissions' => $this->roles->listPermissions()]);
    }

    public function create(Request $request): Response
    {
        [$code, $name, $permissions] = $this->rolePayload($request, true);
        $result = $this->roles->createCustomRole(
            $this->principal($request),
            $this->scope($request),
            $code,
            $name,
            $permissions,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function update(Request $request, array $parameters): Response
    {
        [, $name, $permissions] = $this->rolePayload($request, false);
        $result = $this->roles->updateCustomRole(
            $this->principal($request),
            $this->scope($request),
            $parameters['roleCode'],
            $name,
            $permissions,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function assignBusiness(Request $request, array $parameters): Response
    {
        $result = $this->roles->assignBusinessRole(
            $this->principal($request),
            $parameters['userPublicId'],
            $this->roleCode($request),
            $this->scope($request),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function assignBranch(Request $request, array $parameters): Response
    {
        $result = $this->roles->assignBranchRole(
            $this->principal($request),
            $parameters['userPublicId'],
            $this->roleCode($request),
            $this->scope($request),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function removeBusiness(Request $request, array $parameters): Response
    {
        $result = $this->roles->removeBusinessRole(
            $this->principal($request),
            $parameters['userPublicId'],
            $parameters['roleCode'],
            $this->scope($request),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function removeBranch(Request $request, array $parameters): Response
    {
        $result = $this->roles->removeBranchRole(
            $this->principal($request),
            $parameters['userPublicId'],
            $parameters['roleCode'],
            $this->scope($request),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    private function rolePayload(Request $request, bool $includeCode): array
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $payload = $request->json();
        $code = $payload['code'] ?? '';
        $name = $payload['name'] ?? null;
        $permissions = $payload['permissions'] ?? null;
        if (($includeCode && !is_string($code)) || !is_string($name) || !is_array($permissions)) {
            throw new HttpException(422, 'invalid_role_payload', 'Role code, name and permissions are required.');
        }
        return [$code, $name, $permissions];
    }

    private function roleCode(Request $request): string
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $payload = $request->json();
        $code = $payload['role_code'] ?? null;
        if (!is_string($code)) {
            throw new HttpException(422, 'role_code_required', 'A role_code string is required.');
        }
        return $code;
    }

    private function principal(Request $request): AuthenticatedPrincipal
    {
        $principal = $request->attribute('principal');
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new HttpException(401, 'authentication_required', 'Authentication is required.');
        }
        return $principal;
    }

    private function scope(Request $request): BusinessScope
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return $scope;
    }

    private function ipAddress(Request $request): string
    {
        return (string) ($request->attribute('remote_ip') ?? '');
    }

    private function idempotentResponse(array $result): Response
    {
        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
