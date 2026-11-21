<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/v3/migrations/022_customer-consent-evidence.sql');
$schema = file_get_contents($root . '/database/v3/schema.sql');
$service = file_get_contents($root . '/v3/backend/src/Customers/CustomerService.php');
$repository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/CustomerRepository.php');
$controller = file_get_contents($root . '/v3/backend/src/Customers/CustomerController.php');
$routes = file_get_contents($root . '/v3/backend/public/index.php');
$client = file_get_contents($root . '/v3/frontend/src/shared/api/client.ts');
$workspace = file_get_contents($root . '/v3/frontend/src/features/customers/CustomersWorkspace.tsx');
$integration = file_get_contents($root . '/scripts/check-v3-customer-integration.php');
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
        && str_contains($migration, 'notice_version')
        && str_contains($migration, 'evidence_ref IS NOT NULL')
        && str_contains($migration, 'recipient_phone_normalized IS NOT NULL'),
    'only marketing SMS consent is represented in this first contract' =>
        str_contains($migration, "purpose IN ('marketing')")
        && str_contains($migration, "channel IN ('sms')"),
    'consent management permission is granted to manager role' =>
        str_contains($migration, "customers.consent.manage")
        && str_contains($migration, "r.code = 'manager'"),
    'clean-install schema contains the consent event table' =>
        str_contains($schema, 'CREATE TABLE pos_customer_consent_events'),
    'consent grant requires explicit decision, notice version and evidence reference' =>
        str_contains($service, 'customer_consent_evidence_required')
        && str_contains($service, 'customer_phone_required_for_consent')
        && str_contains($service, '$evidenceRef === null')
        && str_contains($service, "'decision', 'notice_version', 'evidence_ref'"),
    'consent writes are permissioned, idempotent and audited' =>
        str_contains($service, 'customers.consent.manage')
        && str_contains($service, 'customers.consent.record')
        && str_contains($service, 'customer.marketing_consent.granted')
        && str_contains($service, 'customer.marketing_consent.withdrawn'),
    'repository derives latest state from append-only event history' =>
        str_contains($repository, 'latestConsent')
        && str_contains($repository, 'consentHistory')
        && str_contains($repository, 'ORDER BY id DESC')
        && str_contains($repository, "? 'stale'"),
    'customer API route has session, consent permission, CSRF and same-origin protection' =>
        str_contains($routes, '/customers/{customerPublicId}/marketing-sms-consent')
        && str_contains($routes, "makePermissionMiddleware('customers.consent.manage')")
        && str_contains($routes, '$csrfMiddleware')
        && str_contains($routes, '$originMiddleware'),
    'customer profile exposes consent controls and warns against inferred consent' =>
        str_contains($client, 'recordCustomerMarketingSmsConsent')
        && str_contains($workspace, 'وجود شماره همراه به‌تنهایی رضایت محسوب نمی‌شود')
        && str_contains($workspace, 'ثبت انصراف'),
    'CRM integration contract covers default unknown, grant, withdrawal and replay' =>
        str_contains($integration, "marketing_consent']['decision'] === 'unknown'")
        && str_contains($integration, 'consentReplay')
        && str_contains($integration, 'consentWithdrawn')
        && str_contains($integration, "['marketing_consent']['decision'] === 'stale'")
];
$failed = false;
foreach ($checks as $label => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL);
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
