<?php
declare(strict_types=1);

namespace PersiaCoder\PosV3\Communications\Sms;

/**
 * V3 namespaced adaptation of the V2 birthday-SMS and National Payamak inbox
 * parsing helpers. This class parses inbound data only; it never sends SMS,
 * changes customer records, or treats an arbitrary message as consent.
 */
final class LegacySmsParser
{
    public static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
    }

    public static function isJalaliLeapYear(int $year): bool
    {
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

    /** Accepts only an explicit "تولد YYYY/MM/DD" command. */
    public static function parseBirthday(string $message): ?string
    {
        $message = trim(self::normalizeDigits($message));
        $message = preg_replace('/[\x{200c}\x{200f}\x{200e}]/u', '', $message) ?? $message;
        if (!preg_match('/^تولد\s*[:：]?\s*(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*$/u', $message, $matches)) {
            return null;
        }
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
        if ($year < 1200 || $year > 1499 || $month < 1 || $month > 12 || $day < 1) return null;
        $maxDay = $month <= 6 ? 31 : ($month <= 11 ? 30 : (self::isJalaliLeapYear($year) ? 30 : 29));
        if ($day > $maxDay) return null;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function field(array $row, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) !== strtolower($name)) continue;
                if (is_string($value) || is_int($value)) {
                    $value = trim((string)$value);
                    if ($value !== '') return $value;
                }
            }
        }
        return null;
    }

    /**
     * Parses the documented National Payamak GetMessages Value envelope.
     * Unknown structures fail closed instead of guessing field mappings.
     */
    public static function parsePayamakInbox(string $raw): array
    {
        $envelope = json_decode($raw, true);
        if (!is_array($envelope) || !array_key_exists('Value', $envelope)) {
            throw new \RuntimeException('sms_inbox_invalid_envelope');
        }
        $value = $envelope['Value'];
        if (is_string($value)) {
            $decoded = json_decode(trim($value), true);
            if (!is_array($decoded)) {
                if (trim($value) === '' || trim($value) === '[]') return [];
                throw new \RuntimeException('sms_inbox_unsupported_value_format');
            }
            $value = $decoded;
        }
        if (!is_array($value)) throw new \RuntimeException('sms_inbox_unsupported_value_format');

        if (isset($value['Messages']) && is_array($value['Messages'])) $value = $value['Messages'];
        elseif (isset($value['messages']) && is_array($value['messages'])) $value = $value['messages'];
        elseif (isset($value['Items']) && is_array($value['Items'])) $value = $value['Items'];
        elseif (isset($value['items']) && is_array($value['items'])) $value = $value['items'];

        if ($value === []) return [];
        if (!array_is_list($value)) throw new \RuntimeException('sms_inbox_unsupported_value_format');

        $messages = [];
        foreach ($value as $row) {
            if (!is_array($row)) throw new \RuntimeException('sms_inbox_unsupported_row_format');
            $sender = self::field($row, ['SenderNumber','Sender','From','Mobile','MobileNumber','SourceNumber','Phone']);
            $text = self::field($row, ['Text','Message','MessageText','Body','SmsText']);
            if ($sender === null || $text === null) throw new \RuntimeException('sms_inbox_unknown_fields');
            $messages[] = [
                'provider_id' => self::field($row, ['MessageId','MsgId','Id','ID','SmsId','MessageID']),
                'sender' => $sender,
                'text' => $text,
                'received_at' => self::field($row, ['ReceiveDate','ReceivedAt','DateTime','Date','SendDate']),
            ];
        }
        return $messages;
    }

    /** Normalize Iranian mobile numbers to the stable 09xxxxxxxxx key. */
    public static function normalizeIranMobile(string $phone): ?string
    {
        $phone = self::normalizeDigits($phone);
        $phone = preg_replace('/[\s()\-]/u', '', $phone) ?? '';
        if (str_starts_with($phone, '+98')) $phone = '0' . substr($phone, 3);
        elseif (str_starts_with($phone, '0098')) $phone = '0' . substr($phone, 4);
        elseif (str_starts_with($phone, '98') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
        return preg_match('/^09[0-9]{9}$/D', $phone) ? $phone : null;
    }
}
