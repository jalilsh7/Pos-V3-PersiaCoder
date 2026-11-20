<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

/**
 * 3Food POS - PHP API foundation
 * Phase 1: database connectivity, first-manager setup, sessions and RBAC.
 * All business/POS endpoints are added in later migration phases.
 */

$configPath = dirname(__DIR__, 2) . '/private/config.php';
if (!is_file($configPath)) {
    json_response(['ok' => false, 'error' => 'server_not_configured', 'message' => 'تنظیمات سرور انجام نشده است'], 503);
}
$config = require $configPath;
require_once dirname(__DIR__, 2) . '/private/birthday-sms.php';
require_once dirname(__DIR__, 2) . '/private/birthday-sms-inbox.php';
if (!is_array($config) || strlen((string)($config['app_key'] ?? '')) < 32) {
    json_response(['ok' => false, 'error' => 'server_not_configured', 'message' => 'کلید امنیتی سرور تنظیم نشده است'], 503);
}

function request_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function json_response(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'none'");
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (request_is_https()) header('Strict-Transport-Security: max-age=31536000');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function utf8_length(string $value): int {
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function loyalty_tier(int $points, array $settings): string {
    $silver=max(1,(int)($settings['loyaltySilverPoints']??500));
    $gold=max($silver+1,(int)($settings['loyaltyGoldPoints']??1500));
    $platinum=max($gold+1,(int)($settings['loyaltyPlatinumPoints']??4000));
    if($points >= $platinum)return 'platinum';
    if($points >= $gold)return 'gold';
    if($points >= $silver)return 'silver';
    return 'bronze';
}
function loyalty_discount_rate(int $points, array $settings): int {
    $tier=loyalty_tier($points,$settings);
    $key=['silver'=>'loyaltySilverDiscount','gold'=>'loyaltyGoldDiscount','platinum'=>'loyaltyPlatinumDiscount'][$tier]??null;
    return $key===null?0:max(0,min(100,(int)($settings[$key]??(['silver'=>3,'gold'=>5,'platinum'=>8][$tier]))));
}

function product_unit_cost(PDO $pdo, array $product): int {
    $configured=max(0,(int)($product['cost']??0));
    $recipe=$product['recipe']??[];
    if(!is_array($recipe)||!$recipe)return $configured;
    $stmt=$pdo->prepare("SELECT payload FROM pos_records WHERE resource='ingredients' AND id=? LIMIT 1");
    $total=0.0;
    $validLines=0;
    foreach($recipe as $line){
        if(!is_array($line))continue;
        $id=(string)($line['ingredientId']??'');$qty=max(0,(float)($line['qty']??0));
        if($id===''||$qty<=0)continue;
        $stmt->execute([$id]);$raw=$stmt->fetchColumn();
        $ingredient=is_string($raw)?json_decode($raw,true):null;
        if(is_array($ingredient)){
            $total+=max(0,(float)($ingredient['costPerUnit']??0))*$qty;
            $validLines++;
        }
    }
    // Recipe-based COGS follows the current weighted ingredient cost after supplier receipts.
    return $validLines>0?(int)round($total):$configured;
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'invalid_json', 'message' => 'درخواست نامعتبر است'], 400);
    }
    if (!is_array($data)) json_response(['ok' => false, 'error' => 'invalid_body', 'message' => 'بدنه درخواست نامعتبر است'], 400);
    return $data;
}

function db(array $config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'] ?? [];
    foreach (['host','database','username','password'] as $key) {
        if (!isset($d[$key]) || $d[$key] === '' || str_contains((string)$d[$key], 'YOUR_') || $d[$key] === 'CHANGE_THIS_DATABASE_PASSWORD') {
            json_response(['ok' => false, 'error' => 'database_not_configured', 'message' => 'تنظیم اتصال دیتابیس کامل نیست'], 503);
        }
    }
    $port = (int)($d['port'] ?? 3306);
    $charset = (string)($d['charset'] ?? 'utf8mb4');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $port, $d['database'], $charset);
    try {
        $pdo = new PDO($dsn, $d['username'], $d['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Store database timestamps in UTC; API presentation converts them to Tehran time.
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    } catch (Throwable $e) {
        error_log('[3Food POS] Database connection failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'database_unavailable', 'message' => 'اتصال به دیتابیس برقرار نشد'], 503);
    }
}

function same_origin_guard(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        if (strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
            json_response(['ok' => false, 'error' => 'origin_rejected', 'message' => 'مبدأ درخواست معتبر نیست'], 403);
        }
        return; // Non-browser same-origin clients may omit Origin.
    }
    $originParts = parse_url($origin);
    if (!is_array($originParts)) {
        json_response(['ok' => false, 'error' => 'origin_rejected', 'message' => 'مبدأ درخواست معتبر نیست'], 403);
    }
    $requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    $requestParts = parse_url('//' . $requestHost);
    if (!is_array($requestParts)) {
        json_response(['ok' => false, 'error' => 'origin_rejected', 'message' => 'مبدأ درخواست معتبر نیست'], 403);
    }
    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    $requestScheme = request_is_https() ? 'https' : 'http';
    $originHost = strtolower((string)($originParts['host'] ?? ''));
    $requestHostName = strtolower((string)($requestParts['host'] ?? ''));
    $originPort = (int)($originParts['port'] ?? ($originScheme === 'https' ? 443 : 80));
    $requestPort = (int)($requestParts['port'] ?? ($requestScheme === 'https' ? 443 : 80));
    if (!in_array($originScheme, ['http', 'https'], true) ||
        $originHost === '' || $originHost !== $requestHostName ||
        $originScheme !== $requestScheme || $originPort !== $requestPort ||
        isset($originParts['user']) || isset($originParts['pass']) ||
        isset($originParts['path']) && $originParts['path'] !== '' ||
        isset($originParts['query']) || isset($originParts['fragment'])) {
        json_response(['ok' => false, 'error' => 'origin_rejected', 'message' => 'مبدأ درخواست معتبر نیست'], 403);
    }
}

function start_secure_session(array $config): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = request_is_https();
    session_name((string)($config['session']['name'] ?? '3FOODPOSSESSID'));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $https ? '1' : '0');
    ini_set('session.cookie_samesite', (string)($config['session']['same_site'] ?? 'Strict'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => (string)($config['session']['same_site'] ?? 'Strict'),
    ]);
    session_start();

    $idle = (int)($config['session']['idle_timeout'] ?? 28800);
    if (isset($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_seen'] = time();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}

function require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $saved = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($saved) || $sent === '' || !hash_equals($saved, $sent)) {
        json_response(['ok' => false, 'error' => 'csrf_failed', 'message' => 'نشست امنیتی منقضی شده است؛ صفحه را تازه‌سازی کنید'], 419);
    }
}

function current_user(PDO $pdo): ?array {
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) return null;
    $q = $pdo->prepare('SELECT id, username, display_name, role, is_active, must_change_password, session_version FROM app_users WHERE id = ? LIMIT 1');
    $q->execute([(int)$id]);
    $user = $q->fetch();
    if (!$user || !(int)$user['is_active'] || (int)($user['session_version'] ?? 0) !== (int)($_SESSION['auth_version'] ?? -1)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    // Normalize MySQL TINYINT values before JSON serialization. Some PDO
    // driver configurations return "0"/"1" strings; JavaScript treats "0"
    // as truthy and would incorrectly force the password-change screen.
    $user['id'] = (int)$user['id'];
    $user['is_active'] = (bool)$user['is_active'];
    $user['must_change_password'] = (bool)$user['must_change_password'];
    $user['session_version'] = (int)$user['session_version'];
    return $user;
}

function require_user(PDO $pdo): array {
    $user = current_user($pdo);
    if (!$user) json_response(['ok' => false, 'error' => 'unauthorized', 'message' => 'برای ادامه وارد حساب کاربری شوید'], 401);
    return $user;
}

function require_role(PDO $pdo, array $roles): array {
    $user = require_user($pdo);
    if (!in_array($user['role'], $roles, true)) {
        json_response(['ok' => false, 'error' => 'forbidden', 'message' => 'اجازه انجام این عملیات را ندارید'], 403);
    }
    return $user;
}


function require_terminal_agent(PDO $pdo): array {
    $token = (string)($_SERVER['HTTP_X_TERMINAL_AGENT_TOKEN'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        json_response(['ok'=>false,'error'=>'terminal_agent_unauthorized','message'=>'عامل کارتخوان مجاز نیست'],401);
    }
    $stmt=$pdo->prepare('SELECT id,name,enabled FROM app_terminal_agents WHERE token_hash=? LIMIT 1');
    $stmt->execute([hash('sha256',$token)]);
    $agent=$stmt->fetch();
    if(!is_array($agent)||(int)$agent['enabled']!==1) {
        json_response(['ok'=>false,'error'=>'terminal_agent_unauthorized','message'=>'عامل کارتخوان غیرفعال است'],401);
    }
    return $agent;
}


function is_private_terminal_ip(string $ip): bool {
    $packed=@inet_pton($ip);
    if($packed===false)return false;
    if(strlen($packed)===4){
        $a=ord($packed[0]);$b=ord($packed[1]);
        return $a===10||($a===172&&$b>=16&&$b<=31)||($a===192&&$b===168)||($a===169&&$b===254);
    }
    if(strlen($packed)===16){
        $a=ord($packed[0]);$b=ord($packed[1]);
        return (($a&0xfe)===0xfc)||($a===0xfe&&($b&0xc0)===0x80);
    }
    return false;
}

function valid_uuid(string $value): bool {
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$value)===1;
}

function audit(PDO $pdo, ?int $actor, string $action, ?string $entityType = null, ?string $entityId = null, array $details = []): void {
    $stmt = $pdo->prepare('INSERT INTO app_audit_logs (actor_user_id, action, entity_type, entity_id, ip_address, details) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $actor,
        $action,
        $entityType,
        $entityId,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function client_fingerprint(array $config): array {
    $secret = (string)($config['app_key'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return [
        hash_hmac('sha256', $ip, $secret),
        hash_hmac('sha256', $ua, $secret),
    ];
}

function login_rate_limited(PDO $pdo, array $config, string $username): bool {
    [$ipKey, $uaKey] = client_fingerprint($config);
    $usernameKey = hash_hmac('sha256', strtolower($username), (string)($config['app_key'] ?? ''));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM app_login_attempts
         WHERE attempted_at >= (NOW() - INTERVAL 15 MINUTE)
           AND was_successful = 0
           AND (username_key = ? OR ip_key = ?)'
    );
    $stmt->execute([$usernameKey, $ipKey]);
    return (int)$stmt->fetchColumn() >= 8;
}

function record_login_attempt(PDO $pdo, array $config, string $username, bool $success): void {
    [$ipKey] = client_fingerprint($config);
    $pepper = (string)($config['app_key'] ?? '');
    $usernameKey = hash_hmac('sha256', strtolower($username), $pepper);
    $stmt = $pdo->prepare('INSERT INTO app_login_attempts (username_key, ip_key, was_successful) VALUES (?, ?, ?)');
    $stmt->execute([$usernameKey, $ipKey, $success ? 1 : 0]);
    // Keep this operational table bounded.
    $pdo->exec('DELETE FROM app_login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^.*?/api(?:/v1)?#', '', $path) ?: '/';
$path = '/' . trim($path, '/');

if (!in_array($method, ['GET','POST','PATCH','DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if (in_array($method, ['POST','PATCH','DELETE'], true)) same_origin_guard();
$pdo = db($config);
start_secure_session($config);

if ($method === 'GET' && $path === '/health') {
    $pdo->query('SELECT 1');
    json_response(['ok' => true, 'service' => '3food-pos-php-api', 'database' => 'mysql']);
}


/* Network payment terminal management and local-agent queue. */
if ($method === 'GET' && $path === '/terminal-agents') {
    require_role($pdo,['manager']);
    $rows=$pdo->query('SELECT id,name,enabled,agent_version,last_seen_at,created_at FROM app_terminal_agents ORDER BY created_at DESC')->fetchAll();
    json_response(['ok'=>true,'items'=>$rows]);
}
if ($method === 'POST' && $path === '/terminal-agents') {
    $actor=require_role($pdo,['manager']); require_csrf(); $body=request_json();
    $name=trim((string)($body['name']??''));
    if($name===''||utf8_length($name)>120) json_response(['ok'=>false,'error'=>'invalid_agent_name','message'=>'نام کامپیوتر صندوق معتبر نیست'],422);
    $id=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),sprintf('%04x',(hexdec(bin2hex(random_bytes(2)))&0x0fff)|0x4000),sprintf('%04x',(hexdec(bin2hex(random_bytes(2)))&0x3fff)|0x8000),bin2hex(random_bytes(6)));
    $token=bin2hex(random_bytes(32));
    $stmt=$pdo->prepare('INSERT INTO app_terminal_agents (id,name,token_hash,created_by) VALUES (?,?,?,?)');
    $stmt->execute([$id,$name,hash('sha256',$token),(int)$actor['id']]);
    audit($pdo,(int)$actor['id'],'terminal_agent_created','terminal_agent',$id);
    json_response(['ok'=>true,'agent'=>['id'=>$id,'name'=>$name,'enabled'=>true],'token'=>$token],201);
}
if (preg_match('#^/terminal-agents/([a-f0-9-]{36})/disable$#iD',$path,$m) && $method==='POST') {
    $actor=require_role($pdo,['manager']); require_csrf();
    $stmt=$pdo->prepare('UPDATE app_terminal_agents SET enabled=0 WHERE id=?'); $stmt->execute([$m[1]]);
    if($stmt->rowCount()===0) json_response(['ok'=>false,'error'=>'agent_not_found','message'=>'عامل محلی پیدا نشد یا قبلاً غیرفعال شده است'],404);
    audit($pdo,(int)$actor['id'],'terminal_agent_disabled','terminal_agent',$m[1]);
    json_response(['ok'=>true]);
}
if ($method==='GET' && $path==='/terminals/available') {
    require_role($pdo,['manager','cashier']);
    $rows=$pdo->query("SELECT t.id,t.name,t.provider_code,t.model_name,t.driver_code,t.is_default,a.last_seen_at FROM pos_payment_terminals t JOIN app_terminal_agents a ON a.id=t.agent_id WHERE t.enabled=1 AND a.enabled=1 AND t.driver_code<>'unconfigured' AND a.last_seen_at >= (UTC_TIMESTAMP() - INTERVAL 90 SECOND) ORDER BY t.is_default DESC,t.name ASC")->fetchAll();
    foreach($rows as &$row){$row['is_default']=(bool)$row['is_default'];} unset($row);
    json_response(['ok'=>true,'items'=>$rows]);
}
if ($method==='GET' && $path==='/terminals') {
    require_role($pdo,['manager']);
    $rows=$pdo->query('SELECT t.id,t.agent_id,t.local_terminal_key,t.name,t.provider_code,t.model_name,t.driver_code,t.connection_type,t.host,t.port,t.enabled,t.is_default,t.last_seen_at,a.name AS agent_name,a.enabled AS agent_enabled FROM pos_payment_terminals t JOIN app_terminal_agents a ON a.id=t.agent_id ORDER BY t.is_default DESC,t.name ASC')->fetchAll();
    foreach($rows as &$row){$row['enabled']=(bool)$row['enabled'];$row['is_default']=(bool)$row['is_default'];$row['agent_enabled']=(bool)$row['agent_enabled'];$row['port']=(int)$row['port'];} unset($row);
    json_response(['ok'=>true,'items'=>$rows]);
}
if ($method==='POST' && $path==='/terminals') {
    $actor=require_role($pdo,['manager']); require_csrf(); $body=request_json();
    $id=trim((string)($body['id']??'')); $agentId=strtolower(trim((string)($body['agentId']??'')));
    $localKey=trim((string)($body['localTerminalKey']??'')); $name=trim((string)($body['name']??''));
    $provider=preg_replace('/[^a-z0-9_-]/','',strtolower(trim((string)($body['providerCode']??'unknown'))));
    $driver=preg_replace('/[^a-z0-9_-]/','',strtolower(trim((string)($body['driverCode']??'unconfigured'))));
    $connection=(string)($body['connectionType']??'lan'); $host=trim((string)($body['host']??'')); $port=filter_var($body['port']??null,FILTER_VALIDATE_INT);
    $model=trim((string)($body['modelName']??'')); $enabled=($body['enabled']??true)===true?1:0; $isDefault=($body['isDefault']??false)===true?1:0;
    if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$id)||!valid_uuid($agentId)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$localKey)||
       $name===''||utf8_length($name)>120||$provider===''||strlen($provider)>64||$driver===''||strlen($driver)>80||
       !in_array($connection,['lan','wifi'],true)||(filter_var($host,FILTER_VALIDATE_IP)===false||!is_private_terminal_ip($host))||$port===false||$port<1||$port>65535||utf8_length($model)>120) {
        json_response(['ok'=>false,'error'=>'invalid_terminal','message'=>'مشخصات کارتخوان معتبر نیست'],422);
    }
    $agentStmt=$pdo->prepare('SELECT id FROM app_terminal_agents WHERE id=? AND enabled=1 LIMIT 1');$agentStmt->execute([$agentId]);
    if(!$agentStmt->fetchColumn()) json_response(['ok'=>false,'error'=>'terminal_agent_unavailable','message'=>'عامل محلی فعال پیدا نشد'],409);
    try {
        $pdo->beginTransaction();
        if($isDefault===1) $pdo->exec('UPDATE pos_payment_terminals SET is_default=0');
        $stmt=$pdo->prepare('INSERT INTO pos_payment_terminals (id,agent_id,local_terminal_key,name,provider_code,model_name,driver_code,connection_type,host,port,enabled,is_default,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE agent_id=VALUES(agent_id),local_terminal_key=VALUES(local_terminal_key),name=VALUES(name),provider_code=VALUES(provider_code),model_name=VALUES(model_name),driver_code=VALUES(driver_code),connection_type=VALUES(connection_type),host=VALUES(host),port=VALUES(port),enabled=VALUES(enabled),is_default=VALUES(is_default)');
        $stmt->execute([$id,$agentId,$localKey,$name,$provider,$model!==''?$model:null,$driver,$connection,$host,$port,$enabled,$isDefault,(int)$actor['id']]);
        audit($pdo,(int)$actor['id'],'terminal_saved','payment_terminal',$id,['provider'=>$provider,'driver'=>$driver,'enabled'=>$enabled===1]);
        $pdo->commit();
        json_response(['ok'=>true,'terminal'=>['id'=>$id,'agentId'=>$agentId,'name'=>$name,'providerCode'=>$provider,'driverCode'=>$driver,'connectionType'=>$connection,'host'=>$host,'port'=>$port,'enabled'=>$enabled===1,'isDefault'=>$isDefault===1]],201);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[3Food POS] Terminal save failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>'terminal_save_failed','message'=>'ذخیره کارتخوان انجام نشد'],500);
    }
}
if (preg_match('#^/terminals/([A-Za-z0-9_-]{1,100})/disable$#D',$path,$m) && $method==='POST') {
    $actor=require_role($pdo,['manager']); require_csrf();
    $stmt=$pdo->prepare('UPDATE pos_payment_terminals SET enabled=0,is_default=0 WHERE id=?');$stmt->execute([$m[1]]);
    if($stmt->rowCount()===0) json_response(['ok'=>false,'error'=>'terminal_not_found','message'=>'کارتخوان پیدا نشد یا غیرفعال است'],404);
    audit($pdo,(int)$actor['id'],'terminal_disabled','payment_terminal',$m[1]);
    json_response(['ok'=>true]);
}
if ($method==='POST' && $path==='/terminal-payments') {
    $actor=require_role($pdo,['manager','cashier']); require_csrf(); $body=request_json();
    $terminalId=trim((string)($body['terminalId']??''));$amount=filter_var($body['amount']??null,FILTER_VALIDATE_INT);
    $key=strtolower(trim((string)($body['idempotencyKey']??'')));
    if(!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$terminalId)||$amount===false||$amount<1||$amount>100000000000||!valid_uuid($key))
        json_response(['ok'=>false,'error'=>'invalid_terminal_payment','message'=>'مبلغ یا شناسه پرداخت معتبر نیست'],422);
    $existing=$pdo->prepare('SELECT id,terminal_id,amount,status,created_by FROM pos_terminal_payments WHERE idempotency_key=? LIMIT 1');$existing->execute([$key]);$old=$existing->fetch();
    if(is_array($old)){
        if((int)$old['created_by']!==(int)$actor['id']||($old['terminal_id']!==$terminalId||(int)$old['amount']!==$amount)) json_response(['ok'=>false,'error'=>'terminal_payment_idempotency_conflict','message'=>'شناسه تکراری برای پرداخت دیگری استفاده شده است'],409);
        json_response(['ok'=>true,'payment'=>['id'=>$old['id'],'status'=>$old['status'],'amount'=>(int)$old['amount']]]);
    }

    try {
        $pdo->beginTransaction();
        $device=$pdo->prepare('SELECT t.id,t.driver_code,t.enabled,a.enabled AS agent_enabled,a.last_seen_at FROM pos_payment_terminals t JOIN app_terminal_agents a ON a.id=t.agent_id WHERE t.id=? FOR UPDATE');
        $device->execute([$terminalId]);$terminal=$device->fetch();
        if(!is_array($terminal)||(int)$terminal['enabled']!==1||(int)$terminal['agent_enabled']!==1){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'terminal_unavailable','message'=>'کارتخوان فعال یا متصل نیست'],409);
        }
        if($terminal['driver_code']==='unconfigured'){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'terminal_driver_unconfigured','message'=>'برای این مدل کارتخوان هنوز درایور تأییدشده نصب نشده است'],409);
        }
        if(!$terminal['last_seen_at']||strtotime((string)$terminal['last_seen_at'])<time()-90){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'terminal_agent_offline','message'=>'برنامه اتصال کارتخوان روی کامپیوتر صندوق آنلاین نیست'],409);
        }
        $pdo->prepare("UPDATE pos_terminal_payments SET status='unknown',completed_at=UTC_TIMESTAMP() WHERE terminal_id=? AND status='processing' AND requested_at < (UTC_TIMESTAMP() - INTERVAL 120 SECOND)")->execute([$terminalId]);
        $unresolved=$pdo->prepare("SELECT id,status FROM pos_terminal_payments WHERE terminal_id=? AND (status IN ('processing','unknown') OR (status='approved' AND order_id IS NULL)) ORDER BY requested_at DESC LIMIT 1 FOR UPDATE");
        $unresolved->execute([$terminalId]);$unresolvedPayment=$unresolved->fetch();
        if(is_array($unresolvedPayment)){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'terminal_payment_reconciliation_required','message'=>'تراکنش قبلی نتیجه قطعی ندارد؛ پیش از پرداخت جدید باید وضعیت آن از بانک یا کارتخوان استعلام شود','payment_id'=>$unresolvedPayment['id'],'status'=>$unresolvedPayment['status']],409);
        }
        $queuedByActor=$pdo->prepare("SELECT id FROM pos_terminal_payments WHERE terminal_id=? AND created_by=? AND status='queued' LIMIT 1 FOR UPDATE");
        $queuedByActor->execute([$terminalId,(int)$actor['id']]);
        if($queuedByActor->fetchColumn()){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'terminal_payment_already_queued','message'=>'برای این صندوق یک درخواست پرداخت در صف وجود دارد؛ همان درخواست را پیگیری یا لغو کنید'],409);
        }
        $paymentId=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),sprintf('%04x',(hexdec(bin2hex(random_bytes(2)))&0x0fff)|0x4000),sprintf('%04x',(hexdec(bin2hex(random_bytes(2)))&0x3fff)|0x8000),bin2hex(random_bytes(6)));
        $insert=$pdo->prepare("INSERT INTO pos_terminal_payments (id,idempotency_key,terminal_id,amount,currency,status,created_by) VALUES (?,?,?,?,'IRT','queued',?)");
        $insert->execute([$paymentId,$key,$terminalId,$amount,(int)$actor['id']]);
        audit($pdo,(int)$actor['id'],'terminal_payment_queued','terminal_payment',$paymentId,['terminal_id'=>$terminalId,'amount'=>$amount]);
        $pdo->commit();
        json_response(['ok'=>true,'payment'=>['id'=>$paymentId,'status'=>'queued','amount'=>$amount]],201);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[3Food POS] Terminal payment queue failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>'terminal_payment_queue_failed','message'=>'درخواست پرداخت ایجاد نشد'],500);
    }
}

