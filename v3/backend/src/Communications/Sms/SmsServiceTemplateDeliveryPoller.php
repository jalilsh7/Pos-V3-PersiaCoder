<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use Throwable;

final readonly class SmsServiceTemplateDeliveryPoller
{
    public function __construct(private PDO $db, private NationalPayamakSharedNumberProvider $provider) {}

    /** @return array{claimed:int,delivered:int,failed:int,pending:int,unknown:int} */
    public function pollBatch(int $limit = 50, int $cooldownSeconds = 300): array
    {
        $limit = max(1, min(100, $limit));
        $cooldownSeconds = max(60, min(3600, $cooldownSeconds));
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                "SELECT id,business_id,provider_message_id FROM pos_sms_service_outbox
                 WHERE status='accepted' AND provider_message_id IS NOT NULL
                   AND (next_delivery_check_at IS NULL OR next_delivery_check_at <= UTC_TIMESTAMP(3))
                 ORDER BY id ASC LIMIT :limit FOR UPDATE SKIP LOCKED"
            );
            $q->bindValue(':limit', $limit, PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $ids = array_map(static fn(array $r): int => (int)$r['id'], $rows);
                $this->db->exec(
                    'UPDATE pos_sms_service_outbox SET next_delivery_check_at=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL '
                    . $cooldownSeconds . ' SECOND) WHERE id IN (' . implode(',', $ids) . ')'
                );
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $counts = ['claimed'=>count($rows),'delivered'=>0,'failed'=>0,'pending'=>0,'unknown'=>0];
        foreach ($rows as $row) {
            try {
                $result = $this->provider->getDeliveryStatus((string)$row['provider_message_id']);
                $status = (string)$result['status'];
                $raw = (int)$result['raw_status'];
                $u = $this->db->prepare(
                    "UPDATE pos_sms_service_outbox
                     SET delivery_status_code=?,delivery_checked_at=UTC_TIMESTAMP(3),
                         status=CASE WHEN ? IN ('delivered','failed') THEN ? ELSE status END,
                         failure_code=CASE WHEN ?='failed' THEN CONCAT('sms_delivery_status_',?) ELSE NULL END,
                         updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND business_id=? AND status='accepted'"
                );
                $u->execute([$raw,$status,$status,$status,$raw,(int)$row['id'],(int)$row['business_id']]);
                if ($status === 'delivered') $counts['delivered']++;
                elseif ($status === 'failed') $counts['failed']++;
                else $counts['pending']++;
            } catch (SmsProviderException $e) {
                $safe = preg_match('/^[a-z0-9_]{1,64}$/D',$e->getMessage()) ? $e->getMessage() : 'sms_delivery_poll_failed';
                $u = $this->db->prepare(
                    "UPDATE pos_sms_service_outbox SET delivery_checked_at=UTC_TIMESTAMP(3),failure_code=?,updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND business_id=? AND status='accepted'"
                );
                $u->execute([$safe,(int)$row['id'],(int)$row['business_id']]);
                $counts['unknown']++;
            }
        }
        return $counts;
    }
}
