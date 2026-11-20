<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;

final readonly class SmsServiceTemplateOutboxReadController
{
    public function __construct(private SmsServiceTemplateOutboxReadService $service) {}

    public function list(Request $request): Response
    {
        $scope=$request->attribute('business_scope');
        if(!$scope instanceof BusinessScope) throw new HttpException(400,'business_context_required','A valid business and branch context is required.');
        $raw=$request->query('limit')??'50';
        if(!preg_match('/^[0-9]{1,3}$/',$raw)) throw new HttpException(422,'sms_outbox_limit_invalid','Limit must be between 1 and 100.');
        return Response::success($this->service->list($scope,(int)$raw,$request->query('status')));
    }
}
