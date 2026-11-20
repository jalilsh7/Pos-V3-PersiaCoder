<?php
declare(strict_types=1);
require_once __DIR__ . '/../php-backend/private/birthday-sms.php';

$cases = [
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
foreach ($cases as [$input, $expected]) {
    $actual = threefood_parse_birthday_sms($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "Birthday parser mismatch for {$input}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
echo "Birthday SMS parser checks passed (" . count($cases) . " cases).\n";
