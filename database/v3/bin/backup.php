<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
PosV3DatabaseTools::requireCli();
if(!extension_loaded('zlib'))throw new RuntimeException('PHP zlib extension is required.');
$output=$argv[1]??'';
if($output===''||!str_ends_with($output,'.jsonl.gz'))throw new RuntimeException('Usage: php backup.php /secure/path/backup.jsonl.gz');
$directory=dirname($output);
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create backup directory.');
$realDirectory=realpath($directory);
if($realDirectory===false)throw new RuntimeException('Cannot resolve backup directory.');
$finalPath=$realDirectory.DIRECTORY_SEPARATOR.basename($output);
if(file_exists($finalPath))throw new RuntimeException('Refusing to overwrite an existing backup.');
$publicRoot=realpath(dirname(__DIR__,3).'/php-backend/public');
if($publicRoot!==false&&str_starts_with($finalPath,rtrim($publicRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))throw new RuntimeException('Backups must be stored outside the public web root.');
$tempPath=$finalPath.'.tmp-'.bin2hex(random_bytes(6));
$pdo=PosV3DatabaseTools::connect();$lock=PosV3DatabaseTools::acquireLock($pdo);$gzip=null;
try{
 $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
 $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
 $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
 if(count($tables)<61)throw new RuntimeException('Refusing backup: V3 schema table count is below the 61-table baseline.');
 $baselineQuery=$pdo->prepare('SELECT checksum FROM app_schema_migrations WHERE version=?');$baselineQuery->execute(['000_baseline']);$installedBaseline=$baselineQuery->fetchColumn();
 $expectedBaseline=hash_file('sha256',dirname(__DIR__).'/schema.sql');
 if(!is_string($installedBaseline)||!hash_equals($expectedBaseline,$installedBaseline))throw new RuntimeException('Refusing backup: installed V3 baseline checksum is missing or differs from this release.');
 $descriptors=[];
 foreach($tables as $table){
  $create=$pdo->query('SHOW CREATE TABLE '.PosV3DatabaseTools::quoteIdentifier($table))->fetch(PDO::FETCH_NUM);
  if(!$create||!isset($create[1]))throw new RuntimeException("Cannot read CREATE TABLE for {$table}.");
  $columnQuery=$pdo->prepare("SELECT column_name AS col_name,data_type AS col_data_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND extra NOT LIKE '%GENERATED%' ORDER BY ordinal_position");
  $columnQuery->execute([$table]);$columnMetadata=$columnQuery->fetchAll(PDO::FETCH_ASSOC);
  $columns=[];$binaryColumns=[];
  foreach($columnMetadata as $columnInfo){
   $columnName=(string)$columnInfo['col_name'];$columns[]=$columnName;
   if(in_array(strtolower((string)$columnInfo['col_data_type']),['binary','varbinary','tinyblob','blob','mediumblob','longblob'],true))$binaryColumns[]=$columnName;
  }
  if(!$columns)throw new RuntimeException("No insertable columns found for {$table}.");
  $count=(int)$pdo->query('SELECT COUNT(*) FROM '.PosV3DatabaseTools::quoteIdentifier($table))->fetchColumn();
  $descriptors[]=['type'=>'table','name'=>$table,'create_sql'=>$create[1],'columns'=>$columns,'binary_columns'=>$binaryColumns,'row_count'=>$count];
 }
 $previousUmask=umask(0077);$gzip=gzopen($tempPath,'wb9');umask($previousUmask);
 if($gzip===false)throw new RuntimeException('Cannot create compressed backup file.');
 $hmacKey=PosV3DatabaseTools::env('POS_V3_BACKUP_HMAC_KEY');
 if(strlen($hmacKey)<32)throw new RuntimeException('POS_V3_BACKUP_HMAC_KEY must contain at least 32 bytes.');
 $hmac=hash_init('sha256',HASH_HMAC,$hmacKey);
 $write=static function(array $record)use($gzip,$hmac):void{
  $line=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
  if(gzwrite($gzip,$line)!==strlen($line))throw new RuntimeException('Short write while creating backup archive.');
  hash_update($hmac,$line);
 };
 $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();$engine=stripos($version,'mariadb')!==false?'mariadb':'mysql';
 $write(['type'=>'header','format'=>'persiacoder-pos-v3-jsonl-gzip','format_version'=>2,'created_at_utc'=>gmdate('c'),'engine'=>$engine,'server_version'=>$version,'table_count'=>count($tables),'baseline_sha256'=>$expectedBaseline]);
 foreach($descriptors as $descriptor)$write($descriptor);
 foreach($descriptors as $descriptor){
  $table=$descriptor['name'];$quotedColumns=implode(',',array_map([PosV3DatabaseTools::class,'quoteIdentifier'],$descriptor['columns']));
  $statement=$pdo->query('SELECT '.$quotedColumns.' FROM '.PosV3DatabaseTools::quoteIdentifier($table));
  while(($values=$statement->fetch(PDO::FETCH_NUM))!==false){
   foreach($descriptor['binary_columns'] as $binaryColumn){
    $index=array_search($binaryColumn,$descriptor['columns'],true);
    if($index!==false&&$values[$index]!==null)$values[$index]=['__pos_v3_binary_base64'=>base64_encode((string)$values[$index])];
   }
   $write(['type'=>'row','table'=>$table,'values'=>$values]);
  }
 }
 $signature=hash_final($hmac);
 $signatureLine=json_encode(['type'=>'signature','algorithm'=>'hmac-sha256','value'=>$signature],JSON_THROW_ON_ERROR)."\n";
 if(gzwrite($gzip,$signatureLine)!==strlen($signatureLine))throw new RuntimeException('Short write while finalizing backup signature.');
 $pdo->exec('COMMIT');
 if(!gzclose($gzip))throw new RuntimeException('Could not finalize compressed backup.');$gzip=null;
 if(!chmod($tempPath,0600))throw new RuntimeException('Could not restrict backup file permissions.');
 if(!rename($tempPath,$finalPath))throw new RuntimeException('Could not publish completed backup file.');
 echo "Backup completed: {$finalPath}\nTables: ".count($tables)."; format: JSONL + gzip; permissions: 0600\n";
}catch(Throwable $error){
 try{$pdo->exec('ROLLBACK');}catch(Throwable){}
 if(is_resource($gzip))gzclose($gzip);if(is_file($tempPath))unlink($tempPath);throw $error;
}finally{PosV3DatabaseTools::releaseLock($pdo,$lock);}
