<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

interface SmsTemplateProvider
{
    /** @param list<string> $variables */
    public function sendTemplate(string $recipient, int $bodyId, array $variables): array;
}
