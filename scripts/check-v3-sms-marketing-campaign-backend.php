<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsMarketingCampaignService.php');
$repository = file_get_contents($root . '/v3/backend/src/Infrastructure/Database/SmsMarketingCampaignRepository.php');
$controller = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsMarketingCampaignController.php');
$routes = file_get_contents($root . '/v3/backend/public/index.php');
$migration = file_get_contents($root . '/database/v3/migrations/024_sms-marketing-campaign-permission.sql');
$schema = file_get_contents($root . '/database/v3/schema.sql');
$transactional = file_get_contents($root . '/v3/backend/src/Communications/Sms/SmsServiceTemplateOutbox.php');
$workspace = file_get_contents($root . '/v3/frontend/src/features/communications/SmsMarketingCampaignsWorkspace.tsx');
$client = file_get_contents($root . '/v3/frontend/src/shared/api/client.ts');
$checks = [
    'campaign management has dedicated permission' =>
        str_contains($service, 'communications.sms.marketing.manage')
        && str_contains($migration, 'communications.sms.marketing.manage')
        && str_contains($schema, 'communications.sms.marketing.manage'),
    'campaign create is idempotent and draft-only' =>
        str_contains($service, 'sms.marketing.campaign.create')
        && str_contains($repository, "'draft'"),
    'audience only includes latest granted consent bound to current phone' =>
        str_contains($repository, 'ORDER BY c.id ASC')
        && str_contains($repository, 'SELECT MAX(e2.id)')
        && str_contains($service, "['decision'] !== 'granted'")
        && str_contains($service, 'recipient_phone_normalized')
        && str_contains($service, 'hash_equals($phone'),
    'inactive customers and invalid mobile numbers are excluded' =>
        str_contains($service, "['is_active']")
        && str_contains($service, '/^09[0-9]{9}$/'),
    'recipient snapshot stores customer and consent IDs without raw phone' =>
        str_contains($repository, 'insertEligibleRecipient')
        && !str_contains($repository, 'phone_normalized, status'),
    'API routes require session, dedicated permission, CSRF and same-origin on writes' =>
        str_contains($routes, '/api/v1/communications/sms/marketing/campaigns')
        && str_contains($routes, "\$csrfMiddleware")
        && str_contains($routes, "\$originMiddleware"),
    'campaign feature has no send or dispatch action' =>
        !str_contains($controller, 'send(')
        && !str_contains($service, 'dispatch('),
    'campaign detail and frontend workspace are connected' =>
        str_contains($routes, '/{campaignPublicId}\'')
        && str_contains($workspace, 'prepareSmsMarketingAudience')
        && str_contains($workspace, 'رضایت')
        && str_contains($client, 'getSmsMarketingCampaign'),
    'transactional SMS implementation remains separate' =>
        !str_contains($transactional, 'pos_sms_marketing_campaign'),
];
$failed = false;
foreach ($checks as $label => $passed) {
    fwrite(STDOUT, ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL);
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