if (preg_match('#^/terminal-payments/([a-f0-9-]{36})$#iD',$path,$m) && $method==='GET') {
    $actor=require_role($pdo,['manager','cashier']);
    $stmt=$pdo->prepare('SELECT p.id,p.terminal_id,p.order_id,p.amount,p.currency,p.status,p.external_transaction_id,p.trace_number,p.retrieval_reference_number,p.response_code,p.response_message,p.requested_at,p.completed_at,p.created_by,t.name AS terminal_name FROM pos_terminal_payments p JOIN pos_payment_terminals t ON t.id=p.terminal_id WHERE p.id=? LIMIT 1');
    $stmt->execute([$m[1]]);
    $payment=$stmt->fetch();
    if(!is_array($payment)||($actor['role']!=='manager'&&(int)$payment['created_by']!==(int)$actor['id'])) {
        json_response(['ok'=>false,'error'=>'terminal_payment_not_found','message'=>'تراکنش کارتخوان پیدا نشد'],404);
    }
    unset($payment['created_by']);
    $payment['amount']=(int)$payment['amount'];
    $payment['order_id']=$payment['order_id']===null?null:(int)$payment['order_id'];
    json_response(['ok'=>true,'payment'=>$payment]);
}

if (preg_match('#^/terminal-payments/([a-f0-9-]{36})/attach-order$#iD',$path,$m) && $method==='POST') {
    $actor=require_role($pdo,['manager','cashier']);require_csrf();$body=request_json();
    $orderId=filter_var($body['orderId']??null,FILTER_VALIDATE_INT);
    if($orderId===false||$orderId<1) json_response(['ok'=>false,'error'=>'invalid_order_id'],422);
    try {
        $pdo->beginTransaction();
        $paymentStmt=$pdo->prepare('SELECT id,amount,status,order_id,created_by FROM pos_terminal_payments WHERE id=? FOR UPDATE');$paymentStmt->execute([$m[1]]);$payment=$paymentStmt->fetch();
        if(!is_array($payment)||($actor['role']!=='manager'&&(int)$payment['created_by']!==(int)$actor['id'])){$pdo->rollBack();json_response(['ok'=>false,'error'=>'payment_not_found'],404);}
        if($payment['status']!=='approved'){$pdo->rollBack();json_response(['ok'=>false,'error'=>'payment_not_approved','message'=>'فقط تراکنش تأییدشده قابل اتصال به سفارش است'],409);}
        $orderStmt=$pdo->prepare('SELECT id,payment_method,totals,created_by FROM pos_orders WHERE id=? LIMIT 1');$orderStmt->execute([$orderId]);$order=$orderStmt->fetch();
        $totals=is_array($order)?json_decode((string)$order['totals'],true):null;
        if(!is_array($order)||(string)$order['payment_method']!=='card'||(int)($totals['total']??-1)!==(int)$payment['amount']||(int)$order['created_by']!==(int)$payment['created_by']){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'payment_order_mismatch','message'=>'مبلغ یا مالک سفارش با تراکنش کارتخوان مطابقت ندارد'],409);
        }
        if($payment['order_id']!==null&&(int)$payment['order_id']!==$orderId){$pdo->rollBack();json_response(['ok'=>false,'error'=>'payment_already_linked'],409);}
        $update=$pdo->prepare('UPDATE pos_terminal_payments SET order_id=? WHERE id=? AND order_id IS NULL');$update->execute([$orderId,$m[1]]);
        audit($pdo,(int)$actor['id'],'terminal_payment_linked','terminal_payment',$m[1],['order_id'=>$orderId]);
        $pdo->commit();
        json_response(['ok'=>true,'order_id'=>$orderId]);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[3Food POS] Terminal payment linking failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>'terminal_payment_link_failed','message'=>'اتصال تراکنش به سفارش انجام نشد'],500);
    }
}


if ($method==='GET' && $path==='/terminal-payments/pending') {
    $actor=require_role($pdo,['manager','cashier']);
    $stmt=$pdo->prepare("SELECT p.id,p.terminal_id,p.amount,p.currency,p.status,p.requested_at,p.completed_at,t.name AS terminal_name FROM pos_terminal_payments p JOIN pos_payment_terminals t ON t.id=p.terminal_id WHERE p.created_by=? AND (p.status IN ('queued','processing','unknown') OR (p.status='approved' AND p.order_id IS NULL)) ORDER BY p.requested_at DESC LIMIT 10");
    $stmt->execute([(int)$actor['id']]);
    $rows=$stmt->fetchAll();
    foreach($rows as &$row)$row['amount']=(int)$row['amount'];unset($row);
    json_response(['ok'=>true,'items'=>$rows]);
}
if (preg_match('#^/terminal-payments/([a-f0-9-]{36})/cancel$#iD',$path,$m) && $method==='POST') {
    $actor=require_role($pdo,['manager','cashier']);require_csrf();
    $stmt=$pdo->prepare("UPDATE pos_terminal_payments SET status='cancelled',completed_at=UTC_TIMESTAMP() WHERE id=? AND status='queued' AND (created_by=? OR ?='manager')");
    $stmt->execute([$m[1],(int)$actor['id'],$actor['role']]);
    if($stmt->rowCount()!==1) json_response(['ok'=>false,'error'=>'payment_cannot_be_cancelled','message'=>'این تراکنش در حال اجرا یا تعیین‌تکلیف است؛ وضعیت آن را بررسی کنید'],409);
    audit($pdo,(int)$actor['id'],'terminal_payment_cancelled','terminal_payment',$m[1]);
    json_response(['ok'=>true,'status'=>'cancelled']);
}
if ($method==='GET' && $path==='/terminal-agent/next') {
    $agent=require_terminal_agent($pdo);
    try {
        $pdo->beginTransaction();
        $agentVersion=substr(trim((string)($_SERVER['HTTP_X_TERMINAL_AGENT_VERSION']??'')),0,40);
        $heartbeat=$pdo->prepare('UPDATE app_terminal_agents SET last_seen_at=UTC_TIMESTAMP(),agent_version=COALESCE(NULLIF(?,\'\'),agent_version) WHERE id=?');$heartbeat->execute([$agentVersion,$agent['id']]);
        $stmt=$pdo->prepare("SELECT p.id,p.amount,p.currency,p.status,t.id AS terminal_id,t.name AS terminal_name,t.provider_code,t.model_name,t.driver_code,t.connection_type,t.host,t.port,t.local_terminal_key FROM pos_terminal_payments p JOIN pos_payment_terminals t ON t.id=p.terminal_id WHERE t.agent_id=? AND t.enabled=1 AND p.status='queued' ORDER BY p.requested_at ASC LIMIT 1 FOR UPDATE");
        $stmt->execute([$agent['id']]);$payment=$stmt->fetch();
        if(is_array($payment)){
            $update=$pdo->prepare("UPDATE pos_terminal_payments SET status='processing' WHERE id=? AND status='queued'");$update->execute([$payment['id']]);
            $payment['status']='processing';$payment['amount']=(int)$payment['amount'];$payment['port']=(int)$payment['port'];
        }
        $pdo->commit();
        json_response(['ok'=>true,'agent'=>['id'=>$agent['id'],'name'=>$agent['name']],'payment'=>$payment?:null]);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[3Food POS] Terminal agent poll failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>'terminal_agent_poll_failed'],500);
    }
}
if (preg_match('#^/terminal-agent/payments/([a-f0-9-]{36})/result$#iD',$path,$m) && $method==='POST') {
    $agent=require_terminal_agent($pdo);$body=request_json();
    $status=(string)($body['status']??'');$amount=filter_var($body['amount']??null,FILTER_VALIDATE_INT);
    if(!in_array($status,['approved','declined','cancelled','unknown'],true)||$amount===false||$amount<1)
        json_response(['ok'=>false,'error'=>'invalid_terminal_result'],422);
    $stmt=$pdo->prepare('SELECT p.id,p.amount,p.status,t.agent_id FROM pos_terminal_payments p JOIN pos_payment_terminals t ON t.id=p.terminal_id WHERE p.id=? LIMIT 1');
    $stmt->execute([$m[1]]);$payment=$stmt->fetch();
    if(!is_array($payment)||(string)$payment['agent_id']!==(string)$agent['id']) json_response(['ok'=>false,'error'=>'terminal_payment_not_found'],404);
    if((int)$payment['amount']!==$amount) json_response(['ok'=>false,'error'=>'terminal_amount_mismatch'],409);
    if($payment['status']!=='processing') {
        if($payment['status']===$status) json_response(['ok'=>true,'status'=>$status,'replayed'=>true]);
        json_response(['ok'=>false,'error'=>'terminal_payment_already_final','message'=>'وضعیت تراکنش قبلاً تعیین شده است'],409);
    }
    $external=substr(trim((string)($body['externalTransactionId']??'')),0,120);
    $trace=substr(trim((string)($body['traceNumber']??'')),0,80);
    $rrn=substr(trim((string)($body['retrievalReferenceNumber']??'')),0,80);
    $code=substr(trim((string)($body['responseCode']??'')),0,40);
    $message=substr(trim((string)($body['responseMessage']??'')),0,240);
    if($status==='approved'&&$external===''&&$trace===''&&$rrn==='') json_response(['ok'=>false,'error'=>'terminal_approval_reference_missing'],422);
    $safe=['providerCode'=>substr(preg_replace('/[^a-zA-Z0-9_-]/','',(string)($body['providerCode']??'')),0,64),'responseCode'=>$code];
    $update=$pdo->prepare('UPDATE pos_terminal_payments SET status=?,external_transaction_id=?,trace_number=?,retrieval_reference_number=?,response_code=?,response_message=?,safe_response=?,completed_at=UTC_TIMESTAMP() WHERE id=? AND status=\'processing\'');
    $update->execute([$status,$external!==''?$external:null,$trace!==''?$trace:null,$rrn!==''?$rrn:null,$code!==''?$code:null,$message!==''?$message:null,json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$m[1]]);
    audit($pdo,null,'terminal_payment_result','terminal_payment',$m[1],['status'=>$status,'trace_present'=>$trace!=='','rrn_present'=>$rrn!=='']);
    json_response(['ok'=>true,'status'=>$status]);
}


if ($method === 'GET' && $path === '/preflight') {
    $requiredTables = [
        'app_users', 'app_login_attempts', 'app_audit_logs', 'app_install_state', 'app_appearance_settings',
        'pos_records', 'pos_counters', 'pos_orders', 'pos_order_effects',
        'pos_discount_redemptions', 'app_terminal_agents', 'pos_payment_terminals', 'pos_terminal_payments',
    ];
    $tableStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND ENGINE = "InnoDB"'
    );
    $missingTables = [];
    foreach ($requiredTables as $table) {
        $tableStmt->execute([$table]);
        if ((int)$tableStmt->fetchColumn() !== 1) $missingTables[] = $table;
    }
    $checks = [
        'php_version' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'json' => extension_loaded('json'),
        'database_connection' => true,
        'innodb_schema' => count($missingTables) === 0,
        'https' => request_is_https(),
    ];
    $setupRequired = null;
    if ($checks['innodb_schema']) {
        $setupCompleted = (int)$pdo->query('SELECT setup_completed FROM app_install_state WHERE id = 1')->fetchColumn() === 1;
        $usersCount = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
        $setupRequired = !$setupCompleted && $usersCount === 0;
    }
    $coreReady = $checks['php_version'] && $checks['pdo_mysql'] && $checks['json']
        && $checks['database_connection'] && $checks['innodb_schema'];
    json_response([
        'ok' => true,
        'service' => '3food-pos-php-api',
        'php_version' => PHP_VERSION,
        'checks' => $checks,
        'missing_innodb_tables' => $missingTables,
        'setup_required' => $setupRequired,
        'production_ready' => $coreReady && $checks['https'],
    ]);
}

if ($method === 'GET' && $path === '/appearance') {
    $defaults = ['primary'=>'#c2410c','secondary'=>'#1f6b4a','mode'=>'light','fontFamily'=>'byekan','fontSize'=>15,'cardRadius'=>16,'buttonRadius'=>12,'logoUrl'=>null];
    $stored = $pdo->query('SELECT settings FROM app_appearance_settings WHERE id = 1')->fetchColumn();
    $settings = is_string($stored) ? json_decode($stored, true) : null;
    if (!is_array($settings)) $settings = $defaults;
    $settings = array_merge($defaults,$settings);
    $settings['fontFamily'] = 'byekan';
    json_response(['ok'=>true,'settings'=>$settings]);
}
if ($method === 'POST' && $path === '/appearance') {
    $actor = require_role($pdo, ['manager']); require_csrf(); $body = request_json(); $input = $body['settings'] ?? null;
    if (!is_array($input)) json_response(['ok'=>false,'error'=>'invalid_appearance'],422);
    $primary=strtolower((string)($input['primary']??'')); $secondary=strtolower((string)($input['secondary']??''));
    $mode=(string)($input['mode']??''); $font=(string)($input['fontFamily']??'');
    $fontSize=filter_var($input['fontSize']??null,FILTER_VALIDATE_INT); $cardRadius=filter_var($input['cardRadius']??null,FILTER_VALIDATE_INT);
    $buttonRadius=filter_var($input['buttonRadius']??null,FILTER_VALIDATE_INT); $logoUrl=$input['logoUrl']??null;
    if (!preg_match('/^#[0-9a-f]{6}$/D',$primary)||!preg_match('/^#[0-9a-f]{6}$/D',$secondary)||
        !in_array($mode,['light','dark'],true)||!in_array($font,['byekan'],true)||
        $fontSize===false||$fontSize<13||$fontSize>20||$cardRadius===false||$cardRadius<8||$cardRadius>28||
        $buttonRadius===false||$buttonRadius<6||$buttonRadius>24||
        !($logoUrl===null||(is_string($logoUrl)&&preg_match('#^/uploads/appearance/[a-f0-9]{32}\.(png|jpg|webp)$#D',$logoUrl)))) {
        json_response(['ok'=>false,'error'=>'invalid_appearance','message'=>'یکی از مقادیر ظاهر معتبر نیست'],422);
    }
    $settings=['primary'=>$primary,'secondary'=>$secondary,'mode'=>$mode,'fontFamily'=>$font,'fontSize'=>$fontSize,'cardRadius'=>$cardRadius,'buttonRadius'=>$buttonRadius,'logoUrl'=>$logoUrl];
    $encoded=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $stmt=$pdo->prepare('INSERT INTO app_appearance_settings (id,settings,updated_by) VALUES (1,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),updated_by=VALUES(updated_by)');
    $stmt->execute([$encoded,(int)$actor['id']]);
    audit($pdo,(int)$actor['id'],'appearance_updated','appearance','1',['mode'=>$mode,'fontFamily'=>$font]);
    json_response(['ok'=>true,'settings'=>$settings]);
}
if ($method === 'POST' && $path === '/appearance/reset') {
    $actor=require_role($pdo,['manager']); require_csrf(); $pdo->exec('DELETE FROM app_appearance_settings WHERE id=1');
    audit($pdo,(int)$actor['id'],'appearance_reset','appearance','1');
    json_response(['ok'=>true,'settings'=>['primary'=>'#c2410c','secondary'=>'#1f6b4a','mode'=>'light','fontFamily'=>'byekan','fontSize'=>15,'cardRadius'=>16,'buttonRadius'=>12,'logoUrl'=>null]]);
}
if ($method === 'POST' && $path === '/appearance/logo') {
    $actor=require_role($pdo,['manager']); require_csrf();
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>800000) json_response(['ok'=>false,'error'=>'logo_too_large'],413);
    $body=request_json(); $encodedImage=$body['image']??'';
    if (!is_string($encodedImage)||$encodedImage===''||strlen($encodedImage)>700000) json_response(['ok'=>false,'error'=>'invalid_logo'],422);
    $binary=base64_decode($encodedImage,true);
    if (!is_string($binary)||strlen($binary)<1||strlen($binary)>524288) json_response(['ok'=>false,'error'=>'invalid_logo','message'=>'فایل لوگو معتبر یا در محدوده حجم مجاز نیست'],422);
    $imageInfo=@getimagesizefromstring($binary); $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->buffer($binary);
    $extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if (!is_array($imageInfo)||!isset($extensions[$mime])||($imageInfo['mime']??'')!==$mime||
        (int)$imageInfo[0]<1||(int)$imageInfo[1]<1||(int)$imageInfo[0]>2000||(int)$imageInfo[1]>2000) {
        json_response(['ok'=>false,'error'=>'invalid_logo','message'=>'فقط تصویر سالم PNG، JPG یا WebP تا ابعاد ۲۰۰۰ پیکسل پذیرفته می‌شود'],422);
    }
    $uploadDir=dirname(__DIR__).'/uploads/appearance';
    if (!is_dir($uploadDir)&&!mkdir($uploadDir,0755,true)&&!is_dir($uploadDir)) json_response(['ok'=>false,'error'=>'logo_storage_unavailable'],500);
    $filename=bin2hex(random_bytes(16)).'.'.$extensions[$mime]; $target=$uploadDir.'/'.$filename;
    if (file_put_contents($target,$binary,LOCK_EX)===false) json_response(['ok'=>false,'error'=>'logo_storage_failed'],500);
    @chmod($target,0644); $url='/uploads/appearance/'.$filename;
    audit($pdo,(int)$actor['id'],'appearance_logo_uploaded','appearance',null,['mime'=>$mime,'bytes'=>strlen($binary)]);
    json_response(['ok'=>true,'logo_url'=>$url],201);
}

if ($method === 'GET' && $path === '/setup/status') {
    $completed = (int)$pdo->query('SELECT setup_completed FROM app_install_state WHERE id = 1')->fetchColumn() === 1;
    $users = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
    json_response(['ok' => true, 'setup_required' => !$completed && $users === 0]);
}

if ($method === 'POST' && $path === '/setup/manager') {
    $provided = (string)($_SERVER['HTTP_X_SETUP_TOKEN'] ?? '');
    $expected = (string)($config['setup_token'] ?? '');
    if (strlen($expected) < 32 || str_contains($expected, 'REPLACE_') || !hash_equals($expected, $provided)) {
        json_response(['ok' => false, 'error' => 'setup_token_invalid', 'message' => 'کلید راه‌اندازی معتبر نیست'], 403);
    }
    $body = request_json();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $display = trim((string)($body['display_name'] ?? 'مدیر'));
    if (!preg_match('/^[a-zA-Z0-9._-]{4,64}$/', $username)) {
        json_response(['ok' => false, 'error' => 'invalid_username', 'message' => 'نام کاربری باید ۴ تا ۶۴ حرف انگلیسی، عدد یا . _ - باشد'], 422);
    }
    if (strlen($password) < 12 || strlen($password) > 200) {
        json_response(['ok' => false, 'error' => 'weak_password', 'message' => 'رمز عبور باید حداقل ۱۲ نویسه باشد'], 422);
    }
    if ($display === '' || utf8_length($display) > 120) {
        json_response(['ok' => false, 'error' => 'invalid_display_name', 'message' => 'نام نمایشی معتبر نیست'], 422);
    }
    try {
        $pdo->beginTransaction();
        $installStmt = $pdo->query('SELECT setup_completed FROM app_install_state WHERE id = 1 FOR UPDATE');
        $install = $installStmt->fetch();
        $existing = (int)$pdo->query('SELECT COUNT(*) FROM app_users')->fetchColumn();
        if (!$install || (int)$install['setup_completed'] === 1 || $existing > 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'setup_closed', 'message' => 'راه‌اندازی اولیه قبلاً انجام شده است'], 409);
        }
        $stmt = $pdo->prepare('INSERT INTO app_users (username, password_hash, display_name, role, is_active, must_change_password) VALUES (?, ?, ?, "manager", 1, 0)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->exec('UPDATE app_install_state SET setup_completed = 1 WHERE id = 1');
        audit($pdo, $userId, 'initial_manager_created', 'user', (string)$userId);
        $pdo->commit();
        // The setup token must also be removed from private/config.php immediately after this succeeds.
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $userId, 'auth_version' => 0, 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
        json_response(['ok' => true, 'user' => ['id' => $userId, 'username' => $username, 'display_name' => $display, 'role' => 'manager'], 'csrf_token' => $_SESSION['csrf']], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] Initial manager setup failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'setup_failed', 'message' => 'ساخت حساب مدیر انجام نشد'], 500);
    }
}

