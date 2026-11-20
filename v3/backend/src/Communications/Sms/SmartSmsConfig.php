<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use InvalidArgumentException;

final readonly class SmartSmsConfig
{
    public function __construct(
        public string $username,
        public string $apiKey,
        public string $sender,
        public ?string $backupSenderOne = null,
        public ?string $backupSenderTwo = null,
        public int $timeoutSeconds = 20,
    ) {
        if (trim($username) === '' || trim($apiKey) === '' || trim($sender) === '') {
            throw new InvalidArgumentException('sms_provider_configuration_incomplete');
        }
        if ($timeoutSeconds < 5 || $timeoutSeconds > 60) {
            throw new InvalidArgumentException('sms_provider_timeout_out_of_range');
        }
    }

    public static function fromEnvironment(): ?self
    {
        $username = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_USERNAME');
        $apiKey = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_API_KEY');
        $sender = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_SENDER');
        if (!is_string($username) || trim($username) === ''
            || !is_string($apiKey) || trim($apiKey) === ''
            || !is_string($sender) || trim($sender) === '') {
            return null;
        }

        $timeout = getenv('POS_V3_SMS_HTTP_TIMEOUT');
        $timeoutSeconds = is_string($timeout) && ctype_digit($timeout) ? (int) $timeout : 20;
        $backupOne = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_BACKUP_SENDER_ONE');
        $backupTwo = getenv('POS_V3_SMS_NATIONAL_PAYAMAK_BACKUP_SENDER_TWO');

        return new self(
            trim($username),
            trim($apiKey),
            trim($sender),
            is_string($backupOne) && trim($backupOne) !== '' ? trim($backupOne) : null,
            is_string($backupTwo) && trim($backupTwo) !== '' ? trim($backupTwo) : null,
            $timeoutSeconds,
        );
    }
}
