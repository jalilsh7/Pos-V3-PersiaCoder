<?php
declare(strict_types=1);

namespace PersiaCoder\\PosV3\\Finance;

use PersiaCoder\\PosV3\\Auth\\AuthenticatedPrincipal;
use PersiaCoder\\PosV3\\Auth\\BusinessScope;
use PersiaCoder\\PosV3\\Http\\HttpException;
use PersiaCoder\\PosV3\\Http\\Request;
use PersiaCoder\\PosV3\\Http\\Response;

final readonly class OrderPaymentController
{
    public function __construct(private OrderPaymentService $payments) {}

    public function list(Request $request, array $parameters): Response
    {
        return Response::success($this->payments->list(
            $this->scope($request),
            (string) ($parameters['orderPublicId'] ?? ''),
        ));
    }

    public function captureCash(Request $request, array $parameters): Response
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\\/json(?:\\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        $result = $this->payments->captureCash(
            $this->principal($request),
            $this->scope($request),
            (string) ($parameters['orderPublicId'] ?? ''),
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
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
}
