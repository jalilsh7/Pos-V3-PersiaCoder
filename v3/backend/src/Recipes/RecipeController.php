<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Recipes;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class RecipeController
{
    public function __construct(private RecipeService $recipes) {}

    public function showProductRecipe(Request $request, array $parameters): Response
    {
        return Response::success(['recipe' => $this->recipes->getProductRecipe(
            $this->scope($request),
            (string) ($parameters['productPublicId'] ?? ''),
            $request->query('variant_id'),
        )]);
    }

    public function createDraftVersion(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->recipes->createDraftVersion(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['productPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function activateVersion(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->recipes->activateVersion(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['versionPublicId'] ?? ''),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    private function jsonPayload(Request $request): array
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\\/json(?:\\s*;|$)/', $contentType)) {
            throw new HttpException(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }
        return $request->json();
    }

    private function idempotentResponse(array $result): Response
    {
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
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }
        return $scope;
    }
}
