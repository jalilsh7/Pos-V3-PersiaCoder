<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

interface SmsHttpClient
{
    /** @param array<string,mixed> $payload */
    public function postJson(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse;
}
