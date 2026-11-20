<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use RuntimeException;

final class CurlSmsHttpClient implements SmsHttpClient
{
    public function postJson(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new SmsProviderException('sms_http_transport_unavailable', null, false, false);
        }
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('sms_provider_https_required');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new RuntimeException('sms_request_json_invalid');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new SmsProviderException('sms_http_transport_unavailable', null, false, true);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $responseBody = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);

        if ($responseBody === false || $curlError !== 0) {
            // The provider may have accepted the request before the transport failed.
            throw new SmsProviderException('sms_provider_transport_outcome_unknown', null, false, true);
        }
        return new SmsHttpResponse($statusCode, (string) $responseBody);
    }
}
