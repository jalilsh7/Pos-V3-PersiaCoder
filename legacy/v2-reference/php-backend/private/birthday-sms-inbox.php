<?php
declare(strict_types=1);

/**
 * Normalize the documented National Payamak REST GetMessages response.
 * The provider wraps its result in Value; only structured JSON message rows
 * with recognizable sender and text fields are accepted. Unknown formats fail
 * closed so no customer data can be changed from a guessed response shape.
 */
function threefood_sms_field(array $row, array $names): ?string {
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

function threefood_parse_payamak_inbox_response(string $raw): array {
    $envelope = json_decode($raw, true);
    if (!is_array($envelope) || !array_key_exists('Value', $envelope)) {
        throw new RuntimeException('sms_inbox_invalid_envelope');
    }
    $value = $envelope['Value'];
    if (is_string($value)) {
        $decoded = json_decode(trim($value), true);
        if (!is_array($decoded)) {
            if (trim($value) === '' || trim($value) === '[]') return [];
            throw new RuntimeException('sms_inbox_unsupported_value_format');
        }
        $value = $decoded;
    }
    if (!is_array($value)) throw new RuntimeException('sms_inbox_unsupported_value_format');

    // Some provider responses wrap rows in a named collection.
    if (isset($value['Messages']) && is_array($value['Messages'])) $value = $value['Messages'];
    elseif (isset($value['messages']) && is_array($value['messages'])) $value = $value['messages'];
    elseif (isset($value['Items']) && is_array($value['Items'])) $value = $value['Items'];
    elseif (isset($value['items']) && is_array($value['items'])) $value = $value['items'];

    if ($value === []) return [];
    if (!array_is_list($value)) throw new RuntimeException('sms_inbox_unsupported_value_format');

    $messages = [];
    foreach ($value as $row) {
        if (!is_array($row)) throw new RuntimeException('sms_inbox_unsupported_row_format');
        $sender = threefood_sms_field($row, ['SenderNumber','Sender','From','Mobile','MobileNumber','SourceNumber','Phone']);
        $text = threefood_sms_field($row, ['Text','Message','MessageText','Body','SmsText']);
        if ($sender === null || $text === null) {
            throw new RuntimeException('sms_inbox_unknown_fields');
        }
        $id = threefood_sms_field($row, ['MessageId','MsgId','Id','ID','SmsId','MessageID']);
        $receivedAt = threefood_sms_field($row, ['ReceiveDate','ReceivedAt','DateTime','Date','SendDate']);
        $messages[] = [
            'provider_id' => $id,
            'sender' => $sender,
            'text' => $text,
            'received_at' => $receivedAt,
        ];
    }
    return $messages;
}

/** Convert Iranian mobile formats to a stable local 09xxxxxxxxx key. */
function threefood_normalize_iran_mobile(string $phone): ?string {
    $phone = threefood_normalize_sms_digits($phone);
    $phone = preg_replace('/[\s()\-]/u', '', $phone) ?? '';
    if (str_starts_with($phone, '+98')) $phone = '0' . substr($phone, 3);
    elseif (str_starts_with($phone, '0098')) $phone = '0' . substr($phone, 4);
    elseif (str_starts_with($phone, '98') && strlen($phone) === 12) $phone = '0' . substr($phone, 2);
    return preg_match('/^09[0-9]{9}$/D', $phone) ? $phone : null;
}
