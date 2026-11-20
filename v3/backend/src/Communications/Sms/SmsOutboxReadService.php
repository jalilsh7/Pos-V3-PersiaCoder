<?php
declare(strict_types=1);
namespace PersiaCoder\\PosV3\\Communications\\Sms;
use PDO;
use PersiaCoder\\PosV3\\Auth\\BusinessScope;
use PersiaCoder\\PosV3\\Http\\HttpException;
final readonly class SmsOutboxReadService
{
    private const STATUSES = ['queued','processing','accepted','sent_to_carrier','delivered','failed','rejected','unknown','cancelled'];
    public function __construct(private PDO $connection) {}
    public function list(BusinessScope $scope, int $limit, ?string $status, ?string $cursor): array
    {
        if ($limit < 1 || $limit > 100) throw new HttpException(422, 'sms_outbox_limit_invalid', 'Limit must be between 1 and 100.');
        if ($status !== null && !in_array($status, self::STATUSES, true)) throw new HttpException(422, 'sms_outbox_status_invalid', 'The SMS status filter is not supported.');
        if ($scope->branchId === null) throw new HttpException(400, 'business_branch_context_required', 'A branch context is required.');
        $cursorData = $this->decodeCursor($cursor);
        $where = ['business_id = :business_id', 'branch_id = :branch_id'];
        $params = ['business_id'=>$scope->businessId, 'branch_id'=>$scope->branchId];
        if ($status !== null) { $where[]='status = :status'; $params['status']=$status; }
        if ($cursorData !== null) {
            $where[]='(created_at < :cursor_created_at OR (created_at = :cursor_created_at_equal AND id < :cursor_id))';
            $params['cursor_created_at']=$cursorData['created_at'];
            $params['cursor_created_at_equal']=$cursorData['created_at'];
            $params['cursor_id']=$cursorData['id'];
        }
        $q=$this->connection->prepare('SELECT id,public_id,message_type,provider_code,status,attempt_count,failure_code,created_at,updated_at,accepted_at,delivered_at FROM pos_sms_outbox WHERE '.implode(' AND ',$where).' ORDER BY created_at DESC,id DESC LIMIT :limit');
        foreach($params as $key=>$value) $q->bindValue(':'.$key,$value);
        $q->bindValue(':limit',$limit+1,PDO::PARAM_INT); $q->execute();
        $rows=$q->fetchAll(PDO::FETCH_ASSOC); $more=count($rows)>$limit; if($more) array_pop($rows);
        $items=[]; $ids=[];
        foreach($rows as $row) {
            $ids[]=(int)$row['id'];
            $items[]=['id'=>(string)$row['public_id'],'message_type'=>(string)$row['message_type'],'provider'=>(string)$row['provider_code'],'status'=>(string)$row['status'],'attempt_count'=>(int)$row['attempt_count'],'failure_code'=>$row['failure_code']===null?null:(string)$row['failure_code'],'created_at'=>(string)$row['created_at'],'updated_at'=>(string)$row['updated_at'],'accepted_at'=>$row['accepted_at']===null?null:(string)$row['accepted_at'],'delivered_at'=>$row['delivered_at']===null?null:(string)$row['delivered_at'],'attempts'=>[]];
        }
        if($ids!==[]) {
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $a=$this->connection->prepare('SELECT message_id,attempt_number,attempt_type,outcome,provider_status_code,error_code,attempted_at,completed_at FROM pos_sms_delivery_attempts WHERE business_id=? AND branch_id=? AND message_id IN ('.$ph.') ORDER BY message_id ASC,id ASC');
            $a->execute(array_merge([$scope->businessId,$scope->branchId],$ids)); $map=[];
            foreach($items as $i=>$_) $map[$ids[$i]]=$i;
            while($row=$a->fetch(PDO::FETCH_ASSOC)) {
                $mid=(int)$row['message_id']; if(!isset($map[$mid])) continue;
                $items[$map[$mid]]['attempts'][]=['number'=>(int)$row['attempt_number'],'type'=>(string)$row['attempt_type'],'outcome'=>(string)$row['outcome'],'provider_status_code'=>$row['provider_status_code']===null?null:(int)$row['provider_status_code'],'error_code'=>$row['error_code']===null?null:(string)$row['error_code'],'attempted_at'=>(string)$row['attempted_at'],'completed_at'=>$row['completed_at']===null?null:(string)$row['completed_at']];
            }
        }
        $next=null;
        if($more && $rows!==[]) { $last=$rows[count($rows)-1]; $next=self::encodeCursor((string)$last['created_at'],(int)$last['id']); }
        return ['messages'=>$items,'next_cursor'=>$next];
    }
    private function decodeCursor(?string $cursor): ?array
    {
        if($cursor===null || $cursor==='') return null;
        if(strlen($cursor)>256) throw new HttpException(400,'sms_outbox_cursor_invalid','The pagination cursor is invalid.');
        $raw=base64_decode(strtr($cursor,'-_','+/').str_repeat('=',(4-strlen($cursor)%4)%4),true);
        $data=$raw===false?null:json_decode($raw,true);
        if(!is_array($data)||!isset($data['created_at'],$data['id'])||!is_string($data['created_at'])||!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?$/',$data['created_at'])||!(is_int($data['id'])||(is_string($data['id'])&&ctype_digit($data['id'])))||(int)$data['id']<1) throw new HttpException(400,'sms_outbox_cursor_invalid','The pagination cursor is invalid.');
        return ['created_at'=>$data['created_at'],'id'=>(int)$data['id']];
    }
    private static function encodeCursor(string $createdAt,int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['created_at'=>$createdAt,'id'=>$id],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
    }
}
