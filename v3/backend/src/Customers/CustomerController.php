<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Customers;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class CustomerController
{
    public function __construct(private CustomerService $customers) {}

    public function list(Request $request): Response
    {
        $rawLimit = $request->query('limit') ?? '50';
        if (!preg_match('/^[0-9]{1,3}$/', $rawLimit)) {
            throw new HttpException(422, 'customer_limit_invalid', 'Customer limit must be an integer between 1 and 100.');
        }
        return Response::success($this->customers->list(
            $this->scope($request),
            $request->query('q') ?? '',
            $request->query('status') ?? 'all',
            (int) $rawLimit,
            $request->query('cursor'),
        ));
    }

    public function quickSearch(Request $request): Response
    {
        $result = $this->customers->list(
            $this->scope($request),
            $request->query('q') ?? '',
            'active',
            20,
        );
        return Response::success(['customers' => array_map(static fn (array $customer): array => [
            'id' => $customer['id'],
            'name' => $customer['name'],
            'phone' => $customer['phone'],
        ], $result['customers'])]);
    }

    public function show(Request $request, array $parameters): Response
    {
        return Response::success($this->customers->get(
            $this->scope($request),
            (string) ($parameters['customerPublicId'] ?? ''),
        ));
    }

    public function create(Request $request): Response
    {
        $this->assertJson($request);
        $result = $this->customers->create(
            $this->principal($request),
            $this->scope($request),
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return $this->idempotentResponse($result);
    }

    public function update(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        $result = $this->customers->update(
            $this->principal($request),
            $this->scope($request),
            (string) ($parameters['customerPublicId'] ?? ''),
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        );
        return $this->idempotentResponse($result);
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
            throw new HttpException(400, 'business_context_required', 'A valid business context is required.');
        }
        return $scope;
    }

    private function idempotentResponse(array $result): Response
    {
        return Response::json($result['body'], $result['status'])
            ->withHeader('Idempotency-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
