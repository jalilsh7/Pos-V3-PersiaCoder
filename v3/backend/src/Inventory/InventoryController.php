<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Inventory;

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class InventoryController
{
    public function __construct(
        private InventoryService $inventory,
        private InventoryDocumentService $documents,
        private InventoryPostingService $posting,
        private SupplierService $suppliers,
    ) {}

    public function suppliers(Request $request): Response
    {
        $includeInactive = ($request->query('include_inactive') ?? '1') !== '0';
        return Response::success(['suppliers' => $this->suppliers->list(
            $this->scope($request), $includeInactive,
        )]);
    }

    public function createSupplier(Request $request): Response
    {
        return $this->idempotentResponse($this->suppliers->create(
            $this->principal($request), $this->scope($request), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateSupplier(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->suppliers->update(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['supplierPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function units(Request $request): Response
    {
        return Response::success(['units' => $this->inventory->listUnits()]);
    }

    public function stockItems(Request $request): Response
    {
        $limitValue = $request->query('limit') ?? '100';
        if (!ctype_digit($limitValue)) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The inventory limit is invalid.');
        }
        return Response::success(
            ['stock_items' => $this->inventory->listStockItems(
                $this->scope($request), $request->query('search'), (int) $limitValue,
            )],
            ['limit' => (int) $limitValue],
        );
    }

    public function createStockItem(Request $request): Response
    {
        return $this->idempotentResponse($this->inventory->createStockItem(
            $this->principal($request), $this->scope($request), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateStockItem(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->inventory->updateStockItem(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['stockItemPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function locations(Request $request): Response
    {
        return Response::success(['locations' => $this->inventory->listLocations($this->scope($request))]);
    }

    public function createLocation(Request $request): Response
    {
        return $this->idempotentResponse($this->inventory->createLocation(
            $this->principal($request), $this->scope($request), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function updateLocation(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->inventory->updateLocation(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['locationPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }


    public function balances(Request $request): Response
    {
        $limitValue = $request->query('limit') ?? '100';
        if (!ctype_digit($limitValue)) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The inventory limit is invalid.');
        }
        $items = $this->inventory->listBalances(
            $this->scope($request),
            $request->query('stock_item_id'),
            $request->query('location_id'),
            (int) $limitValue,
        );
        return Response::success(['balances' => $items], ['limit' => (int) $limitValue]);
    }

    public function ledger(Request $request): Response
    {
        $limitValue = $request->query('limit') ?? '50';
        $beforeValue = $request->query('before_id');
        if (!ctype_digit($limitValue) || ($beforeValue !== null && !ctype_digit($beforeValue))) {
            throw new HttpException(422, 'inventory_pagination_invalid', 'The inventory ledger pagination is invalid.');
        }
        $result = $this->inventory->listLedger(
            $this->scope($request),
            $request->query('stock_item_id'),
            $request->query('location_id'),
            $beforeValue === null ? null : (int) $beforeValue,
            (int) $limitValue,
        );
        return Response::success(
            ['entries' => $result['items'], 'next_cursor' => $result['next_cursor']],
            ['limit' => (int) $limitValue, 'next_cursor' => $result['next_cursor']],
        );
    }


    public function documents(Request $request): Response
    {
        $limitValue = $request->query('limit') ?? '50';
        if (!ctype_digit($limitValue)) {
            throw new HttpException(422, 'inventory_limit_invalid', 'The inventory limit is invalid.');
        }
        return Response::success(
            ['documents' => $this->documents->list(
                $this->scope($request), $request->query('document_type'),
                $request->query('status'), (int) $limitValue,
            )],
            ['limit' => (int) $limitValue],
        );
    }

    public function document(Request $request, array $parameters): Response
    {
        return Response::success([
            'stock_document' => $this->documents->get(
                $this->scope($request), (string) ($parameters['documentPublicId'] ?? ''),
            ),
        ]);
    }

    public function createDocument(Request $request): Response
    {
        return $this->idempotentResponse($this->documents->create(
            $this->principal($request), $this->scope($request), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }


    public function createDocumentLine(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->documents->addLine(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['documentPublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }


    public function updateDocumentLine(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->documents->updateLine(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['documentPublicId'] ?? ''),
            (string) ($parameters['linePublicId'] ?? ''), $this->jsonPayload($request),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function documentValuation(Request $request, array $parameters): Response
    {
        return Response::success(['valuation' => $this->documents->getValuation(
            $this->scope($request), (string) ($parameters['documentPublicId'] ?? ''),
        )]);
    }

    public function postDocument(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->posting->post(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['documentPublicId'] ?? ''),
            $request->header('idempotency-key') ?? '', $request->requestId,
            (string) ($request->attribute('remote_ip') ?? ''),
        ));
    }

    public function deleteDocumentLine(Request $request, array $parameters): Response
    {
        return $this->idempotentResponse($this->documents->deleteLine(
            $this->principal($request), $this->scope($request),
            (string) ($parameters['documentPublicId'] ?? ''),
            (string) ($parameters['linePublicId'] ?? ''),
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
