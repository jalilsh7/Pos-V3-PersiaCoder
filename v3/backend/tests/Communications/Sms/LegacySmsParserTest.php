<?php
declare(strict_types=1);

require dirname(__DIR__, 4) . '/bootstrap.php';

use PersiaCoder\PosV3\Communications\Sms\LegacySmsParser;

$birthdayCases = [
    ['تولد ۱۳۷۰/۰۵/۱۲', '1370-05-12'],
    ['تولد: 1370-5-2', '1370-05-02'],
    ['تولد ۱۴۰۳/۱۲/۳۰', '1403-12-30'],
    ['تولد ۱۴۰۴/۱۲/۳۰', null],
    ['تولد ۱۴۰۴/۰۷/۳۱', null],
    ['سلام، تولدم ۱۳۷۰/۰۵/۱۲', null],
    ['۱۳۷۰/۰۵/۱۲', null],
    ['تولد ۱۵۰۰/۰۱/۰۱', null],
    ['تولد ۱۳۷۰/۱۳/۰۱', null],
    ['تولد ۱۳۷۰/۰۵/۰۰', null],
    ['تولد ١٣٧٠/٠٥/١٢', '1370-05-12'],
];
foreach ($birthdayCases as [$input, $expected]) {
    if (LegacySmsParser::parseBirthday($input) !== $expected) {
        throw new RuntimeException('Birthday parser mismatch: ' . $input);
    }
}

$inboxCases = [
    json_encode(['Value' => [['MessageId' => 123, 'SenderNumber' => '09123456789', 'Text' => 'تولد ۱۳۷۰/۰۵/۱۲', 'ReceiveDate' => '2026-11-19']]], JSON_UNESCAPED_UNICODE),
    json_encode(['Value' => json_encode([['Id' => 'x1', 'From' => '09123456789', 'Message' => 'تولد 1370/05/12']], JSON_UNESCAPED_UNICODE)], JSON_UNESCAPED_UNICODE),
];
foreach ($inboxCases as $raw) {
    if (count(LegacySmsParser::parsePayamakInbox($raw)) !== 1) {
        throw new RuntimeException('Payamak inbox parser mismatch');
    }
}
if (LegacySmsParser::parsePayamakInbox(json_encode(['Value' => '[]'])) !== []) {
    throw new RuntimeException('Empty inbox parser mismatch');
}

$phones = [
    '+989123456789' => '09123456789',
    '00989123456789' => '09123456789',
    '۰۹۱۲۳۴۵۶۷۸۹' => '09123456789',
    '+98 912 345 6789' => '09123456789',
];
foreach ($phones as $input => $expected) {
    if (LegacySmsParser::normalizeIranMobile($input) !== $expected) {
        throw new RuntimeException('Iranian mobile normalization mismatch');
    }
}

foreach (['{}', '{"Value":"not-json"}', '{"Value":[{"foo":"bar"}]}'] as $raw) {
    try {
        LegacySmsParser::parsePayamakInbox($raw);
        throw new RuntimeException('Unsupported inbox format was accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === 'Unsupported inbox format was accepted') throw $exception;
    }
}

echo 'Legacy SMS parser tests passed (' . count($birthdayCases) . ' birthday cases). ' . PHP_EOL;
