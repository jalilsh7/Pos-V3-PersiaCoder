<?php
declare(strict_types=1);
namespace PersiaCoder\\PosV3\\Communications\\Sms;
use PersiaCoder\\PosV3\\Auth\\BusinessScope;
use PersiaCoder\\PosV3\\Http\\HttpException;
use PersiaCoder\\PosV3\\Http\\Request;
use PersiaCoder\\PosV3\\Http\\Response;
final readonly class SmsOutboxReadController
{
    public function __construct(private SmsOutboxReadService $outbox) {}
    public function list(Request $request): Response
    {
        $scope = $request->attribute('business_scope');
        if (!$scope instanceof BusinessScope) throw new HttpException(400, 'business_context_required', 'A valid business and branch context is required.');
        $rawLimit = $request->query('limit') ?? '25';
        if (!preg_match('/^[0-9]{1,3}$/', $rawLimit)) throw new HttpException(422, 'sms_outbox_limit_invalid', 'Limit must be an integer between 1 and 100.');
        $status = $request->query('status');
        if ($status !== null && !is_string($status)) throw new HttpException(422, 'sms_outbox_status_invalid', 'Status filter is invalid.');
        return Response::success($this->outbox->list($scope, (int) $rawLimit, $status, $request->query('cursor')));
    }
}