if ($method === 'POST' && $path === '/auth/login') {
    $body = request_json();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') json_response(['ok' => false, 'error' => 'credentials_required', 'message' => 'نام کاربری و رمز عبور را وارد کنید'], 422);
    if (login_rate_limited($pdo, $config, $username)) {
        json_response(['ok' => false, 'error' => 'too_many_attempts', 'message' => 'تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه دیگر دوباره امتحان کنید'], 429);
    }
    $stmt = $pdo->prepare('SELECT id, username, password_hash, display_name, role, is_active, must_change_password, session_version FROM app_users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    $ok = $row && (int)$row['is_active'] === 1 && password_verify($password, $row['password_hash']);
    record_login_attempt($pdo, $config, $username, (bool)$ok);
    if (!$ok) json_response(['ok' => false, 'error' => 'invalid_credentials', 'message' => 'نام کاربری یا رمز عبور اشتباه است'], 401);

    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$row['id'], 'auth_version' => (int)$row['session_version'], 'last_seen' => time(), 'csrf' => bin2hex(random_bytes(32))];
    $update = $pdo->prepare('UPDATE app_users SET last_login_at = NOW() WHERE id = ?');
    $update->execute([(int)$row['id']]);
    audit($pdo, (int)$row['id'], 'login', 'user', (string)$row['id']);
    json_response([
        'ok' => true,
        'user' => [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'must_change_password' => (bool)$row['must_change_password'],
        ],
        'csrf_token' => $_SESSION['csrf'],
    ]);
}

if ($method === 'GET' && $path === '/auth/me') {
    $user = current_user($pdo);
    if (!$user) json_response(['ok' => true, 'authenticated' => false]);
    json_response(['ok' => true, 'authenticated' => true, 'user' => [
        'id' => (int)$user['id'], 'username' => (string)$user['username'],
        'display_name' => (string)$user['display_name'], 'role' => (string)$user['role'],
        'must_change_password' => (bool)$user['must_change_password'],
    ], 'csrf_token' => csrf_token()]);
}

if ($method === 'POST' && $path === '/auth/logout') {
    require_csrf();
    $user = current_user($pdo);
    if ($user) audit($pdo, (int)$user['id'], 'logout', 'user', (string)$user['id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => (bool)$params['secure'],
            'httponly' => true,
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
    json_response(['ok' => true]);
}

if ($method === 'POST' && $path === '/auth/change-password') {
    $user = require_user($pdo);
    require_csrf();
    $body = request_json();
    $current = (string)($body['current_password'] ?? '');
    $next = (string)($body['new_password'] ?? '');
    if (strlen($next) < 12 || strlen($next) > 200) {
        json_response(['ok' => false, 'error' => 'weak_password', 'message' => 'رمز جدید باید حداقل ۱۲ نویسه باشد'], 422);
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM app_users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $hash = (string)$stmt->fetchColumn();
    if ($current === '' || !password_verify($current, $hash)) {
        json_response(['ok' => false, 'error' => 'current_password_invalid', 'message' => 'رمز فعلی صحیح نیست'], 401);
    }
    $update = $pdo->prepare('UPDATE app_users SET password_hash = ?, must_change_password = 0, session_version = session_version + 1 WHERE id = ?');
    $update->execute([password_hash($next, PASSWORD_DEFAULT), (int)$user['id']]);
    audit($pdo, (int)$user['id'], 'password_changed', 'user', (string)$user['id']);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['auth_version'] = (int)$user['session_version'] + 1;
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_response(['ok' => true, 'csrf_token' => $_SESSION['csrf']]);
}

if ($method === 'GET' && $path === '/users') {
    require_role($pdo, ['manager']);
    $rows = $pdo->query('SELECT id, username, display_name, role, is_active, must_change_password, last_login_at, created_at FROM app_users ORDER BY id ASC')->fetchAll();
    json_response(['ok' => true, 'users' => $rows]);
}

if ($method === 'POST' && $path === '/users') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $body = request_json();
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $display = trim((string)($body['display_name'] ?? ''));
    $role = (string)($body['role'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9._-]{4,64}$/', $username)) json_response(['ok' => false, 'error' => 'invalid_username', 'message' => 'نام کاربری معتبر نیست'], 422);
    if (strlen($password) < 12 || strlen($password) > 200) json_response(['ok' => false, 'error' => 'weak_password', 'message' => 'رمز عبور باید حداقل ۱۲ نویسه باشد'], 422);
    if ($display === '' || utf8_length($display) > 120) json_response(['ok' => false, 'error' => 'invalid_display_name', 'message' => 'نام نمایشی معتبر نیست'], 422);
    if (!in_array($role, ['manager','cashier','kitchen'], true)) json_response(['ok' => false, 'error' => 'invalid_role', 'message' => 'نقش انتخابی معتبر نیست'], 422);
    try {
        $stmt = $pdo->prepare('INSERT INTO app_users (username, password_hash, display_name, role, is_active, must_change_password) VALUES (?, ?, ?, ?, 1, 1)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display, $role]);
        $id = (int)$pdo->lastInsertId();
        audit($pdo, (int)$actor['id'], 'user_created', 'user', (string)$id, ['role' => $role]);
        json_response(['ok' => true, 'user' => ['id' => $id, 'username' => $username, 'display_name' => $display, 'role' => $role, 'is_active' => 1]], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') json_response(['ok' => false, 'error' => 'username_exists', 'message' => 'این نام کاربری قبلاً ثبت شده است'], 409);
        error_log('[3Food POS] User creation failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'user_create_failed', 'message' => 'ساخت کاربر انجام نشد'], 500);
    }
}

if (preg_match('#^/users/(\\d+)$#', $path, $match) && $method === 'PATCH') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $targetId = (int)$match[1];
    $body = request_json();
    $allowed = ['display_name', 'role', 'is_active', 'password'];
    $updates = [];
    $values = [];
    if (array_key_exists('display_name', $body)) {
        $v = trim((string)$body['display_name']);
        if ($v === '' || utf8_length($v) > 120) json_response(['ok' => false, 'error' => 'invalid_display_name'], 422);
        $updates[] = 'display_name = ?'; $values[] = $v;
    }
    if (array_key_exists('role', $body)) {
        $v = (string)$body['role'];
        if (!in_array($v, ['manager','cashier','kitchen'], true)) json_response(['ok' => false, 'error' => 'invalid_role'], 422);
        $updates[] = 'role = ?'; $values[] = $v;
    }
    if (array_key_exists('is_active', $body)) {
        if ((int)$targetId === (int)$actor['id'] && !$body['is_active']) json_response(['ok' => false, 'error' => 'cannot_disable_self', 'message' => 'نمی‌توانید حساب خودتان را غیرفعال کنید'], 422);
        $updates[] = 'is_active = ?'; $values[] = !empty($body['is_active']) ? 1 : 0;
    }
    if (array_key_exists('password', $body)) {
        $v = (string)$body['password'];
        if (strlen($v) < 12 || strlen($v) > 200) json_response(['ok' => false, 'error' => 'weak_password'], 422);
        $updates[] = 'password_hash = ?'; $values[] = password_hash($v, PASSWORD_DEFAULT);
        $updates[] = 'must_change_password = 1';
    }
    if (!$updates) json_response(['ok' => false, 'error' => 'no_changes'], 422);

    // Serialize manager-account changes so two concurrent requests cannot remove
    // the last active manager at the same time.
    try {
        $pdo->beginTransaction();
        $pdo->query('SELECT id FROM app_install_state WHERE id = 1 FOR UPDATE');
        $targetStmt = $pdo->prepare('SELECT role, is_active FROM app_users WHERE id = ? FOR UPDATE');
        $targetStmt->execute([$targetId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'user_not_found'], 404);
        }
        $newRole = array_key_exists('role', $body) ? (string)$body['role'] : (string)$target['role'];
        $newActive = array_key_exists('is_active', $body) ? (!empty($body['is_active']) ? 1 : 0) : (int)$target['is_active'];
        if ($target['role'] === 'manager' && (int)$target['is_active'] === 1 && ($newRole !== 'manager' || $newActive !== 1)) {
            $activeManagers = (int)$pdo->query("SELECT COUNT(*) FROM app_users WHERE role = 'manager' AND is_active = 1")->fetchColumn();
            if ($activeManagers <= 1) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'last_manager_required', 'message' => 'حداقل یک حساب مدیر فعال باید باقی بماند'], 409);
            }
        }
        if (array_key_exists('role', $body) || array_key_exists('is_active', $body) || array_key_exists('password', $body)) {
            $updates[] = 'session_version = session_version + 1';
        }
        $values[] = $targetId;
        $stmt = $pdo->prepare('UPDATE app_users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($values);
        audit($pdo, (int)$actor['id'], 'user_updated', 'user', (string)$targetId, ['fields' => array_keys(array_intersect_key($body, array_flip($allowed)))]);
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] User update failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'user_update_failed', 'message' => 'ویرایش کاربر انجام نشد'], 500);
    }
}


// Shared POS data: all records are stored centrally in MySQL, never trusted from localStorage.
$resourceRoles = [
    'categories' => ['manager'],
    'products' => ['manager'],
    'ingredients' => ['manager'],
    'customers' => ['manager','cashier'],
    'discounts' => ['manager'],
    'expenses' => ['manager'],
    'expense_categories' => ['manager'],
    'suppliers' => ['manager'],
    'supplier_purchases' => ['manager'],
    'templates' => ['manager'],
    'settings' => ['manager'],
    'sms_logs' => ['manager','cashier'],

];

if (preg_match('#^/ingredients/([A-Za-z0-9_-]+)/stock$#D', $path, $stockMatch) && $method === 'POST') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $body = request_json();
    $deltaRaw = $body['delta'] ?? null;
    if (!is_int($deltaRaw) && !is_float($deltaRaw) && !(is_string($deltaRaw) && is_numeric($deltaRaw))) {
        json_response(['ok' => false, 'error' => 'invalid_stock_delta', 'message' => 'مقدار تغییر موجودی معتبر نیست'], 422);
    }
    $delta = (float)$deltaRaw;
    if (!is_finite($delta) || $delta == 0.0 || abs($delta) > 1000000 || round($delta, 3) !== $delta) {
        json_response(['ok' => false, 'error' => 'invalid_stock_delta', 'message' => 'تغییر موجودی باید غیرصفر و حداکثر سه رقم اعشار باشد'], 422);
    }
    $ingredientId = $stockMatch[1];
    try {
        $pdo->beginTransaction();
        $select = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = ? FOR UPDATE");
        $select->execute([$ingredientId]);
        $row = $select->fetch();
        if (!$row) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'ingredient_not_found', 'message' => 'ماده اولیه پیدا نشد'], 404);
        }
        $item = json_decode((string)$row['payload'], true);
        if (!is_array($item) || !isset($item['stock']) || !is_numeric($item['stock'])) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'ingredient_stock_invalid', 'message' => 'اطلاعات موجودی ماده اولیه معتبر نیست'], 409);
        }
        $before = (float)$item['stock'];
        $after = round($before + $delta, 3);
        if (!is_finite($before) || $before < 0 || $after < 0 || $after > 1000000000) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'insufficient_stock', 'message' => 'موجودی برای این تغییر کافی نیست'], 409);
        }
        $item['stock'] = $after;
        $payload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $update = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'ingredients' AND id = ?");
        $update->execute([$payload, (int)$actor['id'], $ingredientId]);
        audit($pdo, (int)$actor['id'], 'ingredient_stock_adjusted', 'ingredient', $ingredientId, ['delta' => $delta, 'before' => $before, 'after' => $after]);
        $pdo->commit();
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] Ingredient stock adjustment failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'stock_adjustment_failed', 'message' => 'تغییر موجودی ذخیره نشد'], 500);
    }
}

if ($method === 'GET' && $path === '/backup/export') {
    $actor = require_role($pdo, ['manager']);
    $records = $pdo->query('SELECT id, resource, payload, updated_by, created_at, updated_at FROM pos_records ORDER BY resource, id')->fetchAll();
    foreach ($records as &$record) {
        $record['payload'] = json_decode((string)$record['payload'], true) ?: [];
        if ($record['resource'] === 'settings' && is_array($record['payload'])) {
            unset($record['payload']['smsUser'], $record['payload']['smsKey'], $record['payload']['smsFrom']);
        }
    }
    unset($record);
    $orders = $pdo->query('SELECT id, order_number, status, idempotency_key, request_hash, customer_id, customer_name, customer_phone, guest_name, guest_phone, payment_method, points_used, points_earned, discount_code, items, totals, note, created_by, created_at, updated_at FROM pos_orders ORDER BY id')->fetchAll();
    foreach ($orders as &$order) {
        $order['items'] = json_decode((string)$order['items'], true) ?: [];
        $order['totals'] = json_decode((string)$order['totals'], true) ?: [];
    }
    unset($order);
    $effects = $pdo->query('SELECT order_id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta, reversed_at, reversed_by, created_at FROM pos_order_effects ORDER BY id')->fetchAll();
    $redemptions = $pdo->query('SELECT order_id, discount_id, code, customer_id, redeemed_at, reversed_at FROM pos_discount_redemptions ORDER BY id')->fetchAll();
    $counters = $pdo->query('SELECT counter_key, counter_value FROM pos_counters ORDER BY counter_key')->fetchAll();
    // Agent credentials are never exported. Restored agents are disabled and must be paired again.
    $terminalAgents = $pdo->query('SELECT id,name,agent_version,created_at FROM app_terminal_agents ORDER BY id')->fetchAll();
    $paymentTerminals = $pdo->query('SELECT id,agent_id,local_terminal_key,name,provider_code,model_name,driver_code,connection_type,host,port,created_at,updated_at FROM pos_payment_terminals ORDER BY id')->fetchAll();
    $terminalPayments = $pdo->query('SELECT id,idempotency_key,terminal_id,order_id,amount,currency,status,external_transaction_id,trace_number,retrieval_reference_number,response_code,response_message,safe_response,requested_at,completed_at,updated_at FROM pos_terminal_payments ORDER BY requested_at')->fetchAll();
    foreach($terminalPayments as &$terminalPayment){
        if(is_string($terminalPayment['safe_response']??null)) $terminalPayment['safe_response']=json_decode($terminalPayment['safe_response'],true);
        if(in_array($terminalPayment['status'],['queued','processing'],true)) $terminalPayment['status']='unknown';
    } unset($terminalPayment);
    audit($pdo, (int)$actor['id'], 'backup_exported', 'backup', null, ['records' => count($records), 'orders' => count($orders)]);
    json_response(['ok' => true, 'backup' => [
        'format' => '3food-pos-mysql-backup',
        'version' => 1,
        'exported_at' => gmdate('c'),
        'records' => $records,
        'orders' => $orders,
        'order_effects' => $effects,
        'discount_redemptions' => $redemptions,
        'counters' => $counters,
        'terminal_agents' => $terminalAgents,
        'payment_terminals' => $paymentTerminals,
        'terminal_payments' => $terminalPayments,
    ]]);
}

if ($method === 'POST' && $path === '/backup/restore') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8 * 1024 * 1024) {
        json_response(['ok' => false, 'error' => 'backup_too_large'], 413);
    }
    $body = request_json();
    $backup = $body['backup'] ?? null;
    if (!is_array($backup) || ($backup['format'] ?? '') !== '3food-pos-mysql-backup' || (int)($backup['version'] ?? 0) !== 1) {
        json_response(['ok' => false, 'error' => 'invalid_backup'], 422);
    }
    foreach (['records','orders','order_effects','discount_redemptions','counters'] as $key) {
        if (!isset($backup[$key]) || !is_array($backup[$key])) {
            json_response(['ok' => false, 'error' => 'invalid_backup_structure'], 422);
        }
    }
    foreach(['terminal_agents','payment_terminals','terminal_payments'] as $optionalKey){
        if(!isset($backup[$optionalKey])) $backup[$optionalKey]=[];
        if(!is_array($backup[$optionalKey])) json_response(['ok'=>false,'error'=>'invalid_backup_structure'],422);
    }
    if (count($backup['records']) > 40000 || count($backup['orders']) > 20000 ||
        count($backup['order_effects']) > 60000 || count($backup['discount_redemptions']) > 20000 ||
        count($backup['counters']) > 100 || count($backup['terminal_agents']) > 100 ||
        count($backup['payment_terminals']) > 500 || count($backup['terminal_payments']) > 50000) {
        json_response(['ok' => false, 'error' => 'backup_limits_exceeded'], 413);
    }
    try {
        $pdo->beginTransaction();
        $pdo->query('SELECT counter_key FROM pos_counters WHERE counter_key = "order_number" FOR UPDATE')->fetchColumn();
        // Lock the empty/current key ranges too, preventing concurrent POS writes
        // from slipping in after the empty-database check but before restoration.
        $pdo->query('SELECT id FROM pos_records FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM pos_orders FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM pos_order_effects FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM pos_discount_redemptions FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM app_terminal_agents FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM pos_payment_terminals FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM pos_terminal_payments FOR UPDATE')->fetchAll();
        $occupied = (int)$pdo->query('SELECT (SELECT COUNT(*) FROM pos_records) + (SELECT COUNT(*) FROM pos_orders) + (SELECT COUNT(*) FROM pos_order_effects) + (SELECT COUNT(*) FROM pos_discount_redemptions) + (SELECT COUNT(*) FROM app_terminal_agents) + (SELECT COUNT(*) FROM pos_payment_terminals) + (SELECT COUNT(*) FROM pos_terminal_payments)')->fetchColumn();
        if ($occupied !== 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'restore_requires_empty_pos', 'message' => 'بازیابی فقط روی دیتابیس عملیاتی خالی انجام می‌شود؛ ابتدا از اطلاعات فعلی پشتیبان بگیرید و دیتابیس مقصد خالی باشد'], 409);
        }
        $allowedResources = ['categories','products','ingredients','customers','discounts','expenses','expense_categories','suppliers','supplier_purchases','templates','settings','sms_logs','system_meta'];
        $insertRecord = $pdo->prepare('INSERT INTO pos_records (id, resource, payload, updated_by) VALUES (?, ?, ?, ?)');
        foreach ($backup['records'] as $record) {
            if (!is_array($record) || !in_array($record['resource'] ?? '', $allowedResources, true) ||
                !is_string($record['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $record['id']) ||
                !is_array($record['payload'] ?? null)) throw new InvalidArgumentException('Invalid backup record');
            $payloadData = $record['payload'];
            if ($record['resource'] === 'settings') unset($payloadData['smsUser'], $payloadData['smsKey'], $payloadData['smsFrom']);
            $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($payload) > 262144) throw new InvalidArgumentException('Backup record too large');
            $insertRecord->execute([$record['id'], $record['resource'], $payload, null]);
        }
        $insertOrder = $pdo->prepare('INSERT INTO pos_orders (id, order_number, status, idempotency_key, request_hash, customer_id, customer_name, customer_phone, guest_name, guest_phone, payment_method, points_used, points_earned, discount_code, items, totals, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $orderIds = [];
        foreach ($backup['orders'] as $order) {
            if (!is_array($order) || !is_numeric($order['id'] ?? null) || (int)$order['id'] < 1 ||
                !is_numeric($order['order_number'] ?? null) || (int)$order['order_number'] < 1 ||
                !in_array($order['status'] ?? '', ['new','preparing','ready','delivered','cancelled'], true) ||
                !is_array($order['items'] ?? null) || !is_array($order['totals'] ?? null)) throw new InvalidArgumentException('Invalid backup order');
            $id = (int)$order['id'];
            if (isset($orderIds[$id])) throw new InvalidArgumentException('Duplicate backup order');
            $orderIds[$id] = true;
            $items = json_encode($order['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $totals = json_encode($order['totals'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insertOrder->execute([$id, (int)$order['order_number'], $order['status'], $order['idempotency_key'] ?? null, $order['request_hash'] ?? null,
                $order['customer_id'] ?? null, $order['customer_name'] ?? null, $order['customer_phone'] ?? null,
                $order['guest_name'] ?? null, $order['guest_phone'] ?? null, $order['payment_method'] ?? 'cash',
                (int)($order['points_used'] ?? 0), (int)($order['points_earned'] ?? 0), $order['discount_code'] ?? null,
                $items, $totals, $order['note'] ?? null, (int)$actor['id'], $order['created_at'] ?? gmdate('Y-m-d H:i:s'), $order['updated_at'] ?? gmdate('Y-m-d H:i:s')]);
        }
        $insertEffect = $pdo->prepare('INSERT INTO pos_order_effects (order_id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta, reversed_at, reversed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)');
        foreach ($backup['order_effects'] as $effect) {
            if (!is_array($effect) || !isset($orderIds[(int)($effect['order_id'] ?? 0)]) ||
                !in_array($effect['effect_type'] ?? '', ['ingredient','customer'], true) ||
                !is_string($effect['resource_id'] ?? null) || strlen($effect['resource_id']) > 100) throw new InvalidArgumentException('Invalid backup effect');
            $insertEffect->execute([(int)$effect['order_id'], $effect['effect_type'], $effect['resource_id'], $effect['quantity'] ?? 0,
                $effect['points_spent'] ?? 0, $effect['points_earned'] ?? 0, $effect['money_delta'] ?? 0, $effect['visits_delta'] ?? 0,
                $effect['reversed_at'] ?? null, $effect['created_at'] ?? gmdate('Y-m-d H:i:s')]);
        }
        $insertRedemption = $pdo->prepare('INSERT INTO pos_discount_redemptions (order_id, discount_id, code, customer_id, redeemed_at, reversed_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($backup['discount_redemptions'] as $redemption) {
            if (!is_array($redemption) || !isset($orderIds[(int)($redemption['order_id'] ?? 0)]) ||
                !is_string($redemption['discount_id'] ?? null) || !is_string($redemption['code'] ?? null)) throw new InvalidArgumentException('Invalid backup redemption');
            $insertRedemption->execute([(int)$redemption['order_id'], $redemption['discount_id'], $redemption['code'],
                $redemption['customer_id'] ?? null, $redemption['redeemed_at'] ?? gmdate('Y-m-d H:i:s'), $redemption['reversed_at'] ?? null]);
        }

        $agentIds=[];
        $insertAgent=$pdo->prepare('INSERT INTO app_terminal_agents (id,name,token_hash,enabled,agent_version,last_seen_at,created_by,created_at) VALUES (?,?,?,0,?,NULL,?,?)');
        foreach($backup['terminal_agents'] as $agent){
            if(!is_array($agent)||!valid_uuid((string)($agent['id']??''))||trim((string)($agent['name']??''))===''||utf8_length((string)$agent['name'])>120) throw new InvalidArgumentException('Invalid terminal agent backup');
            $agentIds[(string)$agent['id']]=true;
            $insertAgent->execute([$agent['id'],substr((string)$agent['name'],0,120),hash('sha256',random_bytes(32)),isset($agent['agent_version'])?substr((string)$agent['agent_version'],0,40):null,(int)$actor['id'],$agent['created_at']??gmdate('Y-m-d H:i:s')]);
        }
        $terminalIds=[];
        $insertTerminal=$pdo->prepare('INSERT INTO pos_payment_terminals (id,agent_id,local_terminal_key,name,provider_code,model_name,driver_code,connection_type,host,port,enabled,is_default,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,0,0,?,?,?)');
        foreach($backup['payment_terminals'] as $terminal){
            if(!is_array($terminal)||!is_string($terminal['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$terminal['id'])||
                !isset($agentIds[(string)($terminal['agent_id']??'')])||!preg_match('/^[A-Za-z0-9_-]{1,100}$/D',(string)($terminal['local_terminal_key']??''))||
                !in_array($terminal['connection_type']??'', ['lan','wifi'],true)||(filter_var($terminal['host']??'',FILTER_VALIDATE_IP)===false||!is_private_terminal_ip((string)$terminal['host']))||
                !is_numeric($terminal['port']??null)||(int)$terminal['port']<1||(int)$terminal['port']>65535) throw new InvalidArgumentException('Invalid payment terminal backup');
            $terminalIds[$terminal['id']]=true;
            $insertTerminal->execute([$terminal['id'],$terminal['agent_id'],$terminal['local_terminal_key'],substr((string)($terminal['name']??''),0,120),substr((string)($terminal['provider_code']??'unknown'),0,64),isset($terminal['model_name'])?substr((string)$terminal['model_name'],0,120):null,substr((string)($terminal['driver_code']??'unconfigured'),0,80),$terminal['connection_type'],$terminal['host'],(int)$terminal['port'],(int)$actor['id'],$terminal['created_at']??gmdate('Y-m-d H:i:s'),$terminal['updated_at']??gmdate('Y-m-d H:i:s')]);
        }
        $insertTerminalPayment=$pdo->prepare('INSERT INTO pos_terminal_payments (id,idempotency_key,terminal_id,order_id,amount,currency,status,external_transaction_id,trace_number,retrieval_reference_number,response_code,response_message,safe_response,created_by,requested_at,completed_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($backup['terminal_payments'] as $terminalPayment){
            if(!is_array($terminalPayment)||!valid_uuid((string)($terminalPayment['id']??''))||!valid_uuid((string)($terminalPayment['idempotency_key']??''))||
                !isset($terminalIds[(string)($terminalPayment['terminal_id']??'')])||!is_numeric($terminalPayment['amount']??null)||(int)$terminalPayment['amount']<1||
                !in_array($terminalPayment['status']??'', ['queued','processing','approved','declined','cancelled','unknown','expired'],true)) throw new InvalidArgumentException('Invalid terminal payment backup');
            $linkedOrder=$terminalPayment['order_id']??null;
            if($linkedOrder!==null&&!isset($orderIds[(int)$linkedOrder])) throw new InvalidArgumentException('Invalid linked order in terminal payment backup');
            $paymentStatus=in_array($terminalPayment['status'],['queued','processing'],true)?'unknown':$terminalPayment['status'];
            $safeResponse=is_array($terminalPayment['safe_response']??null)?json_encode($terminalPayment['safe_response'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
            $insertTerminalPayment->execute([$terminalPayment['id'],$terminalPayment['idempotency_key'],$terminalPayment['terminal_id'],$linkedOrder,(int)$terminalPayment['amount'],substr((string)($terminalPayment['currency']??'IRT'),0,3),$paymentStatus,
                isset($terminalPayment['external_transaction_id'])?substr((string)$terminalPayment['external_transaction_id'],0,120):null,isset($terminalPayment['trace_number'])?substr((string)$terminalPayment['trace_number'],0,80):null,
                isset($terminalPayment['retrieval_reference_number'])?substr((string)$terminalPayment['retrieval_reference_number'],0,80):null,isset($terminalPayment['response_code'])?substr((string)$terminalPayment['response_code'],0,40):null,
                isset($terminalPayment['response_message'])?substr((string)$terminalPayment['response_message'],0,240):null,$safeResponse,(int)$actor['id'],$terminalPayment['requested_at']??gmdate('Y-m-d H:i:s'),
                $terminalPayment['completed_at']??null,$terminalPayment['updated_at']??gmdate('Y-m-d H:i:s')]);
        }

        $counter = 0;
        foreach ($backup['counters'] as $entry) {
            if (!is_array($entry) || ($entry['counter_key'] ?? '') !== 'order_number' || !is_numeric($entry['counter_value'] ?? null) ||
                (int)$entry['counter_value'] < 0 || (int)$entry['counter_value'] > 1000000000) throw new InvalidArgumentException('Invalid backup counter');
            $counter = (int)$entry['counter_value'];
        }
        $maxOrder = (int)$pdo->query('SELECT COALESCE(MAX(order_number), 0) FROM pos_orders')->fetchColumn();
        $pdo->prepare("UPDATE pos_counters SET counter_value = ? WHERE counter_key = 'order_number'")->execute([max($counter, $maxOrder)]);
        audit($pdo, (int)$actor['id'], 'backup_restored', 'backup', null, [
            'records' => count($backup['records']), 'orders' => count($backup['orders']),
            'effects' => count($backup['order_effects']), 'redemptions' => count($backup['discount_redemptions']), 'terminal_agents' => count($backup['terminal_agents']), 'terminals' => count($backup['payment_terminals']), 'terminal_payments' => count($backup['terminal_payments']),
        ]);
        $pdo->commit();
        json_response(['ok' => true, 'restored' => [
            'records' => count($backup['records']), 'orders' => count($backup['orders']),
            'effects' => count($backup['order_effects']), 'redemptions' => count($backup['discount_redemptions']),
        ]]);
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'invalid_backup_data', 'message' => 'ساختار یا یکی از رکوردهای فایل پشتیبان معتبر نیست'], 422);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] Backup restore failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'backup_restore_failed', 'message' => 'بازیابی انجام نشد؛ هیچ بخشی از اطلاعات تغییر نکرد'], 500);
    }
}

