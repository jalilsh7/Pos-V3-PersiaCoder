<?php
declare(strict_types=1);

/**
 * Strict parser for birthday replies received through SMS.
 * Accepted examples: "تولد ۱۳۷۰/۰۵/۱۲", "تولد: 1370-05-12".
 * Returns a normalized Jalali YYYY-MM-DD string, or null for anything ambiguous.
 */
function threefood_normalize_sms_digits(string $value): string {
    return strtr($value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

/** Jalali leap-year calculation using the established 33-year/break-year calendar. */
function threefood_is_jalali_leap_year(int $year): bool {
    $breaks = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
    $count = count($breaks);
    if ($year < $breaks[0] || $year >= $breaks[$count - 1]) return false;

    $leapJ = -14;
    $jp = $breaks[0];
    $jump = 0;
    for ($i = 1; $i < $count; $i++) {
        $jm = $breaks[$i];
        $jump = $jm - $jp;
        if ($year < $jm) break;
        $leapJ += intdiv($jump, 33) * 8 + intdiv($jump % 33, 4);
        $jp = $jm;
    }
    $n = $year - $jp;
    $leapJ += intdiv($n, 33) * 8 + intdiv(($n % 33) + 3, 4);
    if (($jump % 33) === 4 && ($jump - $n) === 4) $leapJ++;

    $gy = $year + 621;
    $leapG = intdiv($gy, 4) - intdiv((intdiv($gy, 100) + 1) * 3, 4) - 150;
    $march = 20 + $leapJ - $leapG;
    if (($jump - $n) < 6) $n = $n - $jump + intdiv($jump + 4, 33) * 33;
    $leap = ((($n + 1) % 33) - 1) % 4;
    if ($leap === -1) $leap = 4;
    return $leap === 0;
}

/**
 * Parse only explicit birthday commands; never infer a date from an arbitrary SMS.
 * Supported range intentionally excludes implausible years and future years.
 */
function threefood_parse_birthday_sms(string $message): ?string {
    $message = trim(threefood_normalize_sms_digits($message));
    $message = preg_replace('/[\x{200c}\x{200f}\x{200e}]/u', '', $message) ?? $message;
    if (!preg_match('/^تولد\s*[:：]?\s*(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*$/u', $message, $matches)) {
        return null;
    }
    $year = (int)$matches[1];
    $month = (int)$matches[2];
    $day = (int)$matches[3];
    if ($year < 1200 || $year > 1499 || $month < 1 || $month > 12 || $day < 1) return null;
    $maxDay = $month <= 6 ? 31 : ($month <= 11 ? 30 : (threefood_is_jalali_leap_year($year) ? 30 : 29));
    if ($day > $maxDay) return null;
    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}
