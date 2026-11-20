<?php
declare(strict_types=1);
namespace PersiaCoder\PosV3\Communications\Sms;
use PDO;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Http\HttpException;
final readonly class SmsServiceTemplateService
{
    private const CATALOG = [
      'order.created' => [
        ['key'=>'order_number','label'=>'شماره سفارش','source'=>'orders.order_number','example'=>'1205'],
        ['key'=>'customer_name','label'=>'نام مشتری','source'=>'customers.display_name','example'=>'علی رضایی'],
        ['key'=>'total_amount','label'=>'مبلغ نهایی','source'=>'orders.total_amount','example'=>'۲۵۰٬۰۰۰'],
        ['key'=>'created_at','label'=>'زمان ثبت سفارش','source'=>'orders.created_at','example'=>'۱۴۰۵/۰۸/۳۰ ۱۲:۳۰'],
      ],
      'order.status_changed' => [
        ['key'=>'order_number','label'=>'شماره سفارش','source'=>'orders.order_number','example'=>'1205'],
        ['key'=>'customer_name','label'=>'نام مشتری','source'=>'customers.display_name','example'=>'علی رضایی'],
        ['key'=>'order_status','label'=>'وضعیت سفارش','source'=>'orders.order_status','example'=>'در حال آماده‌سازی'],
      ],
      'order.ready' => [
        ['key'=>'order_number','label'=>'شماره سفارش','source'=>'orders.order_number','example'=>'1205'],
        ['key'=>'customer_name','label'=>'نام مشتری','source'=>'customers.display_name','example'=>'علی رضایی'],
      ],
      'order.completed' => [
        ['key'=>'order_number','label'=>'شماره سفارش','source'=>'orders.order_number','example'=>'1205'],
        ['key'=>'customer_name','label'=>'نام مشتری','source'=>'customers.display_name','example'=>'علی رضایی'],
      ],
    ];
    public function __construct(private PDO $db) {}
    public function catalog(): array { return ['triggers'=>array_map(static fn($key,$vars)=>['key'=>$key,'variables'=>$vars],array_keys(self::CATALOG),array_values(self::CATALOG))]; }
    public function list(BusinessScope $scope): array {
      $q=$this->db->prepare('SELECT trigger_key,provider_body_id,enabled,variable_keys_json,version,updated_at FROM pos_sms_service_templates WHERE business_id=? AND branch_id=? ORDER BY trigger_key');
      $q->execute([$scope->businessId,$scope->branchId]); $rows=[];
      while($r=$q->fetch(PDO::FETCH_ASSOC)) $rows[]=['trigger_key'=>$r['trigger_key'],'provider_body_id'=>(int)$r['provider_body_id'],'enabled'=>(bool)$r['enabled'],'variable_keys'=>json_decode((string)$r['variable_keys_json'],true)?:[],'version'=>(int)$r['version'],'updated_at'=>$r['updated_at']];
      return ['templates'=>$rows,'catalog'=>$this->catalog()['triggers']];
    }
    public function save(BusinessScope $scope,int $actorId,array $input): array {
      $key=$input['trigger_key']??null; $body=$input['provider_body_id']??null; $enabled=$input['enabled']??false; $keys=$input['variable_keys']??null;
      if(!is_string($key)||!isset(self::CATALOG[$key])) throw new HttpException(422,'sms_trigger_invalid','رویداد پیامک معتبر نیست.');
      if(!(is_int($body)||(is_string($body)&&ctype_digit($body)))||(int)$body<1||(int)$body>2147483647) throw new HttpException(422,'sms_template_id_invalid','کد قالب ملی‌پیامک معتبر نیست.');
      if(!is_bool($enabled)||!is_array($keys)||!array_is_list($keys)||count($keys)>20) throw new HttpException(422,'sms_variable_mapping_invalid','نگاشت متغیرها معتبر نیست.');
      $allowed=array_column(self::CATALOG[$key],'key'); $seen=[];
      foreach($keys as $v) { if(!is_string($v)||!in_array($v,$allowed,true)||isset($seen[$v])) throw new HttpException(422,'sms_variable_mapping_invalid','فقط متغیرهای راهنمای همین رویداد و بدون تکرار مجاز هستند.'); $seen[$v]=true; }
      if($enabled && $keys===[]) throw new HttpException(422,'sms_variable_mapping_required','برای فعال‌سازی حداقل یک متغیر انتخاب کنید.');
      $json=json_encode(array_values($keys),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
      $this->db->beginTransaction();
      try {
        $find=$this->db->prepare('SELECT provider_body_id,enabled,variable_keys_json,version FROM pos_sms_service_templates WHERE business_id=? AND branch_id=? AND trigger_key=? FOR UPDATE');
        $find->execute([$scope->businessId,$scope->branchId,$key]); $before=$find->fetch(PDO::FETCH_ASSOC);
        $version=$before?(int)$before['version']+1:1;
        $up=$this->db->prepare('INSERT INTO pos_sms_service_templates (business_id,branch_id,trigger_key,provider_body_id,enabled,variable_keys_json,version,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE provider_body_id=VALUES(provider_body_id),enabled=VALUES(enabled),variable_keys_json=VALUES(variable_keys_json),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP(3)');
        $up->execute([$scope->businessId,$scope->branchId,$key,(int)$body,$enabled?1:0,$json,$version,$actorId,$actorId]);
        $audit=$this->db->prepare('INSERT INTO pos_sms_service_template_audit (business_id,branch_id,trigger_key,actor_user_id,action_code,before_json,after_json) VALUES (?,?,?,?,?,?,?)');
        $audit->execute([$scope->businessId,$scope->branchId,$key,$actorId,$before?'updated':'created',$before?json_encode($before,JSON_THROW_ON_ERROR):null,json_encode(['provider_body_id'=>(int)$body,'enabled'=>$enabled,'variable_keys'=>$keys,'version'=>$version],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $this->db->commit();
      } catch(\Throwable $e) { if($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
      return ['trigger_key'=>$key,'provider_body_id'=>(int)$body,'enabled'=>$enabled,'variable_keys'=>$keys,'version'=>$version];
    }
}