if ($method === 'POST' && $path === '/migration/import') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $body = request_json();
    $allowed = ['categories','products','ingredients','customers','discounts','expenses','expense_categories','suppliers','supplier_purchases','templates','settings'];
    $provided = $body['data'] ?? null;
    if (!is_array($provided)) json_response(['ok' => false, 'error' => 'invalid_import'], 422);
    $encodedBody = json_encode($provided, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($encodedBody) > 2 * 1024 * 1024) {
        json_response(['ok' => false, 'error' => 'import_too_large', 'message' => 'حجم انتقال داده بیش از ۲ مگابایت است'], 413);
    }
    try {
        $pdo->beginTransaction();
        $existingRecords = (int)$pdo->query('SELECT COUNT(*) FROM pos_records')->fetchColumn();
        $existingOrders = (int)$pdo->query('SELECT COUNT(*) FROM pos_orders')->fetchColumn();
        if ($existingRecords > 0 || $existingOrders > 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'import_not_empty', 'message' => 'دیتابیس قبلاً داده دارد؛ انتقال اولیه فقط روی دیتابیس خالی مجاز است'], 409);
        }
        $insert = $pdo->prepare('INSERT INTO pos_records (id, resource, payload, updated_by) VALUES (?, ?, ?, ?)');
        $counts = [];
        foreach ($allowed as $resource) {
            $records = $provided[$resource] ?? [];
            if (!is_array($records) || count($records) > 5000) {
                throw new InvalidArgumentException('Invalid import resource: ' . $resource);
            }
            $counts[$resource] = 0;
            foreach ($records as $record) {
                if (!is_array($record)) throw new InvalidArgumentException('Invalid record in ' . $resource);
                $id = $resource === 'settings' ? (string)($record['id'] ?? 'main') : (string)($record['id'] ?? '');
                if ($id === '' || strlen($id) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
                    throw new InvalidArgumentException('Invalid record id in ' . $resource);
                }
                if ($resource === 'settings') {
                    unset($record['smsUser'], $record['smsKey'], $record['smsFrom']);
                }
                $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (strlen($payload) > 262144) throw new InvalidArgumentException('Record too large in ' . $resource);
                $insert->execute([$id, $resource, $payload, (int)$actor['id']]);
                $counts[$resource]++;
            }
        }
        $nextOrder = isset($body['nextOrder']) ? max(1, min(1000000000, (int)$body['nextOrder'])) : 1042;
        $pdo->prepare("UPDATE pos_counters SET counter_value = ? WHERE counter_key = 'order_number'")->execute([$nextOrder - 1]);
        audit($pdo, (int)$actor['id'], 'legacy_data_imported', 'migration', null, ['counts' => $counts]);
        $pdo->commit();
        json_response(['ok' => true, 'counts' => $counts, 'orders_imported' => 0]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) {
            json_response(['ok' => false, 'error' => 'invalid_import_record', 'message' => 'یکی از رکوردهای انتقال داده معتبر نیست'], 422);
        }
        error_log('[3Food POS] Legacy import failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'import_failed', 'message' => 'انتقال داده انجام نشد'], 500);
    }
}


if ($method === 'GET' && $path === '/bootstrap') {
    $user = require_user($pdo);
    if ($user['role'] === 'manager') {
        $defaults = ['قبوض آب، برق و گاز','مواد اولیه','حقوق و دستمزد','اجاره','تعمیرات و نگهداری','بسته‌بندی','حمل‌ونقل','سایر هزینه‌ها'];
        // Seed defaults once. A marker prevents intentionally deleted categories from reappearing on every refresh.
        $seeded = $pdo->prepare("SELECT id FROM pos_records WHERE resource = 'system_meta' AND id = 'expense_categories_seeded' LIMIT 1");
        $seeded->execute();
        if ($seeded->fetchColumn() === false) {
            $seed = $pdo->prepare("INSERT IGNORE INTO pos_records (id, resource, payload, updated_by) VALUES (?, 'expense_categories', ?, ?)");
            foreach ($defaults as $index => $label) {
                $id = 'expense_cat_' . ($index + 1);
                $seed->execute([$id, json_encode(['id'=>$id,'name'=>$label,'active'=>true], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$user['id']]);
            }
            $marker = $pdo->prepare("INSERT IGNORE INTO pos_records (id, resource, payload, updated_by) VALUES ('expense_categories_seeded', 'system_meta', ?, ?)");
            $marker->execute(['{"seeded":true}', (int)$user['id']]);
        }
    }
    $resources = $user['role'] === 'kitchen' ? ['categories','products'] : ($user['role'] === 'cashier' ? ['categories','products','ingredients','customers','discounts','settings','sms_logs'] : ['categories','products','ingredients','customers','discounts','expenses','expense_categories','suppliers','supplier_purchases','templates','settings','sms_logs']);
    $result = [];
    foreach ($resources as $resource) {
        $q = $pdo->prepare('SELECT id, payload FROM pos_records WHERE resource = ? ORDER BY created_at ASC');
        $q->execute([$resource]);
        $result[$resource] = array_map(static function(array $row) use ($resource, $user): array {
            $value = json_decode((string)$row['payload'], true);
            if (!is_array($value)) return [];
            // Provider credentials must never be returned by the POS data API.
            if ($resource === 'settings') {
                unset($value['smsUser'], $value['smsKey'], $value['smsFrom']);
            }
            // Cashiers need current stock for checkout warnings, but never ingredient cost data.
            if ($resource === 'ingredients' && $user['role'] === 'cashier') {
                unset($value['costPerUnit'], $value['cost'], $value['unitCost']);
            }
            return $value;
        }, $q->fetchAll());
    }
    $orders = $pdo->query('SELECT id, order_number, status, customer_id, customer_name, customer_phone, guest_name, guest_phone, payment_method, points_used, points_earned, discount_code, items, totals, note, created_at, updated_at FROM pos_orders ORDER BY id DESC')->fetchAll();
    foreach ($orders as &$order) {
        $order['id'] = (string)$order['id'];
        $order['orderNumber'] = (int)$order['order_number'];
        $order['createdAt'] = (new DateTimeImmutable((string)$order['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z');
        $order['customerId'] = $order['customer_id'];
        $order['customerName'] = $order['customer_name'];
        $order['customerPhone'] = $order['customer_phone'] ?? $order['guest_phone'] ?? '';
        $order['customerName'] = $order['customer_name'] ?: ($order['guest_name'] ?? 'مشتری');
        $order['payment'] = $order['payment_method'] ?? 'cash';
        $order['pointsUsed'] = (int)($order['points_used'] ?? 0);
        $order['pointsEarned'] = (int)($order['points_earned'] ?? 0);
        $order['discountCode'] = $order['discount_code'] ?? '';
        $order['items'] = json_decode((string)$order['items'], true) ?: [];
        $order['totals'] = json_decode((string)$order['totals'], true) ?: [];
        unset($order['order_number'], $order['customer_id'], $order['customer_name'], $order['customer_phone'], $order['guest_name'], $order['guest_phone'], $order['payment_method'], $order['points_used'], $order['points_earned'], $order['discount_code']);
        // Kitchen staff need preparation details, not customer contact data or
        // cashier/manager notes that may contain private instructions.
        if ($user['role'] === 'kitchen') {
            unset($order['customerId'], $order['customerName'], $order['customerPhone'], $order['note']);
        }
    }
    unset($order);
    $result['orders'] = $orders;
    if ($user['role'] === 'kitchen') {
        $result = ['categories' => $result['categories'] ?? [], 'products' => $result['products'] ?? [], 'orders' => $result['orders']];
    }
    $counter = (int)$pdo->query("SELECT counter_value FROM pos_counters WHERE counter_key = 'order_number'")->fetchColumn();
    json_response(['ok' => true, 'data' => $result, 'next_order' => $counter + 1, 'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'display_name' => $user['display_name'], 'role' => $user['role']]]);
}


if (preg_match('#^/suppliers/([A-Za-z0-9_-]+)/purchases$#D', $path, $supplierMatch) && $method === 'POST') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $supplierId = $supplierMatch[1];
    $body = request_json();
    $item = $body['item'] ?? null;
    if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string)($item['id'] ?? '')) ||
        !in_array($item['kind'] ?? '', ['purchase','payment'], true) ||
        trim((string)($item['title'] ?? '')) === '' ||
        !is_numeric($item['amount'] ?? null) || !is_numeric($item['paid'] ?? null)) {
        json_response(['ok'=>false,'error'=>'invalid_supplier_transaction','message'=>'اطلاعات خرید یا پرداخت معتبر نیست'],422);
    }
    $amount=(int)$item['amount']; $paid=(int)$item['paid'];
    if ($amount<0 || $paid<0 || $amount>100000000000 || $paid>100000000000 ||
        ($item['kind']==='purchase' && $paid>$amount) ||
        ($item['kind']==='payment' && ($amount!==0 || $paid===0))) {
        json_response(['ok'=>false,'error'=>'invalid_supplier_amount','message'=>'مبلغ خرید یا تسویه معتبر نیست'],422);
    }
    try {
        $pdo->beginTransaction();
        $supplierStmt=$pdo->prepare("SELECT payload FROM pos_records WHERE resource='suppliers' AND id=? FOR UPDATE");
        $supplierStmt->execute([$supplierId]); $raw=$supplierStmt->fetchColumn();
        $supplier=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($supplier) || empty($supplier['active'])) {
            $pdo->rollBack(); json_response(['ok'=>false,'error'=>'supplier_not_found','message'=>'تأمین‌کننده فعال پیدا نشد'],404);
        }
        $currentBalance=max(0,(int)($supplier['balance']??0));
        if($item['kind']==='payment' && $paid>$currentBalance){$pdo->rollBack();json_response(['ok'=>false,'error'=>'supplier_payment_exceeds_balance','message'=>'مبلغ پرداخت از بدهی تأمین‌کننده بیشتر است'],409);}
        $ingredientId=trim((string)($item['ingredientId']??''));
        $ingredientQty=max(0,(float)($item['quantity']??0));
        $ingredientUnitCost=max(0,(int)($item['unitCost']??0));
        if($ingredientId!=='' && ($item['kind']!=='purchase' || $ingredientQty<=0 || $ingredientUnitCost<=0 || abs($amount-(int)round($ingredientQty*$ingredientUnitCost))>max(1,(int)round($amount*0.01)))){
            $pdo->rollBack();json_response(['ok'=>false,'error'=>'invalid_inventory_purchase','message'=>'مقدار یا قیمت ماده اولیه با مبلغ خرید هم‌خوانی ندارد'],422);
        }
        if($ingredientId!==''){
            $ingredientStmt=$pdo->prepare("SELECT payload FROM pos_records WHERE resource='ingredients' AND id=? FOR UPDATE");
            $ingredientStmt->execute([$ingredientId]);$ingredientRaw=$ingredientStmt->fetchColumn();
            $ingredient=is_string($ingredientRaw)?json_decode($ingredientRaw,true):null;
            if(!is_array($ingredient)){$pdo->rollBack();json_response(['ok'=>false,'error'=>'ingredient_not_found','message'=>'ماده اولیه انتخاب‌شده پیدا نشد'],404);}
            $oldStock=(float)($ingredient['stock']??0);$oldCost=max(0,(float)($ingredient['costPerUnit']??0));
            $newStock=round($oldStock+$ingredientQty,3);
            $newCost=$oldStock>0?round((($oldStock*$oldCost)+($ingredientQty*$ingredientUnitCost))/($oldStock+$ingredientQty),2):$ingredientUnitCost;
            $ingredient['stock']=$newStock;$ingredient['costPerUnit']=$newCost;
            $ingredientUpdate=$pdo->prepare("UPDATE pos_records SET payload=?,updated_by=? WHERE resource='ingredients' AND id=?");
            $ingredientUpdate->execute([json_encode($ingredient,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$actor['id'],$ingredientId]);
            $item['ingredientId']=$ingredientId;$item['quantity']=$ingredientQty;$item['unitCost']=$ingredientUnitCost;
            $item['stockAfter']=$newStock;$item['weightedCostAfter']=$newCost;
        }
        $item['supplierId']=$supplierId;
        $tehranNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        $item['date']=$tehranNow->format('Y-m-d');
        $item['createdAt']=$tehranNow->format('c');
        $balance=max(0,$currentBalance+($item['kind']==='purchase'?$amount-$paid:-$paid));
        $supplier['balance']=$balance;
        $payload=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $insert=$pdo->prepare("INSERT INTO pos_records (id,resource,payload,updated_by) VALUES (?,'supplier_purchases',?,?)");
        $insert->execute([(string)$item['id'],$payload,(int)$actor['id']]);
        $update=$pdo->prepare("UPDATE pos_records SET payload=?,updated_by=? WHERE resource='suppliers' AND id=?");
        $update->execute([json_encode($supplier,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$actor['id'],$supplierId]);
        audit($pdo,(int)$actor['id'],$item['kind']==='purchase'?'supplier_purchase_recorded':'supplier_payment_recorded','supplier',$supplierId,['amount'=>$amount,'paid'=>$paid,'balance'=>$balance]);
        $pdo->commit();
        json_response(['ok'=>true,'item'=>$item,'supplier'=>$supplier]);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        error_log('[3Food POS] Supplier transaction failed: '.$e->getMessage());
        json_response(['ok'=>false,'error'=>'supplier_transaction_failed','message'=>'ثبت خرید یا تسویه انجام نشد'],500);
    }
}

if (preg_match('#^/records/([a-z_]+)$#', $path, $m)) {
    $resource = $m[1];
    if (!isset($resourceRoles[$resource])) json_response(['ok' => false, 'error' => 'resource_not_found'], 404);
    $actor = require_user($pdo);
    if (!in_array($actor['role'], $resourceRoles[$resource], true)) json_response(['ok' => false, 'error' => 'forbidden'], 403);
    if ($method === 'GET') {
        $q = $pdo->prepare('SELECT payload FROM pos_records WHERE resource = ? ORDER BY created_at ASC');
        $q->execute([$resource]);
        $rows = [];
        foreach ($q->fetchAll() as $row) {
            $v = json_decode((string)$row['payload'], true);
            if (!is_array($v)) continue;
            // Keep SMS gateway credentials server-side, even for manager reads.
            if ($resource === 'settings') unset($v['smsUser'], $v['smsKey'], $v['smsFrom']);
            $rows[] = $v;
        }
        json_response(['ok' => true, 'items' => $rows]);
    }
    if ($method === 'POST') {
        require_csrf();
        $body = request_json();
        $item = $body['item'] ?? null;
        if (!is_array($item) || !isset($item['id']) || !is_string($item['id']) && !is_int($item['id'])) {
            json_response(['ok' => false, 'error' => 'invalid_item'], 422);
        }
        // Never truncate identifiers: two distinct client IDs must not silently
        // collide into the same MySQL record key.
        $id = (string)$item['id'];
        if ($id === '' || strlen($id) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/D', $id)) {
            json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        }
        // SMS gateway credentials are server-only configuration. Ignore legacy
        // browser fields on writes as well as reads, so they cannot be persisted
        // or echoed back through the generic settings-record endpoint.
        if ($resource === 'settings') {
            unset($item['smsUser'], $item['smsKey'], $item['smsFrom']);
            $allowedStockPolicies = ['block', 'warn_and_allow', 'allow_negative'];
            if (array_key_exists('stockPolicy', $item)) {
                if (!is_string($item['stockPolicy']) || !in_array($item['stockPolicy'], $allowedStockPolicies, true)) {
                    json_response(['ok' => false, 'error' => 'invalid_stock_policy', 'message' => 'سیاست موجودی انتخاب‌شده معتبر نیست'], 422);
                }
            } else {
                $existingSettingsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = ? LIMIT 1");
                $existingSettingsStmt->execute([$id]);
                $existingSettingsRaw = $existingSettingsStmt->fetchColumn();
                $existingSettings = is_string($existingSettingsRaw) ? json_decode($existingSettingsRaw, true) : null;
                $existingPolicy = is_array($existingSettings) ? ($existingSettings['stockPolicy'] ?? null) : null;
                $item['stockPolicy'] = is_string($existingPolicy) && in_array($existingPolicy, $allowedStockPolicies, true) ? $existingPolicy : 'warn_and_allow';
            }
        }
        // Cashiers may maintain customer contact details, but loyalty balances
        // are server-owned and must never be writable through generic CRUD.
        $cashierCustomerWrite = $resource === 'customers' && $actor['role'] === 'cashier';
        if ($cashierCustomerWrite) {
            $pdo->beginTransaction();
            $currentCustomer = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
            $currentCustomer->execute([$id]);
            $currentPayload = $currentCustomer->fetchColumn();
            $current = is_string($currentPayload) ? json_decode($currentPayload, true) : null;
            if (is_array($current)) {
                foreach (['points', 'totalSpent', 'visits', 'createdAt'] as $serverField) {
                    if (array_key_exists($serverField, $current)) $item[$serverField] = $current[$serverField];
                    else unset($item[$serverField]);
                }
            } else {
                $item['points'] = 0;
                $item['totalSpent'] = 0;
                $item['visits'] = 0;
                $item['createdAt'] = gmdate('c');
            }
        }
        $payload = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 262144) {
            if ($cashierCustomerWrite && $pdo->inTransaction()) $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'record_too_large', 'message' => 'حجم اطلاعات ارسالی بیش از حد مجاز است'], 413);
        }
        $q = $pdo->prepare('INSERT INTO pos_records (id, resource, payload, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_by = VALUES(updated_by)');
        $q->execute([$id, $resource, $payload, (int)$actor['id']]);
        audit($pdo, (int)$actor['id'], 'record_saved', $resource, $id);
        if ($cashierCustomerWrite) $pdo->commit();
        json_response(['ok' => true, 'item' => $item], 201);
    }
    if ($method === 'DELETE') {
        // Cashiers may maintain customer details during checkout, but destructive
        // record removal remains a manager-only operation.
        if ($resource === 'customers' && $actor['role'] !== 'manager') {
            json_response(['ok' => false, 'error' => 'forbidden'], 403);
        }
        require_csrf();
        $id = (string)($_GET['id'] ?? '');
        if ($id === '' || strlen($id) > 100) json_response(['ok' => false, 'error' => 'invalid_id'], 422);
        $q = $pdo->prepare('DELETE FROM pos_records WHERE resource = ? AND id = ?');
        $q->execute([$resource, $id]);
        audit($pdo, (int)$actor['id'], 'record_deleted', $resource, $id);
        json_response(['ok' => true]);
    }
}

