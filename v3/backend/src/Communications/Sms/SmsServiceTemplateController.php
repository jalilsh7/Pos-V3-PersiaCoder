<?php
declare(strict_types=1);
namespace PersiaCoder\PosV3\Communications\Sms;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Http\Request;
use PersiaCoder\PosV3\Http\Response;
final readonly class SmsServiceTemplateController
{
 public function __construct(private SmsServiceTemplateService $service) {}
 public function list(Request $request): Response { $scope=$request->attribute('business_scope'); if(!$scope instanceof BusinessScope||$scope->branchId===null) throw new HttpException(400,'business_context_required','انتخاب کسب‌وکار و شعبه الزامی است.'); return Response::success($this->service->list($scope)); }
 public function save(Request $request): Response { $scope=$request->attribute('business_scope'); $actor=$request->attribute('principal'); if(!$scope instanceof BusinessScope||$scope->branchId===null||!$actor instanceof \PersiaCoder\PosV3\Auth\AuthenticatedPrincipal) throw new HttpException(400,'business_context_required','انتخاب کسب‌وکار و شعبه الزامی است.'); return Response::success(['template'=>$this->service->save($scope,$actor->userId,$request->json())]); }
}
