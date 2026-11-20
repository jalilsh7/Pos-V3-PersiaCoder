<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

final readonly class SmsHttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}
}
