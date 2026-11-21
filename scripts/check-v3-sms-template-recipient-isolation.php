<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$outbox = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsServiceTemplateOutbox.php');
$customerConsent = file_get_contents($root . '/v3/backend/src/Customers/CustomerService.php');
$checks = [
    'linked customer must resolve inside the same business and be active' =>
        str_contains($outbox, 'WHERE business_id = ? AND id = ? AND is_active = 1 LIMIT 1')
        && str_contains($outbox, "if (!is_array($row) || !is_string($row['phone_normalized'] ?? null)) return false;"),
    'linked customer never falls back to a caller supplied guest recipient' =>
        str_contains($outbox, 'A linked customer must never silently fall back')
        && str_contains($outbox, '$recipient = $customerRecipient;'),
    'explicit recipient override must match the linked customer mobile' =>
        str_contains($outbox, '!hash_equals($customerRecipient, $providedRecipient)')
        && str_contains($outbox, 'if ($recipient === null) return false;'),
    'guest recipient path remains separate from linked-customer path' =>
        str_contains($outbox, '} else {')
        && str_contains($outbox, '$recipient = $providedRecipient;'),
    'transactional template enqueue does not consult marketing consent' =>
        !str_contains($outbox, 'latestConsent')
        && !str_contains($outbox, 'marketing_consent'),
    'marketing consent remains explicit and append-only in customer service' =>
        str_contains($customerConsent, 'recordMarketingSmsConsent')
        && str_contains($customerConsent, 'customer.marketing_consent.withdrawn'),
];
$failed = false;
foreach ($checks as $label => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL);
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
