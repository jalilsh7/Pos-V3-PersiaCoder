<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;

final readonly class SmsServiceTemplateOutboxReadService
{
    private const STATUSES = ['queued','processing','accepted','delivered','failed','rejected','unknown'];

    public function __construct(private PDO $db) {}

    public function list(BusinessScope $scope, int $limit, ?string $status): array
    {
        if ($scope->branchId === null) throw new HttpException(400,'business_branch_context_required','A branch context is required.');
        if ($limit < 1 || $limit > 100) throw new HttpException(422,'sms_outbox_limit_invalid','Limit must be between 1 and 100.');
        if ($status !== null && !in_array($status,self::STATUSES,true)) throw new HttpException(422,'sms_outbox_status_invalid','The SMS status filter is not supported.');
        $sql='SELECT public_id,trigger_key,provider_code,provider_body_id,status,attempt_count,failure_code,created_at,updated_at
              FROM pos_sms_service_outbox WHERE business_id=:business_id AND branch_id=:branch_id';
        $params=['business_id'=>$scope->businessId,'branch_id'=>$scope->branchId];
        if($status!==null){$sql.=' AND status=:status';$params['status']=$status;}
        $sql.=' ORDER BY created_at DESC,id DESC LIMIT :limit';
        $q=$this->db->prepare($sql);
        foreach($params as $key=>$value)$q->bindValue(':'.$key,$value);
        $q->bindValue(':limit',$limit,PDO::PARAM_INT);
        $q->execute();
        $messages=[];
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $messages[]=[
                'id'=>(string)$r['public_id'],
                'trigger_key'=>(string)$r['trigger_key'],
                'provider'=>(string)$r['provider_code'],
                'provider_body_id'=>(int)$r['provider_body_id'],
                'status'=>(string)$r['status'],
                'attempt_count'=>(int)$r['attempt_count'],
                'failure_code'=>$r['failure_code']===null?null:(string)$r['failure_code'],
                'created_at'=>(string)$r['created_at'],
                'updated_at'=>(string)$r['updated_at'],
            ];
        }
        return ['messages'=>$messages];
    }
}
