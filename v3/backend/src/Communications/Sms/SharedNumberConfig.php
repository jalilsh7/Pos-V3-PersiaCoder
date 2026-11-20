<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use InvalidArgumentException;

final readonly class SharedNumberConfig
{
    private string $apiKey;

    public function __construct(
        public string $username,
        string $apiKey,
        public int $timeoutSeconds = 20,
    ) {
        $this->apiKey = $apiKey;
        if (trim($username) === '' || trim($apiKey) === '') {
            throw new InvalidArgumentException('sms_shared_number_configuration_incomplete');
        }
        if ($timeoutSeconds < 5 || $timeoutSeconds > 60) {
            throw new InvalidArgumentException('sms_provider_timeout_out_of_range');
        }
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public static function fromEnvironment(): ?self
    {
        $username = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_USERNAME');
        $apiKey = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_API_KEY');
        if (!is_string($username) || trim($username) === ''
            || !is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }
        $timeout = getenv('POS_V3_SMS_HTTP_TIMEOUT');
        $timeoutSeconds = is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : 20;
        return new self(trim($username), trim($apiKey), $timeoutSeconds);
    }
}
