<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use JsonException;

final readonly class NationalPayamakSmartSmsProvider implements SmsProvider
{
    private const SEND_URL = 'https://rest.payamak-panel.com/api/SmartSMS/Send';
    private const SEND_MULTIPLE_URL = 'https://rest.payamak-panel.com/api/SmartSMS/SendMultiple';
    private const DELIVERIES_URL = 'https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries';

    public function __construct(
        private SmartSmsConfig $config,
        private SmsHttpClient $http,
    ) {}

    /**
     * Sends one identical text to up to 100 recipients.
     * The provider's returned message IDs must be persisted by the caller.
     *
     * @param list<string> $recipients
     * @return array{accepted:bool,messages:list<array{mobile:string,provider_id:string,status:string}>}
     */
    public function send(string $text, array $recipients): array
    {
        $numbers = self::normalizeRecipients($recipients);
        $text = self::validateText($text);
        $payload = $this->credentials() + [
            'to' => implode(',', $numbers),
            'text' => $text,
            'from' => $this->config->sender,
        ] + $this->backupSenders();

        $response = $this->post(self::SEND_URL, $payload);
        $decoded = self::decodeObject($response->body);
        $retStatus = (int) ($decoded['RetStatus'] ?? 0);
        $strStatus = strtolower(trim((string) ($decoded['StrRetStatus'] ?? '')));
        if ($response->statusCode !== 200 || $retStatus !== 1 || !in_array($strStatus, ['ok', 'success'], true)) {
            throw self::providerError($decoded, $response->statusCode);
        }

        $ids = self::parseIds($decoded['Value'] ?? null);
        if (count($ids) !== count($numbers)) {
            throw new SmsProviderException('sms_provider_response_unmatched_ids', null, false, true);
        }

        $messages = [];
        foreach ($numbers as $index => $mobile) {
            $messages[] = ['mobile' => $mobile, 'provider_id' => $ids[$index], 'status' => 'queued'];
        }
        return ['accepted' => true, 'messages' => $messages];
    }

    /**
     * Sends personalized text to up to 100 recipients using SendMultiple.
     *
     * @param list<array{to:string,text:string}> $messages
     * @return array{accepted:bool,messages:list<array{mobile:string,provider_id:string,status:string}>}
     */
    public function sendPersonalized(array $messages): array
    {
        if ($messages === [] || count($messages) > 100) {
            throw new SmsProviderException('sms_batch_size_invalid');
        }

        $numbers = [];
        $texts = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['to'], $message['text'])
                || !is_string($message['to']) || !is_string($message['text'])) {
                throw new SmsProviderException('sms_message_invalid');
            }
            $normalized = LegacySmsParser::normalizeIranMobile($message['to']);
            if ($normalized === null) throw new SmsProviderException('sms_recipient_invalid');
            $numbers[] = $normalized;
            $texts[] = self::validateText($message['text']);
        }
        if (count(array_unique($numbers)) !== count($numbers)) {
            throw new SmsProviderException('sms_duplicate_recipient');
        }

        $payload = $this->credentials() + [
            'to' => $numbers,
            'text' => $texts,
            'from' => $this->config->sender,
        ] + $this->backupSenders();

        $response = $this->post(self::SEND_MULTIPLE_URL, $payload);
        $decoded = self::decodeObject($response->body);
        if ($response->statusCode !== 200 || (string) ($decoded['ReqStatus'] ?? '') !== '1') {
            throw self::providerError($decoded, $response->statusCode);
        }

        $rows = $decoded['Result'] ?? null;
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== count($numbers)) {
            throw new SmsProviderException('sms_provider_response_unmatched_ids', null, false, true);
        }

        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !isset($row['ID'], $row['Mobile'])) {
                throw new SmsProviderException('sms_provider_response_invalid_row', null, false, true);
            }
            $mobile = LegacySmsParser::normalizeIranMobile((string) $row['Mobile']);
            $id = trim((string) $row['ID']);
            if ($mobile !== $numbers[$index] || !preg_match('/^[0-9]{1,24}$/D', $id)) {
                throw new SmsProviderException('sms_provider_response_unmatched_ids', null, false, true);
            }
            $result[] = ['mobile' => $mobile, 'provider_id' => $id, 'status' => 'queued'];
        }
        return ['accepted' => true, 'messages' => $result];
    }

    /**
     * Fetches provider delivery states for up to 100 previously stored IDs.
     *
     * @param list<string|int> $providerIds
     * @return list<array{provider_id:string,raw_status:int,status:string,terminal:bool}>
     */
    public function getDeliveryStatuses(array $providerIds): array
    {
        if ($providerIds === [] || count($providerIds) > 100) {
            throw new SmsProviderException('sms_delivery_batch_size_invalid');
        }
        $ids = [];
        foreach ($providerIds as $id) {
            $value = trim((string) $id);
            if (!preg_match('/^[0-9]{1,19}$/D', $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new SmsProviderException('sms_provider_id_invalid');
            }
            $ids[] = (int) $value;
        }

        $response = $this->post(self::DELIVERIES_URL, $this->credentials() + ['Ids' => $ids]);
        $decoded = self::decodeObject($response->body);
        if ($response->statusCode !== 200 || (int) ($decoded['RetStatus'] ?? 0) !== 1) {
            throw self::providerError($decoded, $response->statusCode);
        }
        $value = $decoded['Value'] ?? null;
        if (is_string($value)) {
            $parsed = json_decode($value, true);
            if (is_array($parsed)) $value = $parsed;
            elseif (preg_match('/^[0-9,;\s-]+$/', $value)) {
                $value = preg_split('/[,;\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        if (!is_array($value) || !array_is_list($value) || count($value) !== count($ids)) {
            throw new SmsProviderException('sms_delivery_response_invalid', null, false, true);
        }

        $result = [];
        foreach ($value as $index => $rawStatus) {
            if (!is_numeric($rawStatus)) throw new SmsProviderException('sms_delivery_status_invalid', null, false, true);
            $code = (int) $rawStatus;
            [$status, $terminal] = self::mapDeliveryStatus($code);
            $result[] = [
                'provider_id' => (string) $ids[$index],
                'raw_status' => $code,
                'status' => $status,
                'terminal' => $terminal,
            ];
        }
        return $result;
    }

    /** @return array<string,string> */
    private function credentials(): array
    {
        return ['username' => $this->config->username, 'password' => $this->config->apiKey()];
    }

    /** @return array<string,string> */
    private function backupSenders(): array
    {
        $result = [];
        if ($this->config->backupSenderOne !== null) $result['fromSupportOne'] = $this->config->backupSenderOne;
        if ($this->config->backupSenderTwo !== null) $result['fromSupportTwo'] = $this->config->backupSenderTwo;
        return $result;
    }

    private function post(string $url, array $payload): SmsHttpResponse
    {
        try {
            return $this->http->postJson($url, $payload, $this->config->timeoutSeconds);
        } catch (SmsProviderException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new SmsProviderException('sms_provider_transport_outcome_unknown', null, false, true);
        }
    }

    private static function validateText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $text)) {
            throw new SmsProviderException('sms_text_invalid');
        }
        return $text;
    }

    /** @return list<string> */
    private static function normalizeRecipients(array $recipients): array
    {
        if ($recipients === [] || count($recipients) > 100) {
            throw new SmsProviderException('sms_batch_size_invalid');
        }
        $numbers = [];
        foreach ($recipients as $recipient) {
            if (!is_string($recipient)) throw new SmsProviderException('sms_recipient_invalid');
            $number = LegacySmsParser::normalizeIranMobile($recipient);
            if ($number === null) throw new SmsProviderException('sms_recipient_invalid');
            $numbers[] = $number;
        }
        if (count(array_unique($numbers)) !== count($numbers)) {
            throw new SmsProviderException('sms_duplicate_recipient');
        }
        return $numbers;
    }

    /** @return list<string> */
    private static function parseIds(mixed $value): array
    {
        if (is_int($value) || is_string($value)) {
            $parts = preg_split('/[,;\s]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($value) && array_is_list($value)) {
            $parts = $value;
        } else {
            return [];
        }
        $ids = [];
        foreach ($parts as $part) {
            $id = trim((string) $part);
            if (!preg_match('/^[0-9]{1,19}$/D', $id) || filter_var($id, FILTER_VALIDATE_INT) === false) return [];
            $ids[] = $id;
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    private static function decodeObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SmsProviderException('sms_provider_response_invalid', null, false, true);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new SmsProviderException('sms_provider_response_invalid', null, false, true);
        }
        return $decoded;
    }

    private static function providerError(array $decoded, int $httpStatus): SmsProviderException
    {
        $raw = $decoded['ReqStatus'] ?? $decoded['Value'] ?? $decoded['RetStatus'] ?? null;
        $code = is_numeric($raw) ? (int) $raw : null;
        $message = match ($code) {
            -10 => 'sms_provider_delivery_report_error',
            -3 => 'sms_provider_delivery_not_available',
            -2 => 'sms_provider_delivery_id_invalid',
            -1 => 'sms_provider_delivery_credentials_invalid',
            0 => 'sms_provider_credentials_invalid',
            1 => 'sms_provider_recipient_text_count_mismatch',
            2 => 'sms_provider_insufficient_credit',
            4 => 'sms_provider_batch_limit_exceeded',
            5 => 'sms_provider_sender_invalid',
            7 => 'sms_provider_text_filtered',
            9 => 'sms_provider_public_sender_not_allowed',
            14 => 'sms_provider_link_not_allowed',
            15 => 'sms_provider_unsubscribe_marker_required',
            default => 'sms_provider_request_rejected',
        };
        $retryable = $httpStatus === 429;
        $uncertain = $httpStatus >= 500 || $httpStatus === 0;
        return new SmsProviderException($message, $code, $retryable, $uncertain);
    }

    /** @return array{0:string,1:bool} */
    private static function mapDeliveryStatus(int $code): array
    {
        return match ($code) {
            0, 8 => ['sent_to_carrier', false],
            1 => ['delivered', true],
            2, 3, 16 => ['failed', true],
            35, 300, 500 => ['rejected', true],
            200 => ['sent', false],
            400 => ['queued', false],
            5, 100 => ['unknown', false],
            default => ['unknown', false],
        };
    }
}
