<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Finance;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class CashRegisterController
{
    public function __construct(private CashRegisterService $cash) {}

    public function list(Request $request): Response
    {
        return Response::success($this->cash->list($this->scope($request)));
    }

    public function createRegister(Request $request): Response
    {
        $this->assertJson($request);
        return $this->idempotentResponse($this->cash->createRegister(
            $this->principal($request),
            $this->scope($request),
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        ));
    }

    public function openSession(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        return $this->idempotentResponse($this->cash->openSession(
            $this->principal($request),
            $this->scope($request),
            $parameters['registerPublicId'],
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        ));
    }

    public function closeSession(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        return $this->idempotentResponse($this->cash->closeSession(
            $this->principal($request),
            $this->scope($request),
            $parameters['sessionPublicId'],
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        ));
    }

    public function reviewSession(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        return $this->idempotentResponse($this->cash->reviewSession(
            $this->principal($request),
            $this->scope($request),
            $parameters['sessionPublicId'],
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        ));
    }

    private function assertJson(Request $request): void
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
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
            throw new HttpException(400, 'business_context_required', 'A valid business and branch scope is required.');
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
