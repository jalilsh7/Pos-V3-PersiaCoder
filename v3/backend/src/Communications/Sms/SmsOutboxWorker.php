<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Infrastructure\Database\SmsOutboxRepository;
use Throwable;

final readonly class SmsOutboxWorker
{
    private SmsOutboxRepository $outbox;
    private SmsOutboxCrypto $crypto;

    public function __construct(
        PDO $connection,
        private SmsProvider $provider,
        string $applicationKey,
    ) {
        $this->outbox = new SmsOutboxRepository($connection);
        $this->crypto = new SmsOutboxCrypto($applicationKey);
    }

    /** @return array{claimed:int,accepted:int,rejected:int,unknown:int,retryable:int,deferred:int,recovered_unknown:int} */
    public function processBatch(int $limit = 100): array
    {
        $recovered = $this->outbox->recoverStaleProcessing();
        $rows = $this->outbox->claimQueuedBatch($limit);
        $counts = [
            'claimed' => count($rows), 'accepted' => 0, 'rejected' => 0,
            'unknown' => 0, 'retryable' => 0, 'deferred' => 0,
            'recovered_unknown' => $recovered,
        ];
        $prepared = [];
        $seenRecipients = [];

        foreach ($rows as $row) {
            try {
                $mobile = $this->crypto->decrypt(
                    (string) $row['recipient_ciphertext'],
                    (string) $row['recipient_nonce'],
                    (string) $row['recipient_tag'],
                    (string) $row['recipient_hash'],
                );
                $messageText = $this->crypto->decrypt(
                    (string) $row['message_ciphertext'],
                    (string) $row['message_nonce'],
                    (string) $row['message_tag'],
                    (string) $row['message_hash'],
                );
                if (!preg_match('/^09[0-9]{9}$/D', $mobile) || trim($messageText) === '') {
                    throw new \RuntimeException('sms_outbox_payload_invalid');
                }
            } catch (Throwable) {
                $this->outbox->markRejected($row, 'sms_outbox_integrity_failed', 'Outbox payload integrity check failed.');
                $counts['rejected']++;
                continue;
            }

            if (isset($seenRecipients[$mobile])) {
                $this->outbox->releaseClaimed((int) $row['id']);
                $counts['deferred']++;
                continue;
            }
            $seenRecipients[$mobile] = true;
            $prepared[] = ['row' => $row, 'mobile' => $mobile, 'text' => $messageText];
        }

        if ($prepared === []) return $counts;

        $messages = array_map(static fn (array $item): array => [
            'to' => $item['mobile'],
            'text' => $item['text'],
        ], $prepared);

        try {
            $result = $this->provider->sendPersonalized($messages);
        } catch (SmsProviderException $exception) {
            foreach ($prepared as $item) {
                $row = $item['row'];
                if ($exception->deliveryUnknown) {
                    $this->outbox->markUnknown($row, self::safeCode($exception));
                    $counts['unknown']++;
                } elseif ($exception->retryable) {
                    $delay = 60 * (2 ** min(5, (int) $row['attempt_count']));
                    $this->outbox->markRetryableFailure($row, self::safeCode($exception), $exception->getMessage(), $delay);
                    $counts['retryable']++;
                } else {
                    $this->outbox->markRejected($row, self::safeCode($exception), $exception->getMessage());
                    $counts['rejected']++;
                }
            }
            return $counts;
        } catch (Throwable) {
            foreach ($prepared as $item) {
                $this->outbox->markUnknown($item['row'], 'sms_worker_unexpected_outcome');
                $counts['unknown']++;
            }
            return $counts;
        }

        $providerMessages = $result['messages'] ?? null;
        if (($result['accepted'] ?? false) !== true || !is_array($providerMessages)
            || count($providerMessages) !== count($prepared)) {
            foreach ($prepared as $item) {
                $this->outbox->markUnknown($item['row'], 'sms_provider_response_unmatched');
                $counts['unknown']++;
            }
            return $counts;
        }

        foreach ($prepared as $index => $item) {
            $providerMessage = $providerMessages[$index] ?? null;
            if (!is_array($providerMessage)
                || ($providerMessage['mobile'] ?? null) !== $item['mobile']
                || !isset($providerMessage['provider_id'])
                || !preg_match('/^[0-9]{1,19}$/D', (string) $providerMessage['provider_id'])) {
                $this->outbox->markUnknown($item['row'], 'sms_provider_response_unmatched');
                $counts['unknown']++;
                continue;
            }
            $this->outbox->markAccepted($item['row'], (string) $providerMessage['provider_id']);
            $counts['accepted']++;
        }
        return $counts;
    }

    private static function safeCode(SmsProviderException $exception): string
    {
        $message = $exception->getMessage();
        return preg_match('/^[a-z0-9_]{1,64}$/D', $message) ? $message : 'sms_provider_error';
    }
}
