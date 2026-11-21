<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/v3/migrations/022_customer-consent-evidence.sql');
$schema = file_get_contents($root . '/database/v3/schema.sql');
$service = file_get_contents($root . '/v3/backend/src/Customers/CustomerService.php');
$checks = [
    'consent history is append-only and event-key deduplicated' =>
        str_contains($migration, 'CREATE TABLE pos_customer_consent_events')
        && str_contains($migration, 'UNIQUE KEY uq_customer_consent_event_key'),
    'consent records are isolated by business and customer composite scope' =>
        str_contains($migration, 'FOREIGN KEY (business_id, customer_id)')
        && str_contains($migration, 'REFERENCES pos_customers(business_id, id)'),
    'marketing grant requires a notice version and cannot be imported as consent' =>
        str_contains($migration, "decision <> 'granted'")
        && str_contains($migration, "source <> 'import'")
        && str_contains($migration, 'notice_version'),
    'only marketing SMS consent is represented in this first contract' =>
        str_contains($migration, "purpose IN ('marketing')")
        && str_contains($migration, "channel IN ('sms')"),
    'consent management permission is granted to manager role' =>
        str_contains($migration, "customers.consent.manage")
        && str_contains($migration, "r.code = 'manager'"),
    'clean-install schema contains the consent event table' =>
        str_contains($schema, 'CREATE TABLE pos_customer_consent_events'),
    'customer CRUD does not silently infer consent' =>
        !str_contains($service, 'pos_customer_consent_events'),
];
$failed = false;
foreach ($checks as $label => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL);
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
