<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Infrastructure\Database\SmsOutboxRepository;
use Throwable;

final readonly class SmsOutboxDeliveryPoller
{
    private SmsOutboxRepository $outbox;

    public function __construct(PDO $connection, private SmsProvider $provider)
    {
        $this->outbox = new SmsOutboxRepository($connection);
    }

    /** @return array{claimed:int,updated:int,unknown:int} */
    public function pollBatch(int $limit = 100, int $cooldownSeconds = 300): array
    {
        $rows = $this->outbox->claimDeliveryBatch($limit, $cooldownSeconds);
        $counts = ['claimed' => count($rows), 'updated' => 0, 'unknown' => 0];
        if ($rows === []) return $counts;

        $ids = array_map(static fn (array $row): string => (string) $row['provider_message_id'], $rows);
        try {
            $statuses = $this->provider->getDeliveryStatuses($ids);
        } catch (Throwable $error) {
            $code = $error instanceof SmsProviderException ? $error->getMessage() : 'sms_delivery_poll_failed';
            foreach ($rows as $row) {
                $this->outbox->recordDeliveryCheckFailure($row, self::safeCode($code));
                $counts['unknown']++;
            }
            return $counts;
        }

        if (count($statuses) !== count($rows)) {
            foreach ($rows as $row) {
                $this->outbox->recordDeliveryCheckFailure($row, 'sms_delivery_response_unmatched');
                $counts['unknown']++;
            }
            return $counts;
        }

        foreach ($rows as $index => $row) {
            $status = $statuses[$index] ?? null;
            if (!is_array($status)
                || (string) ($status['provider_id'] ?? '') !== (string) $row['provider_message_id']
                || !isset($status['raw_status'], $status['status'])) {
                $this->outbox->recordDeliveryCheckFailure($row, 'sms_delivery_response_unmatched');
                $counts['unknown']++;
                continue;
            }
            $this->outbox->applyDeliveryStatus(
                $row,
                (int) $status['raw_status'],
                (string) $status['status'],
                (bool) ($status['terminal'] ?? false),
            );
            $counts['updated']++;
        }
        return $counts;
    }

    private static function safeCode(string $message): string
    {
        return preg_match('/^[a-z0-9_]{1,64}$/D', $message) ? $message : 'sms_delivery_poll_failed';
    }
}
