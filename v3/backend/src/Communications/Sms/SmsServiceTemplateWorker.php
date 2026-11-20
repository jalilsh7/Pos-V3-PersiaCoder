<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use Throwable;

final readonly class SmsServiceTemplateWorker
{
    private SmsOutboxCrypto $crypto;

    public function __construct(private PDO $db, private SmsTemplateProvider $provider, string $applicationKey)
    {
        $this->crypto = new SmsOutboxCrypto($applicationKey);
    }

    public function processBatch(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        // A worker crash after provider acceptance must never cause a blind resend.
        $this->db->exec("UPDATE pos_sms_service_outbox SET status = 'unknown', failure_code = 'sms_worker_stale_processing', updated_at = UTC_TIMESTAMP(3) WHERE status = 'processing' AND updated_at <= UTC_TIMESTAMP(3) - INTERVAL 10 MINUTE");
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare(
                'SELECT * FROM pos_sms_service_outbox
                 WHERE status = \'queued\' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP(3))
                 ORDER BY id ASC LIMIT :limit FOR UPDATE SKIP LOCKED'
            );
            $q->bindValue(':limit', $limit, PDO::PARAM_INT);
            $q->execute();
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($rows !== []) {
                $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
                $this->db->exec('UPDATE pos_sms_service_outbox SET status = \'processing\', updated_at = UTC_TIMESTAMP(3) WHERE id IN (' . implode(',', $ids) . ')');
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        $counts = ['claimed'=>count($rows),'accepted'=>0,'rejected'=>0,'unknown'=>0,'retryable'=>0];
        foreach ($rows as $row) {
            $attempt = (int) $row['attempt_count'] + 1;
            try {
                $recipient = $this->crypto->decrypt((string)$row['recipient_ciphertext'],(string)$row['recipient_nonce'],(string)$row['recipient_tag'],(string)$row['recipient_hash']);
                $json = $this->crypto->decrypt((string)$row['variables_ciphertext'],(string)$row['variables_nonce'],(string)$row['variables_tag'],(string)$row['variables_hash']);
                $variables = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($variables) || !array_is_list($variables) || !preg_match('/^09[0-9]{9}$/D',$recipient)) throw new \RuntimeException('sms_template_payload_invalid');
                $result = $this->provider->sendTemplate($recipient,(int)$row['provider_body_id'],$variables);
                if (($result['accepted'] ?? false) !== true || !preg_match('/^[0-9]{16,19}$/D',(string)($result['provider_id'] ?? ''))) throw new \RuntimeException('sms_template_provider_response_invalid');
                $this->finish($row,$attempt,'accepted',(string)$result['provider_id'],null,null);
                $counts['accepted']++;
            } catch (SmsProviderException $e) {
                if ($e->deliveryUnknown) {
                    $this->finish($row,$attempt,'unknown',null,$e->getMessage(),null);
                    $counts['unknown']++;
                } elseif ($e->retryable && $attempt < 3) {
                    $delay = min(900,60 * (2 ** ($attempt - 1)));
                    $this->finish($row,$attempt,'queued',null,$e->getMessage(),$delay);
                    $counts['retryable']++;
                } else {
                    $this->finish($row,$attempt,'rejected',null,$e->getMessage(),null);
                    $counts['rejected']++;
                }
            } catch (Throwable $e) {
                $this->finish($row,$attempt,'rejected',null,'sms_template_payload_or_worker_error',null);
                $counts['rejected']++;
            }
        }
        return $counts;
    }

    private function finish(array $row,int $attempt,string $status,?string $providerId,?string $error,?int $delay): void
    {
        $next = $delay === null ? null : gmdate('Y-m-d H:i:s',time()+$delay);
        $this->db->beginTransaction();
        try {
            $u=$this->db->prepare(
                'UPDATE pos_sms_service_outbox SET status=?,provider_message_id=?,attempt_count=?,next_attempt_at=?,failure_code=?,updated_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND status=\'processing\''
            );
            $u->execute([$status,$providerId,$attempt,$next,$error,$row['id']]);
            $a=$this->db->prepare(
                'INSERT INTO pos_sms_service_delivery_attempts (business_id,message_id,attempt_number,outcome,provider_code,provider_message_id,error_code)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $outcome = match($status) {'accepted'=>'accepted','unknown'=>'unknown','queued'=>'retryable_failure',default=>'rejected'};
            $a->execute([(int)$row['business_id'],(int)$row['id'],$attempt,$outcome,(string)$row['provider_code'],$providerId,$error]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
