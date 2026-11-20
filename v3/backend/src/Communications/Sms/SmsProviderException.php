<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

use RuntimeException;

final class SmsProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $providerCode = null,
        public readonly bool $retryable = false,
        public readonly bool $deliveryUnknown = false,
    ) {
        parent::__construct($message);
    }
}
