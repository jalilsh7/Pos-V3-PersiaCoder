<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Catalog;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class CatalogController
{
    public function __construct(private CatalogService $catalog) {}

    public function categories(Request $request): Response
    {
        return Response::success(['categories' => $this->catalog->listCategories($this->scope($request))]);
    }

    public function products(Request $request): Response
    {
        $limitValue = $request->query('limit') ?? '100';
        if (!ctype_digit($limitValue)) {
            throw new HttpException(422, 'catalog_limit_invalid', 'The catalog limit is invalid.');
        }
        $products = $this->catalog->listProducts(
            $this->scope($request),
            $request->query('search'),
            $request->query('category_id'),
            (int) $limitValue,
        );
        return Response::success(['products' => $products], ['limit' => (int) $limitValue]);
    }

    public function createCategory(Request $request): Response
    {
        $payload = $this->jsonPayload($request);
        if (!is_string($payload['name'] ?? null)) {
            throw new HttpException(422, 'category_name_invalid', 'A category name is required.');
        }
        $result = $this->catalog->createCategory(
            $this->principal($request),
            $this->scope($request),
            $payload,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return $this->idempotentResponse($result);
    }

    public function createProduct(Request $request): Response
    {
        $payload = $this->jsonPayload($request);
        if (!is_string($payload['name'] ?? null) || !array_key_exists('base_price_amount', $payload)) {
            throw new HttpException(422, 'product_payload_invalid', 'Product name and base_price_amount are required.');
        }
        $result = $this->catalog->createProduct(
            $this->principal($request),
            $this->scope($request),
            $payload,
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return $this->idempotentResponse($result);
    }


    public function updateCategory(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->updateCategory(
            $this->principal($request), $this->scope($request), (string) ($parameters['categoryPublicId'] ?? ''),
            $this->jsonPayload($request), $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateProduct(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->updateProduct(
            $this->principal($request), $this->scope($request), (string) ($parameters['productPublicId'] ?? ''),
            $this->jsonPayload($request), $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function archiveProduct(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->archiveProduct(
            $this->principal($request), $this->scope($request), (string) ($parameters['productPublicId'] ?? ''),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    private function jsonPayload(Request $request): array
    {
        $contentType = strtolower(trim($request->header('content-type') ?? ''));
        if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
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

    public function productDetails(Request $r, array $p): Response { return Response::success(['product'=>$this->catalog->productDetails($this->scope($r),(string)($p['productPublicId']??''))]); }
    public function createVariant(Request $r, array $p): Response { return $this->idempotentResponse($this->catalog->createVariant($this->principal($r),$this->scope($r),(string)($p['productPublicId']??''),$this->jsonPayload($r),$r->header('idempotency-key')??'',$r->requestId,(string)($r->attribute('remote_ip')??''))); }
    public function createOptionGroup(Request $r): Response { return $this->idempotentResponse($this->catalog->createOptionGroup($this->principal($r),$this->scope($r),$this->jsonPayload($r),$r->header('idempotency-key')??'',$r->requestId,(string)($r->attribute('remote_ip')??''))); }
    public function attachOptionGroup(Request $r, array $p): Response { return $this->idempotentResponse($this->catalog->attachOptionGroup($this->principal($r),$this->scope($r),(string)($p['productPublicId']??''),$this->jsonPayload($r),$r->header('idempotency-key')??'',$r->requestId,(string)($r->attribute('remote_ip')??''))); }


    public function updateVariant(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->updateVariant(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['variantPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateOptionGroup(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->updateOptionGroup(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['groupPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateOption(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->catalog->updateOption(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['optionPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

}
