<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Auth;

use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;

final readonly class AuditLogController
{
    public function __construct(private AuditLogRepository $audit) {}

    public function list(Request $request): Response
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) {
            throw new HttpException(400, 'business_context_required', 'A valid business scope is required.');
        }

        $beforeValue = $request->query('before_id');
        $beforeId = null;
        if ($beforeValue !== null) {
            if (!ctype_digit($beforeValue) || (int) $beforeValue < 1) {
                throw new HttpException(422, 'audit_cursor_invalid', 'The audit cursor is invalid.');
            }
            $beforeId = (int) $beforeValue;
        }

        $limitValue = $request->query('limit') ?? '50';
        if (!ctype_digit($limitValue) || (int) $limitValue < 1 || (int) $limitValue > 100) {
            throw new HttpException(422, 'audit_limit_invalid', 'Audit page size must be between 1 and 100.');
        }
        $limit = (int) $limitValue;
        $events = $this->audit->listForBusiness($scope->businessId, $scope->branchId, $beforeId, $limit);
        $nextCursor = count($events) === $limit ? (string) $events[array_key_last($events)]['id'] : null;

        return Response::success(
            ['events' => $events],
            ['next_before_id' => $nextCursor, 'limit' => $limit],
        );
    }
}
