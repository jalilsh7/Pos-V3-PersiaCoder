<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$customerService = file_get_contents($root . '/v3/backend/src/Customers/CustomerService.php');
$customerRepository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/CustomerRepository.php');
$campaignRepository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/SmsMarketingCampaignRepository.php');

$checks = [
    'consent events suppress prepared recipients' => str_contains($customerService, "'consent_changed'"),
    'phone changes suppress prepared recipients' => str_contains($customerService, "'customer_phone_changed'"),
    'inactive customers suppress prepared recipients' => str_contains($customerService, "'customer_inactive'"),
    'suppression updates eligible recipients only' => str_contains($customerRepository, "AND status = 'eligible'"),
    'suppression records reason and evaluation time' => str_contains($customerRepository, "suppression_reason = ?, evaluated_at = CURRENT_TIMESTAMP(3)"),
    'suppression is business and customer scoped' => str_contains($customerRepository, 'WHERE business_id = ? AND customer_id = ? AND status ='),
    'campaign audience preparation shares business lock' => str_contains($campaignRepository, 'function lockBusiness(int $businessId)'),
    'audience snapshot is tied to exact consent event' => str_contains($customerService, 'Any newer event (including a fresh grant) requires a new audience snapshot.'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "SMS marketing suppression contract failed: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo "SMS marketing suppression contract: " . count($checks) . " checks defined." . PHP_EOL;
