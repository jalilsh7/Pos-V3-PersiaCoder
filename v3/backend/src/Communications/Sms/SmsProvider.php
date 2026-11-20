<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

interface SmsProvider
{
    /** @param list<string> $recipients */
    public function send(string $text, array $recipients): array;

    /** @param list<array{to:string,text:string}> $messages */
    public function sendPersonalized(array $messages): array;

    /** @param list<string|int> $providerIds */
    public function getDeliveryStatuses(array $providerIds): array;
}
