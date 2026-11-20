<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use PDO;
use PersiaCoder\PosV3\Auth\BusinessScope;

final readonly class SmsServiceTemplateOutbox
{
    private SmsOutboxCrypto $crypto;

    public function __construct(private PDO $db, string $applicationKey)
    {
        $this->crypto = new SmsOutboxCrypto($applicationKey);
    }

    /** Queues an immutable snapshot of approved template variables in the caller's order transaction. */
    public function enqueueForOrderEvent(BusinessScope $scope, string $trigger, array $context): bool
    {
        if ($scope->branchId === null || !in_array($trigger, [
            'order.created', 'order.status_changed', 'order.ready', 'order.completed',
        ], true)) return false;

        $config = $this->db->prepare(
            'SELECT provider_body_id, variable_keys_json
             FROM pos_sms_service_templates
             WHERE business_id = ? AND branch_id = ? AND trigger_key = ? AND enabled = 1
             LIMIT 1'
        );
        $config->execute([$scope->businessId, $scope->branchId, $trigger]);
        $template = $config->fetch(PDO::FETCH_ASSOC);
        if (!is_array($template)) return false;

        $customer = null;
        if (isset($context['customer_id']) && is_int($context['customer_id']) && $context['customer_id'] > 0) {
            $lookup = $this->db->prepare(
                'SELECT name, phone_normalized FROM pos_customers
                 WHERE business_id = ? AND id = ? AND is_active = 1 LIMIT 1'
            );
            $lookup->execute([$scope->businessId, $context['customer_id']]);
            $row = $lookup->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) $customer = $row;
        }

        $recipientSource = is_string($context['recipient_phone'] ?? null) && trim($context['recipient_phone']) !== ''
            ? $context['recipient_phone']
            : (is_array($customer) ? (string) ($customer['phone_normalized'] ?? '') : '');
        $recipient = LegacySmsParser::normalizeIranMobile($recipientSource);
        if ($recipient === null) return false;

        $statusLabels = [
            'draft' => 'پیش‌نویس',
            'awaiting_payment' => 'در انتظار پرداخت',
            'accepted' => 'تأیید شده',
            'preparing' => 'در حال آماده‌سازی',
            'ready' => 'آماده تحویل',
            'completed' => 'تکمیل شده',
            'cancelled' => 'لغو شده',
            'rejected' => 'رد شده',
        ];
        $amount = number_format((int) ($context['total_amount'] ?? 0), 0, '.', ',');
        $amount = strtr($amount, '0123456789,', '۰۱۲۳۴۵۶۷۸۹٬');
        $available = [
            'order_number' => (string) (int) ($context['order_number'] ?? 0),
            'customer_name' => trim((string) ($customer['name'] ?? 'مشتری عزیز')) ?: 'مشتری عزیز',
            'total_amount' => $amount,
            'created_at' => (string) ($context['created_at'] ?? ''),
            'order_status' => $statusLabels[(string) ($context['order_status'] ?? '')] ?? (string) ($context['order_status'] ?? ''),
        ];
        $keys = json_decode((string) $template['variable_keys_json'], true);
        if (!is_array($keys) || !array_is_list($keys) || $keys === [] || count($keys) > 20) return false;
        $values = [];
        foreach ($keys as $key) {
            if (!is_string($key) || !array_key_exists($key, $available) || $available[$key] === '') return false;
            $values[] = $available[$key];
        }
        $orderId = (int) ($context['order_id'] ?? 0);
        if ($orderId < 1) return false;

        $variables = $this->crypto->encrypt(json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $recipientCipher = $this->crypto->encrypt($recipient);
        $idempotency = hash('sha256', implode(':', [
            $scope->businessId, $scope->branchId, $trigger, $orderId,
            (string) ($context['order_status'] ?? 'created'),
        ]));

        $insert = $this->db->prepare(
            'INSERT IGNORE INTO pos_sms_service_outbox
                (business_id, branch_id, order_id, trigger_key, provider_code, provider_body_id,
                 recipient_ciphertext, recipient_nonce, recipient_tag, recipient_hash,
                 variables_ciphertext, variables_nonce, variables_tag, variables_hash,
                 status, idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'queued\', ?)'
        );
        $insert->execute([
            $scope->businessId, $scope->branchId, $orderId, $trigger,
            'national_payamak_shared_number', (int) $template['provider_body_id'],
            $recipientCipher['ciphertext'], $recipientCipher['nonce'], $recipientCipher['tag'], $recipientCipher['hash'],
            $variables['ciphertext'], $variables['nonce'], $variables['tag'], $variables['hash'],
            $idempotency,
        ]);
        return $insert->rowCount() === 1;
    }
}
