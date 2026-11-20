<?php
declare(strict_types=1);
final class PosV3DatabaseTools {
 public static function requireCli(): void { if (PHP_SAPI !== 'cli') { http_response_code(404); exit("Not found\n"); } }
 public static function env(string $name, ?string $default = null): string {
  $value=getenv($name); if ($value===false || $value==='') { if ($default!==null) return $default; throw new RuntimeException("Missing environment variable: {$name}"); } return $value;
 }
 public static function connect(): PDO {
  if (PHP_VERSION_ID < 80200) throw new RuntimeException('PHP 8.2 or newer is required.');
  if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL extension is required.');
  $host=self::env('POS_V3_DB_HOST'); $port=self::env('POS_V3_DB_PORT','3306'); $database=self::env('POS_V3_DB_NAME'); $username=self::env('POS_V3_DB_USER'); $password=self::env('POS_V3_DB_PASSWORD','');
  if (!preg_match('/^[A-Za-z0-9_]+$/',$database)) throw new RuntimeException('Invalid database name.');
  if (!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('Invalid database port.');
  $pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$username,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
  $serverVersion=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
  if (!preg_match('/^(\\d+\\.\\d+\\.\\d+)/',$serverVersion,$matches)) throw new RuntimeException('Unable to determine MySQL/MariaDB server version.');
  $version=$matches[1];
  if (stripos($serverVersion,'mariadb')!==false) {
   if (version_compare($version,'10.6.0','<')) throw new RuntimeException("MariaDB 10.6 or newer is required; detected {$serverVersion}.");
  } elseif (stripos($serverVersion,'mysql')!==false || preg_match('/^8\\./',$version)) {
   if (version_compare($version,'8.0.16','<')) throw new RuntimeException("MySQL 8.0.16 or newer is required; detected {$serverVersion}.");
  } else {
   throw new RuntimeException("Unsupported database engine: {$serverVersion}.");
  }
  return $pdo;
 }
 public static function quoteIdentifier(string $identifier): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) throw new RuntimeException('Invalid SQL identifier.');
  return chr(96) . $identifier . chr(96);
 }
 /** @return list<string> */
 public static function statements(string $sql): array {
  $statements=[]; $buffer=''; $quote=null; $lineComment=false; $blockComment=false; $length=strlen($sql);
  for($i=0;$i<$length;$i++){
   $char=$sql[$i]; $next=$i+1<$length?$sql[$i+1]:'';
   if($lineComment){if($char==="\n"){$lineComment=false;$buffer.="\n";}continue;}
   if($blockComment){if($char==='*'&&$next==='/'){$blockComment=false;$i++;$buffer.=' ';}continue;}
   if($quote!==null){$buffer.=$char;if($char==='\\'&&$quote!==chr(96)&&$i+1<$length){$buffer.=$sql[++$i];continue;}if($char===$quote){if($next===$quote)$buffer.=$sql[++$i];else $quote=null;}continue;}
   if($char==='/'&&$next==='*'){$blockComment=true;$i++;continue;}
   if($char==='#'||($char==='-'&&$next==='-'&&($i+2>=$length||ctype_space($sql[$i+2])))){$lineComment=true;if($char==='-')$i++;continue;}
   if($char==="'"||$char==='"'||ord($char)===96){$quote=$char;$buffer.=$char;continue;}
   if($char===';'){$statement=trim($buffer);if($statement!=='')$statements[]=$statement;$buffer='';continue;}
   $buffer.=$char;
  }
  if($quote!==null||$blockComment)throw new RuntimeException('SQL file ends inside a quoted value or block comment.');
  $statement=trim($buffer);if($statement!=='')$statements[]=$statement;return $statements;
 }
 public static function acquireLock(PDO $pdo): string {
  $lockName='posv3:'.substr(hash('sha256',self::env('POS_V3_DB_NAME')),0,48);$s=$pdo->prepare('SELECT GET_LOCK(?, 30)');$s->execute([$lockName]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Could not acquire V3 database lock.');return $lockName;
 }
 public static function releaseLock(PDO $pdo,string $lockName):void{$s=$pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lockName]);}
 public static function executeSqlFile(PDO $pdo,string $path):int {
  $sql=file_get_contents($path);if($sql===false)throw new RuntimeException("Cannot read SQL file: {$path}");$statements=self::statements($sql);
  foreach($statements as $index=>$statement){try{$pdo->exec($statement);}catch(Throwable $error){throw new RuntimeException(sprintf('SQL failed at statement %d in %s: %s',$index+1,basename($path),$error->getMessage()),0,$error);}}
  return count($statements);
 }
}
