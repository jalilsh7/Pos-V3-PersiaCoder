<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/v3/migrations/023_sms-marketing-campaign-foundation.sql');
$schema = file_get_contents($root . '/database/v3/schema.sql');
$transactional = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsOutboxWorker.php');
$templateOutbox = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsServiceTemplateOutbox.php');
$checks = [
    'campaign storage is separate from transactional outbox' =>
        str_contains($migration, 'CREATE TABLE pos_sms_marketing_campaigns')
        && str_contains($migration, 'CREATE TABLE pos_sms_marketing_campaign_recipients')
        && !str_contains($migration, 'pos_sms_outbox'),
    'campaign and recipient records are business scoped' =>
        str_contains($migration, 'fk_sms_marketing_campaign_business')
        && str_contains($migration, 'FOREIGN KEY (business_id, campaign_id)')
        && str_contains($migration, 'FOREIGN KEY (business_id, customer_id)'),
    'one campaign recipient per customer is enforced' =>
        str_contains($migration, 'uq_sms_marketing_recipient_once'),
    'campaign recipient stores consent evidence reference, not a consent assertion' =>
        str_contains($migration, 'consent_event_id BIGINT UNSIGNED NULL')
        && str_contains($migration, 'fk_sms_marketing_recipient_consent'),
    'campaign state machine does not enable dispatch by default' =>
        str_contains($migration, "DEFAULT 'draft'")
        && str_contains($migration, "('draft','prepared','paused','cancelled','completed')"),
    'clean install schema contains the same campaign tables' =>
        str_contains($schema, 'CREATE TABLE pos_sms_marketing_campaigns')
        && str_contains($schema, 'CREATE TABLE pos_sms_marketing_campaign_recipients'),
    'transactional worker remains isolated from marketing campaign tables' =>
        !str_contains($transactional, 'pos_sms_marketing_campaign')
        && !str_contains($templateOutbox, 'pos_sms_marketing_campaign'),
];
$failed = false;
foreach ($checks as $label => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL);
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
