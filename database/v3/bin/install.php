<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php'; PosV3DatabaseTools::requireCli();
$adminUsername=PosV3DatabaseTools::env('POS_V3_ADMIN_USERNAME');$adminPassword=PosV3DatabaseTools::env('POS_V3_ADMIN_PASSWORD');$adminName=PosV3DatabaseTools::env('POS_V3_ADMIN_NAME','مدیر سیستم');
if(!preg_match('/^[A-Za-z0-9._-]{3,64}$/',$adminUsername))throw new RuntimeException('Admin username must be 3-64 characters: letters, numbers, dot, underscore or hyphen.');
if(strlen($adminPassword)<14)throw new RuntimeException('Initial admin password must contain at least 14 characters.');
if(strlen($adminName)>120)throw new RuntimeException('Initial admin display name must be at most 120 bytes.');
$pdo=PosV3DatabaseTools::connect();$lock=PosV3DatabaseTools::acquireLock($pdo);
try{
 $existing=(int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
 if($existing!==0)throw new RuntimeException("Installation stopped: target database is not empty ({$existing} tables/views found). No objects were changed.");
 $schemaPath=dirname(__DIR__).'/schema.sql';if(!is_file($schemaPath))throw new RuntimeException('V3 baseline schema.sql was not found.');
 $started=microtime(true);$statementCount=PosV3DatabaseTools::executeSqlFile($pdo,$schemaPath);
 $tableCount=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
 if($tableCount!==61)throw new RuntimeException("Baseline verification failed: expected 61 tables, found {$tableCount}. Recreate the incomplete database before retrying.");
 $checksum=hash_file('sha256',$schemaPath);$elapsed=(int)round((microtime(true)-$started)*1000);
  $pdo->beginTransaction();
 try{
  $pdo->exec("INSERT INTO app_roles(code,name,is_system,is_active) VALUES('owner','مالک سیستم',1,1)");$roleId=(int)$pdo->lastInsertId();
  $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);$hex=bin2hex($bytes);
  $publicId=sprintf('%s-%s-%s-%s-%s',substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20,12));
  $user=$pdo->prepare('INSERT INTO app_users(public_id,username,password_hash,display_name,is_active,must_change_password) VALUES(?,?,?,?,1,1)');$user->execute([$publicId,$adminUsername,password_hash($adminPassword,PASSWORD_DEFAULT),$adminName]);$userId=(int)$pdo->lastInsertId();
  $assignment=$pdo->prepare('INSERT INTO app_user_roles(user_id,role_id,assigned_by) VALUES(?,?,?)');$assignment->execute([$userId,$roleId,$userId]);
  $record=$pdo->prepare('INSERT INTO app_schema_migrations(version,checksum,applied_at,execution_ms) VALUES(?,?,UTC_TIMESTAMP(3),?)');$record->execute(['000_baseline',$checksum,max(0,$elapsed)]);
  $pdo->exec('UPDATE app_install_state SET setup_completed=1,installed_at=UTC_TIMESTAMP(3) WHERE id=1');$pdo->commit();
 }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
 echo "V3 installation completed.\nTables: {$tableCount}; SQL statements: {$statementCount}; baseline checksum: {$checksum}\nInitial owner account created; password change is required after first login.\n";
}finally{PosV3DatabaseTools::releaseLock($pdo,$lock);}
