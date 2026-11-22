<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Auth\AuthenticatedPrincipal;
use PersiaCoder\PosV3\Auth\BusinessScope;
use PersiaCoder\PosV3\Auth\PermissionService;
use PersiaCoder\PosV3\Http\HttpException;
use PersiaCoder\PosV3\Infrastructure\Database\AuditLogRepository;
use PersiaCoder\PosV3\Infrastructure\Database\SmsMarketingCampaignRepository;
use PersiaCoder\PosV3\Security\AuditLogService;
use PersiaCoder\PosV3\Security\IdempotencyService;

final readonly class SmsMarketingCampaignService
{
    private SmsMarketingCampaignRepository $campaigns;
    private IdempotencyService $idempotency;
    private AuditLogService $audit;

    public function __construct(private PDO $db, string $applicationKey, private PermissionService $permissions)
    {
        $this->campaigns = new SmsMarketingCampaignRepository($db);
        $this->idempotency = new IdempotencyService($db, $applicationKey);
        $this->audit = new AuditLogService(new AuditLogRepository($db));
    }

    public function list(AuthenticatedPrincipal $actor, BusinessScope $scope): array
    {
        $this->assertPermission($actor, $scope);
        return ['campaigns' => $this->campaigns->list($scope->businessId)];
    }

    public function create(
        AuthenticatedPrincipal $actor, BusinessScope $scope, array $payload,
        string $key, string $requestId, string $ip
    ): array {
        $this->assertPermission($actor, $scope);
        foreach (array_keys($payload) as $field) {
            if (!in_array($field, ['title', 'provider_body_id', 'variable_keys'], true)) {
                throw new HttpException(422, 'sms_campaign_field_invalid', 'Unsupported campaign field.');
            }
        }
        $title = is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
        if ($title === '' || self::length($title) > 160) {
            throw new HttpException(422, 'sms_campaign_title_invalid', 'Campaign title is required and must not exceed 160 characters.');
        }
        $bodyId = filter_var($payload['provider_body_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($bodyId) || $bodyId < 1) {
            throw new HttpException(422, 'sms_campaign_body_id_invalid', 'A valid provider template body ID is required.');
        }
        $keys = $payload['variable_keys'] ?? null;
        $allowed = ['customer_name', 'order_number', 'total_amount', 'created_at', 'order_status'];
        if (!is_array($keys) || !array_is_list($keys) || $keys === [] || count($keys) > 20) {
            throw new HttpException(422, 'sms_campaign_variables_invalid', 'Variable keys must be a non-empty ordered list.');
        }
        $seen = [];
        foreach ($keys as $value) {
            if (!is_string($value) || !in_array($value, $allowed, true) || isset($seen[$value])) {
                throw new HttpException(422, 'sms_campaign_variables_invalid', 'Variable keys contain an unsupported or duplicate key.');
            }
            $seen[$value] = true;
        }
        $input = ['title' => $title, 'provider_body_id' => $bodyId, 'variable_keys' => $keys];
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $this->idempotency->execute($actor->userId, $scope, 'sms.marketing.campaign.create', $key, $fingerprint,
            function (PDO $connection) use ($actor, $scope, $input, $requestId, $ip): array {
                $this->campaigns->lockBusiness($scope->businessId);
                $publicId = self::uuid();
                $this->campaigns->create($scope->businessId, $publicId, $input['title'], $input['provider_body_id'], $input['variable_keys'], $actor->userId);
                $this->audit->record($actor->userId, 'sms.marketing_campaign.created', 'sms_marketing_campaign', $publicId,
                    null, ['status' => 'draft', 'provider_body_id' => $input['provider_body_id']],
                    null, $requestId, $ip, $scope->businessId, $scope->branchId);
                return ['status' => 201, 'body' => ['data' => ['campaign' => [
                    'id' => $publicId, 'title' => $input['title'], 'provider_code' => 'national_payamak_shared_number',
                    'provider_body_id' => $input['provider_body_id'], 'variable_keys' => $input['variable_keys'], 'status' => 'draft'
                ]], 'meta' => (object) []]];
            });
    }

    public function prepareAudience(
        AuthenticatedPrincipal $actor, BusinessScope $scope, string $publicId,
        string $key, string $requestId, string $ip
    ): array {
        $this->assertPermission($actor, $scope);
        if (!self::isUuid($publicId)) throw new HttpException(400, 'sms_campaign_id_invalid', 'Campaign identifier is invalid.');
        $fingerprint = hash('sha256', json_encode(['campaign_id' => $publicId, 'action' => 'prepare_audience']));
        return $this->idempotency->execute($actor->userId, $scope, 'sms.marketing.campaign.prepare', $key, $fingerprint,
            function (PDO $connection) use ($actor, $scope, $publicId, $requestId, $ip): array {
                $this->campaigns->lockBusiness($scope->businessId);
                $campaign = $this->campaigns->find($scope->businessId, $publicId, true);
                if ($campaign === null) throw new HttpException(404, 'sms_campaign_not_found', 'Campaign was not found.');
                if ($campaign['status'] === 'prepared') {
                    return ['status' => 200, 'body' => ['data' => ['campaign' => [
                        'id' => $publicId, 'status' => 'prepared',
                        'recipients' => $this->campaigns->recipientCounts($scope->businessId, (int) $campaign['id']),
                    ]], 'meta' => (object) []]];
                }
                if ($campaign['status'] !== 'draft') throw new HttpException(409, 'sms_campaign_not_draft', 'Only draft campaigns can prepare an audience.');
                $counts = ['total_customers' => 0, 'eligible' => 0, 'inactive' => 0, 'no_phone' => 0,
                    'no_consent' => 0, 'withdrawn' => 0, 'invalidated' => 0, 'stale_phone' => 0];
                foreach ($this->campaigns->audience($scope->businessId) as $customer) {
                    $counts['total_customers']++;
                    if (!(bool) $customer['is_active']) { $counts['inactive']++; continue; }
                    $phone = is_string($customer['phone_normalized']) ? $customer['phone_normalized'] : '';
                    if (!preg_match('/^09[0-9]{9}$/', $phone)) { $counts['no_phone']++; continue; }
                    if ($customer['consent_event_id'] === null) { $counts['no_consent']++; continue; }
                    if ($customer['decision'] === 'withdrawn') { $counts['withdrawn']++; continue; }
                    if ($customer['decision'] === 'invalidated') { $counts['invalidated']++; continue; }
                    if ($customer['decision'] !== 'granted') { $counts['no_consent']++; continue; }
                    if (!is_string($customer['recipient_phone_normalized'])
                        || !hash_equals($phone, $customer['recipient_phone_normalized'])) {
                        $counts['stale_phone']++; continue;
                    }
                    $this->campaigns->insertEligibleRecipient($scope->businessId, (int) $campaign['id'],
                        (int) $customer['id'], (int) $customer['consent_event_id']);
                    $counts['eligible']++;
                }
                $this->campaigns->markPrepared($scope->businessId, (int) $campaign['id']);
                $this->audit->record($actor->userId, 'sms.marketing_campaign.audience_prepared', 'sms_marketing_campaign', $publicId,
                    ['status' => 'draft'], ['status' => 'prepared', 'eligible_count' => $counts['eligible']],
                    null, $requestId, $ip, $scope->businessId, $scope->branchId);
                return ['status' => 200, 'body' => ['data' => ['campaign' => [
                    'id' => $publicId, 'status' => 'prepared', 'audience' => $counts,
                ]], 'meta' => (object) []]];
            });
    }

    private function assertPermission(AuthenticatedPrincipal $actor, BusinessScope $scope): void
    {
        if (!$this->permissions->allows($actor, 'communications.sms.marketing.manage', $scope)) {
            throw new HttpException(403, 'permission_denied', 'SMS marketing campaign management permission is required.');
        }
    }
    private static function uuid(): string
    {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private static function isUuid(string $v): bool { return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v); }
    private static function length(string $v): int { return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v); }
}
