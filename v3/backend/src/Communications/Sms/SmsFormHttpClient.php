<?php
declare(strict_types=1);

namespace PersiaCoder\\PosV3\\Communications\\Sms;

interface SmsFormHttpClient extends SmsHttpClient
{
    /** @param array<string,string|int|bool> $payload */
    public function postForm(string $url, array $payload, int $timeoutSeconds): SmsHttpResponse;
}