if ($method === 'POST' && $path === '/orders') {
    $actor = require_role($pdo, ['manager','cashier']);
    require_csrf();
    $body = request_json();
    $items = $body['items'] ?? null;
    $totals = $body['totals'] ?? null;
    $idempotencyKey = isset($body['idempotencyKey']) && is_string($body['idempotencyKey'])
        ? strtolower(trim($body['idempotencyKey']))
        : '';
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $idempotencyKey)) {
        json_response(['ok' => false, 'error' => 'invalid_idempotency_key', 'message' => 'شناسه یکتای ثبت سفارش معتبر نیست؛ دوباره تلاش کنید'], 422);
    }
    $requestHash = hash('sha256', json_encode([
        'items' => $items,
        'totals' => $totals,
        'customer' => $body['customer'] ?? null,
        'discountCode' => strtoupper(trim((string)($body['discountCode'] ?? ''))),
        'note' => $body['note'] ?? null,
        'guestName' => $body['guestName'] ?? null,
        'guestPhone' => $body['guestPhone'] ?? null,
        'payment' => $body['payment'] ?? 'cash',
        'terminalPaymentId' => $body['terminalPaymentId'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if ($idempotencyKey !== null) {
        $existingStmt = $pdo->prepare('SELECT id, order_number, status, items, totals, request_hash, created_at FROM pos_orders WHERE idempotency_key = ? LIMIT 1');
        $existingStmt->execute([$idempotencyKey]);
        $existing = $existingStmt->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                json_response(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'این شناسه قبلاً برای سفارش دیگری استفاده شده است'], 409);
            }
            json_response(['ok' => true, 'replayed' => true, 'order' => [
                'id' => (string)$existing['id'],
                'orderNumber' => (int)$existing['order_number'],
                'status' => (string)$existing['status'],
                'createdAt' => (new DateTimeImmutable((string)$existing['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z'),
                'items' => json_decode((string)$existing['items'], true) ?: [],
                'totals' => json_decode((string)$existing['totals'], true) ?: [],
            ]]);
        }
    }
    if (!is_array($items) || count($items) < 1 || count($items) > 100 || !is_array($totals)) json_response(['ok' => false, 'error' => 'invalid_order', 'message' => 'اقلام یا مبلغ سفارش معتبر نیست'], 422);
    $serverSubtotal = 0;
    $ingredientNeeds = [];
    $canonicalItems = [];
    $productSnapshots = [];
    $productLookup = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'products' AND id = ? LIMIT 1");
    foreach ($items as $line) {
        if (!is_array($line) || !isset($line['productId'], $line['qty']) || !is_numeric($line['qty']) || (float)$line['qty'] <= 0 || (float)$line['qty'] > 999 || floor((float)$line['qty']) !== (float)$line['qty']) {
            json_response(['ok' => false, 'error' => 'invalid_order_item'], 422);
        }
        $productLookup->execute([(string)$line['productId']]);
        $productRaw = $productLookup->fetchColumn();
        $product = is_string($productRaw) ? json_decode($productRaw, true) : null;
        if (!is_array($product) || empty($product['active'])) json_response(['ok' => false, 'error' => 'product_unavailable', 'message' => 'یکی از محصولات سفارش فعال نیست'], 409);
        $productId = (string)$line['productId'];
        $productSnapshots[$productId] = hash('sha256', (string)$productRaw);
        // Derive ingredient consumption from the server-side recipe, never from browser input.
        foreach (($product['recipe'] ?? []) as $recipeLine) {
            if (!is_array($recipeLine) || !isset($recipeLine['ingredientId'], $recipeLine['qty'])) continue;
            $ingredientId = (string)$recipeLine['ingredientId'];
            $recipeQty = (float)$recipeLine['qty'];
            if ($ingredientId !== '' && $recipeQty > 0 && is_finite($recipeQty)) {
                $ingredientNeeds[$ingredientId] = ($ingredientNeeds[$ingredientId] ?? 0) + ($recipeQty * (int)$line['qty']);
            }
        }
        $basePrice = filter_var($product['price'] ?? null, FILTER_VALIDATE_INT);
        if ($basePrice === false || $basePrice < 0) json_response(['ok' => false, 'error' => 'invalid_product_price'], 409);
        $unitPrice = $basePrice;
        $validOptions = [];
        foreach (($product['options'] ?? []) as $option) {
            if (!is_array($option) || !isset($option['id'])) continue;
            $optionPrice = filter_var($option['price'] ?? 0, FILTER_VALIDATE_INT);
            if ($optionPrice === false) continue;
            $validOptions[(string)$option['id']] = ['price' => $optionPrice, 'name' => substr((string)($option['name'] ?? ''), 0, 120)];
        }
        $rawOptionIds = $line['optionIds'] ?? [];
        if (!is_array($rawOptionIds) || count($rawOptionIds) > 30) json_response(['ok' => false, 'error' => 'invalid_product_option'], 422);
        $optionIds = array_map('strval', $rawOptionIds);
        if (count($optionIds) !== count(array_unique($optionIds))) json_response(['ok' => false, 'error' => 'duplicate_product_option'], 422);
        $optionLabels = [];
        foreach ($optionIds as $optionId) {
            if (!array_key_exists($optionId, $validOptions)) json_response(['ok' => false, 'error' => 'invalid_product_option'], 422);
            $unitPrice += $validOptions[$optionId]['price'];
            $optionLabels[] = $validOptions[$optionId]['name'];
        }
        if ($unitPrice < 0 || $unitPrice > 100000000000) json_response(['ok' => false, 'error' => 'invalid_order_item_price'], 422);
        if (isset($line['unitPrice']) && (!is_numeric($line['unitPrice']) || (float)$line['unitPrice'] !== (float)$unitPrice)) json_response(['ok' => false, 'error' => 'price_changed', 'message' => 'قیمت محصول تغییر کرده است؛ سبد را تازه‌سازی کنید'], 409);
        $serverSubtotal += $unitPrice * (int)$line['qty'];
        $clientKey = isset($line['key']) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string)$line['key']) ? (string)$line['key'] : 'line-' . count($canonicalItems);
        $canonicalItems[] = [
            'key' => $clientKey,
            'name' => substr((string)($product['name'] ?? 'محصول'), 0, 160),
            'productId' => $productId,
            'qty' => (int)$line['qty'],
            'unitPrice' => $unitPrice,
            'unitCost' => product_unit_cost($pdo,$product),
            'optionIds' => $optionIds,
            'optionLabels' => $optionLabels,
            'note' => isset($line['note']) ? substr(trim((string)$line['note']), 0, 500) : '',
        ];
    }
    $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
    $customerId = isset($customer['id']) ? (string)$customer['id'] : '';
    $customerRecord = null;
    if ($customerId !== '') {
        if (strlen($customerId) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/D', $customerId)) {
            json_response(['ok' => false, 'error' => 'invalid_customer'], 422);
        }
        $customerStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? LIMIT 1");
        $customerStmt->execute([$customerId]);
        $customerRaw = $customerStmt->fetchColumn();
        $customerRecord = is_string($customerRaw) ? json_decode($customerRaw, true) : null;
        if (!is_array($customerRecord)) json_response(['ok' => false, 'error' => 'customer_not_found', 'message' => 'مشتری انتخاب‌شده پیدا نشد؛ فهرست مشتریان را تازه‌سازی کنید'], 409);
    }
    $customerName = is_array($customerRecord) ? substr((string)($customerRecord['name'] ?? ''), 0, 160) : null;
    $customerPhone = is_array($customerRecord) ? substr((string)($customerRecord['phone'] ?? ''), 0, 30) : null;
    $guestName = $customerId === '' ? trim((string)($body['guestName'] ?? 'مشتری')) : null;
    $guestPhone = $customerId === '' ? trim((string)($body['guestPhone'] ?? '')) : null;
    $payment = (string)($body['payment'] ?? 'cash');
    $terminalPaymentId = trim((string)($body['terminalPaymentId'] ?? ''));
    if ($payment === 'online') {
        json_response(['ok' => false, 'error' => 'online_payment_unavailable', 'message' => 'پرداخت آنلاین تا اتصال و تأیید واقعی درگاه فعال نیست؛ از پرداخت نقدی یا کارتخوان استفاده کنید'], 409);
    }
    if (!in_array($payment, ['cash','card'], true) || utf8_length($guestName ?? '') > 160 || strlen($guestPhone ?? '') > 30 ||
        ($payment === 'card' && !valid_uuid($terminalPaymentId)) || ($payment !== 'card' && $terminalPaymentId !== '')) {
        json_response(['ok' => false, 'error' => 'invalid_order_details', 'message' => 'اطلاعات مشتری یا روش پرداخت معتبر نیست'], 422);
    }
    $discountCode = strtoupper(trim((string)($body['discountCode'] ?? '')));
    if (strlen($discountCode) > 64 || ($discountCode !== '' && !preg_match('/^[A-Z0-9_-]+$/D', $discountCode))) {
        json_response(['ok' => false, 'error' => 'invalid_discount_code'], 422);
    }
    $discountRecordId = null;
    $discountRecord = null;
    if ($discountCode !== '') {
        $discountRows = $pdo->query("SELECT id, payload FROM pos_records WHERE resource = 'discounts'")->fetchAll();
        foreach ($discountRows as $discountRow) {
            $candidate = json_decode((string)$discountRow['payload'], true);
            if (is_array($candidate) && strtoupper(trim((string)($candidate['code'] ?? ''))) === $discountCode) {
                $discountRecordId = (string)$discountRow['id'];
                $discountRecord = $candidate;
                break;
            }
        }
        if (!is_array($discountRecord)) json_response(['ok' => false, 'error' => 'discount_not_found', 'message' => 'کد تخفیف معتبر نیست'], 422);
    }
    $totalFields = ['subtotal', 'tierDiscount', 'codeDiscount', 'pointsDiscount', 'tax', 'total'];
    foreach ($totalFields as $key) {
        if (!isset($totals[$key]) || !is_numeric($totals[$key]) || (float)$totals[$key] < 0 || (float)$totals[$key] > 100000000000 || floor((float)$totals[$key]) !== (float)$totals[$key]) {
            json_response(['ok' => false, 'error' => 'invalid_order_total', 'message' => 'مقادیر مالی سفارش معتبر نیست'], 422);
        }
    }
    if ((int)$totals['subtotal'] !== (int)$serverSubtotal) json_response(['ok' => false, 'error' => 'subtotal_mismatch', 'message' => 'جمع سفارش با قیمت محصولات مطابقت ندارد'], 409);
    $subtotal = (int)$totals['subtotal'];
    $tierDiscount = (int)$totals['tierDiscount'];
    $codeDiscount = (int)$totals['codeDiscount'];
    $pointsDiscount = (int)$totals['pointsDiscount'];
    $discountTotal = $tierDiscount + $codeDiscount + $pointsDiscount;
    $tierSettingsRaw=$pdo->query("SELECT payload FROM pos_records WHERE resource='settings' AND id='main' LIMIT 1")->fetchColumn();
    $tierSettings=is_string($tierSettingsRaw)?json_decode($tierSettingsRaw,true):[];if(!is_array($tierSettings))$tierSettings=[];
    $customerPoints = max(0, (int)($customerRecord['points'] ?? 0));
    $tierRate = loyalty_discount_rate($customerPoints,$tierSettings);
    $expectedTierDiscount = (int)round($subtotal * $tierRate / 100);
    $expectedCodeDiscount = 0;
    if ($discountCode !== '') {
        $discountType = (string)($discountRecord['type'] ?? '');
        $discountValue = (float)($discountRecord['value'] ?? 0);
        $maxDiscount = max(0, (int)($discountRecord['maxDiscount'] ?? 0));
        $minOrder = max(0, (int)($discountRecord['minOrder'] ?? 0));
        $maxUses = max(0, (int)($discountRecord['maxUses'] ?? 0));
        $usedCount = max(0, (int)($discountRecord['usedCount'] ?? 0));
        $perCustomer = max(0, (int)($discountRecord['perCustomer'] ?? 0));
        $today = date('Y-m-d');
        $startsAt = trim((string)($discountRecord['startsAt'] ?? ''));
        $endsAt = trim((string)($discountRecord['endsAt'] ?? ''));
        $audience = (string)($discountRecord['audience'] ?? 'all');
        $tier = loyalty_tier($customerPoints,$tierSettings);
        if (!in_array($discountType, ['percent','fixed'], true) || !is_finite($discountValue) || $discountValue <= 0 || ($discountType === 'percent' && $discountValue > 100)) {
            json_response(['ok' => false, 'error' => 'invalid_discount_config', 'message' => 'تعریف کد تخفیف معتبر نیست'], 409);
        }
        if (($startsAt !== '' && $today < $startsAt) || ($endsAt !== '' && $today > $endsAt)) json_response(['ok' => false, 'error' => 'discount_out_of_date', 'message' => 'کد تخفیف در این تاریخ فعال نیست'], 409);
        if ($maxUses > 0 && $usedCount >= $maxUses) json_response(['ok' => false, 'error' => 'discount_limit_reached', 'message' => 'سقف استفاده از این کد تمام شده است'], 409);
        if ($subtotal - $expectedTierDiscount < $minOrder) json_response(['ok' => false, 'error' => 'discount_minimum_not_met', 'message' => 'مبلغ سفارش به حداقل لازم برای این کد نرسیده است'], 409);
        if ($audience === 'club' && $customerId === '') json_response(['ok' => false, 'error' => 'discount_customer_required', 'message' => 'این کد فقط برای اعضای باشگاه است'], 409);
        if (in_array($audience, ['bronze','silver','gold','platinum'], true) && ($customerId === '' || $tier !== $audience)) json_response(['ok' => false, 'error' => 'discount_audience_mismatch', 'message' => 'این کد برای سطح عضویت مشتری قابل استفاده نیست'], 409);
        if (!in_array($audience, ['all','club','bronze','silver','gold','platinum'], true)) json_response(['ok' => false, 'error' => 'invalid_discount_audience'], 409);
        $afterTierForCode = max(0, $subtotal - $expectedTierDiscount);
        $expectedCodeDiscount = $discountType === 'percent'
            ? (int)round($afterTierForCode * $discountValue / 100)
            : min($afterTierForCode, (int)round($discountValue));
        if ($discountType === 'percent' && $maxDiscount > 0) $expectedCodeDiscount = min($expectedCodeDiscount, $maxDiscount);
    }
    $settingsForPointsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
    $settingsForPointsStmt->execute();
    $rawPointsSettings = $settingsForPointsStmt->fetchColumn();
    $pointsSettings = is_string($rawPointsSettings) ? json_decode($rawPointsSettings, true) : [];
    $tomanPerPointCheck = max(1, (int)($pointsSettings['tomanPerPoint'] ?? 1));
    $expectedPointsDiscount = min(max(0, $subtotal - $expectedTierDiscount - $expectedCodeDiscount), $customerPoints * $tomanPerPointCheck);
    if ($pointsDiscount > $expectedPointsDiscount) json_response(['ok' => false, 'error' => 'invalid_points_discount', 'message' => 'مقدار تخفیف امتیازی معتبر نیست'], 409);
    $taxSettingsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
    $taxSettingsStmt->execute();
    $rawTaxSettings = $taxSettingsStmt->fetchColumn();
    $taxSettings = is_string($rawTaxSettings) ? json_decode($rawTaxSettings, true) : [];
    if (!is_array($taxSettings)) $taxSettings = [];
    $taxEnabled = !array_key_exists('taxEnabled', $taxSettings) || $taxSettings['taxEnabled'] !== false;
    $taxPercent = (float)($taxSettings['taxPercent'] ?? 0);
    $stockPolicy = (string)($taxSettings['stockPolicy'] ?? 'warn_and_allow');
    if (!in_array($stockPolicy, ['block', 'warn_and_allow', 'allow_negative'], true)) $stockPolicy = 'warn_and_allow';
    if (!is_finite($taxPercent) || $taxPercent < 0 || $taxPercent > 100) {
        json_response(['ok' => false, 'error' => 'invalid_tax_settings', 'message' => 'تنظیم درصد مالیات معتبر نیست'], 409);
    }
    $taxableAmount = max(0, $subtotal - $discountTotal);
    $expectedTax = $taxEnabled ? (int)round($taxableAmount * $taxPercent / 100) : 0;
    // Membership tiers in the POS model grant at most 8% (platinum).
    // Keep client-supplied discounts within their mathematically possible bases.
    $maxTierDiscount = $expectedTierDiscount;
    $afterTier = $subtotal - $tierDiscount;
    $afterCode = $afterTier - $codeDiscount;
    if (
        $tierDiscount !== $expectedTierDiscount ||
        $codeDiscount !== $expectedCodeDiscount ||
        $pointsDiscount > $afterCode ||
        $discountTotal > $subtotal ||
        (int)$totals['tax'] !== $expectedTax ||
        (int)$totals['total'] !== $subtotal - $discountTotal + (int)$totals['tax']
    ) {
        json_response(['ok' => false, 'error' => 'order_total_mismatch', 'message' => 'تخفیف‌ها یا محاسبه مبلغ نهایی سفارش معتبر نیست'], 422);
    }
    // Canonical customer details were loaded before validating tier and coupon discounts.
    if ($pointsDiscount > 0 && $customerId === '') {
        json_response(['ok' => false, 'error' => 'points_require_customer', 'message' => 'برای استفاده از امتیاز باید مشتری انتخاب شود'], 422);
    }
    try {
        $pdo->beginTransaction();

        if($payment==='card'){
            $terminalPaymentLock=$pdo->prepare("SELECT id FROM pos_terminal_payments WHERE id=? AND created_by=? AND amount=? AND status='approved' AND order_id IS NULL FOR UPDATE");
            $terminalPaymentLock->execute([$terminalPaymentId,(int)$actor['id'],(int)$totals['total']]);
            if(!$terminalPaymentLock->fetchColumn()){
                $pdo->rollBack();
                json_response(['ok'=>false,'error'=>'terminal_payment_not_approved','message'=>'برای ثبت پرداخت کارتی، تأیید معتبر کارتخوان با همین مبلغ الزامی است'],409);
            }
        }
        // Lock product rows and ensure price, recipe and options did not change after quoting.
        $productLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'products' AND id = ? FOR UPDATE");
        foreach ($productSnapshots as $productId => $snapshotHash) {
            $productLock->execute([$productId]);
            $lockedProductRaw = $productLock->fetchColumn();
            if (!is_string($lockedProductRaw) || !hash_equals($snapshotHash, hash('sha256', $lockedProductRaw))) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'product_changed_requote', 'message' => 'اطلاعات یا قیمت یکی از محصولات تغییر کرده است؛ سبد را دوباره محاسبه کنید'], 409);
            }
        }

        $pointsToSpend = 0;
        $pointsEarned = 0;

        if ($discountCode !== '' && $discountRecordId !== null) {
            $discountLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'discounts' AND id = ? FOR UPDATE");
            $discountLock->execute([$discountRecordId]);
            $lockedDiscountRaw = $discountLock->fetchColumn();
            $lockedDiscount = is_string($lockedDiscountRaw) ? json_decode($lockedDiscountRaw, true) : null;
            if (!is_array($lockedDiscount) || strtoupper(trim((string)($lockedDiscount['code'] ?? ''))) !== $discountCode) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'discount_changed', 'message' => 'کد تخفیف تغییر کرده است؛ دوباره بررسی کنید'], 409);
            }
            foreach (['type','value','maxDiscount','minOrder','maxUses','perCustomer','startsAt','endsAt','audience'] as $ruleField) {
                if (($lockedDiscount[$ruleField] ?? null) !== ($discountRecord[$ruleField] ?? null)) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'discount_changed', 'message' => 'قوانین کد تخفیف هم‌زمان تغییر کرده است؛ سفارش را دوباره محاسبه کنید'], 409);
                }
            }
            $lockedMaxUses = max(0, (int)($lockedDiscount['maxUses'] ?? 0));
            $lockedUsedCount = max(0, (int)($lockedDiscount['usedCount'] ?? 0));
            $globalRedemptionsStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_discount_redemptions WHERE discount_id = ? AND reversed_at IS NULL');
            $globalRedemptionsStmt->execute([$discountRecordId]);
            $globalRedemptions = (int)$globalRedemptionsStmt->fetchColumn();
            $authoritativeUsedCount = max($lockedUsedCount, $globalRedemptions);
            if ($lockedMaxUses > 0 && $authoritativeUsedCount >= $lockedMaxUses) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'discount_limit_reached', 'message' => 'آخرین سهمیه این کد هم‌زمان مصرف شد'], 409);
            }
            $lockedPerCustomer = max(0, (int)($lockedDiscount['perCustomer'] ?? 0));
            if ($lockedPerCustomer > 0 && $customerId !== '') {
                $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_discount_redemptions WHERE discount_id = ? AND customer_id = ? AND reversed_at IS NULL');
                $usageStmt->execute([$discountRecordId, $customerId]);
                if ((int)$usageStmt->fetchColumn() >= $lockedPerCustomer) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'discount_customer_limit', 'message' => 'سهمیه استفاده این مشتری از کد تمام شده است'], 409);
                }
            }
            $lockedDiscount['usedCount'] = $authoritativeUsedCount + 1;
            $discountUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'discounts' AND id = ?");
            $discountUpdate->execute([json_encode($lockedDiscount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $discountRecordId]);
        }

        // Lock ingredients in a stable order to reduce deadlocks between concurrent checkouts.
        ksort($ingredientNeeds, SORT_STRING);
        // Lock and deduct every recipe ingredient in the same transaction as order creation.
        $ingredientSelect = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = ? FOR UPDATE");
        $ingredientUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'ingredients' AND id = ?");
        foreach ($ingredientNeeds as $ingredientId => $needed) {
            $ingredientSelect->execute([$ingredientId]);
            $rawIngredient = $ingredientSelect->fetchColumn();
            $ingredient = is_string($rawIngredient) ? json_decode($rawIngredient, true) : null;
            if (!is_array($ingredient)) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'ingredient_not_found', 'message' => 'یکی از مواد اولیه دستور محصول در انبار پیدا نشد'], 409);
            }
            $available = (float)($ingredient['stock'] ?? 0);
            if ($stockPolicy === 'block' && $available + 0.000001 < $needed) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'insufficient_stock', 'message' => 'موجودی یکی از مواد اولیه کافی نیست: ' . (string)($ingredient['name'] ?? $ingredientId)], 409);
            }
            // warn_and_allow keeps the operator informed in the POS; allow_negative skips the warning.
            // Negative stock is retained as an explicit backorder/shortage signal for replenishment reports.
            $ingredient['stock'] = round($available - $needed, 3);
            $ingredientUpdate->execute([
                json_encode($ingredient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (int)$actor['id'],
                $ingredientId,
            ]);
        }

        // Serialize loyalty balance changes so two cashiers cannot spend the same points.
        if ($customerId !== '') {
            $customerLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
            $customerLock->execute([$customerId]);
            $rawCustomer = $customerLock->fetchColumn();
            $lockedCustomer = is_string($rawCustomer) ? json_decode($rawCustomer, true) : null;
            if (!is_array($lockedCustomer)) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'customer_not_found', 'message' => 'مشتری انتخاب‌شده پیدا نشد؛ فهرست مشتریان را تازه‌سازی کنید'], 409);
            }
            $settingsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
            $settingsStmt->execute();
            $rawSettings = $settingsStmt->fetchColumn();
            $settings = is_string($rawSettings) ? json_decode($rawSettings, true) : [];
            $tomanPerPoint = max(1, (int)($settings['tomanPerPoint'] ?? 1));
            $pointsPerToman = max(1, (int)($settings['pointsPerToman'] ?? 100000));
            $pointsToSpend = $pointsDiscount > 0 ? (int)ceil($pointsDiscount / $tomanPerPoint) : 0;
            $customerName = substr((string)($lockedCustomer['name'] ?? $customerName ?? ''), 0, 160);
            $customerPhone = substr((string)($lockedCustomer['phone'] ?? $customerPhone ?? ''), 0, 30);
            $currentPoints = max(0, (int)($lockedCustomer['points'] ?? 0));
            if ($currentPoints !== $customerPoints) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'customer_changed_requote', 'message' => 'امتیاز یا سطح عضویت مشتری تغییر کرده است؛ سفارش را دوباره محاسبه کنید'], 409);
            }
            if ($pointsToSpend > $currentPoints || ($pointsToSpend * $tomanPerPoint) < $pointsDiscount) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'insufficient_points', 'message' => 'امتیاز مشتری برای این تخفیف کافی نیست؛ اطلاعات مشتری را تازه‌سازی کنید'], 409);
            }
            $pointsEarned = (int)floor(((int)$totals['total']) / $pointsPerToman);
            $lockedCustomer['points'] = $currentPoints - $pointsToSpend + $pointsEarned;
            $lockedCustomer['totalSpent'] = (int)($lockedCustomer['totalSpent'] ?? 0) + (int)$totals['total'];
            $lockedCustomer['visits'] = (int)($lockedCustomer['visits'] ?? 0) + 1;
            $customerUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'customers' AND id = ?");
            $customerUpdate->execute([
                json_encode($lockedCustomer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (int)$actor['id'],
                $customerId,
            ]);
        }

        $counter = $pdo->query("SELECT counter_value FROM pos_counters WHERE counter_key = 'order_number' FOR UPDATE")->fetchColumn();
        if ($counter === false) throw new RuntimeException('Order counter missing');
        $orderNumber = (int)$counter + 1;
        $pdo->prepare("UPDATE pos_counters SET counter_value = ? WHERE counter_key = 'order_number'")->execute([$orderNumber]);
        $stmt = $pdo->prepare('INSERT INTO pos_orders (order_number, status, idempotency_key, request_hash, customer_id, customer_name, customer_phone, guest_name, guest_phone, payment_method, points_used, points_earned, discount_code, items, totals, note, created_by) VALUES (?, \'new\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $orderNumber,
            $idempotencyKey,
            $idempotencyKey !== null ? $requestHash : null,
            $customerId !== '' ? $customerId : null,
            $customerName,
            $customerPhone,
            $guestName,
            $guestPhone,
            $payment,
            $pointsToSpend,
            $pointsEarned,
            $discountCode !== '' ? $discountCode : null,
            json_encode($canonicalItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            isset($body['note']) ? substr((string)$body['note'], 0, 2000) : null,
            (int)$actor['id'],
        ]);
        $id = (int)$pdo->lastInsertId();
        if($payment==='card'){
            $linkPayment=$pdo->prepare('UPDATE pos_terminal_payments SET order_id=? WHERE id=? AND order_id IS NULL');
            $linkPayment->execute([$id,$terminalPaymentId]);
            if($linkPayment->rowCount()!==1) throw new RuntimeException('Approved terminal payment could not be linked to the order');
        }
        if ($discountCode !== '' && $discountRecordId !== null) {
            $redemptionInsert = $pdo->prepare('INSERT INTO pos_discount_redemptions (order_id, discount_id, code, customer_id) VALUES (?, ?, ?, ?)');
            $redemptionInsert->execute([$id, $discountRecordId, $discountCode, $customerId !== '' ? $customerId : null]);
        }

        // Persist the exact stock and loyalty effects so cancellation can reverse
        // them once, inside the same transaction as the status change.
        $effectInsert = $pdo->prepare('INSERT INTO pos_order_effects (order_id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($ingredientNeeds as $ingredientId => $needed) {
            if ((float)$needed > 0) $effectInsert->execute([$id, 'ingredient', $ingredientId, round((float)$needed, 3), 0, 0, 0, 0]);
        }
        if ($customerId !== '') {
            $effectInsert->execute([$id, 'customer', $customerId, 0, $pointsToSpend, $pointsEarned, (int)$totals['total'], 1]);
        }
        audit($pdo, (int)$actor['id'], 'order_created', 'order', (string)$id, ['order_number' => $orderNumber]);
        $pdo->commit();
        json_response(['ok' => true, 'order' => ['id' => (string)$id, 'orderNumber' => $orderNumber, 'status' => 'new', 'createdAt' => gmdate('Y-m-d\\TH:i:s\\Z'), 'items' => $canonicalItems, 'totals' => $totals]], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A concurrent retry may win the unique idempotency key after our initial lookup.
        if ($idempotencyKey !== null && $e instanceof PDOException && (string)$e->getCode() === '23000') {
            $retryStmt = $pdo->prepare('SELECT id, order_number, status, items, totals, request_hash, created_at FROM pos_orders WHERE idempotency_key = ? LIMIT 1');
            $retryStmt->execute([$idempotencyKey]);
            $retry = $retryStmt->fetch();
            if (is_array($retry)) {
                if (!hash_equals((string)$retry['request_hash'], $requestHash)) {
                    json_response(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'این شناسه قبلاً برای سفارش دیگری استفاده شده است'], 409);
                }
                json_response(['ok' => true, 'replayed' => true, 'order' => [
                    'id' => (string)$retry['id'],
                    'orderNumber' => (int)$retry['order_number'],
                    'status' => (string)$retry['status'],
                    'createdAt' => (new DateTimeImmutable((string)$retry['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s\\Z'),
                    'items' => json_decode((string)$retry['items'], true) ?: [],
                    'totals' => json_decode((string)$retry['totals'], true) ?: [],
                ]]);
            }
        }
        error_log('[3Food POS] Order creation failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'order_create_failed', 'message' => 'ثبت سفارش انجام نشد'], 500);
    }
}

if (preg_match('#^/orders/(\d+)$#', $path, $editMatch) && $method === 'PATCH') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $body = request_json();
    $id = (int)$editMatch[1];
    $pdo->beginTransaction();
    $editOrderStmt = $pdo->prepare('SELECT * FROM pos_orders WHERE id = ? FOR UPDATE');
    $editOrderStmt->execute([$id]);
    $existingOrder = $editOrderStmt->fetch();
    if (!is_array($existingOrder)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'order_not_found'], 404);
    }
    if ((string)$existingOrder['status'] !== 'new') {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'order_not_editable', 'message' => 'فقط سفارش‌های جدید قابل ویرایش هستند'], 409);
    }
    $storedPaymentMethod = (string)($existingOrder['payment_method'] ?? 'cash');
    if (array_key_exists('payment', $body) && (string)$body['payment'] !== $storedPaymentMethod) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'payment_method_change_requires_new_transaction', 'message' => 'روش پرداخت سفارش پس از ثبت قابل تغییر نیست؛ پرداخت جدید را جداگانه و با تأیید معتبر ثبت کنید'], 409);
    }
    if (in_array($storedPaymentMethod, ['card','online'], true)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'payment_reconciliation_required', 'message' => 'سفارش دارای پرداخت کارتخوان یا روش آنلاین قدیمی تا تعیین‌تکلیف مالی و بازپرداخت قابل ویرایش نیست'], 409);
    }
    $oldRedemptionStmt = $pdo->prepare('SELECT id, discount_id FROM pos_discount_redemptions WHERE order_id = ? AND reversed_at IS NULL FOR UPDATE');
    $oldRedemptionStmt->execute([$id]);
    $oldRedemption = $oldRedemptionStmt->fetch();
    $existingRedemptionId = is_array($oldRedemption) ? (int)$oldRedemption['id'] : null;
    if (is_array($oldRedemption)) {
        $oldDiscountStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'discounts' AND id = ? FOR UPDATE");
        $oldDiscountStmt->execute([(string)$oldRedemption['discount_id']]);
        $oldDiscountRaw = $oldDiscountStmt->fetchColumn();
        $oldDiscount = is_string($oldDiscountRaw) ? json_decode($oldDiscountRaw, true) : null;
        if (is_array($oldDiscount)) {
            $oldDiscount['usedCount'] = max(0, (int)($oldDiscount['usedCount'] ?? 0) - 1);
            $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'discounts' AND id = ?")
                ->execute([json_encode($oldDiscount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], (string)$oldRedemption['discount_id']]);
        }
        $pdo->prepare('UPDATE pos_discount_redemptions SET reversed_at = NOW() WHERE id = ? AND reversed_at IS NULL')->execute([$existingRedemptionId]);
    }
    $oldEffectsStmt = $pdo->prepare('SELECT id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta FROM pos_order_effects WHERE order_id = ? AND reversed_at IS NULL ORDER BY effect_type, resource_id FOR UPDATE');
    $oldEffectsStmt->execute([$id]);
    $oldEffects = $oldEffectsStmt->fetchAll();
    $oldIngredientIds = [];
    foreach ($oldEffects as $effect) if ($effect['effect_type'] === 'ingredient') $oldIngredientIds[] = (string)$effect['resource_id'];
    $oldIngredientIds = array_values(array_unique($oldIngredientIds));
    sort($oldIngredientIds, SORT_STRING);
    $oldIngredientSelect = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = ? FOR UPDATE");
    $oldIngredientUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'ingredients' AND id = ?");
    foreach ($oldIngredientIds as $ingredientId) {
        $oldIngredientSelect->execute([$ingredientId]);
        $rawIngredient = $oldIngredientSelect->fetchColumn();
        $ingredient = is_string($rawIngredient) ? json_decode($rawIngredient, true) : null;
        if (!is_array($ingredient)) throw new RuntimeException('Missing ingredient while reversing order');
        foreach ($oldEffects as $effect) {
            if ($effect['effect_type'] === 'ingredient' && (string)$effect['resource_id'] === $ingredientId) {
                $ingredient['stock'] = round((float)($ingredient['stock'] ?? 0) + (float)$effect['quantity'], 3);
            }
        }
        $oldIngredientUpdate->execute([json_encode($ingredient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $ingredientId]);
    }
    foreach ($oldEffects as $effect) {
        if ($effect['effect_type'] !== 'customer') continue;
        $oldCustomerId = (string)$effect['resource_id'];
        $oldCustomerStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
        $oldCustomerStmt->execute([$oldCustomerId]);
        $rawCustomer = $oldCustomerStmt->fetchColumn();
        $customer = is_string($rawCustomer) ? json_decode($rawCustomer, true) : null;
        if (!is_array($customer)) throw new RuntimeException('Missing customer while reversing order');
        $restoredPoints = (int)($customer['points'] ?? 0) + (int)$effect['points_spent'];
        if ($restoredPoints < (int)$effect['points_earned']) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'loyalty_points_already_spent', 'message' => 'امتیاز حاصل از سفارش قبلاً خرج شده است؛ ابتدا حساب مشتری را بررسی کنید'], 409);
        }
        $customer['points'] = $restoredPoints - (int)$effect['points_earned'];
        $customer['totalSpent'] = max(0, (int)($customer['totalSpent'] ?? 0) - (int)$effect['money_delta']);
        $customer['visits'] = max(0, (int)($customer['visits'] ?? 0) - (int)$effect['visits_delta']);
        $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'customers' AND id = ?")
            ->execute([json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $oldCustomerId]);
    }
    if ($oldEffects) {
        $effectIds = array_map(static fn(array $effect): int => (int)$effect['id'], $oldEffects);
        $marks = implode(',', array_fill(0, count($effectIds), '?'));
        $pdo->prepare("UPDATE pos_order_effects SET reversed_at = NOW(), reversed_by = ? WHERE id IN ($marks) AND reversed_at IS NULL")
            ->execute(array_merge([(int)$actor['id']], $effectIds));
    }
    $items = $body['items'] ?? null;
    $totals = $body['totals'] ?? null;
    $idempotencyKey = null;
    if ($idempotencyKey !== null && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $idempotencyKey)) {
        json_response(['ok' => false, 'error' => 'invalid_idempotency_key'], 422);
    }
    $requestHash = hash('sha256', json_encode([
        'items' => $items,
        'totals' => $totals,
        'customer' => $body['customer'] ?? null,
        'discountCode' => strtoupper(trim((string)($body['discountCode'] ?? ''))),
        'note' => $body['note'] ?? null,
        'guestName' => $body['guestName'] ?? null,
        'guestPhone' => $body['guestPhone'] ?? null,
        'payment' => $body['payment'] ?? 'cash',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if ($idempotencyKey !== null) {
        $existingStmt = $pdo->prepare('SELECT id, order_number, status, items, totals, request_hash FROM pos_orders WHERE idempotency_key = ? LIMIT 1');
        $existingStmt->execute([$idempotencyKey]);
        $existing = $existingStmt->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                json_response(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'این شناسه قبلاً برای سفارش دیگری استفاده شده است'], 409);
            }
            json_response(['ok' => true, 'replayed' => true, 'order' => [
                'id' => (string)$existing['id'],
                'orderNumber' => (int)$existing['order_number'],
                'status' => (string)$existing['status'],
                'items' => json_decode((string)$existing['items'], true) ?: [],
                'totals' => json_decode((string)$existing['totals'], true) ?: [],
            ]]);
        }
    }
    if (!is_array($items) || count($items) < 1 || count($items) > 100 || !is_array($totals)) json_response(['ok' => false, 'error' => 'invalid_order', 'message' => 'اقلام یا مبلغ سفارش معتبر نیست'], 422);
    $serverSubtotal = 0;
    $ingredientNeeds = [];
    $canonicalItems = [];
    $productSnapshots = [];
    $productLookup = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'products' AND id = ? LIMIT 1");
    foreach ($items as $line) {
        if (!is_array($line) || !isset($line['productId'], $line['qty']) || !is_numeric($line['qty']) || (float)$line['qty'] <= 0 || (float)$line['qty'] > 999 || floor((float)$line['qty']) !== (float)$line['qty']) {
            json_response(['ok' => false, 'error' => 'invalid_order_item'], 422);
        }
        $productLookup->execute([(string)$line['productId']]);
        $productRaw = $productLookup->fetchColumn();
        $product = is_string($productRaw) ? json_decode($productRaw, true) : null;
        if (!is_array($product) || empty($product['active'])) json_response(['ok' => false, 'error' => 'product_unavailable', 'message' => 'یکی از محصولات سفارش فعال نیست'], 409);
        $productId = (string)$line['productId'];
        $productSnapshots[$productId] = hash('sha256', (string)$productRaw);
        // Derive ingredient consumption from the server-side recipe, never from browser input.
        foreach (($product['recipe'] ?? []) as $recipeLine) {
            if (!is_array($recipeLine) || !isset($recipeLine['ingredientId'], $recipeLine['qty'])) continue;
            $ingredientId = (string)$recipeLine['ingredientId'];
            $recipeQty = (float)$recipeLine['qty'];
            if ($ingredientId !== '' && $recipeQty > 0 && is_finite($recipeQty)) {
                $ingredientNeeds[$ingredientId] = ($ingredientNeeds[$ingredientId] ?? 0) + ($recipeQty * (int)$line['qty']);
            }
        }
        $basePrice = filter_var($product['price'] ?? null, FILTER_VALIDATE_INT);
        if ($basePrice === false || $basePrice < 0) json_response(['ok' => false, 'error' => 'invalid_product_price'], 409);
        $unitPrice = $basePrice;
        $validOptions = [];
        foreach (($product['options'] ?? []) as $option) {
            if (!is_array($option) || !isset($option['id'])) continue;
            $optionPrice = filter_var($option['price'] ?? 0, FILTER_VALIDATE_INT);
            if ($optionPrice === false) continue;
            $validOptions[(string)$option['id']] = ['price' => $optionPrice, 'name' => substr((string)($option['name'] ?? ''), 0, 120)];
        }
        $rawOptionIds = $line['optionIds'] ?? [];
        if (!is_array($rawOptionIds) || count($rawOptionIds) > 30) json_response(['ok' => false, 'error' => 'invalid_product_option'], 422);
        $optionIds = array_map('strval', $rawOptionIds);
        if (count($optionIds) !== count(array_unique($optionIds))) json_response(['ok' => false, 'error' => 'duplicate_product_option'], 422);
        $optionLabels = [];
        foreach ($optionIds as $optionId) {
            if (!array_key_exists($optionId, $validOptions)) json_response(['ok' => false, 'error' => 'invalid_product_option'], 422);
            $unitPrice += $validOptions[$optionId]['price'];
            $optionLabels[] = $validOptions[$optionId]['name'];
        }
        if ($unitPrice < 0 || $unitPrice > 100000000000) json_response(['ok' => false, 'error' => 'invalid_order_item_price'], 422);
        if (isset($line['unitPrice']) && (!is_numeric($line['unitPrice']) || (float)$line['unitPrice'] !== (float)$unitPrice)) json_response(['ok' => false, 'error' => 'price_changed', 'message' => 'قیمت محصول تغییر کرده است؛ سبد را تازه‌سازی کنید'], 409);
        $serverSubtotal += $unitPrice * (int)$line['qty'];
        $clientKey = isset($line['key']) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', (string)$line['key']) ? (string)$line['key'] : 'line-' . count($canonicalItems);
        $canonicalItems[] = [
            'key' => $clientKey,
            'name' => substr((string)($product['name'] ?? 'محصول'), 0, 160),
            'productId' => $productId,
            'qty' => (int)$line['qty'],
            'unitPrice' => $unitPrice,
            'optionIds' => $optionIds,
            'optionLabels' => $optionLabels,
            'note' => isset($line['note']) ? substr(trim((string)$line['note']), 0, 500) : '',
        ];
    }
    $customer = is_array($body['customer'] ?? null) ? $body['customer'] : [];
    $customerId = isset($customer['id']) ? (string)$customer['id'] : '';
    $customerRecord = null;
    if ($customerId !== '') {
        if (strlen($customerId) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/D', $customerId)) {
            json_response(['ok' => false, 'error' => 'invalid_customer'], 422);
        }
        $customerStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? LIMIT 1");
        $customerStmt->execute([$customerId]);
        $customerRaw = $customerStmt->fetchColumn();
        $customerRecord = is_string($customerRaw) ? json_decode($customerRaw, true) : null;
        if (!is_array($customerRecord)) json_response(['ok' => false, 'error' => 'customer_not_found', 'message' => 'مشتری انتخاب‌شده پیدا نشد؛ فهرست مشتریان را تازه‌سازی کنید'], 409);
    }
    $customerName = is_array($customerRecord) ? substr((string)($customerRecord['name'] ?? ''), 0, 160) : null;
    $customerPhone = is_array($customerRecord) ? substr((string)($customerRecord['phone'] ?? ''), 0, 30) : null;
    $guestName = $customerId === '' ? trim((string)($body['guestName'] ?? 'مشتری')) : null;
    $guestPhone = $customerId === '' ? trim((string)($body['guestPhone'] ?? '')) : null;
    $payment = (string)($body['payment'] ?? $existingOrder['payment_method'] ?? 'cash');
    if (!in_array($payment, ['cash','card','online'], true) || utf8_length($guestName ?? '') > 160 || strlen($guestPhone ?? '') > 30) {
        json_response(['ok' => false, 'error' => 'invalid_order_details', 'message' => 'اطلاعات مشتری یا روش پرداخت معتبر نیست'], 422);
    }
    $discountCode = strtoupper(trim((string)($body['discountCode'] ?? '')));
    if (strlen($discountCode) > 64 || ($discountCode !== '' && !preg_match('/^[A-Z0-9_-]+$/D', $discountCode))) {
        json_response(['ok' => false, 'error' => 'invalid_discount_code'], 422);
    }
    $discountRecordId = null;
    $discountRecord = null;
    if ($discountCode !== '') {
        $discountRows = $pdo->query("SELECT id, payload FROM pos_records WHERE resource = 'discounts'")->fetchAll();
        foreach ($discountRows as $discountRow) {
            $candidate = json_decode((string)$discountRow['payload'], true);
            if (is_array($candidate) && strtoupper(trim((string)($candidate['code'] ?? ''))) === $discountCode) {
                $discountRecordId = (string)$discountRow['id'];
                $discountRecord = $candidate;
                break;
            }
        }
        if (!is_array($discountRecord)) json_response(['ok' => false, 'error' => 'discount_not_found', 'message' => 'کد تخفیف معتبر نیست'], 422);
    }
    $totalFields = ['subtotal', 'tierDiscount', 'codeDiscount', 'pointsDiscount', 'tax', 'total'];
    foreach ($totalFields as $key) {
        if (!isset($totals[$key]) || !is_numeric($totals[$key]) || (float)$totals[$key] < 0 || (float)$totals[$key] > 100000000000 || floor((float)$totals[$key]) !== (float)$totals[$key]) {
            json_response(['ok' => false, 'error' => 'invalid_order_total', 'message' => 'مقادیر مالی سفارش معتبر نیست'], 422);
        }
    }
    if ((int)$totals['subtotal'] !== (int)$serverSubtotal) json_response(['ok' => false, 'error' => 'subtotal_mismatch', 'message' => 'جمع سفارش با قیمت محصولات مطابقت ندارد'], 409);
    $subtotal = (int)$totals['subtotal'];
    $tierDiscount = (int)$totals['tierDiscount'];
    $codeDiscount = (int)$totals['codeDiscount'];
    $pointsDiscount = (int)$totals['pointsDiscount'];
    $discountTotal = $tierDiscount + $codeDiscount + $pointsDiscount;
    $tierSettingsRaw=$pdo->query("SELECT payload FROM pos_records WHERE resource='settings' AND id='main' LIMIT 1")->fetchColumn();
    $tierSettings=is_string($tierSettingsRaw)?json_decode($tierSettingsRaw,true):[];if(!is_array($tierSettings))$tierSettings=[];
    $customerPoints = max(0, (int)($customerRecord['points'] ?? 0));
    $tierRate = loyalty_discount_rate($customerPoints,$tierSettings);
    $expectedTierDiscount = (int)round($subtotal * $tierRate / 100);
    $expectedCodeDiscount = 0;
    if ($discountCode !== '') {
        $discountType = (string)($discountRecord['type'] ?? '');
        $discountValue = (float)($discountRecord['value'] ?? 0);
        $maxDiscount = max(0, (int)($discountRecord['maxDiscount'] ?? 0));
        $minOrder = max(0, (int)($discountRecord['minOrder'] ?? 0));
        $maxUses = max(0, (int)($discountRecord['maxUses'] ?? 0));
        $usedCount = max(0, (int)($discountRecord['usedCount'] ?? 0));
        $perCustomer = max(0, (int)($discountRecord['perCustomer'] ?? 0));
        $today = date('Y-m-d');
        $startsAt = trim((string)($discountRecord['startsAt'] ?? ''));
        $endsAt = trim((string)($discountRecord['endsAt'] ?? ''));
        $audience = (string)($discountRecord['audience'] ?? 'all');
        $tier = loyalty_tier($customerPoints,$tierSettings);
        if (!in_array($discountType, ['percent','fixed'], true) || !is_finite($discountValue) || $discountValue <= 0 || ($discountType === 'percent' && $discountValue > 100)) {
            json_response(['ok' => false, 'error' => 'invalid_discount_config', 'message' => 'تعریف کد تخفیف معتبر نیست'], 409);
        }
        if (($startsAt !== '' && $today < $startsAt) || ($endsAt !== '' && $today > $endsAt)) json_response(['ok' => false, 'error' => 'discount_out_of_date', 'message' => 'کد تخفیف در این تاریخ فعال نیست'], 409);
        if ($maxUses > 0 && $usedCount >= $maxUses) json_response(['ok' => false, 'error' => 'discount_limit_reached', 'message' => 'سقف استفاده از این کد تمام شده است'], 409);
        if ($subtotal - $expectedTierDiscount < $minOrder) json_response(['ok' => false, 'error' => 'discount_minimum_not_met', 'message' => 'مبلغ سفارش به حداقل لازم برای این کد نرسیده است'], 409);
        if ($audience === 'club' && $customerId === '') json_response(['ok' => false, 'error' => 'discount_customer_required', 'message' => 'این کد فقط برای اعضای باشگاه است'], 409);
        if (in_array($audience, ['bronze','silver','gold','platinum'], true) && ($customerId === '' || $tier !== $audience)) json_response(['ok' => false, 'error' => 'discount_audience_mismatch', 'message' => 'این کد برای سطح عضویت مشتری قابل استفاده نیست'], 409);
        if (!in_array($audience, ['all','club','bronze','silver','gold','platinum'], true)) json_response(['ok' => false, 'error' => 'invalid_discount_audience'], 409);
        $afterTierForCode = max(0, $subtotal - $expectedTierDiscount);
        $expectedCodeDiscount = $discountType === 'percent'
            ? (int)round($afterTierForCode * $discountValue / 100)
            : min($afterTierForCode, (int)round($discountValue));
        if ($discountType === 'percent' && $maxDiscount > 0) $expectedCodeDiscount = min($expectedCodeDiscount, $maxDiscount);
    }
    $settingsForPointsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
    $settingsForPointsStmt->execute();
    $rawPointsSettings = $settingsForPointsStmt->fetchColumn();
    $pointsSettings = is_string($rawPointsSettings) ? json_decode($rawPointsSettings, true) : [];
    $tomanPerPointCheck = max(1, (int)($pointsSettings['tomanPerPoint'] ?? 1));
    $expectedPointsDiscount = min(max(0, $subtotal - $expectedTierDiscount - $expectedCodeDiscount), $customerPoints * $tomanPerPointCheck);
    if ($pointsDiscount > $expectedPointsDiscount) json_response(['ok' => false, 'error' => 'invalid_points_discount', 'message' => 'مقدار تخفیف امتیازی معتبر نیست'], 409);
    $taxSettingsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
    $taxSettingsStmt->execute();
    $rawTaxSettings = $taxSettingsStmt->fetchColumn();
    $taxSettings = is_string($rawTaxSettings) ? json_decode($rawTaxSettings, true) : [];
    if (!is_array($taxSettings)) $taxSettings = [];
    $taxEnabled = !array_key_exists('taxEnabled', $taxSettings) || $taxSettings['taxEnabled'] !== false;
    $taxPercent = (float)($taxSettings['taxPercent'] ?? 0);
    $stockPolicy = (string)($taxSettings['stockPolicy'] ?? 'warn_and_allow');
    if (!in_array($stockPolicy, ['block', 'warn_and_allow', 'allow_negative'], true)) $stockPolicy = 'warn_and_allow';
    if (!is_finite($taxPercent) || $taxPercent < 0 || $taxPercent > 100) {
        json_response(['ok' => false, 'error' => 'invalid_tax_settings', 'message' => 'تنظیم درصد مالیات معتبر نیست'], 409);
    }
    $taxableAmount = max(0, $subtotal - $discountTotal);
    $expectedTax = $taxEnabled ? (int)round($taxableAmount * $taxPercent / 100) : 0;
    // Membership tiers in the POS model grant at most 8% (platinum).
    // Keep client-supplied discounts within their mathematically possible bases.
    $maxTierDiscount = $expectedTierDiscount;
    $afterTier = $subtotal - $tierDiscount;
    $afterCode = $afterTier - $codeDiscount;
    if (
        $tierDiscount !== $expectedTierDiscount ||
        $codeDiscount !== $expectedCodeDiscount ||
        $pointsDiscount > $afterCode ||
        $discountTotal > $subtotal ||
        (int)$totals['tax'] !== $expectedTax ||
        (int)$totals['total'] !== $subtotal - $discountTotal + (int)$totals['tax']
    ) {
        json_response(['ok' => false, 'error' => 'order_total_mismatch', 'message' => 'تخفیف‌ها یا محاسبه مبلغ نهایی سفارش معتبر نیست'], 422);
    }
    // Canonical customer details were loaded before validating tier and coupon discounts.
    if ($pointsDiscount > 0 && $customerId === '') {
        json_response(['ok' => false, 'error' => 'points_require_customer', 'message' => 'برای استفاده از امتیاز باید مشتری انتخاب شود'], 422);
    }
    try {
        // Order row and prior effects are already locked in this transaction.

        // Lock product rows and ensure price, recipe and options did not change after quoting.
        $productLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'products' AND id = ? FOR UPDATE");
        foreach ($productSnapshots as $productId => $snapshotHash) {
            $productLock->execute([$productId]);
            $lockedProductRaw = $productLock->fetchColumn();
            if (!is_string($lockedProductRaw) || !hash_equals($snapshotHash, hash('sha256', $lockedProductRaw))) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'product_changed_requote', 'message' => 'اطلاعات یا قیمت یکی از محصولات تغییر کرده است؛ سبد را دوباره محاسبه کنید'], 409);
            }
        }

        $pointsToSpend = 0;
        $pointsEarned = 0;

        if ($discountCode !== '' && $discountRecordId !== null) {
            $discountLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'discounts' AND id = ? FOR UPDATE");
            $discountLock->execute([$discountRecordId]);
            $lockedDiscountRaw = $discountLock->fetchColumn();
            $lockedDiscount = is_string($lockedDiscountRaw) ? json_decode($lockedDiscountRaw, true) : null;
            if (!is_array($lockedDiscount) || strtoupper(trim((string)($lockedDiscount['code'] ?? ''))) !== $discountCode) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'discount_changed', 'message' => 'کد تخفیف تغییر کرده است؛ دوباره بررسی کنید'], 409);
            }
            foreach (['type','value','maxDiscount','minOrder','maxUses','perCustomer','startsAt','endsAt','audience'] as $ruleField) {
                if (($lockedDiscount[$ruleField] ?? null) !== ($discountRecord[$ruleField] ?? null)) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'discount_changed', 'message' => 'قوانین کد تخفیف هم‌زمان تغییر کرده است؛ سفارش را دوباره محاسبه کنید'], 409);
                }
            }
            $lockedMaxUses = max(0, (int)($lockedDiscount['maxUses'] ?? 0));
            $lockedUsedCount = max(0, (int)($lockedDiscount['usedCount'] ?? 0));
            $globalRedemptionsStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_discount_redemptions WHERE discount_id = ? AND reversed_at IS NULL');
            $globalRedemptionsStmt->execute([$discountRecordId]);
            $globalRedemptions = (int)$globalRedemptionsStmt->fetchColumn();
            $authoritativeUsedCount = max($lockedUsedCount, $globalRedemptions);
            if ($lockedMaxUses > 0 && $authoritativeUsedCount >= $lockedMaxUses) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'discount_limit_reached', 'message' => 'آخرین سهمیه این کد هم‌زمان مصرف شد'], 409);
            }
            $lockedPerCustomer = max(0, (int)($lockedDiscount['perCustomer'] ?? 0));
            if ($lockedPerCustomer > 0 && $customerId !== '') {
                $usageStmt = $pdo->prepare('SELECT COUNT(*) FROM pos_discount_redemptions WHERE discount_id = ? AND customer_id = ? AND reversed_at IS NULL');
                $usageStmt->execute([$discountRecordId, $customerId]);
                if ((int)$usageStmt->fetchColumn() >= $lockedPerCustomer) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'discount_customer_limit', 'message' => 'سهمیه استفاده این مشتری از کد تمام شده است'], 409);
                }
            }
            $lockedDiscount['usedCount'] = $authoritativeUsedCount + 1;
            $discountUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'discounts' AND id = ?");
            $discountUpdate->execute([json_encode($lockedDiscount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $discountRecordId]);
        }

        // Lock ingredients in a stable order to reduce deadlocks between concurrent checkouts.
        ksort($ingredientNeeds, SORT_STRING);
        // Lock and deduct every recipe ingredient in the same transaction as order creation.
        $ingredientSelect = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = ? FOR UPDATE");
        $ingredientUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'ingredients' AND id = ?");
        foreach ($ingredientNeeds as $ingredientId => $needed) {
            $ingredientSelect->execute([$ingredientId]);
            $rawIngredient = $ingredientSelect->fetchColumn();
            $ingredient = is_string($rawIngredient) ? json_decode($rawIngredient, true) : null;
            if (!is_array($ingredient)) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'ingredient_not_found', 'message' => 'یکی از مواد اولیه دستور محصول در انبار پیدا نشد'], 409);
            }
            $available = (float)($ingredient['stock'] ?? 0);
            if ($stockPolicy === 'block' && $available + 0.000001 < $needed) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'insufficient_stock', 'message' => 'موجودی یکی از مواد اولیه کافی نیست: ' . (string)($ingredient['name'] ?? $ingredientId)], 409);
            }
            $ingredient['stock'] = round($available - $needed, 3);
            $ingredientUpdate->execute([
                json_encode($ingredient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (int)$actor['id'],
                $ingredientId,
            ]);
        }

        // Serialize loyalty balance changes so two cashiers cannot spend the same points.
        if ($customerId !== '') {
            $customerLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
            $customerLock->execute([$customerId]);
            $rawCustomer = $customerLock->fetchColumn();
            $lockedCustomer = is_string($rawCustomer) ? json_decode($rawCustomer, true) : null;
            if (!is_array($lockedCustomer)) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'customer_not_found', 'message' => 'مشتری انتخاب‌شده پیدا نشد؛ فهرست مشتریان را تازه‌سازی کنید'], 409);
            }
            $settingsStmt = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'settings' AND id = 'main' LIMIT 1");
            $settingsStmt->execute();
            $rawSettings = $settingsStmt->fetchColumn();
            $settings = is_string($rawSettings) ? json_decode($rawSettings, true) : [];
            $tomanPerPoint = max(1, (int)($settings['tomanPerPoint'] ?? 1));
            $pointsPerToman = max(1, (int)($settings['pointsPerToman'] ?? 100000));
            $pointsToSpend = $pointsDiscount > 0 ? (int)ceil($pointsDiscount / $tomanPerPoint) : 0;
            $customerName = substr((string)($lockedCustomer['name'] ?? $customerName ?? ''), 0, 160);
            $customerPhone = substr((string)($lockedCustomer['phone'] ?? $customerPhone ?? ''), 0, 30);
            $currentPoints = max(0, (int)($lockedCustomer['points'] ?? 0));
            if ($currentPoints !== $customerPoints) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'customer_changed_requote', 'message' => 'امتیاز یا سطح عضویت مشتری تغییر کرده است؛ سفارش را دوباره محاسبه کنید'], 409);
            }
            if ($pointsToSpend > $currentPoints || ($pointsToSpend * $tomanPerPoint) < $pointsDiscount) {
                $pdo->rollBack();
                json_response(['ok' => false, 'error' => 'insufficient_points', 'message' => 'امتیاز مشتری برای این تخفیف کافی نیست؛ اطلاعات مشتری را تازه‌سازی کنید'], 409);
            }
            $pointsEarned = (int)floor(((int)$totals['total']) / $pointsPerToman);
            $lockedCustomer['points'] = $currentPoints - $pointsToSpend + $pointsEarned;
            $lockedCustomer['totalSpent'] = (int)($lockedCustomer['totalSpent'] ?? 0) + (int)$totals['total'];
            $lockedCustomer['visits'] = (int)($lockedCustomer['visits'] ?? 0) + 1;
            $customerUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'customers' AND id = ?");
            $customerUpdate->execute([
                json_encode($lockedCustomer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                (int)$actor['id'],
                $customerId,
            ]);
        }

        $orderNumber = (int)$existingOrder['order_number'];
        $stmt = $pdo->prepare("UPDATE pos_orders SET customer_id = ?, customer_name = ?, customer_phone = ?, guest_name = ?, guest_phone = ?, payment_method = ?, points_used = ?, points_earned = ?, discount_code = ?, items = ?, totals = ?, note = ? WHERE id = ? AND status = 'new'");
        $stmt->execute([
            $customerId !== '' ? $customerId : null,
            $customerName,
            $customerPhone,
            $guestName,
            $guestPhone,
            $payment,
            $pointsToSpend,
            $pointsEarned,
            $discountCode !== '' ? $discountCode : null,
            json_encode($canonicalItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            isset($body['note']) ? substr((string)$body['note'], 0, 2000) : null,
            $id,
        ]);
        if ($stmt->rowCount() !== 1) {
            $verify = $pdo->prepare("SELECT status FROM pos_orders WHERE id = ? FOR UPDATE");
            $verify->execute([$id]);
            if ($verify->fetchColumn() !== 'new') throw new RuntimeException('Order is no longer editable');
        }
        if ($discountCode !== '' && $discountRecordId !== null) {
            if ($existingRedemptionId !== null) {
                $redemptionUpdate = $pdo->prepare('UPDATE pos_discount_redemptions SET discount_id = ?, code = ?, customer_id = ?, redeemed_at = NOW(), reversed_at = NULL WHERE id = ? AND order_id = ?');
                $redemptionUpdate->execute([$discountRecordId, $discountCode, $customerId !== '' ? $customerId : null, $existingRedemptionId, $id]);
            } else {
                $redemptionInsert = $pdo->prepare('INSERT INTO pos_discount_redemptions (order_id, discount_id, code, customer_id) VALUES (?, ?, ?, ?)');
                $redemptionInsert->execute([$id, $discountRecordId, $discountCode, $customerId !== '' ? $customerId : null]);
            }
        }

        // Persist the exact stock and loyalty effects so cancellation can reverse
        // them once, inside the same transaction as the status change.
        $effectInsert = $pdo->prepare('INSERT INTO pos_order_effects (order_id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), points_spent = VALUES(points_spent), points_earned = VALUES(points_earned), money_delta = VALUES(money_delta), visits_delta = VALUES(visits_delta), reversed_at = NULL, reversed_by = NULL');
        foreach ($ingredientNeeds as $ingredientId => $needed) {
            if ((float)$needed > 0) $effectInsert->execute([$id, 'ingredient', $ingredientId, round((float)$needed, 3), 0, 0, 0, 0]);
        }
        if ($customerId !== '') {
            $effectInsert->execute([$id, 'customer', $customerId, 0, $pointsToSpend, $pointsEarned, (int)$totals['total'], 1]);
        }
        audit($pdo, (int)$actor['id'], 'order_edited', 'order', (string)$id, ['order_number' => $orderNumber]);
        $pdo->commit();
        json_response(['ok' => true, 'order' => ['id' => (string)$id, 'orderNumber' => $orderNumber, 'status' => 'new', 'items' => $canonicalItems, 'totals' => $totals]]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // A concurrent retry may win the unique idempotency key after our initial lookup.
        if ($idempotencyKey !== null && $e instanceof PDOException && (string)$e->getCode() === '23000') {
            $retryStmt = $pdo->prepare('SELECT id, order_number, status, items, totals, request_hash FROM pos_orders WHERE idempotency_key = ? LIMIT 1');
            $retryStmt->execute([$idempotencyKey]);
            $retry = $retryStmt->fetch();
            if (is_array($retry)) {
                if (!hash_equals((string)$retry['request_hash'], $requestHash)) {
                    json_response(['ok' => false, 'error' => 'idempotency_conflict', 'message' => 'این شناسه قبلاً برای سفارش دیگری استفاده شده است'], 409);
                }
                json_response(['ok' => true, 'replayed' => true, 'order' => [
                    'id' => (string)$retry['id'],
                    'orderNumber' => (int)$retry['order_number'],
                    'status' => (string)$retry['status'],
                    'items' => json_decode((string)$retry['items'], true) ?: [],
                    'totals' => json_decode((string)$retry['totals'], true) ?: [],
                ]]);
            }
        }
        error_log('[3Food POS] Order edit failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'order_edit_failed', 'message' => 'ویرایش سفارش انجام نشد'], 500);
    }
}


if (preg_match('#^/orders/(\d+)/status$#', $path, $m) && $method === 'PATCH') {
    $actor = require_role($pdo, ['manager','kitchen']);
    require_csrf();
    $id = (int)$m[1];
    $body = request_json();
    $status = (string)($body['status'] ?? '');
    if (!in_array($status, ['new','preparing','ready','delivered','cancelled'], true)) json_response(['ok' => false, 'error' => 'invalid_status'], 422);
    // Serialize status changes and enforce a one-way kitchen workflow.
    // A row lock prevents two devices from advancing the same order concurrently.
    try {
        $pdo->beginTransaction();
        $orderStmt = $pdo->prepare('SELECT status, payment_method FROM pos_orders WHERE id = ? FOR UPDATE');
        $orderStmt->execute([$id]);
        $currentOrder = $orderStmt->fetch();
        if (!is_array($currentOrder)) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'order_not_found'], 404);
        }
        $currentStatus = (string)$currentOrder['status'];
        $currentPaymentMethod = (string)($currentOrder['payment_method'] ?? 'cash');
        if ($status === 'cancelled' && in_array($currentPaymentMethod, ['card','online'], true)) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'payment_reconciliation_required', 'message' => 'لغو سفارش پرداخت‌شده بدون تعیین‌تکلیف تراکنش و بازپرداخت مجاز نیست'], 409);
        }

        $transitions = [
            'new' => ['preparing', 'cancelled'],
            'preparing' => ['ready', 'cancelled'],
            'ready' => ['delivered', 'cancelled'],
            'delivered' => [],
            'cancelled' => [],
        ];
        if (!in_array($status, $transitions[(string)$currentStatus] ?? [], true)) {
            $pdo->rollBack();
            json_response([
                'ok' => false,
                'error' => 'invalid_status_transition',
                'message' => 'تغییر وضعیت سفارش از وضعیت فعلی مجاز نیست',
            ], 409);
        }
        if ($actor['role'] === 'kitchen' && $status === 'cancelled') {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'forbidden'], 403);
        }

        if ($status === 'cancelled') {
        if ($status === 'cancelled') {
            $redemptionStmt = $pdo->prepare('SELECT id, discount_id FROM pos_discount_redemptions WHERE order_id = ? AND reversed_at IS NULL FOR UPDATE');
            $redemptionStmt->execute([$id]);
            $redemption = $redemptionStmt->fetch();
            if (is_array($redemption)) {
                $couponLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'discounts' AND id = ? FOR UPDATE");
                $couponLock->execute([(string)$redemption['discount_id']]);
                $couponRaw = $couponLock->fetchColumn();
                $coupon = is_string($couponRaw) ? json_decode($couponRaw, true) : null;
                if (is_array($coupon)) {
                    $coupon['usedCount'] = max(0, (int)($coupon['usedCount'] ?? 0) - 1);
                    $couponUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'discounts' AND id = ?");
                    $couponUpdate->execute([json_encode($coupon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], (string)$redemption['discount_id']]);
                }
                $markRedemption = $pdo->prepare('UPDATE pos_discount_redemptions SET reversed_at = NOW() WHERE id = ? AND reversed_at IS NULL');
                $markRedemption->execute([(int)$redemption['id']]);
            }
        }
            // Reverse only effects recorded for this order. FOR UPDATE plus the
            // order-row lock and reversed_at guard make this operation one-shot.
            $effectsStmt = $pdo->prepare('SELECT id, effect_type, resource_id, quantity, points_spent, points_earned, money_delta, visits_delta FROM pos_order_effects WHERE order_id = ? AND reversed_at IS NULL ORDER BY effect_type, resource_id FOR UPDATE');
            $effectsStmt->execute([$id]);
            $effects = $effectsStmt->fetchAll();
            $ingredientIds = [];
            foreach ($effects as $effect) if ($effect['effect_type'] === 'ingredient') $ingredientIds[] = (string)$effect['resource_id'];
            sort($ingredientIds, SORT_STRING);
            $ingredientSelect = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'ingredients' AND id = ? FOR UPDATE");
            $ingredientUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'ingredients' AND id = ?");
            foreach ($ingredientIds as $ingredientId) {
                $effect = null;
                foreach ($effects as $candidate) if ($candidate['effect_type'] === 'ingredient' && (string)$candidate['resource_id'] === $ingredientId) { $effect = $candidate; break; }
                if (!$effect) continue;
                $ingredientSelect->execute([$ingredientId]);
                $rawIngredient = $ingredientSelect->fetchColumn();
                $ingredient = is_string($rawIngredient) ? json_decode($rawIngredient, true) : null;
                if (!is_array($ingredient)) throw new RuntimeException('Ingredient missing while reversing order ' . $id);
                $ingredient['stock'] = round((float)($ingredient['stock'] ?? 0) + (float)$effect['quantity'], 3);
                $ingredientUpdate->execute([json_encode($ingredient, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $ingredientId]);
            }
            $customerEffects = array_values(array_filter($effects, static fn(array $effect): bool => $effect['effect_type'] === 'customer'));
            foreach ($customerEffects as $effect) {
                $customerLock = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
                $customerLock->execute([(string)$effect['resource_id']]);
                $rawCustomer = $customerLock->fetchColumn();
                $customer = is_string($rawCustomer) ? json_decode($rawCustomer, true) : null;
                if (!is_array($customer)) throw new RuntimeException('Customer missing while reversing order ' . $id);
                $restoredPoints = (int)($customer['points'] ?? 0) + (int)$effect['points_spent'];
                if ($restoredPoints < (int)$effect['points_earned']) {
                    $pdo->rollBack();
                    json_response(['ok' => false, 'error' => 'loyalty_points_already_spent', 'message' => 'امتیاز حاصل از این سفارش قبلاً خرج شده است؛ برای جلوگیری از منفی‌شدن موجودی امتیاز، ابتدا حساب مشتری را بررسی کنید'], 409);
                }
                $customer['points'] = $restoredPoints - (int)$effect['points_earned'];
                $customer['totalSpent'] = max(0, (int)($customer['totalSpent'] ?? 0) - (int)$effect['money_delta']);
                $customer['visits'] = max(0, (int)($customer['visits'] ?? 0) - (int)$effect['visits_delta']);
                $customerUpdate = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'customers' AND id = ?");
                $customerUpdate->execute([json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], (string)$effect['resource_id']]);
            }
            if ($effects) {
                $effectIds = array_map(static fn(array $effect): int => (int)$effect['id'], $effects);
                $marks = implode(',', array_fill(0, count($effectIds), '?'));
                $markStmt = $pdo->prepare("UPDATE pos_order_effects SET reversed_at = NOW(), reversed_by = ? WHERE id IN ($marks) AND reversed_at IS NULL");
                $markStmt->execute(array_merge([(int)$actor['id']], $effectIds));
            }
        }



        $update = $pdo->prepare('UPDATE pos_orders SET status = ?, updated_at = NOW() WHERE id = ?');
        $update->execute([$status, $id]);
        audit($pdo, (int)$actor['id'], 'order_status_changed', 'order', (string)$id, [
            'from' => (string)$currentStatus,
            'to' => $status,
        ]);
        $pdo->commit();
        json_response(['ok' => true, 'status' => $status]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] Order status update failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'order_status_update_failed', 'message' => 'تغییر وضعیت سفارش انجام نشد'], 500);
    }
}


