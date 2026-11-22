<?php
declare(strict_types=1);

namespace PersiaCoder\\PosV3\\Infrastructure\\Database;

use PDO;

final readonly class SmsMarketingCampaignRepository
{
    public function __construct(private PDO $connection) {}

    public function lockBusiness(int $businessId): void
    {
        $q = $this->connection->prepare('SELECT id FROM pos_businesses WHERE id = ? LIMIT 1 FOR UPDATE');
        $q->execute([$businessId]);
        if ($q->fetchColumn() === false) throw new \\RuntimeException('Marketing business scope no longer exists.');
    }

    public function create(int $businessId, string $publicId, string $title, int $bodyId, array $keys, int $actorId): int
    {
        $q = $this->connection->prepare(
            'INSERT INTO pos_sms_marketing_campaigns
             (business_id, public_id, title, provider_code, provider_body_id, variable_keys_json, status, created_by)
             VALUES (?, ?, ?, \'national_payamak_shared_number\', ?, ?, \'draft\', ?)'
        );
        $q->execute([$businessId, $publicId, $title, $bodyId, json_encode($keys, JSON_THROW_ON_ERROR), $actorId]);
        return (int) $this->connection->lastInsertId();
    }

    public function find(int $businessId, string $publicId, bool $forUpdate = false): ?array
    {
        $q = $this->connection->prepare(
            'SELECT id, public_id, title, provider_code, provider_body_id, variable_keys_json,
                    status, scheduled_at, created_at, updated_at
             FROM pos_sms_marketing_campaigns
             WHERE business_id = ? AND public_id = ? LIMIT 1'.($forUpdate ? ' FOR UPDATE' : '')
        );
        $q->execute([$businessId, $publicId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function list(int $businessId, int $limit = 50): array
    {
        $q = $this->connection->prepare(
            'SELECT c.public_id, c.title, c.provider_body_id, c.status, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM pos_sms_marketing_campaign_recipients r
                     WHERE r.business_id = c.business_id AND r.campaign_id = c.id AND r.status = \'eligible\') AS eligible_count
             FROM pos_sms_marketing_campaigns c
             WHERE c.business_id = ? ORDER BY c.id DESC LIMIT ?'
        );
        $q->bindValue(1, $businessId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Locks are serialized by the business row also used by customer consent and profile mutations. */
    public function audience(int $businessId): array
    {
        $q = $this->connection->prepare(
            'SELECT c.id, c.public_id, c.phone_normalized, c.is_active,
                    e.id AS consent_event_id, e.decision, e.recipient_phone_normalized
             FROM pos_customers c
             LEFT JOIN pos_customer_consent_events e
               ON e.business_id = c.business_id AND e.customer_id = c.id
              AND e.purpose = \'marketing\' AND e.channel = \'sms\'
              AND e.id = (
                SELECT MAX(e2.id) FROM pos_customer_consent_events e2
                WHERE e2.business_id = c.business_id AND e2.customer_id = c.id
                  AND e2.purpose = \'marketing\' AND e2.channel = \'sms\'
              )
             WHERE c.business_id = ? ORDER BY c.id ASC'
        );
        $q->execute([$businessId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertEligibleRecipient(int $businessId, int $campaignId, int $customerId, int $consentEventId): void
    {
        $q = $this->connection->prepare(
            'INSERT INTO pos_sms_marketing_campaign_recipients
             (business_id, campaign_id, customer_id, consent_event_id, status)
             VALUES (?, ?, ?, ?, \'eligible\')'
        );
        $q->execute([$businessId, $campaignId, $customerId, $consentEventId]);
    }

    public function markPrepared(int $businessId, int $campaignId): void
    {
        $q = $this->connection->prepare(
            'UPDATE pos_sms_marketing_campaigns SET status = \'prepared\', updated_at = CURRENT_TIMESTAMP(3)
             WHERE business_id = ? AND id = ? AND status = \'draft\''
        );
        $q->execute([$businessId, $campaignId]);
    }

    public function recipientCounts(int $businessId, int $campaignId): array
    {
        $q = $this->connection->prepare(
            'SELECT status, COUNT(*) AS total FROM pos_sms_marketing_campaign_recipients
             WHERE business_id = ? AND campaign_id = ? GROUP BY status'
        );
        $q->execute([$businessId, $campaignId]);
        $out = ['eligible' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0, 'suppressed' => 0];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string) $row['status']] = (int) $row['total'];
        return $out;
    }
}
