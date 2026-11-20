<?php
declare(strict_types=1);
require __DIR__ . '/../php-backend/private/birthday-sms.php';
require __DIR__ . '/../php-backend/private/birthday-sms-inbox.php';

$cases = [
    ['name'=>'JSON Value rows','raw'=>json_encode(['Value'=>[['MessageId'=>123,'SenderNumber'=>'09123456789','Text'=>'تولد ۱۳۷۰/۰۵/۱۲','ReceiveDate'=>'2026-11-19']]]),'count'=>1],
    ['name'=>'JSON string Value','raw'=>json_encode(['Value'=>json_encode([['Id'=>'x1','From'=>'09123456789','Message'=>'تولد 1370/05/12']])]),'count'=>1],
    ['name'=>'empty inbox','raw'=>json_encode(['Value'=>'[]']),'count'=>0],
];
foreach($cases as $case){
    $rows=threefood_parse_payamak_inbox_response($case['raw']);
    if(count($rows)!==$case['count']) throw new RuntimeException('Failed: '.$case['name']);
}
foreach(['+989123456789'=>'09123456789','00989123456789'=>'09123456789','۰۹۱۲۳۴۵۶۷۸۹'=>'09123456789','+98 912 345 6789'=>'09123456789'] as $input=>$expected){
    if(threefood_normalize_iran_mobile($input)!==$expected) throw new RuntimeException('Phone normalization failed');
}
foreach(['{}','{"Value":"not-json"}','{"Value":[{"foo":"bar"}]}'] as $raw){
    try { threefood_parse_payamak_inbox_response($raw); throw new RuntimeException('Unsupported response accepted'); }
    catch(RuntimeException $e){ if($e->getMessage()==='Unsupported response accepted') throw $e; }
}
echo "Birthday SMS inbox parser checks passed.\n";
