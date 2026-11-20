<?php
declare(strict_types=1);

use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxDeliveryPoller;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxService;
use PersiaCoder\PosV3\Communications\Sms\SmsOutboxWorker;
use PersiaCoder\PosV3\Communications\Sms\SmsProvider;
use PersiaCoder\PosV3\Communications\Sms\SmsProviderException;
use PersiaCoder\PosV3\Infrastructure\Database\PermissionRepository;

$root = dirname(__DIR__);
require_once $root . '/database/v3/bin/bootstrap.php';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = "PersiaCoder\\PosV3\\";
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/v3/backend/src/' . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

final class FakeOutboxSmsProvider implements SmsProvider
{
    public ?SmsProviderException $failure = null;
    public array $sentBatches = [];
    private int $nextId = 500000000000000000;

    public function send(string $text, array $recipients): array
    {
        return $this->sendPersonalized(array_map(
            static fn (string $recipient): array => ['to' => $recipient, 'text' => $text],
            $recipients,
        ));
    }

    public function sendPersonalized(array $messages): array
    {
        $this->sentBatches[] = $messages;
        if ($this->failure !== null) throw $this->failure;
        $result = [];
        foreach ($messages as $message) {
            $result[] = [
                'mobile' => $message['to'],
                'provider_id' => (string) $this->nextId++,
                'status' => 'queued',
            ];
        }
        return ['accepted' => true, 'messages' => $result];
    }

    public function getDeliveryStatuses(array $providerIds): array
    {
        return array_map(static fn (string|int $id): array => [
            'provider_id' => (string) $id,
            'raw_status' => 1,
            'status' => 'delivered',
            'terminal' => true,
        ], $providerIds);
    }
}

$pdo = PosV3DatabaseTools::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$uuid = static fn (): string => sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0x0fff) | 0x4000, random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
);
$prefix = 'ci-sms-outbox-' . bin2hex(random_bytes(4));
$businessPublicId = $uuid();
$branchPublicId = $uuid();
$userPublicId = $uuid();
$customerPublicId = $uuid();

$insertBusiness = $pdo->prepare('INSERT INTO pos_businesses (public_id, code, name) VALUES (:public_id, :code, :name)');
$insertBusiness->execute(['public_id' => $businessPublicId, 'code' => $prefix, 'name' => 'CI SMS Outbox Business']);
$businessId = (int) $pdo->lastInsertId();
$insertBranch = $pdo->prepare(
    'INSERT INTO pos_branches (public_id, business_id, code, name) VALUES (:public_id, :business_id, :code, :name)'
);
$insertBranch->execute([
    'public_id' => $branchPublicId, 'business_id' => $businessId,
    'code' => $prefix . '-branch', 'name' => 'CI SMS Branch',
]);
$branchId = (int) $pdo->lastInsertId();

$insertUser = $pdo->prepare(
    'INSERT INTO app_users (public_id, username, password_hash, display_name, is_active, must_change_password)
     VALUES (:public_id, :username, :password_hash, :display_name, 1, 0)'
);
$insertUser->execute([
    'public_id' => $userPublicId,
    'username' => $prefix,
    'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'display_name' => 'CI SMS Manager',
]);
$userId = (int) $pdo->lastInsertId();
$roleId = $pdo->query("SELECT id FROM app_roles WHERE code = 'manager' AND is_active = 1")->fetchColumn();
if ($roleId === false) throw new RuntimeException('The manager role is missing.');
$pdo->prepare(
    'INSERT INTO app_user_business_roles (user_id, business_id, role_id) VALUES (:user_id, :business_id, :role_id)'
)->execute(['user_id' => $userId, 'business_id' => $businessId, 'role_id' => (int) $roleId]);

$insertCustomer = $pdo->prepare(
    'INSERT INTO pos_customers (business_id, public_id, name, phone_normalized, phone_display, notes, is_active)
     VALUES (:business_id, :public_id, :name, :phone_normalized, :phone_display, NULL, 1)'
);
$insertCustomer->execute([
    'business_id' => $businessId,
    'public_id' => $customerPublicId,
    'name' => 'SMS Test Customer',
    'phone_normalized' => '09123456789',
    'phone_display' => '09123456789',
]);
$scope = new BusinessScope($businessId, $businessPublicId, $branchId, $branchPublicId);
$actor = new AuthenticatedPrincipal(
    1, $userId, $userPublicId, $prefix, 'CI SMS Manager', false,
    hash('sha256', str_repeat('s', 43)), new DateTimeImmutable('+1 hour'),
);
$service = new SmsOutboxService(
    $pdo,
    str_repeat('ci-sms-outbox-app-key-', 2),
    new PermissionService(new PermissionRepository($pdo)),
);
$requestId = $uuid();
$ip = '127.0.0.1';

$queued = $service->enqueueTransactional(
    $actor, $scope, '+989123456789', 'سفارش شما ثبت شد لغو11', 'ci-sms-outbox-create-0001',
    $requestId, $ip, $customerPublicId,
);
$messagePublicId = (string) $queued['body']['data']['message']['id'];
$assert($queued['status'] === 202 && $queued['body']['data']['message']['status'] === 'queued',
    'Transactional SMS was not queued.');
$replay = $service->enqueueTransactional(
    $actor, $scope, '+989123456789', 'سفارش شما ثبت شد لغو11', 'ci-sms-outbox-create-0001',
    $requestId, $ip, $customerPublicId,
);
$assert($replay['replayed'] === true, 'SMS enqueue idempotency replay failed.');

