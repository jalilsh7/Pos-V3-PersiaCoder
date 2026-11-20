<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
PosV3DatabaseTools::requireCli();
if(!extension_loaded('zlib'))throw new RuntimeException('PHP zlib extension is required.');
$archive=$argv[1]??'';
if($archive===''||!is_file($archive)||!is_readable($archive))throw new RuntimeException('Usage: php restore.php /secure/path/backup.jsonl.gz');
$pdo=PosV3DatabaseTools::connect();$lock=PosV3DatabaseTools::acquireLock($pdo);$gzip=null;
try{
 $objects=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
 if($objects!==0)throw new RuntimeException("Restore stopped: destination database is not empty ({$objects} tables/views found). No objects were changed.");
 $gzip=gzopen($archive,'rb');if($gzip===false)throw new RuntimeException('Cannot open backup archive.');
 $hmacKey=PosV3DatabaseTools::env('POS_V3_BACKUP_HMAC_KEY');
 if(strlen($hmacKey)<32)throw new RuntimeException('POS_V3_BACKUP_HMAC_KEY must contain at least 32 bytes.');
 $hmac=hash_init('sha256',HASH_HMAC,$hmacKey);$signature=null;$lineNumber=0;
 while(($line=gzgets($gzip))!==false){
  $lineNumber++;$record=json_decode($line,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);
  if(!is_array($record)||!isset($record['type']))throw new RuntimeException('Backup archive contains an invalid record.');
  if(($record['type']??'')==='signature'){
   if($signature!==null||($record['algorithm']??'')!=='hmac-sha256'||!is_string($record['value']??null)||!preg_match('/^[a-f0-9]{64}$/i',$record['value']))throw new RuntimeException('Backup signature record is invalid.');
   $signature=$record['value'];
   if(gzgets($gzip)!==false)throw new RuntimeException('Backup signature must be the final archive record.');
   break;
  }
  hash_update($hmac,$line);
 }
 if($lineNumber<2||$signature===null||!hash_equals(hash_final($hmac),$signature))throw new RuntimeException('Backup authentication failed. The archive is incomplete, modified, or signed with a different key.');
 if(!gzrewind($gzip))throw new RuntimeException('Cannot rewind authenticated backup archive.');
 $readRecord=static function()use($gzip):?array{$line=gzgets($gzip);if($line===false)return null;$record=json_decode($line,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);if(!is_array($record)||!isset($record['type']))throw new RuntimeException('Backup archive contains an invalid record.');return $record;};
 $header=$readRecord();
 $formatVersion=(int)($header['format_version']??0);
 if(!$header||($header['type']??'')!=='header'||($header['format']??'')!=='persiacoder-pos-v3-jsonl-gzip'||!in_array($formatVersion,[1,2],true))throw new RuntimeException('Unsupported or invalid V3 backup format.');
 $serverVersion=(string)$pdo->query('SELECT VERSION()')->fetchColumn();$destinationEngine=stripos($serverVersion,'mariadb')!==false?'mariadb':'mysql';
 if(($header['engine']??'')!==$destinationEngine)throw new RuntimeException('Backup engine family differs from destination database.');
 if(!is_string($header['baseline_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/i',$header['baseline_sha256']))throw new RuntimeException('Backup baseline checksum metadata is invalid.');
 $tables=[];$sawRows=false;
 while(($record=$readRecord())!==null){
  if(($record['type']??'')==='signature')break;
  if(($record['type']??'')==='row'){$sawRows=true;continue;}
  if(($record['type']??'')!=='table'||$sawRows)throw new RuntimeException('Backup records are out of order or contain an unknown type.');
  $name=(string)($record['name']??'');$columns=$record['columns']??null;$binaryColumns=$record['binary_columns']??[];$createSql=$record['create_sql']??null;$rowCount=$record['row_count']??null;
  if(!preg_match('/^[A-Za-z0-9_]+$/',$name)||!is_array($columns)||!$columns||!is_array($binaryColumns)||!is_string($createSql)||!is_int($rowCount)||$rowCount<0)throw new RuntimeException('Backup table descriptor is invalid.');
  if($formatVersion===2&&!array_key_exists('binary_columns',$record))throw new RuntimeException('Backup binary column metadata is missing.');
  foreach($binaryColumns as $binaryColumn)if(!is_string($binaryColumn)||!in_array($binaryColumn,$columns,true))throw new RuntimeException("Invalid binary column descriptor in {$name}.");
  if(isset($tables[$name]))throw new RuntimeException("Duplicate table descriptor: {$name}");
  foreach($columns as $column)if(!is_string($column)||!preg_match('/^[A-Za-z0-9_]+$/',$column))throw new RuntimeException("Invalid column name in {$name}.");
  if(!preg_match('/^CREATE TABLE\\s+`'.preg_quote($name,'/').'`/i',ltrim($createSql))||str_contains($createSql,';'))throw new RuntimeException("Invalid CREATE TABLE statement for {$name}.");
  $tables[$name]=['columns'=>$columns,'binary_columns'=>$binaryColumns,'create_sql'=>$createSql,'expected_rows'=>$rowCount,'actual_rows'=>0,'insert'=>null];
 }
 if(count($tables)!==(int)($header['table_count']??0)||count($tables)<61)throw new RuntimeException('Backup table count does not match V3 baseline.');
 gzrewind($gzip);$readRecord();
 for($i=0;$i<count($tables);$i++)$readRecord();
 $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
 foreach($tables as $name=>$table)$pdo->exec($table['create_sql']);
 gzrewind($gzip);$readRecord();for($i=0;$i<count($tables);$i++)$readRecord();
 while(($record=$readRecord())!==null){
  if(($record['type']??'')==='signature')break;
  if(($record['type']??'')!=='row')throw new RuntimeException('Unexpected non-row record in backup data section.');
  $name=(string)($record['table']??'');if(!isset($tables[$name]))throw new RuntimeException("Backup row references unknown table {$name}.");
  $values=$record['values']??null;if(!is_array($values)||count($values)!==count($tables[$name]['columns']))throw new RuntimeException("Backup row shape mismatch for {$name}.");
  foreach($tables[$name]['binary_columns'] as $binaryColumn){
   $columnIndex=array_search($binaryColumn,$tables[$name]['columns'],true);
   if($columnIndex===false||$values[$columnIndex]===null)continue;
   $encoded=$values[$columnIndex];
   if(!is_array($encoded)||count($encoded)!==1||!is_string($encoded['__pos_v3_binary_base64']??null))throw new RuntimeException("Binary value encoding is invalid for {$name}.{$binaryColumn}.");
   $decoded=base64_decode($encoded['__pos_v3_binary_base64'],true);
   if($decoded===false)throw new RuntimeException("Binary value encoding is invalid for {$name}.{$binaryColumn}.");
   $values[$columnIndex]=$decoded;
  }
  if($tables[$name]['insert']===null){
   $columns=implode(',',array_map([PosV3DatabaseTools::class,'quoteIdentifier'],$tables[$name]['columns']));
   $placeholders=implode(',',array_fill(0,count($tables[$name]['columns']),'?'));
   $tables[$name]['insert']=$pdo->prepare('INSERT INTO '.PosV3DatabaseTools::quoteIdentifier($name).' ('.$columns.') VALUES ('.$placeholders.')');
  }
  $tables[$name]['insert']->execute($values);$tables[$name]['actual_rows']++;
 }
 foreach($tables as $name=>$table)if($table['actual_rows']!==$table['expected_rows'])throw new RuntimeException("Restored row count mismatch for {$name}.");
 $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
 $foreignKeys=$pdo->query(
  "SELECT table_name AS fk_child_table, column_name AS fk_child_column,
          referenced_table_name AS fk_parent_table,
          referenced_column_name AS fk_parent_column,
          constraint_name AS fk_constraint_name,
          ordinal_position AS fk_ordinal_position
   FROM information_schema.key_column_usage
   WHERE table_schema=DATABASE() AND referenced_table_name IS NOT NULL
   ORDER BY table_name,constraint_name,ordinal_position"
 )->fetchAll(PDO::FETCH_ASSOC);
 $groups=[];
 foreach($foreignKeys as $fk){
  $key=$fk['fk_child_table'].'/'.$fk['fk_constraint_name'];
  $groups[$key]['child']=$fk['fk_child_table'];
  $groups[$key]['parent']=$fk['fk_parent_table'];
  $groups[$key]['pairs'][]=[$fk['fk_child_column'],$fk['fk_parent_column']];
 }
 foreach($groups as $group){
  $child=$group['child'];$parent=$group['parent'];$conditions=[];$notNull=[];
  foreach($group['pairs'] as [$childColumn,$parentColumn]){$conditions[]='c.'.PosV3DatabaseTools::quoteIdentifier($childColumn).' = p.'.PosV3DatabaseTools::quoteIdentifier($parentColumn);$notNull[]='c.'.PosV3DatabaseTools::quoteIdentifier($childColumn).' IS NOT NULL';}
  $firstParent=$group['pairs'][0][1];
  $sql='SELECT COUNT(*) FROM '.PosV3DatabaseTools::quoteIdentifier($child).' c LEFT JOIN '.PosV3DatabaseTools::quoteIdentifier($parent).' p ON '.implode(' AND ',$conditions).' WHERE '.implode(' AND ',$notNull).' AND p.'.PosV3DatabaseTools::quoteIdentifier($firstParent).' IS NULL';
  $orphans=(int)$pdo->query($sql)->fetchColumn();if($orphans!==0)throw new RuntimeException("Foreign-key validation failed for {$child} -> {$parent}: {$orphans} orphan rows.");
 }
 echo "Restore completed: ".count($tables)." tables; all row counts and foreign keys verified.\n";
}catch(Throwable $error){try{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable){}throw $error;}
finally{if(is_resource($gzip))gzclose($gzip);PosV3DatabaseTools::releaseLock($pdo,$lock);}
