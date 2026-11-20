<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use JsonException;

final readonly class NationalPayamakSharedNumberProvider implements SmsTemplateProvider
{
    private const SEND_URL = 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber';

    public function __construct(
        private SharedNumberConfig $config,
        private SmsHttpClient $http,
    ) {}

    /**
     * Sends one message using a provider-approved shared service template.
     * The template must already be approved in the Payamak panel.
     *
     * @param list<string> $variables Ordered values matching the approved template placeholders.
     * @return array{accepted:bool,mobile:string,provider_id:string,status:string}
     */
    public function sendTemplate(string $recipient, int $bodyId, array $variables): array
    {
        if ($bodyId < 1) throw new SmsProviderException('sms_shared_template_id_invalid');
        $mobile = LegacySmsParser::normalizeIranMobile($recipient);
        if ($mobile === null) throw new SmsProviderException('sms_recipient_invalid');

        foreach ($variables as $variable) {
            if (!is_string($variable) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $variable)) {
                throw new SmsProviderException('sms_template_variable_invalid');
            }
        }

        $payload = [
            'username' => $this->config->username,
            'password' => $this->config->apiKey(),
            'text' => implode(';', $variables),
            'to' => $mobile,
            'bodyId' => $bodyId,
        ];
        try {
            $response = $this->http->postJson(self::SEND_URL, $payload, $this->config->timeoutSeconds);
        } catch (SmsProviderException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new SmsProviderException('sms_provider_transport_outcome_unknown', null, false, true);
        }

        try {
            $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SmsProviderException('sms_provider_response_invalid', null, false, true);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new SmsProviderException('sms_provider_response_invalid', null, false, true);
        }

        $retStatus = (int) ($decoded['RetStatus'] ?? 0);
        $strStatus = strtolower(trim((string) ($decoded['StrRetStatus'] ?? '')));
        $providerId = trim((string) ($decoded['Value'] ?? ''));
        if ($response->statusCode !== 200 || $retStatus !== 1
            || !in_array($strStatus, ['ok', 'success'], true)
            || !preg_match('/^[0-9]{16,19}$/D', $providerId)
            || filter_var($providerId, FILTER_VALIDATE_INT) === false) {
            throw self::providerError($decoded, $response->statusCode);
        }

        return [
            'accepted' => true,
            'mobile' => $mobile,
            'provider_id' => $providerId,
            'status' => 'queued',
        ];
    }

    private static function providerError(array $decoded, int $httpStatus): SmsProviderException
    {
        $raw = $decoded['Value'] ?? $decoded['RetStatus'] ?? null;
        $code = is_numeric($raw) ? (int) $raw : null;
        $message = match ($code) {
            -110 => 'sms_shared_api_key_required',
            -109 => 'sms_provider_allowed_ip_required',
            -108 => 'sms_provider_ip_blocked',
            -10 => 'sms_provider_link_not_allowed',
            -6 => 'sms_provider_internal_error',
            -5 => 'sms_shared_template_variables_mismatch',
            -4 => 'sms_shared_template_not_approved',
            -3 => 'sms_provider_sender_not_configured',
            -2 => 'sms_provider_single_recipient_only',
            -1 => 'sms_provider_service_access_disabled',
            0 => 'sms_provider_credentials_invalid',
            2 => 'sms_provider_insufficient_credit',
            6 => 'sms_provider_maintenance',
            7 => 'sms_provider_text_filtered',
            10 => 'sms_provider_user_inactive',
            11 => 'sms_provider_message_not_sent',
            12 => 'sms_provider_documents_incomplete',
            18 => 'sms_recipient_invalid',
            19 => 'sms_provider_daily_limit_reached',
            default => 'sms_provider_request_rejected',
        };
        $retryable = $httpStatus === 429;
        $uncertain = $httpStatus >= 500 || $httpStatus === 0;
        return new SmsProviderException($message, $code, $retryable, $uncertain);
    }
}