$mismatchBlocked = false;
try {
    $service->enqueueTransactional(
        $actor, $scope, '09361234567', 'متن دیگر لغو11', 'ci-sms-outbox-mismatch-0001',
        $requestId, $ip, $customerPublicId,
    );
} catch (PersiaCoder\PosV3\Http\HttpException $error) {
    $mismatchBlocked = $error->status === 409 && $error->errorCode === 'customer_phone_mismatch';
}
$assert($mismatchBlocked, 'SMS to a phone different from the selected customer was not blocked.');

$rowQuery = $pdo->prepare(
    'SELECT id, recipient_ciphertext, message_ciphertext, recipient_hash, message_hash, status
     FROM pos_sms_outbox WHERE business_id = :business_id AND public_id = :public_id'
);
$rowQuery->execute(['business_id' => $businessId, 'public_id' => $messagePublicId]);
$row = $rowQuery->fetch(PDO::FETCH_ASSOC);
$assert(is_array($row) && $row['status'] === 'queued', 'SMS outbox row was not persisted.');
$assert(!str_contains((string) $row['recipient_ciphertext'], '09123456789')
    && !str_contains((string) $row['message_ciphertext'], 'سفارش شما ثبت شد لغو11'),
    'SMS recipient or message text was stored in plaintext.');

$provider = new FakeOutboxSmsProvider();
$worker = new SmsOutboxWorker($pdo, $provider, str_repeat('ci-sms-outbox-app-key-', 2));
$processed = $worker->processBatch(100);
$assert($processed['accepted'] === 1, 'Outbox worker did not accept the queued SMS.');
$state = $pdo->prepare('SELECT status, provider_message_id, attempt_count FROM pos_sms_outbox WHERE id = :id');
$state->execute(['id' => (int) $row['id']]);
$accepted = $state->fetch(PDO::FETCH_ASSOC);
$assert(is_array($accepted) && $accepted['status'] === 'accepted'
    && $accepted['provider_message_id'] !== null && (int) $accepted['attempt_count'] === 1,
    'Provider ID or accepted state was not persisted.');

$poller = new SmsOutboxDeliveryPoller($pdo, $provider);
$polled = $poller->pollBatch(100, 60);
$assert($polled['updated'] === 1, 'Delivery poller did not update the accepted message.');
$state->execute(['id' => (int) $row['id']]);
$delivered = $state->fetch(PDO::FETCH_ASSOC);
$assert($delivered['status'] === 'delivered', 'Delivered provider status was not persisted.');

$duplicateOne = $service->enqueueTransactional(
    $actor, $scope, '09123456789', 'پیام اول لغو11', 'ci-sms-outbox-duplicate-0001',
    $requestId, $ip, $customerPublicId,
);
$duplicateTwo = $service->enqueueTransactional(
    $actor, $scope, '09123456789', 'پیام دوم لغو11', 'ci-sms-outbox-duplicate-0002',
    $requestId, $ip, $customerPublicId,
);
$deferred = $worker->processBatch(100);
$assert($deferred['accepted'] === 1 && $deferred['deferred'] === 1,
    'Worker did not defer a duplicate recipient to avoid same-batch ambiguity.');
$next = $worker->processBatch(100);
$assert($next['accepted'] === 1, 'Deferred recipient message was not processed on the next worker pass.');

$unknownQueued = $service->enqueueTransactional(
    $actor, $scope, '09123456789', 'نتیجه نامشخص لغو11', 'ci-sms-outbox-unknown-0001',
    $requestId, $ip, $customerPublicId,
);
$provider->failure = new SmsProviderException('sms_provider_transport_outcome_unknown', null, false, true);
$unknownResult = $worker->processBatch(100);
$assert($unknownResult['unknown'] === 1, 'Unknown provider outcome was not marked unknown.');
$provider->failure = null;
$secondUnknownPass = $worker->processBatch(100);
$assert($secondUnknownPass['accepted'] === 0, 'Unknown outcome was blindly retried.');

$retryQueued = $service->enqueueTransactional(
    $actor, $scope, '09123456789', 'قابل تلاش مجدد لغو11', 'ci-sms-outbox-retry-0001',
    $requestId, $ip, $customerPublicId,
);
$provider->failure = new SmsProviderException('sms_provider_request_rejected', 429, true, false);
$retryResult = $worker->processBatch(100);
$assert($retryResult['retryable'] === 1, 'Retryable provider rejection was not scheduled for retry.');
$provider->failure = null;

$staleQueued = $service->enqueueTransactional(
    $actor, $scope, '09123456789', 'پردازش متوقف‌شده لغو11', 'ci-sms-outbox-stale-0001',
    $requestId, $ip, $customerPublicId,
);
$stalePublicId = (string) $staleQueued['body']['data']['message']['id'];
$pdo->prepare(
    "UPDATE pos_sms_outbox SET status = 'processing', updated_at = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 700 SECOND)
     WHERE business_id = :business_id AND public_id = :public_id"
)->execute(['business_id' => $businessId, 'public_id' => $stalePublicId]);
$recovery = $worker->processBatch(100);
$staleState = $pdo->prepare('SELECT status FROM pos_sms_outbox WHERE business_id = :business_id AND public_id = :public_id');
$staleState->execute(['business_id' => $businessId, 'public_id' => $stalePublicId]);
$assert($recovery['recovered_unknown'] === 1 && $staleState->fetchColumn() === 'unknown',
    'Stale processing claim was not moved to unknown without retry.');

echo 'V3 SMS outbox encryption, idempotency, worker, retry, unknown and delivery poller integration checks passed.' . PHP_EOL;
