<?php
declare(strict_types=1);

use PersiaCoder\\PosV3\\Communications\\Sms\\SharedNumberTemplateRegistry;
use PersiaCoder\\PosV3\\Communications\\Sms\\SmsProviderException;

require_once dirname(__DIR__, 4) . '/src/Communications/Sms/SmsProviderException.php';
require_once dirname(__DIR__, 4) . '/src/Communications/Sms/SharedNumberTemplateRegistry.php';

$registry = new SharedNumberTemplateRegistry([
    'order_ready' => ['body_id' => 123456, 'variable_count' => 2],
]);
$definition = $registry->require('order_ready', 2);
if ($definition !== ['body_id' => 123456, 'variable_count' => 2]) {
    throw new RuntimeException('Configured template was not resolved.');
}

try {
    $registry->require('unknown_template', 0);
    throw new RuntimeException('Unknown template must be rejected.');
} catch (SmsProviderException $exception) {
    if ($exception->getMessage() !== 'sms_shared_template_not_configured') throw $exception;
}

try {
    $registry->require('order_ready', 1);
    throw new RuntimeException('Template variable mismatch must be rejected.');
} catch (SmsProviderException $exception) {
    if ($exception->getMessage() !== 'sms_shared_template_variables_mismatch') throw $exception;
}

foreach ([
    ['bad key!' => ['body_id' => 1, 'variable_count' => 0]],
    ['bad_id' => ['body_id' => 0, 'variable_count' => 0]],
    ['bad_count' => ['body_id' => 1, 'variable_count' => 21]],
] as $invalid) {
    try {
        new SharedNumberTemplateRegistry($invalid);
        throw new RuntimeException('Invalid template definition must be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "SharedNumberTemplateRegistryTest: OK\n";