if ($method === 'GET' && $path === '/reports/summary') {
    $actor = require_role($pdo, ['manager']);
    $from = (string)($_GET['from'] ?? date('Y-m-01'));
    $to = (string)($_GET['to'] ?? date('Y-m-d'));
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $from) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $to)) {
        json_response(['ok'=>false,'error'=>'invalid_report_range','message'=>'بازه تاریخ گزارش معتبر نیست'],422);
    }
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$fromDate || !$toDate || $toDate < $fromDate || $toDate->diff($fromDate)->days > 366) {
        json_response(['ok'=>false,'error'=>'invalid_report_range','message'=>'بازه گزارش حداکثر ۳۶۶ روز و باید معتبر باشد'],422);
    }
    $ordersStmt = $pdo->prepare("SELECT id, order_number, status, payment_method, items, totals, created_at FROM pos_orders WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY created_at ASC");
    $tehran = new DateTimeZone('Asia/Tehran'); $utc = new DateTimeZone('UTC');
    $fromUtc = (new DateTimeImmutable($from . ' 00:00:00', $tehran))->setTimezone($utc)->format('Y-m-d H:i:s');
    $toUtc = (new DateTimeImmutable($to . ' 00:00:00', $tehran))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $ordersStmt = $pdo->prepare('SELECT id, order_number, status, payment_method, items, totals, created_at FROM pos_orders WHERE created_at >= ? AND created_at < ? ORDER BY created_at ASC');
    $ordersStmt->execute([$fromUtc, $toUtc]);
    $orders = $ordersStmt->fetchAll();
    $products = []; $categoryNames=[]; $byCategory=[];
    foreach ($pdo->query("SELECT id,payload FROM pos_records WHERE resource='categories'")->fetchAll() as $row) { $category=json_decode((string)$row['payload'],true); if(is_array($category))$categoryNames[(string)$row['id']]=(string)($category['name']??'بدون دسته'); }
    foreach ($pdo->query("SELECT id,payload FROM pos_records WHERE resource='products'")->fetchAll() as $row) {
        $p = json_decode((string)$row['payload'], true);
        if (is_array($p)) $products[(string)$row['id']] = $p;
    }
    $sales=0; $count=0; $cancelled=0; $cost=0; $taxCollected=0; $discountsTotal=0; $daily=[]; $byPayment=['cash'=>0,'card'=>0,'online'=>0]; $byProduct=[];
    foreach ($orders as $order) {
        if ($order['status'] === 'cancelled') { $cancelled++; continue; }
        $totals=json_decode((string)$order['totals'],true) ?: [];
        $total=(int)($totals['total']??0); $tax=(int)($totals['tax']??0); $discount=(int)($totals['tierDiscount']??0)+(int)($totals['codeDiscount']??0)+(int)($totals['pointsDiscount']??0); $revenue=max(0,(int)($totals['subtotal']??$total)-$discount); $sales+=$revenue; $taxCollected+=$tax; $discountsTotal+=$discount; $count++;
        $payment=(string)($order['payment_method']??'cash'); if(isset($byPayment[$payment])) $byPayment[$payment]+=$total;
        $day=(new DateTimeImmutable((string)$order['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d'); $daily[$day]=($daily[$day]??0)+$revenue;
        $items=json_decode((string)$order['items'],true) ?: [];
        foreach($items as $line){
            if(!is_array($line)) continue;
            $productId=(string)($line['productId']??''); $qty=max(0,(int)($line['qty']??0));
            $name=(string)($line['name']??'محصول'); $unit=(int)($line['unitPrice']??0);
            $byProduct[$name]??=['quantity'=>0,'revenue'=>0]; $byProduct[$name]['quantity']+=$qty; $byProduct[$name]['revenue']+=$unit*$qty;
            $categoryId=(string)($products[$productId]['categoryId']??'');$categoryName=$categoryNames[$categoryId]??'بدون دسته';$byCategory[$categoryName]??=['quantity'=>0,'revenue'=>0];$byCategory[$categoryName]['quantity']+=$qty;$byCategory[$categoryName]['revenue']+=$unit*$qty;
            $cost += max(0,(int)($line['unitCost']??($products[$productId]['cost']??0)))*$qty;
        }
    }
    $expenseStmt=$pdo->prepare("SELECT payload FROM pos_records WHERE resource='expenses'");
    $expenseStmt->execute(); $expenses=[]; $expenseTotal=0; $expenseGroups=[];
    foreach($expenseStmt->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $e=json_decode((string)$raw,true); if(!is_array($e)) continue;
        $date=(string)($e['date']??''); if($date<$from||$date>$to) continue;
        $amount=max(0,(int)($e['amount']??0));
        $category=(string)($e['category']??'سایر هزینه‌ها');
        // Raw-material purchases increase inventory; consumed ingredient cost is counted in COGS above.
        if ($category !== 'مواد اولیه') $expenseTotal += $amount;
        $expenseGroups[$category]=($expenseGroups[$category]??0)+$amount;
        $expenses[]=['id'=>(string)($e['id']??''),'title'=>(string)($e['title']??''),'amount'=>$amount,'category'=>$category,'date'=>$date];
    }
    ksort($daily); ksort($expenseGroups); uasort($byProduct,static fn($a,$b)=>$b['quantity']<=>$a['quantity']); uasort($byCategory,static fn($a,$b)=>$b['revenue']<=>$a['revenue']);
    $monthly=[];
    foreach($daily as $day=>$value){$month=substr((string)$day,0,7);$monthly[$month]=($monthly[$month]??0)+$value;}
    ksort($monthly);
    $inventoryPurchases=0;
    foreach($pdo->query("SELECT payload FROM pos_records WHERE resource='supplier_purchases'")->fetchAll(PDO::FETCH_COLUMN) as $rawPurchase){$purchase=json_decode((string)$rawPurchase,true);$purchaseDate=(string)($purchase['date']??'');if(is_array($purchase)&&$purchaseDate>=$from&&$purchaseDate<=$to&&($purchase['kind']??'')==='purchase'&&!empty($purchase['ingredientId']))$inventoryPurchases+=(int)($purchase['amount']??0);}
    $inventoryPurchases+=array_sum(array_map(static fn($e)=>($e['category']??'')==='مواد اولیه'?(int)($e['amount']??0):0,$expenses));
    $lowStock=[]; $allInventory=[]; $inventoryValue=0;
    foreach($pdo->query("SELECT payload FROM pos_records WHERE resource='ingredients'")->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $ingredient=json_decode((string)$raw,true);if(!is_array($ingredient))continue;
        $stock=(float)($ingredient['stock']??0);$min=(float)($ingredient['minStock']??0);$unitCost=(float)($ingredient['costPerUnit']??0);
        $value=max(0,$stock)*max(0,$unitCost);$inventoryValue+=$value;
        $inventoryRow=['name'=>(string)($ingredient['name']??''),'stock'=>$stock,'minStock'=>$min,'unit'=>(string)($ingredient['unit']??''),'costPerUnit'=>$unitCost,'value'=>round($value)];
        $allInventory[]=$inventoryRow;
        if($stock<=$min)$lowStock[]=$inventoryRow;
    }
    $customerRows=[];
    foreach($pdo->query("SELECT payload FROM pos_records WHERE resource='customers'")->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $customer=json_decode((string)$raw,true);if(!is_array($customer))continue;
        $customerRows[]=['name'=>(string)($customer['name']??''),'phone'=>(string)($customer['phone']??''),'totalSpent'=>(int)($customer['totalSpent']??0),'visits'=>(int)($customer['visits']??0),'points'=>(int)($customer['points']??0)];
    }
    usort($customerRows,static fn($a,$b)=>$b['totalSpent']<=>$a['totalSpent']);
    json_response(['ok'=>true,'range'=>['from'=>$from,'to'=>$to],'sales'=>$sales,'orders'=>$count,'cancelled'=>$cancelled,'average_order'=>$count?intdiv($sales,$count):0,'tax_collected'=>$taxCollected,'discounts'=>$discountsTotal,'cost_of_goods'=>$cost,'expenses'=>$expenseTotal,'inventory_purchases'=>$inventoryPurchases,'gross_profit'=>$sales-$cost,'net_profit'=>$sales-$cost-$expenseTotal,'daily'=>array_map(static fn($d,$v)=>['date'=>$d,'sales'=>$v],array_keys($daily),array_values($daily)),'monthly'=>array_map(static fn($m,$v)=>['month'=>$m,'sales'=>$v],array_keys($monthly),array_values($monthly)),'payments'=>$byPayment,'products'=>array_slice(array_map(static fn($name,$v)=>['name'=>$name]+$v,array_keys($byProduct),array_values($byProduct)),0,100),'all_products'=>array_map(static fn($name,$v)=>['name'=>$name]+$v,array_keys($byProduct),array_values($byProduct)),'product_categories'=>array_map(static fn($name,$v)=>['name'=>$name]+$v,array_keys($byCategory),array_values($byCategory)),'expense_categories'=>$expenseGroups,'expense_items'=>$expenses,'inventory'=>['value'=>round($inventoryValue),'low_stock_count'=>count($lowStock)],'inventory_items'=>$allInventory,'low_stock'=>$lowStock,'top_customers'=>array_slice($customerRows,0,20),'all_customers'=>$customerRows]);
}


if ($method === 'POST' && $path === '/sms/birthday-sync') {
    $actor = require_role($pdo, ['manager']);
    require_csrf();
    $sms = $config['sms'] ?? [];
    $username = trim((string)($sms['username'] ?? ''));
    $password = trim((string)($sms['password'] ?? ''));
    $location = $sms['inbox_location'] ?? null;
    $from = trim((string)($sms['inbox_from'] ?? ''));
    $index = trim((string)($sms['inbox_index'] ?? '1'));
    $count = (int)($sms['inbox_count'] ?? 50);
    if (($sms['provider'] ?? 'none') !== 'national_payamak' || $username === '' || $password === ''
        || !is_numeric($location) || (int)$location < 0 || (int)$location > 99
        || !preg_match('/^[0-9]{1,12}$/D', $index) || $count < 1 || $count > 100) {
        json_response(['ok' => false, 'error' => 'sms_inbox_not_configured', 'message' => 'تنظیم دریافت پیامک ورودی در private/config.php کامل نیست'], 503);
    }
    $endpoint = 'https://rest.payamak-panel.com/api/SendSMS/GetMessages';
    $fields = [
        'UserName' => $username,
        'PassWord' => $password,
        'Location' => (string)(int)$location,
        'From' => $from,
        'Index' => $index,
        'Count' => (string)$count,
    ];
    try {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('sms_provider_http_error:' . $status . ':' . substr($curlError, 0, 120));
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => 20,
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $raw = file_get_contents($endpoint, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            if ($raw === false || preg_match('/\s2[0-9]{2}\s/', $statusLine) !== 1) {
                throw new RuntimeException('sms_provider_http_error');
            }
        }
        $messages = threefood_parse_payamak_inbox_response((string)$raw);
        $pdo->beginTransaction();
        $insertInbox = $pdo->prepare('INSERT IGNORE INTO pos_records (id, resource, payload, updated_by) VALUES (?, ?, ?, ?)');
        $findCustomers = $pdo->query("SELECT id, payload FROM pos_records WHERE resource = 'customers' FOR UPDATE")->fetchAll();
        $processed = 0;
        $duplicates = 0;
        $updated = 0;
        $unmatched = 0;
        $ignored = 0;
        foreach ($messages as $message) {
            $sender = threefood_normalize_iran_mobile($message['sender']);
            $providerId = $message['provider_id'];
            $dedupeSource = $providerId !== null
                ? 'provider:' . $providerId
                : 'fallback:' . ($sender ?? $message['sender']) . '|' . $message['text'] . '|' . ($message['received_at'] ?? '');
            $inboxId = 'in_' . substr(hash('sha256', $dedupeSource), 0, 60);
            $birthday = threefood_parse_birthday_sms($message['text']);
            $inbox = [
                'id' => $inboxId,
                'providerId' => $providerId,
                'sender' => $sender ?? $message['sender'],
                'receivedAt' => $message['received_at'],
                'birthday' => $birthday,
                'status' => 'received',
                'processedAt' => gmdate('c'),
            ];
            $insertInbox->execute([$inboxId, 'sms_inbox', json_encode($inbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id']]);
            if ($insertInbox->rowCount() === 0) {
                $duplicates++;
                continue;
            }
            $processed++;
            if ($birthday === null || $sender === null) {
                $inbox['status'] = 'ignored_unrecognized';
                $ignored++;
            } else {
                $matches = [];
                foreach ($findCustomers as $customerRow) {
                    $customer = json_decode((string)$customerRow['payload'], true);
                    if (!is_array($customer)) continue;
                    if (threefood_normalize_iran_mobile((string)($customer['phone'] ?? '')) === $sender) {
                        $matches[] = [$customerRow['id'], $customer];
                    }
                }
                if (count($matches) !== 1) {
                    $inbox['status'] = count($matches) === 0 ? 'customer_not_found' : 'ambiguous_customer';
                    $unmatched++;
                } else {
                    [$customerId] = $matches[0];
                    // Re-read the locked row for each reply so a second SMS in
                    // this same batch cannot overwrite a birthday saved earlier.
                    $currentCustomerQuery = $pdo->prepare("SELECT payload FROM pos_records WHERE resource = 'customers' AND id = ? FOR UPDATE");
                    $currentCustomerQuery->execute([(string)$customerId]);
                    $currentCustomerRaw = $currentCustomerQuery->fetchColumn();
                    $customer = is_string($currentCustomerRaw) ? json_decode($currentCustomerRaw, true) : null;
                    if (!is_array($customer) || trim((string)($customer['birthday'] ?? '')) !== '') {
                        $inbox['status'] = 'birthday_already_set';
                        $ignored++;
                    } else {
                        $customer['birthday'] = $birthday;
                        $customerPayload = json_encode($customer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                        $saveCustomer = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'customers' AND id = ?");
                        $saveCustomer->execute([$customerPayload, (int)$actor['id'], (string)$customerId]);
                        $inbox['status'] = 'birthday_saved';
                        $updated++;
                        audit($pdo, (int)$actor['id'], 'customer_birthday_received_by_sms', 'customer', (string)$customerId, ['inbox_id' => $inboxId]);
                    }
                }
            }
            $saveInbox = $pdo->prepare("UPDATE pos_records SET payload = ?, updated_by = ? WHERE resource = 'sms_inbox' AND id = ?");
            $saveInbox->execute([json_encode($inbox, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (int)$actor['id'], $inboxId]);
        }
        $pdo->commit();
        json_response(['ok' => true, 'received' => count($messages), 'processed' => $processed, 'duplicates' => $duplicates, 'birthdays_saved' => $updated, 'unmatched' => $unmatched, 'ignored' => $ignored]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[3Food POS] Birthday SMS inbox sync failed: ' . $e->getMessage());
        $unsupported = str_starts_with($e->getMessage(), 'sms_inbox_');
        json_response([
            'ok' => false,
            'error' => $unsupported ? 'sms_inbox_format_unsupported' : 'sms_inbox_sync_failed',
            'message' => $unsupported ? 'ساختار پاسخ صندوق پیامک با قالب پشتیبانی‌شده مطابقت ندارد؛ هیچ تغییری در مشتریان ثبت نشد' : 'دریافت یا پردازش پیامک‌های ورودی انجام نشد',
        ], $unsupported ? 502 : 502);
    }
}

if ($method === 'POST' && $path === '/sms/send') {
    $actor = require_role($pdo, ['manager', 'cashier']);
    require_csrf();
    $body = request_json();
    $to = preg_replace('/[^0-9+]/', '', (string)($body['to'] ?? ''));
    $message = trim((string)($body['text'] ?? ''));
    $bodyId = trim((string)($body['bodyId'] ?? ''));
    if (!preg_match('/^\\+?[0-9]{10,15}$/D', $to) || $message === '' || utf8_length($message) > 1000) {
        json_response(['ok' => false, 'error' => 'invalid_sms', 'message' => 'شماره یا متن پیامک معتبر نیست'], 422);
    }
    $sms = $config['sms'] ?? [];
    $username = trim((string)($sms['username'] ?? ''));
    $password = trim((string)($sms['password'] ?? ''));
    $sender = trim((string)($sms['sender'] ?? ''));
    if (($sms['provider'] ?? 'none') !== 'national_payamak' || $username === '' || $password === '' || (!$bodyId && $sender === '')) {
        json_response(['ok' => false, 'error' => 'sms_not_configured', 'message' => 'سرویس پیامک در تنظیمات سرور فعال نشده است'], 503);
    }
    $endpoint = $bodyId
        ? 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber'
        : 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
    $fields = $bodyId
        ? ['username' => $username, 'password' => $password, 'text' => $message, 'to' => $to, 'bodyId' => $bodyId]
        : ['username' => $username, 'password' => $password, 'to' => $to, 'from' => $sender, 'text' => $message, 'isFlash' => 'false'];
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($fields),
        'timeout' => 12,
        'ignore_errors' => true,
    ]]);
    try {
        $raw = file_get_contents($endpoint, false, $context);
        if ($raw === false) throw new RuntimeException('SMS provider request failed');
        $statusLine = $http_response_header[0] ?? '';
        $httpOk = preg_match('/\\s2[0-9]{2}\\s/', $statusLine) === 1;
        $numeric = (int)preg_replace('/[^0-9-]/', '', $raw);
        $ok = $httpOk && $numeric > 0;
        audit($pdo, (int)$actor['id'], 'sms_sent', 'sms', null, ['to' => $to, 'ok' => $ok]);
        json_response(['ok' => true, 'sent' => $ok, 'detail' => substr(trim($raw), 0, 300)]);
    } catch (Throwable $e) {
        error_log('[3Food POS] SMS provider request failed: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'sms_provider_unavailable', 'message' => 'ارتباط با سرویس پیامک برقرار نشد'], 502);
    }
}

json_response(['ok' => false, 'error' => 'not_found', 'message' => 'مسیر API پیدا نشد'], 404);
