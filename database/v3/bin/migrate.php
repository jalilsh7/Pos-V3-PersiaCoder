<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php'; PosV3DatabaseTools::requireCli();
$pdo=PosV3DatabaseTools::connect();$lock=PosV3DatabaseTools::acquireLock($pdo);
try{
 $baselinePath=dirname(__DIR__).'/schema.sql';$baseline=$pdo->prepare('SELECT checksum FROM app_schema_migrations WHERE version=?');$baseline->execute(['000_baseline']);$applied=$baseline->fetchColumn();
 if(!is_string($applied))throw new RuntimeException('V3 baseline is not installed. Run install.php on a new, empty database first.');
 $current=hash_file('sha256',$baselinePath);if(!hash_equals($applied,$current))throw new RuntimeException('Installed baseline checksum differs from repository. Never edit an installed baseline; create a forward migration.');
 $directory=dirname(__DIR__).'/migrations';if(!is_dir($directory))throw new RuntimeException('V3 migrations directory is missing.');
 $files=glob($directory.'/*.sql')?:[];
 foreach($files as $path){$filename=basename($path);if(!preg_match('/^(\d{3,})_[a-z0-9][a-z0-9-]*\.sql$/',$filename))throw new RuntimeException("Invalid migration filename: {$filename}");}
 usort($files,static function(string $left,string $right):int{
  preg_match('/^(\d+)/',basename($left),$leftMatch);preg_match('/^(\d+)/',basename($right),$rightMatch);
  return ((int)$leftMatch[1]<=>(int)$rightMatch[1])?:strcmp($left,$right);
 });
 $appliedCount=0;$lastSequence=null;
 foreach($files as $path){
  $filename=basename($path);preg_match('/^(\d+)/',$filename,$sequenceMatch);$sequence=(int)$sequenceMatch[1];
  if($lastSequence===$sequence)throw new RuntimeException("Duplicate migration sequence number: {$sequence}");
  $lastSequence=$sequence;
  $version=substr($filename,0,-4);if(strlen($version)>64)throw new RuntimeException("Migration version exceeds 64 characters: {$filename}");$checksum=hash_file('sha256',$path);
  $lookup=$pdo->prepare('SELECT checksum FROM app_schema_migrations WHERE version=?');$lookup->execute([$version]);$existing=$lookup->fetchColumn();
  if(is_string($existing)){if(!hash_equals($existing,$checksum))throw new RuntimeException("Checksum mismatch for applied migration {$filename}. Manual review required.");continue;}
  $started=microtime(true);$count=PosV3DatabaseTools::executeSqlFile($pdo,$path);$elapsed=(int)round((microtime(true)-$started)*1000);
  $record=$pdo->prepare('INSERT INTO app_schema_migrations(version,checksum,applied_at,execution_ms) VALUES(?,?,UTC_TIMESTAMP(3),?)');$record->execute([$version,$checksum,max(0,$elapsed)]);$appliedCount++;
  echo "Applied {$filename} ({$count} statements, {$elapsed} ms).\n";
 }
 echo "V3 migrations complete. New migrations applied: {$appliedCount}.\n";
}finally{PosV3DatabaseTools::releaseLock($pdo,$lock);}
