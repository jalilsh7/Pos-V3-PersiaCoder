<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Orders;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function list(Request $request): Response
    {
        $scope = $this->scope($request);
        $status = $request->query('status');
        $channel = $request->query('channel');
        $paymentStatus = $request->query('payment');
        $fulfillmentMethod = $request->query('fulfillment');
        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');
        if (($fromDate !== null && !is_string($fromDate)) || ($toDate !== null && !is_string($toDate))) {
            throw new HttpException(422, 'invalid_order_date_filter', 'Order dates must be strings in YYYY-MM-DD format.');
        }
        $rawLimit = $request->query('limit') ?? '50';
        if (!preg_match('/^[0-9]{1,3}$/', $rawLimit)) {
            throw new HttpException(422, 'invalid_order_limit', 'Limit must be an integer between 1 and 100.');
        }
        $before = $request->query('before');
        if ($before !== null && !is_string($before)) {
            throw new HttpException(422, 'invalid_order_cursor', 'The order pagination cursor is invalid.');
        }
        $page = $this->orders->list(
            $scope,
            $status,
            $channel,
            (int) $rawLimit,
            $before,
            $paymentStatus,
            $fulfillmentMethod,
            $fromDate,
            $toDate,
        );
        return Response::success($page);
    }

    public function show(Request $request, array $parameters): Response
    {
        return Response::success($this->orders->get($this->scope($request), $parameters['orderPublicId']));
    }

    public function create(Request $request): Response
    {
        $this->assertJson($request);
        $result = $this->orders->create(
            $this->principal($request),
            $this->scope($request),
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function transition(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        $result = $this->orders->transition(
            $this->principal($request),
            $this->scope($request),
            $parameters['orderPublicId'],
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
        );
        return $this->idempotentResponse($result);
    }

    public function transitionKitchen(Request $request, array $parameters): Response
    {
        $this->assertJson($request);
        $result = $this->orders->transitionKitchen(
            $this->principal($request),
            $this->scope($request),
            $parameters['orderPublicId'],
            $request->json(),
            $request->header('idempotency-key') ?? '',
            $request->requestId,
            $this->ipAddress($request),
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
